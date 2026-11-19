<?php
/**
 * LM Studio Model Metadata Directory.
 *
 * @package rtCamp\AIProviderForLMStudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use rtCamp\AIProviderForLMStudio\Plugin;
use rtCamp\AIProviderForLMStudio\Provider\LMStudioProvider;

/**
 * Class for the LM Studio model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     models: list<array{type: string, object?: string, key: string, display_name?: string, architecture?: string, capabilities?: array<string, mixed>}>
 * }
 */
class LMStudioModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * Sends a request to list all LM Studio models.
	 *
	 * @return array<string, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata> Map of model ID to model metadata.
	 *
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the API response is not successful or does not contain expected data.
	 *
	 * @since 1.0.0
	 */
	protected function sendListModelsRequest(): array {
		$request  = $this->createRequest( HttpMethodEnum::GET(), 'api/v1/models' );
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = Plugin::with_lmstudio_http_context(
			function () use ( $request ): Response {
				return $this->getHttpTransporter()->send( $request );
			}
		);

		ResponseUtil::throwIfNotSuccessful( $response );

		/**
		 * Models response data structure:
		 * {
		 *     "data": [
		 *         {
		 *             "id": "model-id",
		 *             "object": "model",
		 *             "owned_by": "organization-id"
		 *         },
		 *     ...   *     ]
		 * }
		 *
		 * @var ModelsResponseData $models_data
		 */
		$models_data = $response->getData();
		if ( ! isset( $models_data ) || ! is_array( $models_data ) ) {
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( 'LM Studio', 'data' );
		}

		$models_map = [];

		foreach ( $models_data['models'] as $model_entry ) {
			if ( ! isset( $model_entry['key'] ) || ! is_string( $model_entry['key'] ) || '' === trim( $model_entry['key'] ) ) {
				continue;
			}

			if ( ! isset( $model_entry['type'] ) || ! is_string( $model_entry['type'] ) || 'llm' !== $model_entry['type'] ) {
				continue;
			}

			// Don't include decision models in the text generation model listing.
			if ( self::isDecisionModelEntry( $model_entry ) ) {
				continue;
			}

			$model_name = $model_entry['key'];
			$metadata   = $this->buildModelMetadata( $model_name, $model_entry );

			$models_map[ $model_name ] = $metadata;
		}

		ksort( $models_map );

		return $models_map;
	}

	/**
	 * Builds a ModelMetadata object for a single model, or returns null if the model should be skipped.
	 *
	 * Skips embedding-only models (those whose capabilities array is non-empty and lacks 'completion').
	 * Falls back to text-only generation when details are unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $model_name The model name.
	 * @param array<string, mixed>|null $details The response data from /api/show, or null on failure.
	 *
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelMetadata The model metadata.
	 */
	private function buildModelMetadata( string $model_name, ?array $details ): ModelMetadata {
		$has_vision = false;

		if ( null !== $details ) {
			$model_capabilities = isset( $details['capabilities'] ) ? $details['capabilities'] : [];

			// Check for vision support via capabilities and make sure it's set to true.
			$has_vision = isset( $model_capabilities['vision'] ) && true === $model_capabilities['vision'];
		}

		if ( $has_vision ) {
			$input_modalities_option = new SupportedOption(
				OptionEnum::inputModalities(),
				[
					[ ModalityEnum::text() ],
					[ ModalityEnum::text(), ModalityEnum::image() ],
				]
			);
		} else {
			$input_modalities_option = new SupportedOption(
				OptionEnum::inputModalities(),
				[ [ ModalityEnum::text() ] ]
			);
		}

		$options = [
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::topK() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::outputMimeType(), [ 'text/plain', 'application/json' ] ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::outputModalities(), [ [ ModalityEnum::text() ] ] ),
			$input_modalities_option,
		];

		return new ModelMetadata(
			$model_name,
			$model_name,
			[
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			],
			$options
		);
	}

	/**
	 * Lists the models that support decisions.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string> The model IDs, sorted.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the host is unreachable.
	 */
	public function listDecisionModelIds(): array {
		$request  = $this->createRequest( HttpMethodEnum::GET(), 'api/v1/models' );
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = Plugin::with_lmstudio_http_context(
			function () use ( $request ): Response {
				return $this->getHttpTransporter()->send( $request );
			}
		);

		ResponseUtil::throwIfNotSuccessful( $response );

		/** @var ModelsResponseData $models_data */
		$models_data = $response->getData();
		if ( ! isset( $models_data['models'] ) || ! is_array( $models_data['models'] ) ) {
			return [];
		}

		$decision_model_ids = [];
		foreach ( $models_data['models'] as $model_entry ) {
			if ( ! isset( $model_entry['key'] ) || ! is_string( $model_entry['key'] ) || '' === trim( $model_entry['key'] ) ) {
				continue;
			}

			if ( ! self::isDecisionModelEntry( $model_entry ) ) {
				continue;
			}

			$decision_model_ids[] = $model_entry['key'];
		}

		sort( $decision_model_ids );

		return $decision_model_ids;
	}

	/**
	 * Checks whether a model entry represents a decision-making model.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $model_entry The model entry from LM Studio.
	 * @return bool True if the model is a decision model, false otherwise.
	 */
	public static function isDecisionModelEntry( array $model_entry ): bool {
		$type         = isset( $model_entry['type'] ) && is_string( $model_entry['type'] ) ? $model_entry['type'] : '';
		$capabilities = isset( $model_entry['capabilities'] ) && is_array( $model_entry['capabilities'] ) ? $model_entry['capabilities'] : [];
		$key          = isset( $model_entry['key'] ) && is_string( $model_entry['key'] ) ? trim( $model_entry['key'] ) : '';
		$architecture = isset( $model_entry['architecture'] ) && is_string( $model_entry['architecture'] ) ? $model_entry['architecture'] : '';
		$display_name = isset( $model_entry['display_name'] ) && is_string( $model_entry['display_name'] ) ? $model_entry['display_name'] : '';

		$pattern = '/\b(decision|systemone|classifier|clef|openjev|jev|julia|laya|kev|lev|nimble|tev1|(?:vllm-)?(?:d1|d3)(?=[-_\b]|$))\b/i';

		return 'decision' === $type
			|| 'classifier' === $type
			|| ( isset( $capabilities['decision'] ) && true === $capabilities['decision'] )
			|| in_array( 'decision', $capabilities, true )
			|| 1 === preg_match( $pattern, $key )
			|| 1 === preg_match( $pattern, $architecture )
			|| 1 === preg_match( $pattern, $display_name );
	}

	/**
	 * Creates a request object for LM Studio.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method  The HTTP method.
	 * @param string                                                  $path    The API endpoint path.
	 * @param array<string, string|list<string>>                      $headers The request headers.
	 * @param string|array<string, mixed>|null                        $data    The request data.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request object.
	 */
	private function createRequest( HttpMethodEnum $method, string $path, array $headers = [], $data = null ): Request {
		return new Request(
			$method,
			LMStudioProvider::url( $path ),
			$headers,
			$data
		);
	}
}
