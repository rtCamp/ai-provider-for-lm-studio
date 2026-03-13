<?php
/**
 * LM Studio Text Generation Model.
 *
 * @package rtcamp/ai-provider-for-lmstudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AiProviderForLMStudio\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use rtCamp\AiProviderForLMStudio\Provider\LMStudioProvider;
use rtCamp\AiProviderForLMStudio\Settings\LMStudioSettings;

/**
 * Class for an LM Studio text generation model.
 *
 * Uses LM Studio's OpenAI-compatible endpoints.
 *
 * @since 1.0.0
 */
class LMStudioTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * {@inheritDoc}
	 *
	 * Applies provider-level default model override before sending.
	 *
	 * @since 1.0.0
	 *
	 * @param array $prompt Prompt messages.
	 * @phpstan-param list<\WordPress\AiClient\Messages\DTO\Message> $prompt
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$params = parent::prepareGenerateTextParams( $prompt );

		$selected_model = LMStudioSettings::get_selected_model();
		if ( '' !== $selected_model ) {
			$params['model'] = $selected_model;
		}

		$selected_reasoning = LMStudioSettings::get_selected_reasoning();
		if ( '' !== $selected_reasoning ) {
			$params['reasoning'] = $selected_reasoning;
		} else {
			// Default to "off" for LM Studio to avoid unexpected latency from on-device reasoning when users select a model that supports it without realizing.
			$params['reasoning'] = 'off';
		}

		return apply_filters( 'lm_studio_text_generation_params', $params );
	}

	/**
	 * Creates a request for text generation.
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method The HTTP method.
	 * @param string                                                  $path   The API endpoint path.
	 * @param array                                                   $headers The request headers.
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
		$path = ltrim( (string) preg_replace( '#^v1/?#', '', ltrim( $path, '/' ) ), '/' );
		$path = '/v1/' . $path;

		return new Request(
			$method,
			LMStudioProvider::url( $path ),
			$headers,
			$data,
			$this->prepareRequestOptionsForTextGeneration()
		);
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
