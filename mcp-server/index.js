#!/usr/bin/env node

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { WordPressAbilitiesClient } from "./wp-client.js";

// Use zod from SDK's re-export to avoid version mismatch.
import { z } from "zod";

const WP_URL = process.env.WP_URL || "http://localhost";
const WP_USER = process.env.WP_USER || "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || "";
const MAX_OUTPUT_CHARS = parseInt(process.env.WP_MAX_OUTPUT || "4000", 10);

const server = new McpServer({
  name: "wp-cli-abilities",
  version: "1.0.0",
});

const wpClient = new WordPressAbilitiesClient(WP_URL, WP_USER, WP_APP_PASSWORD);

// ---------------------------------------------------------------------------
// Cache
// ---------------------------------------------------------------------------

let abilitiesCache = null;
let cachedVersion = null;
let lastFetchMs = 0;
const FALLBACK_TTL_MS = 60 * 1000;

async function getAbilities() {
  const currentVersion = await wpClient.getAbilitiesVersion();
  const now = Date.now();

  if (currentVersion !== null) {
    if (abilitiesCache && currentVersion === cachedVersion) {
      return abilitiesCache;
    }
  } else {
    if (abilitiesCache && now - lastFetchMs < FALLBACK_TTL_MS) {
      return abilitiesCache;
    }
  }

  abilitiesCache = await wpClient.listAbilities();
  cachedVersion = currentVersion;
  lastFetchMs = now;
  return abilitiesCache;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Truncates output like Claude Code's Read tool — shows first chunk and
 * tells the agent total size so it can request with offset if needed.
 */
function truncateOutput(text, maxChars = MAX_OUTPUT_CHARS) {
  if (text.length <= maxChars) return text;
  const truncated = text.slice(0, maxChars);
  const remaining = text.length - maxChars;
  return `${truncated}\n\n...(truncated ${remaining} chars. Use offset param to page through results)`;
}

/**
 * For array results, paginate like Claude Code's file reading.
 */
function paginateArray(items, offset = 0, limit = 20) {
  const total = items.length;
  const page = items.slice(offset, offset + limit);
  const hasMore = offset + limit < total;
  return { page, total, offset, hasMore };
}

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

function text(t) {
  return { content: [{ type: "text", text: t }] };
}

function error(t) {
  return { content: [{ type: "text", text: t }], isError: true };
}

// ---------------------------------------------------------------------------
// Tool 1: wp_abilities_list
// Grouped by namespace, with filtering. Token-efficient.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_list",
  "List available WP-CLI abilities. Returns grouped by namespace (plugin, post, user...) with counts. Use filter to narrow results.",
  {
    filter: z.string().optional().describe("Keyword filter on name/description"),
    group: z
      .boolean()
      .optional()
      .describe("Group by namespace with counts (default true). Set false for flat list."),
  },
  async ({ filter, group }) => {
    try {
      let abilities = await getAbilities();

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
        return text("No abilities matched.");
      }

      // Flat list when explicitly requested or when filtered to a small set.
      if (group === false || (filter && abilities.length <= 15)) {
        const lines = abilities.map(
          (a) => `${a.name}${a.meta?.annotations?.destructive ? " [!]" : ""}${a.meta?.annotations?.readonly ? " [ro]" : ""} — ${a.description || a.label}`
        );
        return text(`${abilities.length} abilities:\n${lines.join("\n")}`);
      }

      // Grouped view — like Claude Code's directory listing.
      const groups = {};
      for (const a of abilities) {
        const ns = a.name.split("/")[0] || "other";
        const cmd = (a.name.split("/")[1] || "").split("-");
        const prefix = cmd[0] || "other";
        const key = `${ns}/${prefix}`;
        if (!groups[key]) groups[key] = [];
        groups[key].push(a);
      }

      const lines = [`${abilities.length} abilities across ${Object.keys(groups).length} groups:\n`];
      for (const [ns, items] of Object.entries(groups).sort()) {
        const subcommands = items.map((a) => {
          const sub = a.name.split("/")[1] || a.name;
          const parts = sub.split("-");
          return parts.slice(1).join("-") || parts[0];
        });
        lines.push(`${ns} (${items.length}): ${subcommands.join(", ")}`);
      }
      lines.push(`\nUse filter param to narrow, e.g. filter:"plugin" or filter:"list"`);

      return text(lines.join("\n"));
    } catch (err) {
      return error(`Failed: ${err.message}`);
    }
  }
);

