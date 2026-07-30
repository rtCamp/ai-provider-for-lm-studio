<?php
/**
 * The main plugin class.
 *
 * @since 1.0.0
 * @package rtcamp/ai-provider-for-lm-studio
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use rtCamp\AIProviderForLMStudio\Provider\LMStudioProvider;
use rtCamp\AIProviderForLMStudio\Settings\LMStudioSettings;

/**
 * Plugin class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register_provider' ], 5 );
		add_action( 'init', [ $this, 'register_fallback_auth' ], 15 );
		add_action( 'init', [ $this, 'initialize_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );
		add_filter( 'http_request_host_is_external', [ $this, 'allow_localhost_requests' ], 10, 3 );
		add_filter( 'http_allowed_safe_ports', [ $this, 'allow_lmstudio_ports' ] );
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Scoped to the configured LM Studio host to support local model inference.
		add_filter( 'http_request_args', [ $this, 'extend_lmstudio_timeout' ], 10, 2 );
	}

	/**
	 * Gets the LM Studio host.
	 *
	 * @since 1.0.0
	 *
	 * @return string The LM Studio host.
	 */
	private function get_lmstudio_host(): string {
		$host = getenv( 'LMSTUDIO_HOST' );
		if ( false !== $host && '' !== $host ) {
			return $host;
		}

		$settings = LMStudioSettings::get_settings();
		if ( isset( $settings['host'] ) && '' !== $settings['host'] ) {
			return $settings['host'];
		}

		return 'http://localhost:1234';
	}

	/**
	 * Registers the LM Studio provider with the AI Client.
	 *
	 * @since 1.0.0
	 */
	public function register_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		if ( $registry->hasProvider( LMStudioProvider::class ) ) {
			return;
		}

		$registry->registerProvider( LMStudioProvider::class );
	}

	/**
	 * Registers fallback authentication for LM Studio.
	 *
	 * Local LM Studio does not require authentication by default, so this sets an
	 * empty API key only when no credentials were already configured.
	 *
	 * @since 1.0.0
	 */
	public function register_fallback_auth(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( 'lmstudio' ) ) {
			return;
		}

		$auth = $registry->getProviderRequestAuthentication( 'lmstudio' );
		if ( null !== $auth ) {
			return;
		}

		$registry->setProviderRequestAuthentication(
			'lmstudio',
			new ApiKeyRequestAuthentication( '' )
		);
	}

	/**
	 * Initializes the LM Studio settings.
	 *
	 * @since 1.0.0
	 */
	public function initialize_settings(): void {
		$settings = new LMStudioSettings();
		$settings->init();
	}

	/**
	 * Adds action links to the plugin list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=ai-provider-for-lm-studio' ),
			esc_html__( 'Settings', 'ai-provider-for-lm-studio' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Allows localhost requests to the configured LM Studio host.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $external Whether the request is external.
	 * @param string $host The host of the request.
	 * @param string $url The URL of the request.
	 * @return bool Whether the request is allowed.
	 */
	public function allow_localhost_requests( $external, $host, $url ): bool {
		if ( strpos( $url, $this->get_lmstudio_host() ) !== false ) {
			return true;
		}

		return $external;
	}

	/**
	 * Allows the configured LM Studio port.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int> $ports The ports.
	 * @return array<int> The allowed ports.
	 */
	public function allow_lmstudio_ports( $ports ): array {
		$lmstudio_host = $this->get_lmstudio_host();
		$lmstudio_port = wp_parse_url( $lmstudio_host, PHP_URL_PORT );

		if ( ! $lmstudio_port ) {
			return $ports;
		}

		return array_merge( $ports, [ $lmstudio_port ] );
	}

	/**
	 * Extends timeout for requests to the configured LM Studio host.
	 *
	 * Some environments cap outbound requests at around 30 seconds by default,
	 * which can be too low when LM Studio is loading or warming a model.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args HTTP request args.
	 * @param string               $url  Request URL.
	 * @return array<string, mixed> Filtered HTTP request args.
	 */
	public function extend_lmstudio_timeout( array $args, string $url ): array {
		if ( strpos( $url, $this->get_lmstudio_host() ) === false ) {
			return $args;
		}

		$existing_timeout = isset( $args['timeout'] ) && is_numeric( $args['timeout'] )
			? (float) $args['timeout']
			: 0.0;

		if ( $existing_timeout < 180.0 ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Local model warm-up can exceed the default HTTP timeout.
			$args['timeout'] = 180.0;
		}

		return $args;
	}
}
