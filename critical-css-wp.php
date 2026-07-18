<?php
/**
 * Plugin Name: Critical CSS for WP
 * Description: Generate and inject critical CSS for published WordPress content using a configurable API endpoint.
 * Version: 0.2.8
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
	define( 'CCSS_VERSION', '0.2.8' );
}

require_once CCSS_PLUGIN_DIR . 'includes/class-api.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-admin.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-cron.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-frontend.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-compatibility.php';
require_once CCSS_PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Allow WordPress HTTP API to reach the configured API host.
 * Without this, wp_remote_post blocks requests to private IPs (10.x, 100.x, 192.168.x).
 */
function ccss_allow_api_host( $allow, $host, $url ) {
	$api_urls = array(
		ccss_get_option( 'api_url', '' ),
		ccss_get_inline_api_url(),
	);
	foreach ( $api_urls as $api_url ) {
		$api_host = wp_parse_url( $api_url, PHP_URL_HOST );
		if ( $api_host && $host === $api_host ) {
			return true;
		}
	}
	return $allow;
}
add_filter( 'http_request_host_is_external', 'ccss_allow_api_host', 10, 3 );

function ccss_get_settings() {
	$defaults = array(
		'api_url'         => '',
		'api_key'         => '',
		'public_base_url' => '',
		'post_types'      => array( 'post', 'page' ),
		'interval'        => 'daily',
		'rebuild_days'    => 7,
	);

	$settings = get_option( 'ccss_settings', array() );

	return wp_parse_args( $settings, $defaults );
}

/**
 * Get the inline API endpoint (for HTML+CSS processing).
 *
 * Uses the same URL as the main API endpoint.
 *
 * @return string Inline API endpoint URL.
 */
