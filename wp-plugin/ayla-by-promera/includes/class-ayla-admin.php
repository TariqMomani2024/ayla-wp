<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ayla_Admin {

	const SLUG          = 'ayla-by-promera';
	const SETTINGS_SLUG = 'ayla-by-promera-settings';

	/** route pattern => allowed methods. Anything else is rejected by the proxy. */
	private static function allowed_routes() {
		return array(
			'#^account$#'                        => array( 'GET', 'PUT' ),
			'#^dashboard/stats$#'                => array( 'GET' ),
			'#^leads$#'                          => array( 'GET' ),
			'#^appointments$#'                   => array( 'GET' ),
			'#^conversations$#'                  => array( 'GET' ),
			'#^conversations/\d+/messages$#'     => array( 'GET' ),
			'#^conversations/\d+/resume$#'       => array( 'POST' ),
			'#^(doctors|services|faqs)$#'        => array( 'GET', 'POST' ),
			'#^(doctors|services|faqs)/\d+$#'    => array( 'PUT', 'DELETE' ),
		);
	}

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_post_ayla_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_ayla_proxy', array( __CLASS__, 'proxy' ) );
	}

	public static function menu() {
		add_menu_page(
			Ayla_I18n::t( 'brand' ),
			Ayla_I18n::t( 'menu' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_app' ),
			'dashicons-format-chat',
			58
		);
		add_submenu_page( self::SLUG, Ayla_I18n::t( 'dashboard' ), Ayla_I18n::t( 'dashboard' ), 'manage_options', self::SLUG, array( __CLASS__, 'render_app' ) );
		add_submenu_page( self::SLUG, Ayla_I18n::t( 'settings' ), Ayla_I18n::t( 'settings' ), 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		if ( function_exists( 'dentacare_admin_identity_css' ) ) {
			$file = get_template_directory() . '/assets/css/admin.css';
			wp_enqueue_style( 'ayla-dentacare-identity', get_template_directory_uri() . '/assets/css/admin.css', array(), (string) filemtime( $file ) );
			wp_add_inline_style( 'ayla-dentacare-identity', dentacare_admin_identity_css() );
		}

		wp_enqueue_style( 'ayla-admin', AYLA_PLUGIN_URL . 'assets/admin.css', array(), AYLA_VERSION );
		wp_enqueue_script( 'ayla-admin', AYLA_PLUGIN_URL . 'assets/admin.js', array(), AYLA_VERSION, true );

		wp_localize_script(
			'ayla-admin',
			'AylaData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ayla_proxy' ),
				'configured' => Ayla_Settings::is_configured(),
				'integrated' => Ayla_Bridge::available(),
				'rtl'        => Ayla_I18n::is_rtl(),
				'page'       => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'i18n'       => Ayla_I18n::for_js(),
			)
		);
	}

	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && false !== strpos( (string) $screen->id, self::SLUG ) ? $classes . ' dc-screen ayla-screen' : $classes;
	}

	public static function render_app() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( Ayla_I18n::t( 'forbidden' ) ) );
		}

		$dir = Ayla_I18n::is_rtl() ? 'rtl' : 'ltr';
		echo '<div class="wrap ayla-wrap" dir="' . esc_attr( $dir ) . '">';
		echo '<h1>' . esc_html( Ayla_I18n::t( 'brand' ) ) . '</h1>';

		if ( ! Ayla_Settings::is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( Ayla_I18n::t( 'not_configured' ) ) . '</p></div>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html( Ayla_I18n::t( 'settings' ) ) . '</a></p>';
		} else {
			echo '<div id="ayla-app" data-view="app"></div>';
		}

		echo '</div>';
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( Ayla_I18n::t( 'forbidden' ) ) );
		}

		$s   = Ayla_Settings::get();
		$dir = Ayla_I18n::is_rtl() ? 'rtl' : 'ltr';

		$domain = '' !== $s['domain'] ? $s['domain'] : Ayla_Settings::default_domain();

		echo '<div class="wrap ayla-wrap" dir="' . esc_attr( $dir ) . '">';
		echo '<h1>' . esc_html( Ayla_I18n::t( 'brand' ) ) . ' - ' . esc_html( Ayla_I18n::t( 'settings' ) ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( Ayla_I18n::t( 'saved' ) ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ayla-card">';
		echo '<input type="hidden" name="action" value="ayla_save_settings">';
		wp_nonce_field( 'ayla_save_settings' );
		echo '<h2>' . esc_html( Ayla_I18n::t( 'connection_details' ) ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		self::row( 'domain', Ayla_I18n::t( 'domain' ), 'text', $domain, '' );
		self::row( 'username', Ayla_I18n::t( 'username' ), 'text', $s['username'], '' );
		$password_hint = Ayla_I18n::t( 'password_special_chars' );
		if ( '' !== $s['password'] ) {
			$password_hint .= ' ' . Ayla_I18n::t( 'keep_saved' );
		}
		self::row( 'password', Ayla_I18n::t( 'password' ), 'password', '', $password_hint );
		self::row( 'api_key', Ayla_I18n::t( 'api_key' ), 'password', '', '' !== $s['api_key'] ? Ayla_I18n::t( 'keep_saved' ) : '' );

		echo '</table>';
		submit_button( Ayla_I18n::t( 'save' ) );
		echo '</form>';

		echo '<div class="ayla-card">';
		echo '<h2>' . esc_html( Ayla_I18n::t( 'webhook_url' ) ) . '</h2>';
		echo '<p><code>' . esc_html( rest_url( 'ayla/v1/webhook' ) ) . '</code></p>';
		echo '<h2>' . esc_html( Ayla_I18n::t( 'connection_status' ) ) . '</h2>';
		echo '<p id="ayla-status" data-view="status">' . esc_html( Ayla_I18n::t( 'checking' ) ) . '</p>';
		echo '</div>';

		self::render_site_learning();

		echo '</div>';
	}

	private static function render_site_learning() {
		if ( ! Ayla_Settings::is_configured() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['synced'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			$ok = '1' === (string) $_GET['synced'];
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( Ayla_I18n::t( $ok ? 'site_sync_ok' : 'site_sync_failed' ) ) . '</p></div>';
		}

		echo '<div class="ayla-card"><h2>' . esc_html( Ayla_I18n::t( 'site_learning' ) ) . '</h2>';

		if ( ! Ayla_Site_Sync::available() ) {
			echo '<p>' . esc_html( Ayla_I18n::t( 'site_theme_required' ) ) . '</p></div>';
			return;
		}

		$st = Ayla_Site_Sync::status();
		echo '<p>' . esc_html( Ayla_I18n::t( 'site_learning_help' ) ) . '</p>';
		echo '<p><strong>' . esc_html( Ayla_I18n::t( 'site_last_sync' ) ) . ':</strong> ';
		echo $st['at'] ? esc_html( wp_date( 'Y-m-d H:i', $st['at'] ) ) : esc_html( Ayla_I18n::t( 'site_never' ) );
		echo '</p>';

		if ( $st['at'] && ! $st['ok'] && '' !== $st['error'] ) {
			echo '<p class="notice notice-error inline"><span>' . esc_html( $st['error'] ) . '</span></p>';
		} elseif ( $st['at'] && $st['counts'] ) {
			$c = $st['counts'];
			echo '<p>' . esc_html( Ayla_I18n::t( 'site_counts' ) ) . ': ' . (int) $c['branches'] . ' / ' . (int) $c['services'] . ' / ' . (int) $c['doctors'] . ' / ' . (int) $c['faqs'] . ' / ' . (int) $c['knowledge'] . '</p>';
			if ( $st['over_limit'] > 0 ) {
				echo '<p class="notice notice-warning inline"><span>' . esc_html( sprintf( Ayla_I18n::t( 'site_over_limit' ), (int) $st['over_limit'] ) ) . '</span></p>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ayla_sync_site">';
		wp_nonce_field( 'ayla_sync_site' );
		submit_button( Ayla_I18n::t( 'site_sync_now' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private static function row( $name, $label, $type, $value, $hint ) {
		echo '<tr><th scope="row"><label for="ayla_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input class="regular-text" id="ayla_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" autocomplete="off"' . ( 'text' === $type ? '' : ' placeholder="••••••••"' ) . '>';
		if ( '' !== $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( Ayla_I18n::t( 'forbidden' ) ) );
		}

		check_admin_referer( 'ayla_save_settings' );

		Ayla_Settings::save( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised inside save().

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&saved=1' ) );
		exit;
	}

	/** Same-origin proxy for the dashboard JS. Only allow-listed routes and methods get through. */
	public static function proxy() {
		check_ajax_referer( 'ayla_proxy', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => Ayla_I18n::t( 'forbidden' ) ) ), 403 );
		}

		$route  = isset( $_POST['route'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['route'] ) ), '/' ) : '';
		$method = isset( $_POST['method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['method'] ) ) ) : 'GET';

		if ( ! self::is_allowed( $route, $method ) ) {
			wp_send_json( array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => Ayla_I18n::t( 'forbidden' ) ) ), 403 );
		}

		$query = array();
		if ( isset( $_POST['query'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['query'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $key => $value ) {
					if ( is_scalar( $value ) ) {
						$query[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
					}
				}
			}
		}

		$payload = null;
		if ( isset( $_POST['payload'] ) && '' !== $_POST['payload'] ) {
			$decoded = json_decode( wp_unslash( $_POST['payload'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the service.
			$payload = is_array( $decoded ) ? $decoded : null;
		}

		$res = Ayla_Client::request( $method, '/' . $route, $query, in_array( $method, array( 'POST', 'PUT' ), true ) ? ( null === $payload ? array() : $payload ) : null );

		wp_send_json(
			array(
				'ok'    => $res['ok'],
				'data'  => $res['data'],
				'error' => $res['error'],
			)
		);
	}

	private static function is_allowed( $route, $method ) {
		foreach ( self::allowed_routes() as $pattern => $methods ) {
			if ( preg_match( $pattern, $route ) && in_array( $method, $methods, true ) ) {
				return true;
			}
		}

		return false;
	}
}
