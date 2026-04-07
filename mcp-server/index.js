#!/usr/bin/env node

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "@modelcontextprotocol/sdk/node_modules/zod/lib/index.mjs";
import { WordPressAbilitiesClient } from "./wp-client.js";

const WP_URL = process.env.WP_URL || "http://localhost";
const WP_USER = process.env.WP_USER || "";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || "";
const POLL_INTERVAL_MS = parseInt(process.env.WP_POLL_INTERVAL || "300000", 10); // 5 min

const server = new McpServer({
  name: "wp-cli-abilities",
  version: "1.0.0",
});

const wpClient = new WordPressAbilitiesClient(WP_URL, WP_USER, WP_APP_PASSWORD);

/**
 * Converts a JSON Schema property definition to a Zod schema for MCP tool
 * input validation.
 */
function jsonSchemaPropertyToZod(prop) {
  const type = prop.type || "string";
  let schema;

  switch (type) {
    case "boolean":
      schema = z.boolean();
      break;
    case "integer":
    case "number":
      schema = z.number();
      break;
    case "array":
      schema = z.array(z.any());
      break;
    case "object":
      schema = z.record(z.string(), z.any());
      break;
    default:
      if (prop.enum) {
        schema = z.enum(prop.enum);
      } else {
        schema = z.string();
      }
  }

  if (prop.description) {
    schema = schema.describe(prop.description);
  }

  return schema;
}

/**
 * Builds a Zod object schema from a JSON Schema `properties` map and
 * `required` array, suitable for McpServer.tool().
 */
function buildZodInputShape(inputSchema) {
  if (!inputSchema || !inputSchema.properties) {
    return {};
  }

  const shape = {};
  const requiredFields = new Set(inputSchema.required || []);

  for (const [key, prop] of Object.entries(inputSchema.properties)) {
    let fieldSchema = jsonSchemaPropertyToZod(prop);
    if (!requiredFields.has(key)) {
      fieldSchema = fieldSchema.optional();
    }
    shape[key] = fieldSchema;
  }

  return shape;
}

/**
 * Registers a single WordPress Ability as an MCP tool.
 */
function registerAbilityAsTool(ability) {
  const toolName = ability.name.replace("/", "__");
  const description = [
    ability.description || ability.label,
    ability.meta?.wp_cli_command ? `\nWP-CLI: ${ability.meta.wp_cli_command}` : "",
    ability.meta?.annotations?.destructive ? "\n⚠️ Destructive operation" : "",
    ability.meta?.annotations?.readonly ? "\n(read-only)" : "",
  ]
    .filter(Boolean)
    .join("");

  const inputShape = buildZodInputShape(ability.input_schema);

  server.tool(toolName, description, inputShape, async (params) => {
    try {
      const result = await wpClient.executeAbility(ability.name, params);
      return {
        content: [
          {
            type: "text",
            text: typeof result === "string" ? result : JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        content: [
          {
            type: "text",
            text: `Error executing ${ability.name}: ${err.message}`,
          },
        ],
        isError: true,
      };
    }
  });
}

/**
 * Discovers abilities from WordPress and registers them all as MCP tools.
 */
async function discoverAndRegister() {
  try {
    const abilities = await wpClient.listAbilities();
    let count = 0;

    for (const ability of abilities) {
      registerAbilityAsTool(ability);
      count++;
    }

    console.error(`[wp-cli-abilities] Registered ${count} abilities as MCP tools from ${WP_URL}`);
  } catch (err) {
    console.error(`[wp-cli-abilities] Failed to discover abilities: ${err.message}`);
    console.error(`[wp-cli-abilities] Make sure WP_URL, WP_USER, and WP_APP_PASSWORD are set correctly.`);
  }
}

// Also expose a meta-tool that lets the agent refresh the abilities list.
server.tool(
  "wp_abilities_refresh",
  "Re-discover WordPress abilities. Use this if the site's plugins changed.",
  {},
  async () => {
    try {
      const abilities = await wpClient.listAbilities();
      return {
        content: [
          {
            type: "text",
            text: `Discovered ${abilities.length} abilities. Restart the MCP server to pick up new tools.`,
          },
        ],
      };
    } catch (err) {
      return {
        content: [{ type: "text", text: `Refresh failed: ${err.message}` }],
        isError: true,
      };
    }
  }
);

// Also expose a resource that lists all available abilities.
server.resource("abilities-list", "wp://abilities", async (uri) => {
  try {
    const abilities = await wpClient.listAbilities();
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
  await discoverAndRegister();

  const transport = new StdioServerTransport();
  await server.connect(transport);

  console.error("[wp-cli-abilities] MCP server running on stdio");
}

main().catch((err) => {
  console.error(`[wp-cli-abilities] Fatal: ${err.message}`);
  process.exit(1);
});
