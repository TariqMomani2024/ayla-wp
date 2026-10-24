<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Server-side HTTP client. The browser never talks to the Ayla service and never sees the key. */
class Ayla_Client {

	const TOKEN_TRANSIENT = 'ayla_session_token';

	/**
	 * @param string      $method   GET|POST|PUT|DELETE
	 * @param string      $path     e.g. "/doctors"
	 * @param array       $query    query args
	 * @param array|null  $payload  JSON body
	 * @return array{ok:bool,status:int,data:mixed,error:?array}
	 */
	public static function request( $method, $path, array $query = array(), $payload = null ) {
		$res = self::send( $method, $path, $query, null === $payload ? null : wp_json_encode( $payload ), array(), true );

		if ( 401 === $res['status'] && isset( $res['error']['code'] ) && 'session_required' === $res['error']['code'] ) {
			delete_transient( self::TOKEN_TRANSIENT );
			$res = self::send( $method, $path, $query, null === $payload ? null : wp_json_encode( $payload ), array(), true );
		}

		return $res;
	}

	/** Relay requests carry the raw body untouched and need no dashboard session. */
	public static function relay( $method, $path, array $query, $raw_body, array $extra_headers ) {
		return self::send( $method, $path, $query, $raw_body, $extra_headers, false );
	}

	private static function send( $method, $path, array $query, $body, array $extra_headers, $with_session ) {
		$settings = Ayla_Settings::get();

		if ( ! Ayla_Settings::is_configured() ) {
			return self::failure( 0, 'not_configured', Ayla_I18n::t( 'not_configured' ) );
		}

		$headers = array(
			'Authorization'          => 'Bearer ' . $settings['api_key'],
			'X-Ayla-Domain'          => $settings['domain'],
			'X-Ayla-Plugin-Version'  => AYLA_VERSION,
			'Accept'                 => 'application/json',
			'Content-Type'           => 'application/json',
		);

		if ( $with_session ) {
			$token = self::session_token();
			if ( is_array( $token ) ) {
				return $token;
			}
			$headers['X-Ayla-Token'] = $token;
		}

		$url = AYLA_API_BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array_merge( $headers, $extra_headers ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure( 0, 'unreachable', Ayla_I18n::t( 'error_generic' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'data'   => is_array( $json ) && array_key_exists( 'data', $json ) ? $json['data'] : null,
				'error'  => null,
				'raw'    => $raw,
			);
		}

		return self::error_from( $status, $json, $raw );
	}

	/** @return string|array token, or a failure array */
	private static function session_token() {
		$token = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $token ) && '' !== $token ) {
			return $token;
		}

		$settings = Ayla_Settings::get();
		$res      = self::send(
			'POST',
			'/auth/login',
			array(),
			wp_json_encode(
				array(
					'username' => $settings['username'],
					'password' => $settings['password'],
				)
			),
			array(),
			false
		);

		if ( ! $res['ok'] || empty( $res['data']['token'] ) ) {
			return $res['ok'] ? self::failure( 0, 'login_failed', Ayla_I18n::t( 'error_generic' ) ) : $res;
		}

		$ttl = isset( $res['data']['expires_in'] ) ? max( 60, (int) $res['data']['expires_in'] - 300 ) : 3000;
		set_transient( self::TOKEN_TRANSIENT, $res['data']['token'], $ttl );

		return (string) $res['data']['token'];
	}

	private static function error_from( $status, $json, $raw ) {
		$code    = 'error';
		$message = Ayla_I18n::t( 'error_generic' );

		if ( is_array( $json ) && isset( $json['error']['code'] ) ) {
			$code = (string) $json['error']['code'];
			// Server messages for these codes are already branded and localised.
			if ( in_array( $code, array( 'account_expired', 'account_inactive', 'doctors_limit_reached', 'field_locked', 'plugin_update_required', 'doctor_has_appointments', 'validation_failed', 'faq_limit_reached' ), true ) && ! empty( $json['error']['message'] ) ) {
				$message = (string) $json['error']['message'];
			}
		} elseif ( 422 === $status && is_array( $json ) && ! empty( $json['errors'] ) ) {
			$code  = 'validation_failed';
			$first = reset( $json['errors'] );
			if ( is_array( $first ) && ! empty( $first[0] ) ) {
				$message = (string) $first[0];
			}
		}

		$result        = self::failure( $status, $code, $message );
		$result['raw'] = $raw;

		return $result;
	}

	private static function failure( $status, $code, $message ) {
		return array(
			'ok'     => false,
			'status' => (int) $status,
			'data'   => null,
			'error'  => array(
				'code'    => $code,
				'message' => $message,
			),
			'raw'    => '',
		);
	}
}
