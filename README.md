# WP-CLI to Abilities

A WordPress plugin that auto-detects WP-CLI commands and registers them as [WordPress Abilities](https://developer.wordpress.org/apis/abilities-api/), making CLI functionality discoverable by AI agents and automation tools.

## Requirements

- WordPress 6.9+ (Abilities API)
- PHP 7.4+
- WP-CLI installed on the server

## How It Works

1. **Detection** — On activation, the plugin locates the WP-CLI binary (supports running inside WP-CLI or detecting it externally via `command -v wp` and common install paths).

2. **Discovery** — It parses the full WP-CLI command tree using `wp cli cmd-dump --format=json`, falling back to text-based `wp help` parsing if needed.

3. **Registration** — Each discovered command is registered as a WordPress Ability under the `wp-cli` category with:
   - JSON Schema `input_schema` derived from the command's synopsis (positional args, flags, associative options)
   - Appropriate `output_schema` based on command type (list/get/mutation)
   - `permission_callback` mapped to WordPress capabilities (`activate_plugins`, `edit_posts`, etc.)
   - Annotations: `readonly`, `destructive`, and `idempotent` flags

4. **Execution** — When an ability is invoked (by an AI agent, REST API call, or `wp ability run`), the plugin executes the underlying WP-CLI command and returns structured output.

## Configuration

Navigate to **Settings → WP-CLI Abilities** in the WordPress admin to:

| Setting | Description |
|---------|-------------|
| **Allow-list** | Comma-separated command prefixes to include (e.g., `plugin, post, user`). Empty = all. |
| **Block-list** | Comma-separated command prefixes to exclude (e.g., `db, config`). |
| **Max abilities** | Cap on number of abilities registered (default: 200). |

Meta commands (`cli`, `help`, `shell`, `package`) are always excluded.

## Example

Once active, the command `wp plugin list` becomes the ability `wp-cli/plugin-list`:

```php
$ability = wp_get_ability( 'wp-cli/plugin-list' );
$result  = $ability->execute( array( 'status' => 'active', 'format' => 'json' ) );
```

Or via WP-CLI itself:

```bash
wp ability run wp-cli/plugin-list --input='{"status":"active"}'
```

Or via the REST API (when `show_in_rest` is enabled):

```
GET /wp-json/wp/v2/abilities/wp-cli/plugin-list?status=active
```

## MCP Server (AI Agent Integration)

The included MCP server auto-discovers all registered abilities and exposes them as MCP tools. Any MCP-compatible AI agent (Claude Code, Claude Desktop, etc.) can then call WP-CLI commands directly.

### Setup

```bash
cd mcp-server
npm install
```

### Claude Code

Add to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "wp-cli-abilities": {
      "command": "node",
      "args": ["mcp-server/index.js"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USER": "admin",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### Claude Desktop

Add to `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "wp-cli-abilities": {
      "command": "node",
      "args": ["/path/to/mcp-server/index.js"],
      "env": {
        "WP_URL": "https://your-site.com",
        "WP_USER": "admin",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

### How It Works

1. On startup, the MCP server calls `GET /wp-json/wp/v2/abilities` to discover all registered abilities
2. Each ability is registered as an MCP tool with its JSON Schema mapped to Zod validation
3. When an agent calls a tool, the server POSTs to the ability's execute endpoint
4. A `wp_abilities_refresh` meta-tool lets the agent re-discover abilities without restarting
5. A `wp://abilities` resource provides a browsable list of all available abilities

### Auth

The server uses [WordPress Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for authentication. Generate one at **Users → Profile → Application Passwords** in wp-admin.

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `WP_URL` | WordPress site URL | `http://localhost` |
| `WP_USER` | WordPress username | (empty) |
| `WP_APP_PASSWORD` | Application Password | (empty) |

## Cache

Command discovery results are cached for 1 hour using WordPress transients. The cache auto-clears when plugins are activated/deactivated or the theme is switched. You can also manually clear it from the settings page.

## License

GPL-2.0-or-later
