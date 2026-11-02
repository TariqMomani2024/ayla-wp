<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ayla_Web_Chat {

	const HIDE_META = '_ayla_hide_chat';

	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ayla_web_chat', array( __CLASS__, 'message' ) );
		add_action( 'wp_ajax_nopriv_ayla_web_chat', array( __CLASS__, 'message' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function meta_box() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			add_meta_box( 'ayla-chat-visibility', 'Ayla', array( __CLASS__, 'render_meta_box' ), $type, 'side', 'low' );
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ayla_chat_visibility', 'ayla_chat_visibility_nonce' );
		echo '<label><input type="checkbox" name="ayla_hide_chat" value="1"' . checked( '1', get_post_meta( $post->ID, self::HIDE_META, true ), false ) . '> ' . esc_html( Ayla_I18n::t( 'hide_chat' ) ) . '</label>';
		echo '<p class="description">' . esc_html( Ayla_I18n::t( 'hide_chat_help' ) ) . '</p>';
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['ayla_chat_visibility_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ayla_chat_visibility_nonce'] ) ), 'ayla_chat_visibility' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['ayla_hide_chat'] ) ) {
			update_post_meta( $post_id, self::HIDE_META, '1' );
		} else {
			delete_post_meta( $post_id, self::HIDE_META );
		}
	}

	public static function assets() {
		if ( ! Ayla_Settings::is_configured() ) {
			return;
		}

		if ( is_singular() && '1' === get_post_meta( get_queried_object_id(), self::HIDE_META, true ) ) {
			return;
		}

		$style_version  = filemtime( AYLA_PLUGIN_DIR . 'assets/web-chat.css' );
		$script_version = filemtime( AYLA_PLUGIN_DIR . 'assets/web-chat.js' );

		wp_enqueue_style( 'ayla-web-chat', AYLA_PLUGIN_URL . 'assets/web-chat.css', array(), $style_version );
		wp_enqueue_script( 'ayla-web-chat', AYLA_PLUGIN_URL . 'assets/web-chat.js', array(), $script_version, true );
		$lang = self::language();
		$copy = array(
			'ar' => array(
				'title' => 'المساعد الذكي', 'messageLabel' => 'كيف يمكنني مساعدتك؟', 'messagePlaceholder' => 'اكتب رسالتك...', 'send' => 'إرسال', 'genericError' => 'تعذر إرسال الرسالة الآن. يرجى المحاولة لاحقاً.', 'whatsapp' => 'التواصل عبر واتساب', 'chatHere' => 'المحادثة هنا', 'chooseTitle' => 'كيف تحب أن نتواصل؟', 'expand' => 'تكبير المحادثة', 'shrink' => 'تصغير المحادثة',
			),
			'en' => array(
				'title' => 'Smart assistant', 'messageLabel' => 'How can I help?', 'messagePlaceholder' => 'Write your message...', 'send' => 'Send', 'genericError' => 'Your message could not be sent. Please try again later.', 'whatsapp' => 'Continue on WhatsApp', 'chatHere' => 'Chat here', 'chooseTitle' => 'How would you like to continue?', 'expand' => 'Expand chat', 'shrink' => 'Shrink chat',
			),
		);
		wp_localize_script( 'ayla-web-chat', 'AylaWebChat', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'ayla_web_chat' ),
			'lang'        => $lang,
			'i18n'        => $copy,
			'logoUrl'     => AYLA_PLUGIN_URL . 'assets/ayla-logo.png',
			'whatsappUrl' => self::whatsapp_url(),
		) );
	}

	/** The account's WhatsApp link, cached briefly; empty when WhatsApp is not connected. */
	private static function whatsapp_url() {
		$cached = get_transient( 'ayla_web_chat_whatsapp' );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$res = Ayla_Client::relay( 'GET', '/web-chat/config', array(), null, array() );
		$url = $res['ok'] && ! empty( $res['data']['whatsapp_url'] ) ? esc_url_raw( (string) $res['data']['whatsapp_url'] ) : '';
		if ( 0 !== strpos( $url, 'https://wa.me/' ) ) {
			$url = '';
		}

		set_transient( 'ayla_web_chat_whatsapp', $url, $res['ok'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

		return $url;
	}

	private static function language() {
		$fallback = function_exists( 'dentacare_lang' ) ? dentacare_lang() : ( is_rtl() ? 'ar' : 'en' );
		$cached  = get_transient( 'ayla_web_chat_language' );
		if ( is_string( $cached ) && in_array( $cached, array( 'ar', 'en' ), true ) ) {
			return $cached;
		}

		$res  = Ayla_Client::request( 'GET', '/account' );
		$lang = $res['ok'] && isset( $res['data']['default_language'] ) ? strtolower( substr( (string) $res['data']['default_language'], 0, 2 ) ) : $fallback;
		$lang = 'ar' === $lang ? 'ar' : 'en';
		set_transient( 'ayla_web_chat_language', $lang, 6 * HOUR_IN_SECONDS );

		return $lang;
	}

	public static function message() {
		check_ajax_referer( 'ayla_web_chat', 'nonce' );
		$visitor = isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $visitor ) || '' === $message ) {
			wp_send_json_error( array( 'message' => __( 'يرجى كتابة رسالة صحيحة.', 'ayla-by-promera' ) ), 422 );
		}

		$conversation = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		if ( ! $conversation ) {
			$start = Ayla_Client::relay( 'POST', '/web-chat/start', array(), wp_json_encode( array( 'visitor_id' => $visitor ) ), array() );
			if ( ! $start['ok'] || empty( $start['data']['conversation_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'تعذر بدء المحادثة الآن.', 'ayla-by-promera' ) ), 503 );
			}
			$conversation = (int) $start['data']['conversation_id'];
		}

		$res = Ayla_Client::relay( 'POST', '/web-chat/conversations/' . $conversation . '/messages', array(), wp_json_encode( array(
			'visitor_id' => $visitor,
			'message'    => $message,
		) ), array() );
		if ( ! $res['ok'] ) {
			error_log( 'Ayla web chat message failed: HTTP ' . (int) $res['status'] . ' (' . sanitize_key( (string) $res['error']['code'] ) . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_send_json_error( array( 'message' => __( 'تعذر إرسال الرسالة الآن.', 'ayla-by-promera' ) ), 503 );
		}

		wp_send_json_success( array_merge( $res['data'], array( 'conversation_id' => $conversation ) ) );
	}
}