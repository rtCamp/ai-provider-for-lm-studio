<?php
/**
 * LM Studio Settings.
 *
 * @package rtcamp/ai-provider-for-lm-studio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\AIProviderForLMStudio\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;

/**
 * Class for LM Studio settings in the WordPress admin.
 *
 * @since 1.0.0
 */
class LMStudioSettings {

	private const OPTION_GROUP  = 'ai-provider-for-lm-studio-settings';
	private const OPTION_NAME   = 'connector_for_lmstudio_settings';
	private const PAGE_SLUG     = 'ai-provider-for-lm-studio';
	private const SECTION_ID    = 'connector_for_lmstudio_main';
	private const KEY_MODEL     = 'model';
	private const KEY_REASONING = 'reasoning';

	/**
	 * Initializes the settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_settings_screen' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_script' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Registers the setting and fields.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		add_settings_section(
			self::SECTION_ID,
			'',
			'__return_empty_string',
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_NAME . '_host',
			__( 'Host URL', 'ai-provider-for-lm-studio' ),
			[ $this, 'render_host_field' ],
			self::PAGE_SLUG,
			self::SECTION_ID,
			[ 'label_for' => self::OPTION_NAME . '-host' ]
		);

		add_settings_field(
			self::OPTION_NAME . '_model',
			__( 'Available Models', 'ai-provider-for-lm-studio' ),
			[ $this, 'render_available_models_field' ],
			self::PAGE_SLUG,
			self::SECTION_ID,
			[ 'label_for' => self::OPTION_NAME . '-model' ]
		);

		add_settings_field(
			self::OPTION_NAME . '_reasoning',
			__( 'Reasoning', 'ai-provider-for-lm-studio' ),
			[ $this, 'render_reasoning_field' ],
			self::PAGE_SLUG,
			self::SECTION_ID
		);
	}

	/**
	 * Registers the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function register_settings_screen(): void {
		add_options_page(
			__( 'LM Studio Settings', 'ai-provider-for-lm-studio' ),
			__( 'LM Studio Settings', 'ai-provider-for-lm-studio' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_screen' ]
		);
	}

	/**
	 * Sanitizes the settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The input value.
	 * @return array<string, string> The sanitized settings.
	 */
	public function sanitize_settings( $value ): array {
		if ( ! is_array( $value ) ) {
			return self::get_default_settings();
		}

		$host      = isset( $value['host'] ) ? trim( (string) $value['host'] ) : '';
		$model     = isset( $value[ self::KEY_MODEL ] ) ? sanitize_text_field( (string) $value[ self::KEY_MODEL ] ) : '';
		$model     = trim( $model );
		$reasoning = isset( $value[ self::KEY_REASONING ] ) ? sanitize_text_field( (string) $value[ self::KEY_REASONING ] ) : '';
		$reasoning = trim( $reasoning );

		if ( '' !== $host ) {
			$host = rtrim( esc_url_raw( $host ), '/' );
		}

		return [
			'host'              => $host,
			self::KEY_MODEL     => $model,
			self::KEY_REASONING => $reasoning,
		];
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function render_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>

		<div class="wrap lmstudio-settings-wrap">
			<div class="lmstudio-settings-card">
				<div class="lmstudio-settings-header">
					<div class="lmstudio-header-icon">
						<img src="<?php echo esc_url( AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_URL . 'assets/images/header-logo.svg' ); ?>" alt="" class="lmstudio-header-logo-img" />
					</div>
					<div class="lmstudio-header-content">
						<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
						<p class="lmstudio-header-subtitle">
							<?php
							echo esc_html__( 'Connect and configure your local LM Studio instance for offline AI capabilities in WordPress.', 'ai-provider-for-lm-studio' );
							?>
						</p>
					</div>
				</div>

				<div class="lmstudio-settings-body">
					<div class="lmstudio-connector-info">
						<p>
							<?php
							printf(
								/* translators: 1: opening anchor tag, 2: closing anchor tag */
								esc_html__( 'If your LM Studio server is configured with authentication, set the API token in %1$sSettings > Connectors%2$s.', 'ai-provider-for-lm-studio' ),
								'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '" class="lmstudio-link">',
								'</a>'
							);
							?>
						</p>
					</div>

					<form action="options.php" method="post" class="lmstudio-settings-form">
						<?php
						settings_fields( self::OPTION_GROUP );
						do_settings_sections( self::PAGE_SLUG );
						?>

						<div class="lmstudio-alert lmstudio-alert-info">
							<div class="lmstudio-alert-icon">
								<img src="<?php echo esc_url( AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_URL . 'assets/images/info.svg' ); ?>" alt="" class="lmstudio-alert-icon-img" />
							</div>
							<div class="lmstudio-alert-content">
								<p class="lmstudio-alert-description">
									<?php
									echo esc_html__( 'To access your LM Studio server remotely, you may utilize LM Link or employ free tunneling services such as ngrok or localtunnel. Regardless of the method chosen, it is essential to implement API key authentication to secure your endpoints.', 'ai-provider-for-lm-studio' );
									?>
								</p>
							</div>
						</div>

						<div class="lmstudio-form-actions">
							<?php submit_button(); ?>
						</div>
					</form>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Renders the host URL field.
	 *
	 * @since 1.0.0
	 */
	public function render_host_field(): void {
		$settings = self::get_settings();
		$value    = isset( $settings['host'] ) ? $settings['host'] : '';
		?>

		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_NAME . '-host' ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[host]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="http://localhost:1234"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: 1: code opening tag, 2: code closing tag */
				esc_html__( 'Configure the base URL for the LM Studio server. Leave this empty to use the default (%1$shttp://localhost:1234%2$s).', 'ai-provider-for-lm-studio' ),
				'<code>',
				'</code>'
			);
			?>
		</p>

		<?php
	}

