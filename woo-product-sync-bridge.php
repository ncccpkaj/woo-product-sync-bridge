<?php
/**
 * Plugin Name:     Woo Product Sync Bridge
 * Plugin URI:      https://github.com/ncccpkaj/woo-product-sync-bridge
 * Description:     Securely transfer, replace, and update WooCommerce simple and variable products between connected stores using authenticated REST endpoints and Action Scheduler.
 * Version:         1.0.0
 * Author:          Nayeem Hasan
 * Text Domain:     woo-product-sync-bridge
 * Requires at least: 5.8
 * Requires PHP:    7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.7.0
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WPSB_VERSION', '1.0.0' );
define( 'WPSB_FILE', __FILE__ );
define( 'WPSB_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSB_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPSB_REST_NAMESPACE', 'wpsb/v1' );
define( 'WPSB_MIN_WP', '5.8' );
define( 'WPSB_MIN_PHP', '7.4' );
define( 'WPSB_MIN_WC', '5.0' );
define( 'WPSB_MAX_REST_BODY_BYTES', 100663296 ); // 96 MB.

require_once WPSB_PATH . 'includes/class-wpsb-installer.php';

register_activation_hook( __FILE__, array( 'WPSB_Installer', 'activate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( version_compare( PHP_VERSION, WPSB_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Woo Product Sync Bridge requires PHP %1$s or higher. Your server is running PHP %2$s.', 'woo-product-sync-bridge' ), WPSB_MIN_PHP, PHP_VERSION ) ) . '</p></div>';
				}
			);
			return;
		}

		global $wp_version;
		if ( version_compare( $wp_version, WPSB_MIN_WP, '<' ) ) {
			add_action(
				'admin_notices',
				function () use ( $wp_version ) {
					echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Woo Product Sync Bridge requires WordPress %1$s or higher. Your site is running WordPress %2$s.', 'woo-product-sync-bridge' ), WPSB_MIN_WP, $wp_version ) ) . '</p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Woo Product Sync Bridge requires WooCommerce to be installed and active.', 'woo-product-sync-bridge' ) . '</p></div>';
				}
			);
			return;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WPSB_MIN_WC, '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Woo Product Sync Bridge requires WooCommerce %1$s or higher. Your store is running WooCommerce %2$s.', 'woo-product-sync-bridge' ), WPSB_MIN_WC, WC_VERSION ) ) . '</p></div>';
				}
			);
			return;
		}

		$includes = array(
			'class-wpsb-utils.php',
			'class-wpsb-logger.php',
			'class-wpsb-connections.php',
			'class-wpsb-mapping.php',
			'class-wpsb-auth.php',
			'class-wpsb-http-client.php',
			'class-wpsb-settings.php',
			'class-wpsb-exporter.php',
			'class-wpsb-importer.php',
			'class-wpsb-rest-controller.php',
			'class-wpsb-admin.php',
			'class-wpsb-ajax.php',
			'class-wpsb-jobs.php',
		);

		foreach ( $includes as $include ) {
			require_once WPSB_PATH . 'includes/' . $include;
		}

		WPSB_Settings::init();
		WPSB_REST_Controller::init();
		WPSB_Admin::init();
		WPSB_Ajax::init();
		WPSB_Jobs::init();
	},
	20
);

add_filter(
	'plugin_action_links_' . WPSB_BASENAME,
	function ( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=product_sync' );
		array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'woo-product-sync-bridge' ) . '</a>' );
		return $links;
	}
);
