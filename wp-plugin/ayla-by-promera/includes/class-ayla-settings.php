<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The only data the plugin stores: its connection settings. */
class Ayla_Settings {

	const OPTION = 'ayla_settings';

	/** @return array{domain:string,username:string,password:string,api_key:string} decrypted */
	public static function get() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'domain'   => isset( $raw['domain'] ) ? (string) $raw['domain'] : '',
			'username' => isset( $raw['username'] ) ? (string) $raw['username'] : '',
			'password' => isset( $raw['password'] ) ? Ayla_Secrets::decrypt( $raw['password'] ) : '',
			'api_key'  => isset( $raw['api_key'] ) ? Ayla_Secrets::decrypt( $raw['api_key'] ) : '',
		);
	}

	public static function is_configured() {
		$s = self::get();

		return '' !== $s['domain'] && '' !== $s['username'] && '' !== $s['password'] && '' !== $s['api_key'];
	}

	public static function default_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
	}

	/** Blank password or key fields keep the stored value. */
	public static function save( array $input ) {
		$current = self::get();

		$domain = isset( $input['domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $input['domain'] ) ) ) ) : '';
		$domain = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $domain );
		$domain = preg_replace( '/^www\./', '', explode( '/', $domain )[0] );

		$username = isset( $input['username'] ) ? sanitize_text_field( wp_unslash( $input['username'] ) ) : '';
		$password = isset( $input['password'] ) ? trim( (string) wp_unslash( $input['password'] ) ) : '';
		$api_key  = isset( $input['api_key'] ) ? sanitize_text_field( wp_unslash( $input['api_key'] ) ) : '';

		update_option(
			self::OPTION,
			array(
				'domain'   => $domain,
				'username' => $username,
				'password' => Ayla_Secrets::encrypt( '' !== $password ? $password : $current['password'] ),
				'api_key'  => Ayla_Secrets::encrypt( '' !== $api_key ? $api_key : $current['api_key'] ),
			),
			false
		);

		delete_transient( Ayla_Client::TOKEN_TRANSIENT );
	}
}
