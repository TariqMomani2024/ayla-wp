<?php
// Remove everything the plugin stored when it is deleted from WordPress.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ayla_settings' );
delete_transient( 'ayla_session_token' );