	/**
	 * Renders the available models list.
	 *
	 * @since 1.0.0
	 */
	public function render_available_models_field(): void {
		$settings      = self::get_settings();
		$current_model = isset( $settings[ self::KEY_MODEL ] ) ? (string) $settings[ self::KEY_MODEL ] : '';
		?>

		<div id="lmstudio-models-container">
			<div class="lmstudio-models-row">
				<select
					id="<?php echo esc_attr( self::OPTION_NAME . '-model' ); ?>"
					name="<?php echo esc_attr( self::OPTION_NAME . '[' . self::KEY_MODEL . ']' ); ?>"
					class="regular-text"
				>
					<option value="">
						<?php echo esc_html__( 'Use model selected by AI Client', 'ai-provider-for-lm-studio' ); ?>
					</option>
					<?php if ( '' !== $current_model ) : ?>
						<option value="<?php echo esc_attr( $current_model ); ?>" selected="selected">
							<?php echo esc_html( $current_model ); ?>
						</option>
					<?php endif; ?>
				</select>
				<span id="lmstudio-model-status"></span>
			</div>
		</div>
		<p class="description">
			<?php
			echo esc_html__( 'Choose a default LM Studio model. If left empty, the model requested by AI Client is used.', 'ai-provider-for-lm-studio' );
			?>
		</p>
		<?php
	}

