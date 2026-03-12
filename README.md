# AI Provider for LM Studio

LM Studio provider for the PHP and WP AI Client packages.

## Overview

This plugin provides [LM Studio](https://lmstudio.ai/) integration for the [PHP AI Client SDK](https://github.com/WordPress/php-ai-client) and [wp-ai-client](https://github.com/WordPress/wp-ai-client).

It uses LM Studio's OpenAI-compatible API endpoints documented at:

- https://lmstudio.ai/docs/developer/rest
- https://lmstudio.ai/docs/developer/openai-compat

By default, LM Studio runs at `http://localhost:1234` and exposes OpenAI-compatible endpoints under `/v1`, such as:

- `GET /v1/models`
- `POST /v1/chat/completions`

## Requirements

- PHP 7.4+
- `php-ai-client` `^0.4` or `wp-ai-client` `^0.2`
- LM Studio local server running

## Installation

### As a WordPress plugin

1. Install and activate the `wp-ai-client` plugin.
2. Place this plugin in `wp-content/plugins/ai-provider-for-lmstudio`.
3. Activate "AI Provider for LM Studio" from the Plugins screen.

### As a Composer package

```bash
composer require rtcamp/ai-provider-for-lmstudio
```

## Configuration

- Default host: `http://localhost:1234`
- Optional environment override: `LMSTUDIO_HOST`
- Optional WordPress setting: **Settings > LM Studio Settings**

If your LM Studio server requires authentication, set the API token in **Settings > Connectors** in WordPress AI Client.

## Usage (WordPress)

```php
use WordPress\AI_Client\Prompt_Builder;

$result = Prompt_Builder::create()
    ->using_provider( 'lmstudio' )
    ->set_system_instruction( 'You are a helpful assistant.' )
    ->add_text_message( 'Write a short haiku about sunrise.' )
    ->generate_text();
```
