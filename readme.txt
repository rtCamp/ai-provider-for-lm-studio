=== AI Provider for LM Studio ===
Contributors:      rtcamp, milindmore22
Tags:              ai, lmstudio, llm, local-ai, connector, vision
Requires at least: 7.0
Tested up to:      7.0
Stable tag:        1.0.0
Requires PHP:      7.4
Requires Plugins:  ai
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

LM Studio provider for the WordPress AI Client.

== Description ==

This plugin provides [LM Studio](https://lmstudio.ai/) integration for the WordPress AI Client. It allows you to run AI inference entirely on your own machine — connecting WordPress to a local LM Studio server with no cloud account or API key required.

**Features:**

* LM Studio provider registration for WordPress AI Client.
* Configure a default model for text generation from the admin settings page.
* Automatic model discovery from your running LM Studio instance.
* Text generation using LM Studio's REST API chat endpoint.
* Vision / multimodal input support — send text and images to vision-capable models.
* Settings page under **Settings > LM Studio Settings**.
* Works without an API key in the default local setup.
* Extended HTTP timeout (180 s) to accommodate local model warm-up.

== Installation ==

1. Ensure the WordPress AI plugin is installed and activated.
2. Upload plugin files to `/wp-content/plugins/ai-provider-for-lmstudio/`.
3. Activate plugin through the Plugins menu in WordPress.
4. Start LM Studio and load a model.
5. Configure the host URL and default model in **Settings > LM Studio Settings**.

== Screenshots ==

1. LM Studio settings page showing host URL and model selection.
2. Example of generating post content using a local LM Studio model in the WordPress editor.

== Frequently Asked Questions ==

= Do I need an API key? =

Not by default. LM Studio local server can run without authentication.

If your LM Studio server is configured with API token authentication, set the token in **Settings > Connectors**.

= What host URL is used by default? =

The default is `http://localhost:1234`. You can change this in **Settings > LM Studio Settings** or by setting the `LMSTUDIO_HOST` environment variable.

= Can I change the LM Studio host URL? =

Yes. Enter the full base URL (including port) in **Settings > LM Studio Settings > Host URL**, or set the `LMSTUDIO_HOST` environment variable. The environment variable takes priority over the admin setting.

= Can I use vision / image-input models? =

Yes. When a model loaded in LM Studio has vision capabilities, the plugin automatically uses the multimodal input format when image parts are included in a prompt. No additional configuration is needed.

= Can this plugin generate images? =

No. LM Studio supports LLM inference only. Text-to-image (image generation) is not available through this plugin.

= Why does text generation sometimes time out? =

Loading or warming up a large model in LM Studio can take significant time. The plugin automatically extends the HTTP timeout to 180 seconds for all requests to the configured LM Studio host to accommodate this.
Additionally make sure to turn off thinking ability of model in LM Studio settings, as it can cause requests to hang indefinitely.

= Which models are supported? =

Any LLM-type model loaded in LM Studio is supported. Embedding-only models are filtered out automatically. Vision-capable models also support image input.

== Changelog ==

= 1.0.0 =

* Initial release of the LM Studio provider plugin.
* LM Studio REST API model discovery and text generation support.
* Multimodal (vision) input support for vision-capable models.
* Admin settings page for host URL and default model configuration.
* Automatic localhost allowlisting and extended HTTP timeout for local inference.

== Upgrade Notice ==

= 1.0.0 =

Initial release.

* Initial release
* LM Studio OpenAI-compatible model discovery and chat completions support

== Upgrade Notice ==

= 1.0.0 =

Initial release.