function ccss_get_inline_api_url() {
	// Use the same API URL for both URL-based and inline modes.
	// The API handles both payload shapes at the same endpoint.
	return ccss_get_option( 'api_url', '' );
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

/**
 * Make a relative URL absolute against a base URL.
 *
 * @param string $rel  Relative URL.
 * @param string $base Base URL.
 * @return string Absolute URL.
 */
function ccss_make_url_absolute( $rel, $base ) {
	// Already absolute.
	if ( 0 === strpos( $rel, 'http://' ) || 0 === strpos( $rel, 'https://' ) || 0 === strpos( $rel, '//' ) ) {
		if ( 0 === strpos( $rel, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
			return $scheme . ':' . $rel;
		}
		return $rel;
	}

	// Protocol-relative.
	if ( 0 === strpos( $rel, '//' ) ) {
		$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
		return $scheme . ':' . $rel;
	}

	$parts = wp_parse_url( $base );
	$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
	$host     = isset( $parts['host'] ) ? $parts['host'] : '';
	$port     = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

	// Absolute path.
	if ( '/' === $rel[0] ) {
		return $scheme . '://' . $host . $port . $rel;
	}

	// Relative path.
	$path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '';
	if ( '/' !== substr( $path, -1 ) ) {
		$path .= '/';
	}
	return $scheme . '://' . $host . $port . $path . $rel;
}

/**
 * Capture a post's rendered HTML and full CSS for inline processing.
 *
 * Used as fallback when the API cannot reach the site directly (local dev).
 * Fetches the rendered page via loopback, extracts all stylesheet URLs
 * from the HTML, downloads their content, and returns both the HTML and
 * combined CSS. The API processes these without needing to reach the domain.
 *
 * @param int $post_id Post ID.
 * @return array{html: string, css: string}|false HTML & CSS on success.
 */
function ccss_capture_page_html_and_css( $post_id ) {
	$url = get_permalink( $post_id );
	if ( empty( $url ) || is_wp_error( $url ) ) {
		ccss_log( 'Unable to resolve permalink for post ' . $post_id );
		return false;
	}

	/**
	 * Allow overriding the page HTML before fetching.
	 * Useful for sites that can't loopback properly.
	 *
	 * @param string|null $html    Null to fetch, or pre-rendered HTML.
	 * @param int         $post_id Current post ID.
	 */
	$html = apply_filters( 'ccss_pre_render_html', null, $post_id );
	if ( null === $html ) {
		// Fetch the rendered page.
		$response = wp_remote_get( $url, array(
			'timeout'   => 30,
			'sslverify' => false,
		) );

		if ( is_wp_error( $response ) ) {
			ccss_log( 'Failed to fetch page HTML for ' . $url . ': ' . $response->get_error_message() );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			ccss_log( 'Failed to fetch page HTML for ' . $url . ': HTTP ' . $response_code );
			return false;
		}

		$html = wp_remote_retrieve_body( $response );
	}

	if ( empty( $html ) ) {
		ccss_log( 'Empty page HTML for ' . $url );
		return false;
	}

	// Strip any existing inline critical CSS so regeneration doesn't compound.
	$html = preg_replace( '/<style[^>]*id=["\']critical-css-inline["\'][^>]*>.*?<\/style>/is', '', $html );

	// Collect CSS: extract stylesheet links + inline styles.
	$css = '';

	// External stylesheets.
	preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $link_matches );
	$css_urls = array_unique( $link_matches[1] );

	ccss_log( 'Capture: found ' . count( $css_urls ) . ' stylesheets on ' . $url );

	$downloaded = 0;
	$total_css  = 0;

	foreach ( $css_urls as $css_url ) {

		$css_url = ccss_make_url_absolute( $css_url, $url );

		$css_response = wp_remote_get( $css_url, array(
			'timeout'   => 15,
			'sslverify' => false,
		) );

		if ( ! is_wp_error( $css_response ) && 200 === wp_remote_retrieve_response_code( $css_response ) ) {
			$sheet_css = wp_remote_retrieve_body( $css_response );
			$css .= $sheet_css . "\n";
			$total_css += strlen( $sheet_css );
			$downloaded++;
		}
	}

	ccss_log( 'Capture: downloaded ' . $downloaded . ' stylesheets, total CSS: ' . round( $total_css / 1024, 1 ) . ' KB' );

	// Inline styles in <style> tags.
	preg_match_all( '/<style[^>]*>([^<]+)<\/style>/i', $html, $inline_matches );
	if ( ! empty( $inline_matches[1] ) ) {
		foreach ( $inline_matches[1] as $inline_css ) {
			$css .= trim( $inline_css ) . "\n";
		}
		ccss_log( 'Capture: added ' . count( $inline_matches[1] ) . ' inline style blocks' );
	}

	if ( empty( $css ) ) {
		ccss_log( 'No CSS found in page HTML for ' . $url );
		return false;
	}

	return array(
		'html' => $html,
		'css'  => $css,
	);
}

/**
 * Generate critical CSS for a post.
 *
 * Primary method: sends the page URL to the API for Chromium rendering.
 * Works for any publicly accessible domain.
 *
 * Falls back to inline HTML+CSS capture for local dev environments
 * (.local domains, Tailscale, firewalled networks) where the API
 * cannot reach the site directly.
 *
 * @param int  $post_id Post ID.
 * @param bool $force   Whether to regenerate even if CSS exists.
 * @return bool Success.
 */
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

	$api  = new Ccss_Api();
	$used_url = false;

	// Primary method: send the page URL to the API (fast, works for public domains).
	$api_url_to_send = $url;
	$public_base = ccss_get_option( 'public_base_url', '' );
	if ( ! empty( $public_base ) ) {
		$site_url = get_option( 'siteurl' );
		if ( $site_url && 0 === strpos( $url, $site_url ) ) {
			$api_url_to_send = $public_base . substr( $url, strlen( $site_url ) );
			ccss_log( 'URL rewritten via public_base_url: ' . $url . ' → ' . $api_url_to_send );
		}
	}

	ccss_log( 'Attempting URL-based generation for ' . $api_url_to_send );
	$result = $api->request_css( $api_url_to_send );
	if ( $result['success'] ) {
		$used_url = true;
		ccss_log( 'Generated critical CSS via URL method for ' . $url );
	}

	// Fallback: render locally and send HTML+CSS (for local dev, .local domains, etc.).
	if ( ! $used_url ) {
		ccss_log( 'URL method failed for ' . $url . ': ' . $result['error'] . ' — trying inline capture' );

		$page_data = ccss_capture_page_html_and_css( $post_id );
		if ( false !== $page_data ) {
			ccss_log( 'Attempting inline generation for ' . $url . ' (' . round( strlen( $page_data['html'] ) / 1024, 1 ) . ' KB HTML, ' . round( strlen( $page_data['css'] ) / 1024, 1 ) . ' KB CSS)' );
			$result = $api->request_css_chunked( $page_data['html'], $page_data['css'], $api_url_to_send );
			if ( $result['success'] ) {
				$used_url = true;
				ccss_log( 'Generated critical CSS via inline method for ' . $url );
			} else {
				ccss_log( 'Inline method also failed for ' . $url . ': ' . $result['error'] );
			}
		} else {
			ccss_log( 'Page capture failed for ' . $url . ', giving up' );
		}
	}

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
	/** @var Ccss_Api */
	private $api;
	/** @var Ccss_Admin */
	private $admin;
	/** @var Ccss_Cron */
	private $cron;
	/** @var Ccss_Frontend */
	private $frontend;
	/** @var Ccss_Compatibility */
	private $compatibility;
	/** @var Ccss_Updater */
	private $updater;

	public function __construct() {
		$this->api = new Ccss_Api();
		$this->admin = new Ccss_Admin( $this->api );
		$this->cron = new Ccss_Cron( $this->api );
		$this->frontend = new Ccss_Frontend();
		$this->compatibility = new Ccss_Compatibility();
		$this->updater = new Ccss_Updater( CCSS_PLUGIN_FILE );

		$this->admin->init();
		$this->cron->init();
		$this->frontend->init();
		$this->compatibility->init();
		$this->updater->init();
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	public static function activate() {
		$defaults = array(
			'api_url'         => '',
			'api_key'         => '',
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

/**
 * Handle manual update check button.
 */
function ccss_handle_check_updates() {
	if ( ! isset( $_GET['ccss_check_updates'] )  ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Clear the cached release data so the next check fetches fresh.
	Ccss_Updater::clear_cache();

	// Force WordPress to check for plugin updates right now.
	wp_update_plugins();

	wp_safe_redirect( add_query_arg( 'ccss_updates_done', '1', wp_get_referer() ) );
	exit;
}
add_action( 'admin_init', 'ccss_handle_check_updates' );

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
