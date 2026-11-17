<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta webhook relay. It forwards the request untouched and stores nothing:
 * no parsing, no logging, no business logic.
 */
class Ayla_Relay {

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'ayla/v1',
			'/webhook',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handshake' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'relay' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** Meta's verification handshake: the challenge must be returned as plain text. */
	public static function handshake( WP_REST_Request $request ) {
		$query = array();
		foreach ( array( 'hub_mode', 'hub_verify_token', 'hub_challenge' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$query[ $key ] = (string) $value;
			}
		}

		$res = Ayla_Client::relay( 'GET', '/webhook/whatsapp', $query, null, array() );

		if ( $res['ok'] ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo $res['raw']; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text challenge echoed back to Meta.
			exit;
		}

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Forbidden';
		exit;
	}

	public static function relay( WP_REST_Request $request ) {
		$signature = (string) $request->get_header( 'x_hub_signature_256' );

		$res = Ayla_Client::relay(
			'POST',
			'/webhook/whatsapp',
			array(),
			$request->get_body(),
			'' !== $signature ? array( 'X-Hub-Signature-256' => $signature ) : array()
		);

		if ( $res['ok'] ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// A bad signature is the sender's problem. Anything else asks Meta to retry later.
		if ( 403 === $res['status'] ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		return new WP_REST_Response( array( 'ok' => false ), 503 );
	}
}
