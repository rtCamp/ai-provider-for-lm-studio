<?php
/**
 * LM Studio Provider.
 *
 * @package rtcamp/ai-provider-for-lmstudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AiProviderForLMStudio\Provider;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use rtCamp\AiProviderForLMStudio\Metadata\LMStudioModelMetadataDirectory;
use rtCamp\AiProviderForLMStudio\Models\LMStudioTextGenerationModel;

/**
 * Class for the LM Studio provider.
 *
 * @since 1.0.0
 */
class LMStudioProvider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string {
		$host = getenv( 'LMSTUDIO_HOST' );
		if ( false !== $host && '' !== $host ) {
			return rtrim( $host, '/' );
		}

		$settings = \rtCamp\AiProviderForLMStudio\Settings\LMStudioSettings::get_settings();
		if ( isset( $settings['host'] ) && '' !== $settings['host'] ) {
			return rtrim( $settings['host'], '/' );
		}

		return 'http://localhost:1234';
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
			__( 'LM Studio is a self-hosted platform for managing and deploying large language models (LLMs).', 'ai-provider-for-lmstudio' ),
			AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_DIR . 'assets/images/lmstudio-logo.svg'
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
