<?php
/**
 * The single place that knows where the Ayla service lives.
 * It is never printed in HTML, JS, settings or error messages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AYLA_API_BASE' ) ) {
	define( 'AYLA_API_BASE', 'https://tymzh.net/api/ayla/v1' );
}
