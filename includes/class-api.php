<?php
/**
 * API client for the Critical CSS service.
 */
class Ccss_Api {
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
}
