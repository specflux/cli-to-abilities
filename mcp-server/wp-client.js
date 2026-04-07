/**
 * HTTP client for the WordPress Abilities REST API.
 *
 * Handles authentication (Application Passwords) and provides methods
 * to list and execute abilities.
 */
export class WordPressAbilitiesClient {
  /**
   * @param {string} baseUrl  WordPress site URL (e.g. "https://example.com").
   * @param {string} user     WordPress username for Application Passwords auth.
   * @param {string} appPass  Application Password (spaces allowed — they're stripped).
   */
  constructor(baseUrl, user, appPass) {
    this.baseUrl = baseUrl.replace(/\/+$/, "");
    this.user = user;
    this.appPass = appPass;
    this.abilitiesEndpoint = `${this.baseUrl}/wp-json/wp/v2/abilities`;
  }

  /**
   * Builds the Authorization header value.
   */
  getAuthHeader() {
    if (!this.user || !this.appPass) {
      return null;
    }
    const credentials = Buffer.from(`${this.user}:${this.appPass}`).toString("base64");
    return `Basic ${credentials}`;
  }

  /**
   * Makes an authenticated request to the WordPress REST API.
   *
   * @param {string} url
   * @param {object} options  Fetch options.
   * @returns {Promise<any>}
   */
  async request(url, options = {}) {
    const headers = {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(options.headers || {}),
    };

    const auth = this.getAuthHeader();
    if (auth) {
      headers["Authorization"] = auth;
    }

    const response = await fetch(url, {
      ...options,
      headers,
    });

    if (!response.ok) {
      const body = await response.text().catch(() => "");
      throw new Error(`WordPress API error ${response.status}: ${body.slice(0, 500)}`);
    }

    const contentType = response.headers.get("content-type") || "";
    if (contentType.includes("application/json")) {
      return response.json();
    }
    return response.text();
  }

  /**
   * Lists all registered abilities from the WordPress site.
   *
   * @returns {Promise<Array>} Array of ability objects.
   */
  async listAbilities() {
    const url = new URL(this.abilitiesEndpoint);
    // Request all abilities — the API paginates by default.
    url.searchParams.set("per_page", "100");

    let abilities = [];
    let page = 1;

    while (true) {
      url.searchParams.set("page", String(page));

      const response = await fetch(url.toString(), {
        headers: {
          Accept: "application/json",
          ...(this.getAuthHeader() ? { Authorization: this.getAuthHeader() } : {}),
        },
      });

      if (!response.ok) {
        if (page > 1) break; // Reached the end.
        const body = await response.text().catch(() => "");
        throw new Error(`Failed to list abilities (${response.status}): ${body.slice(0, 500)}`);
      }

      const data = await response.json();
      if (!Array.isArray(data) || data.length === 0) break;

      abilities = abilities.concat(data);

      // Check if there are more pages.
      const totalPages = parseInt(response.headers.get("x-wp-totalpages") || "1", 10);
      if (page >= totalPages) break;
      page++;
    }

    return abilities;
  }

  /**
   * Executes a single ability by name.
   *
   * @param {string} abilityName  e.g. "wp-cli/plugin-list"
   * @param {object} input        Input parameters matching the ability's input_schema.
   * @returns {Promise<any>}      Execution result.
   */
  async executeAbility(abilityName, input = {}) {
    const url = `${this.abilitiesEndpoint}/${encodeURIComponent(abilityName)}/execute`;

    return this.request(url, {
      method: "POST",
      body: JSON.stringify(input),
    });
  }

  /**
   * Retrieves details for a single ability.
   *
   * @param {string} abilityName
   * @returns {Promise<object>}
   */
  async getAbility(abilityName) {
    const url = `${this.abilitiesEndpoint}/${encodeURIComponent(abilityName)}`;
    return this.request(url);
  }

  /**
   * Fetches the lightweight abilities version string.
   *
   * This is a tiny endpoint (~50 bytes) that changes whenever plugins
   * are activated/deactivated or the theme is switched. Used by the MCP
   * server to decide if the full abilities list needs re-fetching.
   *
   * @returns {Promise<string|null>} Version string, or null on error.
   */
  async getAbilitiesVersion() {
    try {
      const url = `${this.baseUrl}/wp-json/wp-cli-abilities/v1/version`;
      const data = await this.request(url);
      return data?.version || null;
    } catch {
      // If the endpoint fails, return null to force a re-fetch.
      return null;
    }
  }
}
