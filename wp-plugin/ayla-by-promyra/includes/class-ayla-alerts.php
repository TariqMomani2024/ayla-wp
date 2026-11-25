<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the site owner, inside WordPress, that Ayla promised a customer a follow-up: a notice on every admin page
 * and a counter on the Ayla menu. It disappears when the owner opens the Ayla dashboard.
 */
class Ayla_Alerts {

	const CACHE = 'ayla_alerts';

	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'badge' ), 999 );
		add_action( 'load-toplevel_page_' . Ayla_Admin::SLUG, array( __CLASS__, 'mark_seen' ) );
	}

	/** @return array{unseen:int,urgent:int,items:array} */
	private static function data() {
		$empty = array( 'unseen' => 0, 'urgent' => 0, 'items' => array() );

		if ( ! current_user_can( 'manage_options' ) || ! Ayla_Settings::is_configured() ) {
			return $empty;
		}

		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res  = Ayla_Client::site( 'GET', '/alerts', array(), null, 4 );
		$data = $res['ok'] && is_array( $res['data'] ) ? array_merge( $empty, $res['data'] ) : $empty;
		set_transient( self::CACHE, $data, $res['ok'] ? 60 : 300 );

		return $data;
	}

	public static function mark_seen() {
		if ( ! current_user_can( 'manage_options' ) || ! Ayla_Settings::is_configured() ) {
			return;
		}

		Ayla_Client::site( 'POST', '/alerts/seen', array(), array(), 4 );
		delete_transient( self::CACHE );
	}

	public static function badge() {
		global $menu;

		$data = self::data();
		if ( $data['unseen'] < 1 || ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $key => $item ) {
			if ( isset( $item[2] ) && Ayla_Admin::SLUG === $item[2] ) {
				$menu[ $key ][0] .= ' <span class="awaiting-mod">' . (int) $data['unseen'] . '</span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	}

	public static function notice() {
		$data = self::data();
		if ( $data['unseen'] < 1 ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, Ayla_Admin::SLUG ) ) {
			return;
		}

		$urgent = (int) $data['urgent'] > 0;
		echo '<div class="notice ' . ( $urgent ? 'notice-error' : 'notice-warning' ) . '"><p><strong>' . esc_html( Ayla_I18n::t( 'alert_followup' ) ) . ' ' . (int) $data['unseen'] . '</strong>';
		if ( $urgent ) {
			echo ' &mdash; ' . esc_html( Ayla_I18n::t( 'alert_urgent' ) ) . ': ' . (int) $data['urgent'];
		}
		echo '</p>';

		$items = array_slice( (array) $data['items'], 0, 3 );
		if ( $items ) {
			echo '<ul style="list-style:disc;margin:0 22px 8px">';
			foreach ( $items as $item ) {
				$who  = ! empty( $item['customer'] ) ? $item['customer'] : '';
				$line = ! empty( $item['summary'] ) ? preg_split( '/\r\n|\r|\n/', (string) $item['summary'] )[0] : '';
				echo '<li>' . esc_html( trim( $who . ( $who && $line ? ' — ' : '' ) . $line ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . Ayla_Admin::SLUG ) ) . '">' . esc_html( Ayla_I18n::t( 'alert_open' ) ) . '</a></p></div>';
	}
}
