<?php
defined( 'ABSPATH' ) || exit;

class WPSB_Admin {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'product_list_modal' ) );
	}

	public static function enqueue( string $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_product_list = 'edit-product' === $screen->id;
		$tab             = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section         = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_settings     = 'woocommerce_page_wc-settings' === $screen->id && 'advanced' === $tab && 'product_sync' === $section;

		if ( ! $is_product_list && ! $is_settings ) {
			return;
		}

		wp_enqueue_style( 'wpsb-admin', WPSB_URL . 'assets/css/admin.css', array(), WPSB_VERSION );
		wp_enqueue_script( 'wpsb-admin', WPSB_URL . 'assets/js/admin.js', array( 'jquery' ), WPSB_VERSION, true );

		wp_localize_script(
			'wpsb-admin',
			'wpsbAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wpsb_admin' ),
				'connections'   => WPSB_Connections::public_list(),
				'hasConnection' => WPSB_Connections::has_active(),
				'batchSize'     => absint( WPSB_Utils::settings()['batch_size'] ),
				'i18n'          => array(
					'transfer'     => __( 'Transfer Product', 'woo-product-sync-bridge' ),
					'transferCount'=> __( 'Transfer Product (%d)', 'woo-product-sync-bridge' ),
					'chooseSite'   => __( 'Choose a website first.', 'woo-product-sync-bridge' ),
					'complete'     => __( 'Completed.', 'woo-product-sync-bridge' ),
				),
			)
		);
	}

	public static function row_action( array $actions, WP_Post $post ): array {
		if ( 'product' !== $post->post_type || ! WPSB_Connections::has_active() || ! WPSB_Utils::can_manage() ) {
			return $actions;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $actions;
		}

		$actions['wpsb_update_remote'] = sprintf(
			'<a href="#" class="wpsb-update-row" data-product-id="%d" data-title="%s" data-sku="%s">%s</a>',
			absint( $post->ID ),
			esc_attr( $product->get_name() ),
			esc_attr( $product->get_sku() ),
			esc_html__( 'Update on another site', 'woo-product-sync-bridge' )
		);

		return $actions;
	}

	public static function product_list_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-product' !== $screen->id || ! WPSB_Connections::has_active() ) {
			return;
		}
		?>
		<div id="wpsb-modal" class="wpsb-modal" aria-hidden="true">
			<div class="wpsb-modal__panel">
				<button type="button" class="wpsb-modal__close" aria-label="<?php esc_attr_e( 'Close', 'woo-product-sync-bridge' ); ?>">&times;</button>
				<div id="wpsb-transfer-view">
					<h2><?php esc_html_e( 'Transfer Products', 'woo-product-sync-bridge' ); ?></h2>
					<label><?php esc_html_e( 'Destination website', 'woo-product-sync-bridge' ); ?></label>
					<select id="wpsb-transfer-site"></select>
					<label><?php esc_html_e( 'Method', 'woo-product-sync-bridge' ); ?></label>
					<div class="wpsb-choice-row">
						<label><input type="radio" name="wpsb_transfer_method" value="instant" checked> <?php esc_html_e( 'Instant Transfer', 'woo-product-sync-bridge' ); ?></label>
						<label><input type="radio" name="wpsb_transfer_method" value="scheduled"> <?php esc_html_e( 'Scheduled Transfer', 'woo-product-sync-bridge' ); ?></label>
					</div>
					<button type="button" class="button button-primary" id="wpsb-start-transfer"><?php esc_html_e( 'Start Transfer', 'woo-product-sync-bridge' ); ?></button>
				</div>
				<div id="wpsb-update-view" hidden>
					<h2><?php esc_html_e( 'Update Product On Another Site', 'woo-product-sync-bridge' ); ?></h2>
					<p><strong id="wpsb-update-title"></strong><br><code id="wpsb-update-sku"></code></p>
					<label><?php esc_html_e( 'Website', 'woo-product-sync-bridge' ); ?></label>
					<select id="wpsb-update-site"></select>
					<div class="wpsb-choice-row">
						<button type="button" class="button" id="wpsb-search-sku"><?php esc_html_e( 'Search by SKU', 'woo-product-sync-bridge' ); ?></button>
						<button type="button" class="button" id="wpsb-search-title"><?php esc_html_e( 'Search by Title', 'woo-product-sync-bridge' ); ?></button>
					</div>
					<div id="wpsb-search-results" class="wpsb-results"></div>
					<label><?php esc_html_e( 'Update part', 'woo-product-sync-bridge' ); ?></label>
					<select id="wpsb-update-part">
						<option value="full"><?php esc_html_e( 'Full product', 'woo-product-sync-bridge' ); ?></option>
						<option value="images"><?php esc_html_e( 'Images', 'woo-product-sync-bridge' ); ?></option>
						<option value="price"><?php esc_html_e( 'Price', 'woo-product-sync-bridge' ); ?></option>
						<option value="descriptions"><?php esc_html_e( 'Description + short description', 'woo-product-sync-bridge' ); ?></option>
						<option value="meta"><?php esc_html_e( 'Other meta', 'woo-product-sync-bridge' ); ?></option>
					</select>
					<div class="wpsb-choice-row">
						<button type="button" class="button button-primary" id="wpsb-update-instant"><?php esc_html_e( 'Update Instantly', 'woo-product-sync-bridge' ); ?></button>
						<button type="button" class="button" id="wpsb-update-schedule"><?php esc_html_e( 'Schedule Update', 'woo-product-sync-bridge' ); ?></button>
					</div>
				</div>
				<div class="wpsb-progress">
					<div class="wpsb-progress__bar"><span id="wpsb-progress-bar"></span></div>
					<strong id="wpsb-progress-text">0%</strong>
				</div>
				<div id="wpsb-conflicts" class="wpsb-conflicts" hidden>
					<h3><?php esc_html_e( 'SKU conflicts', 'woo-product-sync-bridge' ); ?></h3>
					<div id="wpsb-conflict-list"></div>
					<button type="button" class="button button-primary" id="wpsb-replace-selected" disabled><?php esc_html_e( 'Replace Selected', 'woo-product-sync-bridge' ); ?></button>
				</div>
				<label><?php esc_html_e( 'Live log', 'woo-product-sync-bridge' ); ?></label>
				<pre id="wpsb-live-log" class="wpsb-live-log"></pre>
				<div id="wpsb-unsupported" class="wpsb-unsupported" hidden>
					<h3><?php esc_html_e( 'Not transferred', 'woo-product-sync-bridge' ); ?></h3>
					<p><?php esc_html_e( 'These products are not supported by this version.', 'woo-product-sync-bridge' ); ?></p>
					<div id="wpsb-unsupported-list"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