// ---------------------------------------------------------------------------
// Tool 2: wp_abilities_describe
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_describe",
  "Get parameters, output schema, and annotations for a specific ability. Use before running to know what input to pass.",
  {
    ability: z.string().describe('Ability name, e.g. "wp-cli/plugin-list"'),
  },
  async ({ ability: abilityName }) => {
    try {
      const abilities = await getAbilities();
      const match = abilities.find((a) => a.name === abilityName);

      if (!match) {
        const lc = abilityName.toLowerCase();
        const fuzzy = abilities.filter((a) => a.name.toLowerCase().includes(lc));
        if (fuzzy.length > 0) {
          return text(
            `Not found. Similar:\n${fuzzy.slice(0, 10).map((a) => a.name).join("\n")}`
          );
        }
        return error(`Ability "${abilityName}" not found.`);
      }

      return text(JSON.stringify(compactDescribe(match)));
    } catch (err) {
      return error(err.message);
    }
  }
);

// ---------------------------------------------------------------------------
// Tool 3: wp_abilities_run
// Single command execution with output truncation and pagination.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_run",
  "Execute a WP-CLI ability. Supports dry_run to preview, offset/limit to paginate large results.",
  {
    ability: z.string().describe("Ability name"),
    input: z.record(z.string(), z.any()).optional().describe("Input params"),
    dry_run: z.boolean().optional().describe("Preview command without executing"),
    offset: z.number().optional().describe("For large array results: skip first N items"),
    limit: z.number().optional().describe("For large array results: return N items (default 20)"),
  },
  async ({ ability: abilityName, input, dry_run, offset, limit }) => {
    try {
      const abilities = await getAbilities();
      const match = abilities.find((a) => a.name === abilityName);

      if (!match) {
        return error(`"${abilityName}" not found. Use wp_abilities_list.`);
      }

      const validationError = validateInput(input || {}, match.input_schema);
      if (validationError) {
        return error(`invalid input: ${validationError}`);
      }

      if (dry_run) {
        const params = { ...(input || {}), _dry_run: true };
        const preview = await wpClient.executeAbility(abilityName, params);
        return text(`[DRY RUN] ${JSON.stringify(preview)}`);
      }

      const result = await wpClient.executeAbility(abilityName, input || {});
      const isDestructive = match.meta?.annotations?.destructive;
      const prefix = isDestructive ? "⚠️ destructive\n" : "";

      // Handle array results with pagination.
      if (result?.items && Array.isArray(result.items)) {
        const paged = paginateArray(result.items, offset || 0, limit || 20);
        const output = JSON.stringify(paged.page);
        let msg = `${prefix}${paged.total} results`;
        if (paged.hasMore) {
          msg += ` (showing ${paged.offset + 1}-${paged.offset + paged.page.length}, use offset:${paged.offset + (limit || 20)} for next page)`;
        }
        msg += `\n${truncateOutput(output)}`;
        return text(msg);
      }

      // Scalar/object results with truncation.
      const output =
        typeof result === "string" ? result : JSON.stringify(result, null, 2);
      return text(prefix + truncateOutput(output));
    } catch (err) {
      return error(`${abilityName}: ${err.message}`);
    }
  }
);

// ---------------------------------------------------------------------------
// Tool 4: wp_abilities_batch
// Run multiple read-only abilities in a single round trip.
// ---------------------------------------------------------------------------
server.tool(
  "wp_abilities_batch",
  "Run multiple read-only abilities in one call. All must be read-only (no destructive/eval). Returns results keyed by ability name. Max 10 per batch.",
  {
    commands: z
      .array(
        z.object({
          ability: z.string(),
          input: z.record(z.string(), z.any()).optional(),
        })
      )
      .min(1)
      .max(10)
      .describe("Array of {ability, input} objects to run"),
  },
  async ({ commands }) => {
    try {
      const abilities = await getAbilities();
      const results = {};

      for (const cmd of commands) {
        const match = abilities.find((a) => a.name === cmd.ability);

        if (!match) {
          results[cmd.ability] = { error: "not found" };
          continue;
        }

        // Only allow read-only commands in batch.
        if (
          match.meta?.annotations?.destructive ||
          cmd.ability.includes("eval")
        ) {
          results[cmd.ability] = {
            error: "destructive/eval not allowed in batch",
          };
          continue;
        }

        try {
          const result = await wpClient.executeAbility(
            cmd.ability,
            cmd.input || {}
          );
          results[cmd.ability] = result;
        } catch (err) {
          results[cmd.ability] = { error: err.message };
        }
      }

      const output = JSON.stringify(results, null, 2);
      return text(truncateOutput(output));
    } catch (err) {
      return error(`batch: ${err.message}`);
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
          text: `Failed: ${err.message}`,
        },
      ],
    };
  }
});

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
async function main() {
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

  console.error(
    "[wp-cli-abilities] MCP server running (4 tools: list, describe, run, batch)"
  );
}

main().catch((err) => {
  console.error(`[wp-cli-abilities] Fatal: ${err.message}`);
  process.exit(1);
});
