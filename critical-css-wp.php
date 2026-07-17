<?php
/**
 * Plugin Name: Critical CSS for WP
 * Description: Generate and inject critical CSS for published WordPress content using a configurable API endpoint.
 * Version: 0.1.0
 * Author: OpenAI
 * License: GPL-2.0-or-later
 * Text Domain: critical-css-wp
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CCSS_PLUGIN_FILE' ) ) {
	define( 'CCSS_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'CCSS_PLUGIN_DIR' ) ) {
	define( 'CCSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'CCSS_PLUGIN_URL' ) ) {
	define( 'CCSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'CCSS_VERSION' ) ) {
	define( 'CCSS_VERSION', '0.1.0' );
}

require_once CCSS_PLUGIN_DIR . 'includes/class-api.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-admin.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-cron.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-frontend.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-compatibility.php';

/**
 * Allow WordPress HTTP API to reach the configured API host.
 * Without this, wp_remote_post blocks requests to private IPs (10.x, 100.x, 192.168.x).
 */
function ccss_allow_api_host( $allow, $host, $url ) {
	$api_url = ccss_get_option( 'api_url', 'http://100.94.29.96:3001/critical/simple' );
	$api_host = wp_parse_url( $api_url, PHP_URL_HOST );
	if ( $api_host && $host === $api_host ) {
		return true;
	}
	return $allow;
}
add_filter( 'http_request_host_is_external', 'ccss_allow_api_host', 10, 3 );

function ccss_get_settings() {
	$defaults = array(
		'api_url'         => 'http://100.94.29.96:3001/critical/simple',
		'public_base_url' => '',
		'post_types'      => array( 'post', 'page' ),
		'interval'        => 'daily',
		'rebuild_days'    => 7,
	);

	$settings = get_option( 'ccss_settings', array() );

	return wp_parse_args( $settings, $defaults );
}

function ccss_get_option( $key, $default = '' ) {
	$settings = ccss_get_settings();

	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

function ccss_get_enabled_post_types() {
	$post_types = ccss_get_option( 'post_types', array( 'post', 'page' ) );

	if ( ! is_array( $post_types ) ) {
		$post_types = array( $post_types );
	}

	$available = get_post_types( array( 'public' => true ), 'names' );
	$available = array_values( $available );

	$enabled = array();
	foreach ( $post_types as $post_type ) {
		if ( in_array( $post_type, $available, true ) ) {
			$enabled[] = $post_type;
		}
	}

	return array_unique( $enabled );
}

function ccss_is_enabled_post_type( $post_type ) {
	return in_array( $post_type, ccss_get_enabled_post_types(), true );
}

function ccss_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[ccss] ' . $message );
	}
}

function ccss_generate_for_post( $post_id, $force = false ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	if ( ! ccss_is_enabled_post_type( $post->post_type ) ) {
		return false;
	}

	if ( 'publish' !== $post->post_status ) {
		return false;
	}

	if ( ! $force ) {
		$existing_css = get_post_meta( $post_id, '_critical_css', true );
		if ( ! empty( $existing_css ) ) {
			return true;
		}
	}

	$url = get_permalink( $post_id );
	if ( empty( $url ) || is_wp_error( $url ) ) {
		ccss_log( 'Unable to resolve permalink for post ' . $post_id );
		return false;
	}

	// Rewrite URL if a public base URL is configured (e.g. Tailscale hostname).
	$public_base = ccss_get_option( 'public_base_url', '' );
	if ( ! empty( $public_base ) ) {
		$site_url = get_option( 'siteurl' );
		if ( $site_url && 0 === strpos( $url, $site_url ) ) {
			$url = $public_base . substr( $url, strlen( $site_url ) );
		}
	}

	$api = new Ccss_Api();
	$result = $api->request_css( $url );
	if ( ! $result['success'] ) {
		update_post_meta( $post_id, '_critical_css_error', $result['error'] );
		update_post_meta( $post_id, '_critical_css_generated_at', 0 );
		delete_post_meta( $post_id, '_critical_css' );
		ccss_log( 'Critical CSS generation failed for ' . $url . ': ' . $result['error'] );
		return false;
	}

	update_post_meta( $post_id, '_critical_css', $result['css'] );
	update_post_meta( $post_id, '_critical_css_generated_at', time() );
	delete_post_meta( $post_id, '_critical_css_error' );
	ccss_clear_cache_for_url( $url );

	return true;
}

function ccss_has_critical_css( $post_id ) {
	return ! empty( get_post_meta( $post_id, '_critical_css', true ) );
}

function ccss_get_css_size_kb( $post_id ) {
	$css = get_post_meta( $post_id, '_critical_css', true );
	if ( empty( $css ) ) {
		return 0;
	}

	return round( strlen( $css ) / 1024, 2 );
}

function ccss_clear_cache_for_url( $url ) {
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	if ( class_exists( 'LiteSpeed_Cache' ) && method_exists( 'LiteSpeed_Cache', 'purge' ) ) {
		LiteSpeed_Cache::purge();
	}
}

class Ccss_Plugin {
	public function __construct() {
		$this->api = new Ccss_Api();
		$this->admin = new Ccss_Admin( $this->api );
		$this->cron = new Ccss_Cron( $this->api );
		$this->frontend = new Ccss_Frontend();
		$this->compatibility = new Ccss_Compatibility();

		$this->admin->init();
		$this->cron->init();
		$this->frontend->init();
		$this->compatibility->init();
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public static function activate() {
		$defaults = array(
			'api_url'         => 'http://100.94.29.96:3001/critical/simple',
			'public_base_url' => '',
			'post_types'      => array( 'post', 'page' ),
			'interval'        => 'daily',
			'rebuild_days'    => 7,
		);
		if ( false === get_option( 'ccss_settings' ) ) {
			add_option( 'ccss_settings', $defaults );
		}

		$cron = new Ccss_Cron( new Ccss_Api() );
		$cron->schedule_event();
	}

	public static function deactivate() {
		$cron = new Ccss_Cron( new Ccss_Api() );
		$cron->clear_schedule();
	}

	public static function uninstall() {
		delete_option( 'ccss_settings' );
		delete_post_meta_by_key( '_critical_css' );
		delete_post_meta_by_key( '_critical_css_error' );
		delete_post_meta_by_key( '_critical_css_generated_at' );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'critical-css-wp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
}

function ccss_plugin() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Ccss_Plugin();
	}

	return $instance;
}

add_action( 'plugins_loaded', 'ccss_plugin' );
register_activation_hook( __FILE__, array( 'Ccss_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ccss_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Ccss_Plugin', 'uninstall' ) );
