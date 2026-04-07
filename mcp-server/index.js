#!/usr/bin/env node

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "@modelcontextprotocol/sdk/node_modules/zod/lib/index.mjs";
import { WordPressAbilitiesClient } from "./wp-client.js";

const WP_URL = process.env.WP_URL || "http://localhost";
const WP_USER = process.env.WP_USER || "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || "";

const server = new McpServer({
  name: "wp-cli-abilities",
  version: "1.0.0",
});

const wpClient = new WordPressAbilitiesClient(WP_URL, WP_USER, WP_APP_PASSWORD);

/** In-memory abilities cache with version-based invalidation. */
let abilitiesCache = null;
let cachedVersion = null;
let lastFetchMs = 0;
const FALLBACK_TTL_MS = 60 * 1000; // 1 min fallback if version endpoint fails

/**
 * Returns cached abilities, re-fetching only when WordPress signals a change.
 *
 * Calls the lightweight /wp-cli-abilities/v1/version endpoint to check
 * if anything changed. Falls back to a 1-minute TTL if the version
 * endpoint is unavailable (auth error, network failure, etc).
 */
async function getAbilities() {
  const currentVersion = await wpClient.getAbilitiesVersion();
  const now = Date.now();

  // Version endpoint returned successfully — use version-based invalidation.
  if (currentVersion !== null) {
    if (abilitiesCache && currentVersion === cachedVersion) {
      return abilitiesCache;
    }
  } else {
    // Version endpoint failed (auth, network, etc) — fall back to TTL.
    if (abilitiesCache && now - lastFetchMs < FALLBACK_TTL_MS) {
      return abilitiesCache;
    }
  }

  abilitiesCache = await wpClient.listAbilities();
  cachedVersion = currentVersion;
  lastFetchMs = now;
  return abilitiesCache;
}

/**
 * Formats an ability into a compact one-line summary for listing.
 */
function summarizeAbility(a) {
  const flags = [
    a.meta?.annotations?.readonly ? "read-only" : null,
    a.meta?.annotations?.destructive ? "DESTRUCTIVE" : null,
  ]
    .filter(Boolean)
    .join(", ");

  return `${a.name} — ${a.description || a.label}${flags ? ` [${flags}]` : ""}`;
}

/**
 * Builds a compact, token-efficient ability description.
 * Returns a terse structured object — no markdown, no prose.
 */
function compactDescribe(match) {
  const params = {};
  const props = match.input_schema?.properties || {};
  const required = new Set(match.input_schema?.required || []);

  for (const [name, prop] of Object.entries(props)) {
    const p = { type: prop.type || "string" };
    if (required.has(name)) p.required = true;
    if (prop.enum) p.enum = prop.enum;
    if (prop.default !== undefined) p.default = prop.default;
    params[name] = p;
  }

  const out = {};
  for (const [name, prop] of Object.entries(match.output_schema?.properties || {})) {
    out[name] = prop.type || "any";
  }

  const desc = {
    name: match.name,
    label: match.label,
    cmd: match.meta?.wp_cli_command || null,
  };

  const ann = match.meta?.annotations || {};
  if (ann.readonly) desc.readonly = true;
  if (ann.destructive) desc.destructive = true;
  if (ann.idempotent) desc.idempotent = true;

  if (Object.keys(params).length > 0) desc.params = params;
  if (Object.keys(out).length > 0) desc.output = out;

  return desc;
}

/**
 * Validates input against the ability's input_schema.
 * Returns null if valid, or an error message string.
 */
function validateInput(input, inputSchema) {
  if (!inputSchema?.properties) return null;

  const errors = [];
  const required = new Set(inputSchema.required || []);
  const props = inputSchema.properties;

  for (const name of required) {
    if (input[name] === undefined || input[name] === null || input[name] === "") {
      errors.push(`missing:${name}`);
    }
  }

  for (const [name, value] of Object.entries(input)) {
    const prop = props[name];
    if (!prop) continue;
    if (prop.enum && !prop.enum.includes(value)) {
      errors.push(`${name}:bad value "${value}",want:${prop.enum.join("|")}`);
    }
    if (prop.type === "boolean" && typeof value !== "boolean") {
      errors.push(`${name}:want bool,got ${typeof value}`);
    }
    if ((prop.type === "integer" || prop.type === "number") && typeof value !== "number") {
      errors.push(`${name}:want ${prop.type},got ${typeof value}`);
    }
  }

  for (const name of Object.keys(input)) {
    if (!props[name] && name !== "additional_fields") {
      errors.push(`unknown:${name}`);
    }
  }

  return errors.length > 0 ? errors.join("\n") : null;
}

