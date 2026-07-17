<?php
/**
 * LM Studio Text Generation Model.
 *
 * @package rtcamp/connector-for-lmstudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\ConnectorForLMStudio\Models;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use rtCamp\ConnectorForLMStudio\Provider\LMStudioProvider;
use rtCamp\ConnectorForLMStudio\Settings\LMStudioSettings;

/**
 * Class for an LM Studio text generation model.
 *
 * Uses LM Studio REST API endpoints.
 *
 * @since 1.0.0
 */
class LMStudioTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * {@inheritDoc}
	 *
	 * Uses LM Studio REST API endpoint /api/v1/chat.
	 *
	 * @since 1.0.0
	 *
	 * @param array $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException When LM Studio returns an invalid response payload.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$params  = $this->prepareGenerateTextParams( $prompt );
		$request = $this->createRequest(
			HttpMethodEnum::POST(),
			'/api/v1/chat',
			[ 'Content-Type' => 'application/json' ],
			$params
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Prepares parameters for LM Studio REST API chat endpoint.
	 *
	 * Applies provider-level default model override before sending.
	 *
	 * @since 1.0.0
	 *
	 * @param array $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$params = [
			'model' => $this->metadata()->getId(),
			'input' => $this->prepareInputParam( $prompt ),
		];

		$system_instruction = $this->getConfig()->getSystemInstruction();
		$system_prompt      = null !== $system_instruction ? trim( $system_instruction ) : '';

		$output_mime_type = $this->getConfig()->getOutputMimeType();
		$output_schema    = $this->getConfig()->getOutputSchema();

		if ( 'application/json' === $output_mime_type ) {
			$schema_instruction = "\n\nCRITICAL: You must return your response ONLY as a JSON object matching the following JSON schema:\n";
			if ( $output_schema ) {
				$schema_instruction .= wp_json_encode( $output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} else {
				$schema_instruction .= "{\n  \"type\": \"object\"\n}";
			}
			$schema_instruction .= "\nDo not wrap the response in markdown code blocks or add any other text. Return raw JSON.";

			$system_prompt .= $schema_instruction;
		}

		if ( '' !== trim( $system_prompt ) ) {
			$params['system_prompt'] = trim( $system_prompt );
		}

		$selected_model = LMStudioSettings::get_selected_model();
		if ( '' !== $selected_model ) {
			$params['model'] = $selected_model;
		}

		$max_tokens = $this->getConfig()->getMaxTokens();
		if ( null !== $max_tokens ) {
			$params['max_tokens'] = $max_tokens;
		}

		$temperature = $this->getConfig()->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$reasoning = LMStudioSettings::get_selected_reasoning();
		if ( '' !== $reasoning ) {
			$params['reasoning'] = $reasoning;
		}

		$custom_options = $this->getConfig()->getCustomOptions();
		foreach ( $custom_options as $key => $value ) {
			if ( isset( $params[ $key ] ) ) {
				continue;
			}

			$params[ $key ] = $value;
		}

		return apply_filters( 'connector_for_lm_studio_text_generation_params', $params );
	}

	/**
	 * Converts prompt messages into a LM Studio REST input value.
	 *
	 * Returns a plain string for text-only prompts, or an array of typed content
	 * objects when the prompt contains image parts (multimodal).
	 *
	 * @since 1.0.0
	 *
	 * @param array $prompt Prompt messages.
	 * @phpstan-param list<Message> $prompt
	 * @return string|array<int, array<string, string>>
	 */
	private function prepareInputParam( array $prompt ): string|array {
		// Check whether any message contains an image file part.
		$has_images = false;
		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isFile() && $part->getFile() && $part->getFile()->isImage() ) {
					$has_images = true;
					break 2;
				}
			}
		}

		if ( ! $has_images ) {
			// Existing text-only path: collapse all messages into a single string.
			$lines = [];

			foreach ( $prompt as $message ) {
				$text = $this->extractMessageText( $message );
				if ( '' === $text ) {
					continue;
				}

				$role_prefix = $message->getRole()->isModel() ? 'Assistant' : 'User';
				$lines[]     = $role_prefix . ': ' . $text;
			}

			if ( 1 === count( $lines ) && str_starts_with( $lines[0], 'User: ' ) ) {
				return substr( $lines[0], 6 );
			}

			return implode( "\n", $lines );
		}

		// Multimodal path: build an array of typed content objects.
		$content_parts = [];

		foreach ( $prompt as $message ) {
			foreach ( $message->getParts() as $part ) {
				if ( $part->getType()->isText() ) {
					$text = $part->getText();
					if ( null !== $text && '' !== trim( $text ) ) {
						$content_parts[] = [
							'type'    => 'text',
							'content' => $text,
						];
					}
				} elseif ( $part->getType()->isFile() ) {
					$file = $part->getFile();
					if ( null !== $file && $file->isImage() ) {
						$data_url = $file->isRemote() ? $file->getUrl() : $file->getDataUri();
						if ( null !== $data_url ) {
							$content_parts[] = [
								'type'     => 'image',
								'data_url' => $data_url,
							];
						}
					}
				}
			}
		}

		return $content_parts;
	}

	/**
	 * Extracts text content from a message.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Messages\DTO\Message $message Message.
	 * @return string
	 */
	private function extractMessageText( Message $message ): string {
		$chunks = [];

		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( null === $text || '' === trim( $text ) ) {
				continue;
			}
			$chunks[] = $text;
		}

		return implode( "\n", $chunks );
	}

	/**
	 * Creates a request for text generation.
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method The HTTP method.
	 * @param string                                                  $path   The API endpoint path.
	 * @param array<string, string>                                   $headers The request headers.
	 * @param mixed                                                   $data   The request data.
	 *
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The prepared request.
	 *
	 * @since 1.0.0
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = [],
		$data = null
	): Request {
		$path = '/' . ltrim( $path, '/' );
		$url  = LMStudioProvider::url( $path );

		return new Request(
			$method,
			$url,
			$headers,
			$data,
			$this->prepareRequestOptionsForTextGeneration()
		);
	}

	/**
	 * Parses LM Studio REST response into a GenerativeAiResult.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response HTTP response.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException When response cannot be parsed.
	 */
	private function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$data = $response->getData();
		if ( ! is_array( $data ) ) {
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromInvalidData( 'LM Studio REST', 'body', 'Response is not a JSON object.' );
		}

		$text = $this->extractOutputTextFromResponseData( $data );
		if ( '' === $text ) {
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( 'LM Studio REST', 'output' );
		}

		$output_mime_type = $this->getConfig()->getOutputMimeType();
		if ( 'application/json' === $output_mime_type ) {
			$text = $this->cleanJsonResponseText( $text );
		}

		$usage = $this->extractTokenUsageFromResponseData( $data );

		$id = '';
		if ( isset( $data['id'] ) && is_string( $data['id'] ) && '' !== $data['id'] ) {
			$id = $data['id'];
		} else {
			$id = 'lmstudio-' . wp_generate_uuid4();
		}

		$candidate = new Candidate(
			new ModelMessage( [ new MessagePart( $text ) ] ),
			FinishReasonEnum::stop()
		);

		return new GenerativeAiResult(
			$id,
			[ $candidate ],
			$usage,
			$this->providerMetadata(),
			$this->metadata(),
			[ 'lmstudio_response' => $data ]
		);
	}

	/**
	 * Cleans JSON response text from markdown block wrappers if present.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	private function cleanJsonResponseText( string $text ): string {
		$text = trim( $text );

		// Remove markdown code blocks if present.
		if ( str_starts_with( $text, '```' ) ) {
			$text = (string) preg_replace( '/^```[a-zA-Z]*\s*/', '', $text );
			$text = (string) preg_replace( '/\s*```$/', '', $text );
			$text = trim( $text );
		}

		return $text;
	}

	/**
	 * Extracts output text from known LM Studio response shapes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return string
	 */
	private function extractOutputTextFromResponseData( array $data ): string {
		$candidates = [];

		if ( isset( $data['output'] ) && is_array( $data['output'] ) ) {
			foreach ( $data['output'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( ! isset( $item['type'] ) || 'message' !== $item['type'] || ! isset( $item['content'] ) || ! is_string( $item['content'] ) ) {
					continue;
				}

				$candidates[] = $item['content'];
			}
		}

		if ( isset( $data['output'] ) && is_string( $data['output'] ) ) {
			$candidates[] = $data['output'];
		}

		if ( isset( $data['response'] ) && is_string( $data['response'] ) ) {
			$candidates[] = $data['response'];
		}

		if ( isset( $data['content'] ) && is_string( $data['content'] ) ) {
			$candidates[] = $data['content'];
		}

		if ( isset( $data['message'] ) && is_array( $data['message'] ) && isset( $data['message']['content'] ) && is_string( $data['message']['content'] ) ) {
			$candidates[] = $data['message']['content'];
		}

		if (
			isset( $data['choices'] )
			&& is_array( $data['choices'] )
			&& isset( $data['choices'][0] )
			&& is_array( $data['choices'][0] )
		) {
			$choice = $data['choices'][0];
			if ( isset( $choice['text'] ) && is_string( $choice['text'] ) ) {
				$candidates[] = $choice['text'];
			}
			if ( isset( $choice['message'] ) && is_array( $choice['message'] ) && isset( $choice['message']['content'] ) && is_string( $choice['message']['content'] ) ) {
				$candidates[] = $choice['message']['content'];
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( '' !== trim( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Extracts token usage from known LM Studio response shapes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return \WordPress\AiClient\Results\DTO\TokenUsage
	 */
	private function extractTokenUsageFromResponseData( array $data ): TokenUsage {
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : [];
		$stats = isset( $data['stats'] ) && is_array( $data['stats'] ) ? $data['stats'] : [];

		$prompt_tokens = isset( $usage['prompt_tokens'] ) && is_numeric( $usage['prompt_tokens'] )
			? (int) $usage['prompt_tokens']
			: ( isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : ( isset( $stats['input_tokens'] ) && is_numeric( $stats['input_tokens'] ) ? (int) $stats['input_tokens'] : 0 ) );

		$completion_tokens = isset( $usage['completion_tokens'] ) && is_numeric( $usage['completion_tokens'] )
			? (int) $usage['completion_tokens']
			: ( isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : ( isset( $stats['total_output_tokens'] ) && is_numeric( $stats['total_output_tokens'] ) ? (int) $stats['total_output_tokens'] : 0 ) );

		$total_tokens = isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] )
			? (int) $usage['total_tokens']
			: ( isset( $stats['total_tokens'] ) && is_numeric( $stats['total_tokens'] ) ? (int) $stats['total_tokens'] : $prompt_tokens + $completion_tokens );

		return new TokenUsage( $prompt_tokens, $completion_tokens, $total_tokens );
	}

	/**
	 * Prepares request options for text generation.
	 *
	 * LM Studio may need extra time for first-token generation when a model is
	 * loading, so use a longer default timeout than the transport default.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AiClient\Providers\Http\DTO\RequestOptions
	 */
	private function prepareRequestOptionsForTextGeneration(): RequestOptions {
		$existing_options = $this->getRequestOptions();
		$options_array    = null !== $existing_options ? $existing_options->toArray() : [];

		if ( ! isset( $options_array['timeout'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Local model warm-up can exceed the default HTTP timeout.
			$options_array['timeout'] = 180.0;
		}

		if ( ! isset( $options_array['connectTimeout'] ) ) {
			$options_array['connectTimeout'] = 10.0;
		}

		return RequestOptions::fromArray( $options_array );
	}
}
