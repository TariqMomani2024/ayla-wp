<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates from the Promyra server instead of the WordPress.org store. It plugs into WordPress' own update
 * system, so the usual "update available" row, one-click update and auto-updates all work.
 */
class Ayla_Updater {

	const CACHE   = 'ayla_update_info';
	const TTL     = 21600; // 6 hours.
	const FAILED  = 3600;
	const PACKAGE = '/plugin/download/';

	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ) );
		add_action( 'load-toplevel_page_' . Ayla_Admin::SLUG, array( __CLASS__, 'refresh_if_stale' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	private static function basename() {
		return plugin_basename( AYLA_PLUGIN_FILE );
	}

	/** @return array|null the newest release this site can install, as sent by the server */
	private static function release( $force = false ) {
		$cached = get_transient( self::CACHE );

		if ( ! $force && is_array( $cached ) ) {
			return self::newer( $cached['release'] );
		}

		if ( ! Ayla_Settings::is_configured() ) {
			return null;
		}

		$res = Ayla_Client::updates();

		if ( ! $res['ok'] || ! is_array( $res['data'] ) ) {
			// A failed check is remembered briefly so a down server does not slow every admin page.
			set_transient( self::CACHE, array( 'release' => null ), self::FAILED );
			return null;
		}

		$release = ! empty( $res['data']['update_available'] ) && is_array( $res['data']['release'] ) ? $res['data']['release'] : null;
		set_transient( self::CACHE, array( 'release' => $release ), self::TTL );

		return self::newer( $release );
	}

	private static function newer( $release ) {
		if ( ! is_array( $release ) || empty( $release['version'] ) || ! version_compare( $release['version'], AYLA_VERSION, '>' ) ) {
			return null;
		}

		return $release;
	}

	private static function package_url( $version ) {
		return AYLA_API_BASE . self::PACKAGE . rawurlencode( $version );
	}

	private static function notice_text( array $release ) {
		$lang  = Ayla_I18n::lang();
		$other = 'ar' === $lang ? 'en' : 'ar';
		$text  = '';

		if ( isset( $release['notice'] ) && is_array( $release['notice'] ) ) {
			$text = ! empty( $release['notice'][ $lang ] ) ? $release['notice'][ $lang ] : ( ! empty( $release['notice'][ $other ] ) ? $release['notice'][ $other ] : '' );
		}

		return (string) $text;
	}

	/** Adds our release to WordPress' list, or marks the plugin as up to date so auto-update can be switched on. */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! Ayla_Settings::is_configured() ) {
			return $transient;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$release  = self::release( isset( $_GET['force-check'] ) );
		$basename = self::basename();

		$item = (object) array(
			'id'            => 'promyra.ae/' . Ayla_Admin::SLUG,
			'slug'          => Ayla_Admin::SLUG,
			'plugin'        => $basename,
			'new_version'   => $release ? $release['version'] : AYLA_VERSION,
			'url'           => 'https://promyra.ae/',
			'package'       => $release ? self::package_url( $release['version'] ) : '',
			'requires'      => $release && ! empty( $release['requires_wp'] ) ? $release['requires_wp'] : '',
			'requires_php'  => $release && ! empty( $release['requires_php'] ) ? $release['requires_php'] : '',
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		unset( $transient->response[ $basename ], $transient->no_update[ $basename ] );

		if ( $release ) {
			$item->ayla_release           = $release;
			$transient->response[ $basename ] = $item;
		} else {
			$transient->no_update[ $basename ] = $item;
		}

		return $transient;
	}

	/** The "View details" popup on the Plugins page. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || Ayla_Admin::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::release();
		if ( ! $release ) {
			return $result;
		}

		$items = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( isset( $release['changelog'] ) ? $release['changelog'] : '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$items .= '<li>' . esc_html( ltrim( $line, "-* \t" ) ) . '</li>';
			}
		}

		$notice = self::notice_text( $release );

		return (object) array(
			'name'          => Ayla_I18n::t( 'brand' ),
			'slug'          => Ayla_Admin::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://promyra.ae/">Promyra Digital Solutions</a>',
			'homepage'      => 'https://promyra.ae/',
			'requires'      => ! empty( $release['requires_wp'] ) ? $release['requires_wp'] : '6.0',
			'requires_php'  => ! empty( $release['requires_php'] ) ? $release['requires_php'] : '7.4',
			'last_updated'  => ! empty( $release['released_at'] ) ? $release['released_at'] : '',
			'download_link' => self::package_url( $release['version'] ),
			'sections'      => array(
				'description' => '' !== $notice ? '<p>' . esc_html( $notice ) . '</p>' : '<p>' . esc_html( Ayla_I18n::t( 'brand' ) ) . '</p>',
				'changelog'   => '' !== $items ? '<h4>' . esc_html( $release['version'] ) . '</h4><ul>' . $items . '</ul>' : '',
			),
		);
	}

	/**
	 * WordPress cannot send our credentials, so the package link points at the API and the download is done here.
	 *
	 * @param false|string|WP_Error $reply
	 * @param string                $package
	 * @return false|string|WP_Error
	 */
	public static function download( $reply, $package ) {
		$prefix = AYLA_API_BASE . self::PACKAGE;

		if ( ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
			return $reply;
		}

		$version = rawurldecode( substr( $package, strlen( $prefix ) ) );
		$release = self::release();

		// The hash comes from the version check; without it the file cannot be trusted.
		if ( ! $release || $release['version'] !== $version || empty( $release['sha256'] ) ) {
			$release = self::release( true );
		}
		if ( ! $release || $release['version'] !== $version || empty( $release['sha256'] ) ) {
			return new WP_Error( 'ayla_download_failed', Ayla_I18n::t( 'update_download_failed' ) );
		}

		$file = wp_tempnam( $package );
		if ( ! $file ) {
			return new WP_Error( 'ayla_download_failed', Ayla_I18n::t( 'update_download_failed' ) );
		}

		$result = Ayla_Client::download_package( $version, $file );

		if ( is_wp_error( $result ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $result;
		}

		if ( ! hash_equals( strtolower( (string) $release['sha256'] ), (string) hash_file( 'sha256', $file ) ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ayla_integrity_failed', Ayla_I18n::t( 'update_integrity_failed' ) );
		}

		return $file;
	}

	/** Only releases marked "install automatically" (security fixes) override the site's own choice. */
	public static function auto_update( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && self::basename() === $item->plugin && ! empty( $item->ayla_release['auto_update'] ) ) {
			return true;
		}

		return $update;
	}

	public static function clear() {
		delete_transient( self::CACHE );
	}

	/** Opening the Ayla page checks for a release when the last check is old, instead of waiting for WordPress' 12 hour cycle. */
	public static function refresh_if_stale() {
		if ( false !== get_transient( self::CACHE ) || ! current_user_can( 'update_plugins' ) || ! Ayla_Settings::is_configured() ) {
			return;
		}

		self::release( true );

		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) ) {
			// Saving it runs inject(), which reads the fresh result.
			set_site_transient( 'update_plugins', $transient );
		}
	}

	public static function notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$transient = get_site_transient( 'update_plugins' );
		$basename  = self::basename();

		if ( ! is_object( $transient ) || empty( $transient->response[ $basename ]->ayla_release ) ) {
			return;
		}

		$release = self::newer( $transient->response[ $basename ]->ayla_release );
		if ( ! $release ) {
			return;
		}

		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_ayla  = $screen && false !== strpos( (string) $screen->id, Ayla_Admin::SLUG );
		$security = isset( $release['type'] ) && 'security' === $release['type'];

		// Security updates show everywhere in the admin; the others only where the owner is already using Ayla.
		if ( ! $on_ayla && ! $security ) {
			return;
		}

		$url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ), 'upgrade-plugin_' . $basename );

		$changes = '';
		if ( $on_ayla && ! empty( $release['changelog'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $release['changelog'] ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$changes .= '<li>' . esc_html( ltrim( $line, "-* \t" ) ) . '</li>';
				}
			}
		}

		$class = $security ? 'notice notice-error' : 'notice notice-info is-dismissible';

		echo '<div class="' . esc_attr( $class ) . '"><p><strong>' . esc_html( Ayla_I18n::t( $security ? 'update_security' : 'update_available' ) ) . '</strong> ' . esc_html( $release['version'] );
		$text = self::notice_text( $release );
		if ( '' !== $text ) {
			echo ' &mdash; ' . esc_html( $text );
		}
		echo '</p>';
		if ( '' !== $changes ) {
			echo '<p><em>' . esc_html( Ayla_I18n::t( 'update_whats_new' ) ) . '</em></p><ul style="list-style:disc;margin:0 22px 8px">' . $changes . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( Ayla_I18n::t( 'update_now' ) ) . '</a></p></div>';
	}
}
