<?php
/**
 * GitHub release updater for public repos.
 *
 * Checks the latest GitHub release and injects updates into
 * WordPress's plugin update system. No token needed for public repos.
 */
class Ccss_Updater {

	private $file;
	private $plugin;
	private $basename;
	private $active;

	public function __construct( $file ) {
		$this->file = $file;
		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
	}

	public function set_plugin_properties() {
		$this->basename = plugin_basename( $this->file );
		$this->active   = is_plugin_active( $this->basename );
	}

	/**
	 * Hook into the plugin update check.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Fetch the latest release from GitHub.
	 *
	 * @return array|false Release data or false on failure.
	 */
	private function fetch_latest_release() {
		$cache = get_transient( 'ccss_github_release' );
		if ( false !== $cache ) {
			return $cache;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/gmatta01/critical-css-wp/releases/latest',
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'critical-css-wp-updater',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) || empty( $release['zipball_url'] ) ) {
			return false;
		}

		// Cache for 3 hours (avoids hitting GitHub rate limits).
		set_transient( 'ccss_github_release', $release, 3 * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Check GitHub for a newer version and inject the update.
	 *
	 * @param object $transient The plugin update transient.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			ccss_log( 'Updater: no checked plugins, skipping' );
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( false === $release ) {
			ccss_log( 'Updater: fetch_latest_release failed' );
			return $transient;
		}

		$tag    = ltrim( $release['tag_name'], 'v' );
		$current = CCSS_VERSION;
		ccss_log( 'Updater: tag=' . $tag . ' current=' . $current );

		if ( version_compare( $tag, $current, '<=' ) ) {
			ccss_log( 'Updater: no newer version available' );
			return $transient;
		}

		// Build a direct download URL from the tag name.
		// GitHub archive URLs create consistent downloads that WordPress handles natively.
		$archive_url = 'https://github.com/gmatta01/critical-css-wp/archive/refs/tags/' . $release['tag_name'] . '.zip';

		ccss_log( 'Updater: injecting update v' . $tag . ' into transient' );
		$transient->response[ $this->basename ] = (object) array(
			'id'          => 'critical-css-wp',
			'slug'        => dirname( $this->basename ),
			'plugin'      => $this->basename,
			'new_version' => $tag,
			'url'         => $release['html_url'],
			'package'     => $archive_url,
			'icons'       => array(),
			'banners'     => array(),
			'requires'    => '6.0',
			'tested'      => '6.7',
		);

		return $transient;
	}

	/**
	 * Show plugin info in the WordPress details modal.
	 *
	 * @param object|bool $result
	 * @param string      $action
	 * @param object      $args
	 * @return object|bool
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( dirname( $this->basename ) !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( false === $release ) {
			return $result;
		}

		$tag = ltrim( $release['tag_name'], 'v' );
		$archive_url = 'https://github.com/gmatta01/critical-css-wp/archive/refs/tags/' . $release['tag_name'] . '.zip';

		return (object) array(
			'name'          => 'Critical CSS for WP',
			'slug'          => dirname( $this->basename ),
			'version'       => $tag,
			'author'        => '<a href="https://github.com/gmatta01">gmatta01</a>',
			'homepage'      => $release['html_url'],
			'requires'      => '6.0',
			'tested'        => '6.7',
			'download_link' => $archive_url,
			'sections'      => array(
				'description' => $release['body'] ?: 'Critical CSS for WordPress.',
				'changelog'   => $release['body'] ?: '',
			),
			'banners'       => array(),
		);
	}

	/**
	 * Clear the release cache (call this after your own release checks).
	 */
	public static function clear_cache() {
		delete_transient( 'ccss_github_release' );
	}
}
