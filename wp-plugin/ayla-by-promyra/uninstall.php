<?php
// Remove everything the plugin stored when it is deleted from WordPress.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ayla_settings' );
delete_option( 'ayla_site_sync' );
delete_option( 'ayla_bridge_secret' );
wp_clear_scheduled_hook( 'ayla_site_sync_run' );
wp_clear_scheduled_hook( 'ayla_site_sync_daily' );
wp_unschedule_hook( 'ayla_bridge_push' );

delete_transient( 'ayla_session_token' );
delete_transient( 'ayla_web_chat_language' );
delete_transient( 'ayla_web_chat_whatsapp' );
delete_transient( 'ayla_bridge_book_hour' );

global $wpdb;
// Replay-guard transients are named ayla_sig_<hash>, so they need a pattern.
$wpdb->query( $wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	$wpdb->esc_like( '_transient_ayla_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_ayla_' ) . '%'
) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ayla_hide_chat' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
