<?php
/**
 * LM Studio Provider Availability.
 *
 * @package rtcamp/ai-provider-for-lm-studio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Availability check for the LM Studio provider.
 *
 * @since 1.0.0
 */
class LMStudioProviderAvailability implements ProviderAvailabilityInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public function isConfigured(): bool {
		return true;
	}
}
