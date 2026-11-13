<?php
/**
 * LM Studio Provider.
 *
 * @package rtcamp/ai-provider-for-lm-studio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use rtCamp\AIProviderForLMStudio\Metadata\LMStudioModelMetadataDirectory;
use rtCamp\AIProviderForLMStudio\Models\LMStudioDecisionModel;
use rtCamp\AIProviderForLMStudio\Models\LMStudioTextGenerationModel;

/**
 * Class for the LM Studio provider.
 *
 * @since 1.0.0
 */
class LMStudioProvider extends AbstractApiProvider {

	/**
	 * Returns a decision model, ready to answer questions.
	 *
	 * The AI Client has no decision capability, so decision models cannot be
	 * resolved through the registry the way other models are.
	 *
	 * Example:
	 *
	 *     $result = LMStudioProvider::decisionModel( 'nimble' )->decide(
	 *         $comment_text,
	 *         [ 'spam' => DecisionQuestion::noul( 'Is this comment spam?' ) ]
	 *     );
	 *
	 * @since 1.2.0
	 *
	 * @param string|null                                               $model_id     Optional. The model ID. If omitted or null,
	 *                                                                                uses the default decision model from settings.
	 * @param \WordPress\AiClient\Providers\Models\DTO\ModelConfig|null $model_config Optional. Model configuration,
	 *                                                                                for custom options. Default null.
	 * @return \rtCamp\AIProviderForLMStudio\Models\LMStudioDecisionModel The decision model.
	 */
	public static function decisionModel( ?string $model_id = null, ?ModelConfig $model_config = null ): LMStudioDecisionModel {
		$effective_model_id = null !== $model_id && '' !== trim( $model_id )
			? trim( $model_id )
			: \rtCamp\AIProviderForLMStudio\Settings\LMStudioSettings::get_selected_decision_model();

		if ( '' === $effective_model_id ) {
			$effective_model_id = \rtCamp\AIProviderForLMStudio\Settings\LMStudioSettings::get_selected_model();
		}

		if ( '' === $effective_model_id ) {
			$effective_model_id = 'default';
		}

		$model_metadata = new ModelMetadata(
			$effective_model_id,
			$effective_model_id,
			[],
			[ new SupportedOption( OptionEnum::customOptions() ) ]
		);

		$model = new LMStudioDecisionModel( $model_metadata, static::metadata() );
		if ( null !== $model_config ) {
			$model->setConfig( $model_config );
		}

		$registry = AiClient::defaultRegistry();
		if ( $registry->hasProvider( static::class ) ) {
			$registry->bindModelDependencies( $model );
		}

		return $model;
	}

	/**
	 * Lists the models on the LM Studio host that support decisions.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string> The model IDs, sorted.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the host is unreachable.
	 */
	public static function decisionModelIds(): array {
		/** @var \rtCamp\AIProviderForLMStudio\Metadata\LMStudioModelMetadataDirectory $model_metadata_directory */
		$model_metadata_directory = static::modelMetadataDirectory();

		return $model_metadata_directory->listDecisionModelIds();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string {
		return \rtCamp\AIProviderForLMStudio\Settings\LMStudioSettings::get_host();
	}

	/**
	 * Creates a model instance based on the provided metadata.
	 *
	 * @param \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model_metadata    The model metadata.
	 * @param \WordPress\AiClient\Providers\DTO\ProviderMetadata     $provider_metadata The provider metadata.
	 *
	 * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface The created model instance.
	 *
	 * @throws \WordPress\AiClient\Common\Exception\RuntimeException If the model capabilities are unsupported.
	 *
	 * @since 1.0.0
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		$capabilities = $model_metadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new LMStudioTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}

		throw new \WordPress\AiClient\Common\Exception\RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			'Unsupported model capabilities for LM Studio model: ' . $model_metadata->getId()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			'lmstudio',
			'LM Studio',
			ProviderTypeEnum::server(),
			'https://lmstudio.ai/docs/developer/core/authentication',
			RequestAuthenticationMethod::apiKey(),
			__( 'LM Studio is a self-hosted platform for managing and deploying large language models (LLMs).', 'ai-provider-for-lm-studio' ),
			AI_PROVIDER_FOR_LM_STUDIO_PLUGIN_DIR . 'assets/images/lmstudio-logo.svg'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new LMStudioProviderAvailability();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new LMStudioModelMetadataDirectory();
	}
}
