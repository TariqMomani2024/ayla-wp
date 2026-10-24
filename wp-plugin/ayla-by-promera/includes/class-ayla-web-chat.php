<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ayla_Web_Chat {

	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_ayla_web_chat', array( __CLASS__, 'message' ) );
		add_action( 'wp_ajax_nopriv_ayla_web_chat', array( __CLASS__, 'message' ) );
	}

	public static function assets() {
		if ( ! Ayla_Settings::is_configured() ) {
			return;
		}

		wp_enqueue_style( 'ayla-web-chat', AYLA_PLUGIN_URL . 'assets/web-chat.css', array(), AYLA_VERSION );
		wp_enqueue_script( 'ayla-web-chat', AYLA_PLUGIN_URL . 'assets/web-chat.js', array(), AYLA_VERSION, true );
		wp_localize_script( 'ayla-web-chat', 'AylaWebChat', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ayla_web_chat' ),
			'title'   => __( 'المساعد الذكي', 'ayla-by-promera' ),
		) );
	}

	public static function message() {
		check_ajax_referer( 'ayla_web_chat', 'nonce' );
		$visitor = isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $visitor ) || '' === $message ) {
			wp_send_json_error( array( 'message' => __( 'يرجى كتابة رسالة صحيحة.', 'ayla-by-promera' ) ), 422 );
		}

		$conversation = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		if ( ! $conversation ) {
			$start = Ayla_Client::request( 'POST', '/web-chat/start', array(), array( 'visitor_id' => $visitor ) );
			if ( ! $start['ok'] || empty( $start['data']['conversation_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'تعذر بدء المحادثة الآن.', 'ayla-by-promera' ) ), 503 );
			}
			$conversation = (int) $start['data']['conversation_id'];
		}

		$res = Ayla_Client::request( 'POST', '/web-chat/conversations/' . $conversation . '/messages', array(), array(
			'visitor_id' => $visitor,
			'message'    => $message,
			'name'       => $name,
			'phone'      => $phone,
		) );
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => __( 'تعذر إرسال الرسالة الآن.', 'ayla-by-promera' ) ), 503 );
		}

		wp_send_json_success( array_merge( $res['data'], array( 'conversation_id' => $conversation ) ) );
	}
}