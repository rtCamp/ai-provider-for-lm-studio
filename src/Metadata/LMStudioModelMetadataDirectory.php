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
 *     data: list<array{id: string, object?: string, owned_by?: string}>
 * }
 */
class LMStudioModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * Sends a request to list all LM Studio models.
	 * {@inheritDoc}
	 *
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the API response is not successful or does not contain expected data.
	 *
	 * @since 1.0.0
	 */
	protected function sendListModelsRequest(): array {
		$request  = $this->createRequest( HttpMethodEnum::GET(), '/v1/models' );
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
		if ( ! isset( $models_data['data'] ) || ! is_array( $models_data['data'] ) ) {
			throw \WordPress\AiClient\Providers\Http\Exception\ResponseException::fromMissingData( 'LM Studio', 'data' );
		}

		$models_map = [];
		foreach ( $models_data['data'] as $model_entry ) {
			if ( ! isset( $model_entry['id'] ) || ! is_string( $model_entry['id'] ) || '' === trim( $model_entry['id'] ) ) {
				continue;
			}

			$model_id                = trim( $model_entry['id'] );
			$models_map[ $model_id ] = $this->createModelMetadata( $model_id );
		}

		ksort( $models_map );

		return $models_map;
	}

	/**
	 * Creates model metadata for an LM Studio model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_id The model identifier.
	 * @return \WordPress\AiClient\Providers\Models\DTO\ModelMetadata
	 */
	private function createModelMetadata( string $model_id ): ModelMetadata {
		return new ModelMetadata(
			$model_id,
			$model_id,
			[
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			],
			[
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
				new SupportedOption( OptionEnum::inputModalities(), [ [ ModalityEnum::text() ] ] ),
			],
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
