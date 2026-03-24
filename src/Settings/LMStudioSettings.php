<?php
/**
 * LM Studio Settings.
 *
 * @package rtcamp/connector-for-lmstudio
 *
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace rtCamp\ConnectorForLMStudio\Settings;

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

	private const OPTION_GROUP = 'connector-for-lmstudio-settings';
	private const OPTION_NAME  = 'connector_for_lmstudio_settings';
	private const PAGE_SLUG    = 'connector-for-lmstudio';
	private const SECTION_ID   = 'connector_for_lmstudio_main';
	private const AJAX_ACTION  = 'connector_for_lmstudio_list_models';
	private const NONCE_ACTION = 'connector_for_lmstudio_nonce';
	private const KEY_MODEL    = 'model';

	/**
	 * Initializes the settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_settings_screen' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_script' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_list_models' ] );
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
			__( 'Host URL', 'connector-for-lmstudio' ),
			[ $this, 'render_host_field' ],
			self::PAGE_SLUG,
			self::SECTION_ID,
			[ 'label_for' => self::OPTION_NAME . '-host' ]
		);

		add_settings_field(
			self::OPTION_NAME . '_model',
			__( 'Available Models', 'connector-for-lmstudio' ),
			[ $this, 'render_available_models_field' ],
			self::PAGE_SLUG,
			self::SECTION_ID,
			[ 'label_for' => self::OPTION_NAME . '-model' ]
		);
	}

	/**
	 * Registers the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function register_settings_screen(): void {
		add_options_page(
			__( 'LM Studio Settings', 'connector-for-lmstudio' ),
			__( 'LM Studio Settings', 'connector-for-lmstudio' ),
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

		$host  = isset( $value['host'] ) ? trim( (string) $value['host'] ) : '';
		$model = isset( $value[ self::KEY_MODEL ] ) ? sanitize_text_field( (string) $value[ self::KEY_MODEL ] ) : '';
		$model = trim( $model );

		if ( '' !== $host ) {
			$host = rtrim( esc_url_raw( $host ), '/' );
		}

		return [
			'host'          => $host,
			self::KEY_MODEL => $model,
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

		<div class="wrap" style="max-width: 50rem;">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: opening anchor tag, 2: closing anchor tag */
					esc_html__( 'If your LM Studio server is configured with authentication, set the API token in %1$sSettings > Connectors%2$s.', 'connector-for-lmstudio' ),
					'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">',
					'</a>'
				);
				?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
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
				esc_html__( 'Configure the base URL for the LM Studio server. Leave this empty to use the default (%1$shttp://localhost:1234%2$s).', 'connector-for-lmstudio' ),
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
			<select
				id="<?php echo esc_attr( self::OPTION_NAME . '-model' ); ?>"
				name="<?php echo esc_attr( self::OPTION_NAME . '[' . self::KEY_MODEL . ']' ); ?>"
				class="regular-text"
			>
				<option value="">
					<?php echo esc_html__( 'Use model selected by AI Client', 'connector-for-lmstudio' ); ?>
				</option>
				<?php if ( '' !== $current_model ) : ?>
					<option value="<?php echo esc_attr( $current_model ); ?>" selected="selected">
						<?php echo esc_html( $current_model ); ?>
					</option>
				<?php endif; ?>
			</select>
			<span id="lmstudio-model-status"></span>
		</div>
		<p class="description">
			<?php
			echo esc_html__( 'Choose a default LM Studio model. If left empty, the model requested by AI Client is used.', 'connector-for-lmstudio' );
			?>
		</p>
		<hr/>
		<p class="description" style="font-style: italic;">
			<?php
			echo esc_html__( 'To access your LM Studio server remotely, you may utilize LM Link or employ free tunneling services such as ngrok or localtunnel. Regardless of the method chosen, it is essential to implement API key authentication to secure your endpoints', 'connector-for-lmstudio' );
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

		wp_enqueue_script(
			'connector-for-lmstudio-settings',
			plugins_url( 'assets/settings-models.js', CONNECTOR_FOR_LMSTUDIO_PLUGIN_FILE ),
			[],
			'1.0.0',
			true
		);

		wp_localize_script(
			'connector-for-lmstudio-settings',
			'ConnectorForLMStudioSettings',
			[
				'ajaxUrl'       => esc_url( admin_url( 'admin-ajax.php' ) . '?action=' . self::AJAX_ACTION . '&_wpnonce=' . wp_create_nonce( self::NONCE_ACTION ) ),
				'selectedModel' => self::get_selected_model(),
			]
		);
	}

	/**
	 * Handles the AJAX request to list available LM Studio models.
	 *
	 * @since 1.0.0
	 */
	public function ajax_list_models(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'connector-for-lmstudio' ), 403 );
		}

		$provider_id = 'lmstudio';
		$registry    = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( $provider_id ) ) {
			wp_send_json_error( __( 'AI provider not found.', 'connector-for-lmstudio' ), 404 );
		}

		$provider_classname = $registry->getProviderClassName( $provider_id );

		try {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$provider_availability = $provider_classname::availability();
			if ( ! $provider_availability->isConfigured() ) {
				wp_send_json_error( __( 'AI provider not configured - missing API credentials.', 'connector-for-lmstudio' ), 400 );
			}

			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$model_metadata_directory = $provider_classname::modelMetadataDirectory();
			$model_metadata_objects   = $model_metadata_directory->listModelMetadata();

			wp_send_json_success( $model_metadata_objects );
		} catch ( \Throwable $e ) {
			/* translators: %s: Error message. */
			wp_send_json_error( sprintf( __( 'Could not list models for provider. Error: %s', 'connector-for-lmstudio' ), $e->getMessage() ), 500 );
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
			'host'          => '',
			self::KEY_MODEL => '',
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
}
