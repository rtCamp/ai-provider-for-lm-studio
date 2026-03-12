=== AI Provider for LM Studio ===
Contributors:      rtcamp, 10up
Tags:              ai, lmstudio, llm, local-ai, connector
Requires at least: 7.0
Tested up to:      7.0
Stable tag:        1.0.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

LM Studio provider for the WordPress AI Client.

== Description ==

This plugin provides [LM Studio](https://lmstudio.ai/) integration for the WordPress AI Client.

It uses LM Studio OpenAI-compatible API endpoints documented at:

* https://lmstudio.ai/docs/developer/rest
* https://lmstudio.ai/docs/developer/openai-compat

By default, the plugin connects to `http://localhost:1234` and uses:

* `GET /v1/models`
* `POST /v1/chat/completions`

**Features:**

* Text generation with LM Studio models
* Automatic model discovery from `/v1/models`
* Function calling and structured output support via OpenAI-compatible API
* Settings page for configuring host URL
* Works without API key in default local setup

== Installation ==

1. Ensure the WordPress AI Client plugin is installed and activated.
2. Upload plugin files to `/wp-content/plugins/ai-provider-for-lmstudio/`.
3. Activate plugin through the 'Plugins' menu in WordPress.
4. Go to **Settings > LM Studio Settings** to configure host URL.

== Frequently Asked Questions ==

= Do I need an API key? =

Not by default. LM Studio local server can run without authentication.

If your LM Studio server is configured with API token auth, set the token in WordPress AI Client **Settings > Connectors**.

= What host URL is used by default? =

The default is `http://localhost:1234`.

= Can I change the LM Studio host URL? =

Yes. You can set `LMSTUDIO_HOST` as an environment variable, or configure it in **Settings > LM Studio Settings**.

== Changelog ==

= 1.0.0 =

* Initial release
* LM Studio OpenAI-compatible model discovery and chat completions support

== Upgrade Notice ==

= 1.0.0 =

Initial release.
