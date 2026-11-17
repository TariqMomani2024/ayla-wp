<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Encrypts the stored connection secrets with a key derived from the site's salts. */
class Ayla_Secrets {

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return 'enc:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'enc:' ) ) {
			return $stored;
		}

		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}
}
