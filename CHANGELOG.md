# Changelog

## 1.2.0
- Added decision model support with automatic negotiation across System One (/v1/systemone) and OpenAI-compatible (/v1/decisions, /v1/decision) endpoints.
- Added default decision model setting on the admin settings page, defaulting to "Use model selected by AI Client".
- Added dedicated decision model filtering to list only decision-making models in the decision model dropdown.
- Added multimodal decision support with base64 image data URLs for vision-enabled decision models.
- Added `LMStudioProvider::decisionModel()` and `LMStudioProvider::decisionModelIds()`.

## 1.1.2
- Changed env variable from LMSTUDIO_HOST to AI_PROVIDER_FOR_LM_STUDIO_HOST
- Renamed constants fro `_LMSTUDIO_` to `_LM_STUDIO_`

## 1.1.1
- Remove AI from required plugin header.
- Added github source code information in readme.txt
- Renames plugin to "AI Provider for LM Studio"

## 1.1.0
- Initial release of the Connector for LM Studio plugin.
- LM Studio REST API model discovery and text generation support.
- Multimodal (vision) input support for vision-capable models.
- Admin settings page for host URL and default model configuration.
- Automatic localhost allowlisting and extended HTTP timeout for local inference.

## 1.0.0

- Initial public release