	/**
	 * Enqueues the settings page script.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_settings_script( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_dir = AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_DIR;
		$asset_file = $plugin_dir . 'build/admin/settings.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : []; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Asset file path is built from a known constant.

		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : [];
		$version      = isset( $asset['version'] ) ? $asset['version'] : false;

		wp_enqueue_script(
			'ai-provider-for-lm-studio-settings',
			AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_URL . 'build/admin/settings.js',
			$dependencies,
			$version,
			true
		);

		wp_enqueue_style(
			'ai-provider-for-lm-studio-settings',
			AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_URL . 'build/admin/style-settings.css',
			[],
			$version
		);
		wp_style_add_data( 'ai-provider-for-lm-studio-settings', 'rtl', 'replace' );

		$cache_key = 'connector_for_lmstudio_svgs_' . ( is_string( $version ) ? $version : 'default' );
		$svgs      = wp_cache_get( $cache_key, 'ai-provider-for-lm-studio' );

		if ( false === $svgs ) {
			$svgs      = [];
			$svg_files = [
				'vision'    => 'assets/images/vision.svg',
				'tools'     => 'assets/images/tools.svg',
				'reasoning' => 'assets/images/reasoning.svg',
				'error'     => 'assets/images/error.svg',
				'success'   => 'assets/images/success.svg',
			];

			foreach ( $svg_files as $key => $rel_path ) {
				$full_path = $plugin_dir . $rel_path;
				if ( ! file_exists( $full_path ) ) {
					continue;
				}
				$svgs[ $key ] = AI_PROVIDER_FOR_LMSTUDIO_PLUGIN_URL . $rel_path;
			}
			wp_cache_set( $cache_key, $svgs, 'ai-provider-for-lm-studio' );
		}

		wp_localize_script(
			'ai-provider-for-lm-studio-settings',
			'AIProviderForLMStudioSettings',
			[
				'selectedModel'     => self::get_selected_model(),
				'selectedReasoning' => self::get_selected_reasoning(),
				'svgs'              => $svgs,
			]
		);
	}

	/**
	 * Registers the REST API routes.
	 *
	 * @since 1.1.0
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'ai-provider-for-lm-studio/v1',
			'/models',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_models_endpoint' ],
				'permission_callback' => [ $this, 'check_rest_permissions' ],
			]
		);

		register_rest_route(
			'ai-provider-for-lm-studio/v1',
			'/capabilities',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_capabilities_endpoint' ],
				'permission_callback' => [ $this, 'check_rest_permissions' ],
			]
		);
	}

	/**
	 * Checks permissions for the REST API endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the user has permission, false otherwise.
	 */
	public function check_rest_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST API endpoint to retrieve available models.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_models_endpoint(): \WP_REST_Response {
		$provider_id = 'lmstudio';
		$registry    = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( $provider_id ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'AI provider not found.', 'ai-provider-for-lm-studio' ) ], 404 );
		}

		$provider_classname = $registry->getProviderClassName( $provider_id );

		try {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$provider_availability = $provider_classname::availability();
			if ( ! $provider_availability->isConfigured() ) {
				return new \WP_REST_Response( [ 'message' => __( 'AI provider not configured - missing API credentials.', 'ai-provider-for-lm-studio' ) ], 400 );
			}

			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$model_metadata_directory = $provider_classname::modelMetadataDirectory();
			$model_metadata_objects   = $model_metadata_directory->listModelMetadata();

			return new \WP_REST_Response( $model_metadata_objects, 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response(
				[
					// translators: %s: Error message.
					'message' => sprintf( __( 'Could not list models for provider. Error: %s', 'ai-provider-for-lm-studio' ), $e->getMessage() ),
				],
				500
			);
		}
	}

	/**
	 * Gets settings from the WordPress option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> The settings.
	 */
	public static function get_settings(): array {
		$settings = (array) get_option( self::OPTION_NAME, [] );

		return array_merge( self::get_default_settings(), $settings );
	}

	/**
	 * Gets default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Default settings.
	 */
	private static function get_default_settings(): array {
		return [
			'host'              => '',
			self::KEY_MODEL     => '',
			self::KEY_REASONING => '',
		];
	}

	/**
	 * Gets selected default model.
	 *
	 * @since 1.0.0
	 *
	 * @return string Selected model ID, or empty string when unset.
	 */
	public static function get_selected_model(): string {
		$settings = self::get_settings();

		if ( ! isset( $settings[ self::KEY_MODEL ] ) ) {
			return '';
		}

		return trim( (string) $settings[ self::KEY_MODEL ] );
	}

	/**
	 * Renders the reasoning field.
	 *
	 * Shows a hidden input (persisted via form submit) plus a JS-populated
	 * fieldset of radio buttons that appears only when the selected model
	 * reports reasoning capabilities.
	 *
	 * @since 1.0.0
	 */
	public function render_reasoning_field(): void {
		$settings          = self::get_settings();
		$current_reasoning = isset( $settings[ self::KEY_REASONING ] ) ? (string) $settings[ self::KEY_REASONING ] : '';
		?>
		<div id="lmstudio-reasoning-container" class="lmstudio-reasoning-container">
			<fieldset id="lmstudio-reasoning-fieldset" class="lmstudio-reasoning-fieldset">
				<legend class="screen-reader-text">
					<?php esc_html_e( 'Reasoning', 'ai-provider-for-lm-studio' ); ?>
				</legend>
				<!-- Radio buttons injected by assets/admin/settings/index.ts -->
			</fieldset>
			<p class="description">
				<?php esc_html_e( 'Control reasoning mode for the selected model. Options depend on the model\'s capabilities.', 'ai-provider-for-lm-studio' ); ?>
			</p>
		</div>
		<input
			type="hidden"
			id="<?php echo esc_attr( self::OPTION_NAME . '-reasoning' ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[' . self::KEY_REASONING . ']' ); ?>"
			value="<?php echo esc_attr( $current_reasoning ); ?>"
		/>
		<?php
	}

	/**
	 * REST API endpoint to retrieve per-model capabilities.
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_REST_Response The REST response.
	 */
	public function get_capabilities_endpoint(): \WP_REST_Response {
		$url = \rtCamp\AIProviderForLMStudio\Provider\LMStudioProvider::url( 'api/v1/models' );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_get(
			$url,
			[
				'sslverify' => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'message' => $response->get_error_message() ], 500 );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Invalid response from LM Studio.', 'ai-provider-for-lm-studio' ) ], 500 );
		}

		$capabilities_map = [];

		foreach ( $data['models'] as $model ) {
			if ( ! is_array( $model ) || ! isset( $model['key'] ) || ! isset( $model['type'] ) || 'llm' !== $model['type'] ) {
				continue;
			}

			$model_key = $model['key'];
			$reasoning = null;
			$vision    = false;
			$tool_use  = false;

			if ( isset( $model['capabilities'] ) && is_array( $model['capabilities'] ) ) {
				$raw_caps = $model['capabilities'];

				if (
					isset( $raw_caps['reasoning'] ) &&
					is_array( $raw_caps['reasoning'] ) &&
					isset( $raw_caps['reasoning']['allowed_options'] ) &&
					is_array( $raw_caps['reasoning']['allowed_options'] ) &&
					! empty( $raw_caps['reasoning']['allowed_options'] )
				) {
					$raw       = $raw_caps['reasoning'];
					$allowed   = array_values(
						array_filter(
							$raw['allowed_options'],
							'is_string'
						)
					);
					$default   = isset( $raw['default'] ) && is_string( $raw['default'] ) ? $raw['default'] : '';
					$reasoning = [
						'allowed_options' => $allowed,
						'default'         => $default,
					];
				}

				if ( isset( $raw_caps['vision'] ) ) {
					$vision = filter_var( $raw_caps['vision'], FILTER_VALIDATE_BOOLEAN );
				}

				if ( isset( $raw_caps['trained_for_tool_use'] ) ) {
					$tool_use = filter_var( $raw_caps['trained_for_tool_use'], FILTER_VALIDATE_BOOLEAN );
				}
			}

			$capabilities_map[ $model_key ] = [
				'reasoning'            => $reasoning,
				'vision'               => $vision,
				'trained_for_tool_use' => $tool_use,
			];
		}

		return new \WP_REST_Response( $capabilities_map, 200 );
	}

	/**
	 * Gets the saved reasoning setting.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reasoning value (e.g. 'on', 'off'), or empty string when unset.
	 */
	public static function get_selected_reasoning(): string {
		$settings = self::get_settings();

		if ( ! isset( $settings[ self::KEY_REASONING ] ) ) {
			return '';
		}

		return trim( (string) $settings[ self::KEY_REASONING ] );
	}
}
