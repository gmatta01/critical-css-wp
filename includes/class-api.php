<?php
/**
 * API client for the Critical CSS service.
 */
class Ccss_Api {

	/**
	 * API endpoint for URL-based generation (legacy).
	 */
	public function request_css( $url ) {
		$api_url = ccss_get_option( 'api_url', 'http://100.94.29.96:3001/critical/simple' );
		if ( empty( $api_url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The API endpoint is not configured.', 'critical-css-wp' ),
			);
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( array( 'url' => $url ) ),
				'timeout'   => 60,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = $response->get_error_code();
			ccss_log( 'API request failed for ' . $url . ': [' . $code . '] ' . $message );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$message = sprintf( __( 'The API request returned HTTP %d.', 'critical-css-wp' ), $response_code );
			ccss_log( 'API request returned ' . $response_code . ' for ' . $url );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['css'] ) ) {
			ccss_log( 'API response did not contain critical CSS for ' . $url );
			return array(
				'success' => false,
				'error'   => __( 'The API response did not include CSS.', 'critical-css-wp' ),
			);
		}

		return array(
			'success' => true,
			'css'     => (string) $decoded['css'],
		);
	}

	/**
	 * Generate critical CSS from rendered page HTML + CSS content.
	 *
	 * WordPress renders the page locally and sends the raw HTML + CSS
	 * to the API. The API never needs to reach the site's domain,
	 * which eliminates local-dev (.local) and network issues entirely.
	 *
	 * Uses the /critical endpoint which supports inline processing.
	 *
	 * @param string $html Rendered page HTML.
	 * @param string $css  Full stylesheet content.
	 * @return array{success: bool, css?: string, error?: string}
	 */
	public function request_css_from_html( $html, $css ) {
		$api_url = ccss_get_inline_api_url();
		if ( empty( $api_url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The API endpoint is not configured.', 'critical-css-wp' ),
			);
		}

		if ( empty( $html ) || empty( $css ) ) {
			return array(
				'success' => false,
				'error'   => __( 'HTML and CSS content are required.', 'critical-css-wp' ),
			);
		}

		$response = wp_remote_post(
			$api_url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( array(
					'html' => $html,
					'css'  => $css,
				) ),
				'timeout'   => 60,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$code    = $response->get_error_code();
			ccss_log( 'API inline request failed: [' . $code . '] ' . $message );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$message = sprintf( __( 'The API request returned HTTP %d.', 'critical-css-wp' ), $response_code );
			ccss_log( 'API inline request returned ' . $response_code );
			return array(
				'success' => false,
				'error'   => $message,
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['css'] ) ) {
			ccss_log( 'API inline response did not contain critical CSS' );
			return array(
				'success' => false,
				'error'   => __( 'The API response did not include CSS.', 'critical-css-wp' ),
			);
		}

		return array(
			'success' => true,
			'css'     => (string) $decoded['css'],
		);
	}
}

