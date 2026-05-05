<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Settings {

	public static function init() {
		add_filter( 'woocommerce_get_sections_advanced', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( __CLASS__, 'settings' ), 10, 2 );
		add_action( 'woocommerce_admin_field_wpsb_connections', array( __CLASS__, 'connections_field' ) );
		add_action( 'woocommerce_admin_field_wpsb_local_info', array( __CLASS__, 'local_info_field' ) );
		add_action( 'woocommerce_admin_field_wpsb_logs', array( __CLASS__, 'logs_field' ) );
		add_action( 'woocommerce_update_options_advanced_product_sync', array( __CLASS__, 'save' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_download_log' ) );
	}

	public static function add_section( array $sections ): array {
		$sections['product_sync'] = __( 'Product Sync', 'woo-product-sync-bridge' );
		return $sections;
	}

	public static function settings( array $settings, string $section ): array {
		if ( 'product_sync' !== $section ) {
			return $settings;
		}

		$plugin_settings = WPSB_Utils::settings();

		return array(
			array(
				'title' => __( 'Product Sync Bridge', 'woo-product-sync-bridge' ),
				'type'  => 'title',
				'desc'  => __( 'Connect WooCommerce sites and transfer or update products through authenticated plugin endpoints.', 'woo-product-sync-bridge' ),
				'id'    => 'wpsb_settings_start',
			),
			array(
				'type' => 'wpsb_local_info',
				'id'   => 'wpsb_local_info',
			),
			array(
				'title'             => __( 'Batch size', 'woo-product-sync-bridge' ),
				'id'                => 'wpsb_batch_size',
				'type'              => 'number',
				'default'           => 5,
				'value'             => absint( $plugin_settings['batch_size'] ),
				'custom_attributes' => array( 'min' => 1, 'max' => 25 ),
				'desc_tip'          => __( 'Products processed per instant or scheduled batch. Use a smaller number for image-heavy products.', 'woo-product-sync-bridge' ),
			),
			array(
				'title'             => __( 'API timeout', 'woo-product-sync-bridge' ),
				'id'                => 'wpsb_timeout',
				'type'              => 'number',
				'default'           => 45,
				'value'             => absint( $plugin_settings['timeout'] ),
				'custom_attributes' => array( 'min' => 10, 'max' => 180 ),
			),
			array(
				'title'             => __( 'Retry count', 'woo-product-sync-bridge' ),
				'id'                => 'wpsb_retries',
				'type'              => 'number',
				'default'           => 3,
				'value'             => absint( $plugin_settings['retries'] ),
				'custom_attributes' => array( 'min' => 0, 'max' => 10 ),
			),
			array(
				'type' => 'wpsb_connections',
				'id'   => 'wpsb_connections',
			),
			array(
				'type' => 'wpsb_logs',
				'id'   => 'wpsb_logs',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wpsb_settings_end',
			),
		);
	}

	public static function save() {
		if ( ! WPSB_Utils::can_manage() ) {
			return;
		}

		$settings = array(
			'batch_size' => max( 1, min( 25, absint( $_POST['wpsb_batch_size'] ?? 5 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'timeout'    => max( 10, min( 180, absint( $_POST['wpsb_timeout'] ?? 45 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'retries'    => max( 0, min( 10, absint( $_POST['wpsb_retries'] ?? 3 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		update_option( 'wpsb_settings', $settings, false );

		$json = isset( $_POST['wpsb_connections_json'] ) ? wp_unslash( $_POST['wpsb_connections_json'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$connections = json_decode( $json, true );
		WPSB_Connections::save_all( is_array( $connections ) ? $connections : array() );
	}

	public static function local_info_field() {
		$secret = (string) get_option( 'wpsb_local_secret', '' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'This site connection info', 'woo-product-sync-bridge' ); ?></th>
			<td>
				<div class="wpsb-local-info">
					<p><strong><?php esc_html_e( 'Site URL:', 'woo-product-sync-bridge' ); ?></strong> <code><?php echo esc_html( home_url() ); ?></code></p>
					<p><strong><?php esc_html_e( 'REST base:', 'woo-product-sync-bridge' ); ?></strong> <code><?php echo esc_html( rest_url( WPSB_REST_NAMESPACE ) ); ?></code></p>
					<p><strong><?php esc_html_e( 'Shared secret:', 'woo-product-sync-bridge' ); ?></strong> <code id="wpsb-local-secret"><?php echo esc_html( $secret ); ?></code></p>
					<p><button type="button" class="button" id="wpsb-regenerate-secret"><?php esc_html_e( 'Regenerate shared secret', 'woo-product-sync-bridge' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Use this URL and secret when adding this site as a connection on another store.', 'woo-product-sync-bridge' ); ?></p>
				</div>
			</td>
		</tr>
		<?php
	}

	public static function connections_field() {
		$connections = WPSB_Connections::all();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Connected websites', 'woo-product-sync-bridge' ); ?></th>
			<td>
				<input type="hidden" id="wpsb_connections_json" name="wpsb_connections_json" value="<?php echo esc_attr( wp_json_encode( $connections ) ); ?>">
				<div id="wpsb-settings-connections" class="wpsb-settings-connections"></div>
				<button type="button" class="button" id="wpsb-add-connection"><?php esc_html_e( 'Add website', 'woo-product-sync-bridge' ); ?></button>
				<p class="description"><?php esc_html_e( 'Each connected website must have this plugin installed and use that site shared secret.', 'woo-product-sync-bridge' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function logs_field() {
		$download_url = wp_nonce_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=product_sync&wpsb_download_log=1' ), 'wpsb_download_log' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Transfer logs', 'woo-product-sync-bridge' ); ?></th>
			<td>
				<p>
					<button type="button" class="button" id="wpsb-clear-log"><?php esc_html_e( 'Clear logs', 'woo-product-sync-bridge' ); ?></button>
					<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download log', 'woo-product-sync-bridge' ); ?></a>
				</p>
				<textarea class="large-text code wpsb-log-viewer" rows="12" readonly><?php echo esc_textarea( WPSB_Logger::recent() ); ?></textarea>
			</td>
		</tr>
		<?php
	}

	public static function maybe_download_log() {
		if ( empty( $_GET['wpsb_download_log'] ) || ! WPSB_Utils::can_manage() ) {
			return;
		}
		check_admin_referer( 'wpsb_download_log' );
		$path = WPSB_Logger::path();
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Log file not found.', 'woo-product-sync-bridge' ) );
		}
		header( 'Content-Type: text/plain' );
		header( 'Content-Disposition: attachment; filename="woo-product-sync-bridge.log"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