// ---------------------------------------------------------------------------
// Tool 1: wp_abilities_list
// Discover what abilities are available. Lightweight — just names & descriptions.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_list",
  "List all WP-CLI abilities available on the WordPress site. Use this first to discover what commands you can run. Supports optional filtering by keyword or category.",
  {
    filter: z
      .string()
      .optional()
      .describe("Filter abilities by keyword (matches name or description)"),
    category: z
      .string()
      .optional()
      .describe("Filter by category slug (e.g. 'wp-cli')"),
  },
  async ({ filter, category }) => {
    try {
      let abilities = await getAbilities();

      if (category) {
        abilities = abilities.filter((a) => a.category === category);
      }

      if (filter) {
        const lc = filter.toLowerCase();
        abilities = abilities.filter(
          (a) =>
            (a.name || "").toLowerCase().includes(lc) ||
            (a.description || "").toLowerCase().includes(lc) ||
            (a.label || "").toLowerCase().includes(lc) ||
            (a.meta?.wp_cli_command || "").toLowerCase().includes(lc)
        );
      }

      if (abilities.length === 0) {
        return {
          content: [{ type: "text", text: "No abilities matched the filter." }],
        };
      }

      const lines = abilities.map(summarizeAbility);
      return {
        content: [
          {
            type: "text",
            text: `Found ${abilities.length} abilities:\n\n${lines.join("\n")}`,
          },
        ],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Failed to list abilities: ${err.message}` }],
        isError: true,
      };
    }
  }
);

// ---------------------------------------------------------------------------
// Tool 2: wp_abilities_describe
// Get full details (input schema, output schema, annotations) for one ability.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_describe",
  "Get full details about a specific WordPress ability including its input parameters, output schema, and annotations. Use this before running an ability to understand what parameters it accepts.",
  {
    ability: z.string().describe('Ability name, e.g. "wp-cli/plugin-list"'),
  },
  async ({ ability: abilityName }) => {
    try {
      const abilities = await getAbilities();
      const match = abilities.find((a) => a.name === abilityName);

      if (!match) {
        // Try fuzzy match.
        const lc = abilityName.toLowerCase();
        const fuzzy = abilities.filter((a) => a.name.toLowerCase().includes(lc));
        if (fuzzy.length > 0) {
          return {
            content: [
              {
                type: "text",
                text: `Ability "${abilityName}" not found. Did you mean:\n${fuzzy
                  .slice(0, 10)
                  .map((a) => `  - ${a.name}`)
                  .join("\n")}`,
              },
            ],
          };
        }
        return {
          content: [{ type: "text", text: `Ability "${abilityName}" not found.` }],
          isError: true,
        };
      }

      return {
        content: [
          { type: "text", text: JSON.stringify(compactDescribe(match)) },
        ],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Error: ${err.message}` }],
        isError: true,
      };
    }
  }
);

// ---------------------------------------------------------------------------
// Tool 3: wp_abilities_run
// Execute any ability by name with a JSON input object.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_run",
  "Execute a WordPress ability (WP-CLI command). First use wp_abilities_list to discover available abilities, then wp_abilities_describe to check parameters, then this tool to run it.",
  {
    ability: z.string().describe('Ability name, e.g. "wp-cli/plugin-list"'),
    input: z
      .record(z.string(), z.any())
      .optional()
      .describe("Input parameters as key-value pairs matching the ability's input_schema"),
  },
  async ({ ability: abilityName, input }) => {
    try {
      // Validate the ability exists before executing.
      const abilities = await getAbilities();
      const match = abilities.find((a) => a.name === abilityName);

      if (!match) {
        return {
          content: [
            {
              type: "text",
              text: `Ability "${abilityName}" not found. Use wp_abilities_list to see available abilities.`,
            },
          ],
          isError: true,
        };
      }

      // Validate input against schema before calling WordPress.
      const validationError = validateInput(input || {}, match.input_schema);
      if (validationError) {
        return {
          content: [
            { type: "text", text: `invalid input: ${validationError}` },
          ],
          isError: true,
        };
      }

      // Warn on destructive operations.
      const isDestructive = match.meta?.annotations?.destructive;

      const result = await wpClient.executeAbility(abilityName, input || {});

      const output = typeof result === "string" ? result : JSON.stringify(result, null, 2);

      return {
        content: [
          {
            type: "text",
            text: isDestructive
              ? `⚠️ Destructive command executed.\n\n${output}`
              : output,
          },
        ],
      };
    } catch (err) {
      return {
        content: [
          {
            type: "text",
            text: `Error executing ${abilityName}: ${err.message}`,
          },
        ],
        isError: true,
      };
    }
  }
);

// ---------------------------------------------------------------------------
// Resource: browsable abilities list
// ---------------------------------------------------------------------------
server.resource("abilities-list", "wp://abilities", async (uri) => {
  try {
    const abilities = await getAbilities();
    const summary = abilities.map((a) => ({
      name: a.name,
      label: a.label,
      description: a.description,
      readonly: a.meta?.annotations?.readonly || false,
      destructive: a.meta?.annotations?.destructive || false,
      wp_cli_command: a.meta?.wp_cli_command || null,
    }));

    return {
      contents: [
        {
          uri: uri.href,
          mimeType: "application/json",
          text: JSON.stringify(summary, null, 2),
        },
      ],
    };
  } catch (err) {
    return {
      contents: [
        {
          uri: uri.href,
          mimeType: "text/plain",
          text: `Failed to list abilities: ${err.message}`,
        },
      ],
    };
  }
});

async function main() {
  // Pre-warm the cache so first tool call is fast.
  try {
    const abilities = await getAbilities();
    console.error(
      `[wp-cli-abilities] Discovered ${abilities.length} abilities from ${WP_URL}`
    );
  } catch (err) {
    console.error(
      `[wp-cli-abilities] Warning: could not pre-fetch abilities: ${err.message}`
    );
  }

  const transport = new StdioServerTransport();
  await server.connect(transport);

  console.error("[wp-cli-abilities] MCP server running (3 tools: list, describe, run)");
}

main().catch((err) => {
  console.error(`[wp-cli-abilities] Fatal: ${err.message}`);
  process.exit(1);
});
