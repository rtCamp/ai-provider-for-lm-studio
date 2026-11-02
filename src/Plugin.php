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
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * HTTP context nesting depth.
	 *
	 * @var int
	 */
	private static int $http_context_depth = 0;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		self::$instance = $this;
	}

	/**
	 * Gets the singleton plugin instance.
	 *
	 * @since 1.2.0
	 *
	 * @return self The plugin instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Executes a callback with LM Studio HTTP filters temporarily attached.
	 *
	 * Attaches http_request_host_is_external, http_allowed_safe_ports, and http_request_args
	 * only for the duration of the callback to prevent disarming WordPress core SSRF protections
	 * for other requests.
	 *
	 * @since 1.2.0
	 *
	 * @template T
	 * @param callable(): T $callback The callback to execute.
	 * @return T The callback result.
	 */
	public static function with_lmstudio_http_context( callable $callback ) {
		$plugin = self::get_instance();

		if ( 0 === self::$http_context_depth ) {
			add_filter( 'http_request_host_is_external', [ $plugin, 'allow_localhost_requests' ], 10, 3 );
			add_filter( 'http_allowed_safe_ports', [ $plugin, 'allow_lmstudio_ports' ] );
			// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Scoped to LM Studio requests only.
			add_filter( 'http_request_args', [ $plugin, 'extend_lmstudio_timeout' ], 10, 2 );
		}
		++self::$http_context_depth;

		try {
			return $callback();
		} finally {
			--self::$http_context_depth;
			if ( 0 === self::$http_context_depth ) {
				remove_filter( 'http_request_host_is_external', [ $plugin, 'allow_localhost_requests' ], 10 );
				remove_filter( 'http_allowed_safe_ports', [ $plugin, 'allow_lmstudio_ports' ] );
				remove_filter( 'http_request_args', [ $plugin, 'extend_lmstudio_timeout' ], 10 );
			}
		}
	}

	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register_provider' ], 5 );
		add_action( 'init', [ $this, 'register_fallback_auth' ], 15 );
		add_action( 'init', [ $this, 'initialize_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_PROVIDER_FOR_LM_STUDIO_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );
	}

	/**
	 * Gets the LM Studio host.
	 *
	 * @since 1.0.0
	 *
	 * @return string The LM Studio host.
	 */
	private function get_lmstudio_host(): string {
		$host = getenv( 'AI_PROVIDER_FOR_LM_STUDIO_HOST' );
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
	 * Checks if a given URL matches the configured LM Studio host and port.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $url  The request URL.
	 * @param string|null $host Optional host name.
	 * @return bool True if the URL matches LM Studio host and port.
	 */
	private function is_lmstudio_url( string $url, ?string $host = null ): bool {
		$lmstudio_url  = $this->get_lmstudio_host();
		$lmstudio_host = wp_parse_url( $lmstudio_url, PHP_URL_HOST );
		if ( ! is_string( $lmstudio_host ) || '' === $lmstudio_host ) {
			return false;
		}

		$lmstudio_port = wp_parse_url( $lmstudio_url, PHP_URL_PORT );
		if ( null === $lmstudio_port ) {
			$lmstudio_scheme = wp_parse_url( $lmstudio_url, PHP_URL_SCHEME );
			$lmstudio_port   = 'https' === $lmstudio_scheme ? 443 : 80;
		}

		$req_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $req_host ) || '' === $req_host ) {
			$req_host = is_string( $host ) ? $host : '';
		}

		if ( '' === $req_host ) {
			return false;
		}

		$req_port = wp_parse_url( $url, PHP_URL_PORT );
		if ( null === $req_port ) {
			$req_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			$req_port   = 'https' === $req_scheme ? 443 : 80;
		}

		$is_host_match = 0 === strcasecmp( $req_host, $lmstudio_host )
			|| ( in_array( strtolower( $req_host ), [ 'localhost', '127.0.0.1' ], true )
				&& in_array( strtolower( $lmstudio_host ), [ 'localhost', '127.0.0.1' ], true ) );

		return $is_host_match && $req_port === $lmstudio_port;
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
		if ( is_string( $url ) && $this->is_lmstudio_url( $url, is_string( $host ) ? $host : null ) ) {
			return true;
		}

		return (bool) $external;
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

		if ( ! is_int( $lmstudio_port ) || 0 === $lmstudio_port ) {
			return is_array( $ports ) ? $ports : [];
		}

		$ports_array = is_array( $ports ) ? $ports : [];

		return array_merge( $ports_array, [ $lmstudio_port ] );
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
		if ( ! $this->is_lmstudio_url( $url ) ) {
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
