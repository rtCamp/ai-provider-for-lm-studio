<?php
/**
 * LM Studio Model Metadata Directory.
 *
 * @package rtcamp/ai-provider-for-lmstudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AiProviderForLMStudio\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use rtCamp\AiProviderForLMStudio\Provider\LMStudioProvider;

/**
 * Class for the LM Studio model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     models: list<array{type: string, object?: string, key: string, capabilities?: array{vision?: bool, trained_for_tool_use?: bool}}>
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
		$response = $this->getHttpTransporter()->send( $request );

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
			if ( 'llm' !== $model_entry['type'] ) {
				continue;
			}

			$model_name = $model_entry['key'];
			$metadata   = $this->buildModelMetadata( $model_name, $model_entry );
			if ( ! isset( $model_entry['key'] ) || ! is_string( $model_entry['key'] ) || '' === trim( $model_entry['key'] ) ) {
				continue;
			}

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
