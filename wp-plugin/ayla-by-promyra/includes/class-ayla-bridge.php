<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets Ayla check times and book through this site's own booking add-on, so there is one booking engine,
 * one calendar and no clashes. Requests come from Ayla only: they are signed with a secret that this site
 * generates and announces to Ayla during a sync. Bookings are held as pending until reception confirms them.
 */
class Ayla_Bridge {

	const SECRET_OPTION = 'ayla_bridge_secret';
	const PUSH          = 'ayla_bridge_push';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'dcb_appointment_status_changed', array( __CLASS__, 'queue_push' ), 10, 1 );
		add_action( 'dcb_appointment_rescheduled', array( __CLASS__, 'queue_push' ), 10, 1 );
		add_action( self::PUSH, array( __CLASS__, 'push' ) );
	}

	public static function available() {
		return function_exists( 'dcb_create_request' ) && function_exists( 'dentacare_opt' );
	}

	private static function secret() {
		$stored = Ayla_Secrets::decrypt( (string) get_option( self::SECRET_OPTION, '' ) );
		if ( strlen( $stored ) < 32 ) {
			$stored = bin2hex( random_bytes( 24 ) );
			update_option( self::SECRET_OPTION, Ayla_Secrets::encrypt( $stored ), false );
		}

		return $stored;
	}

	/** What Ayla needs to reach this site; sent with every content sync. */
	public static function announce() {
		return array(
			'url'    => rest_url( 'ayla/v1/bridge' ),
			'secret' => self::secret(),
		);
	}

	public static function routes() {
		register_rest_route(
			'ayla/v1',
			'/bridge/(?P<op>slots|book|cancel|reschedule|confirm)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
	}

	public static function verify( WP_REST_Request $request ) {
		if ( ! self::available() ) {
			return false;
		}
		$time = (int) $request->get_header( 'x-ayla-time' );
		$sig  = (string) $request->get_header( 'x-ayla-sig' );
		if ( abs( time() - $time ) > 300 || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $time . "\n" . $request['op'] . "\n" . hash( 'sha256', $request->get_body() ), self::secret() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}
		// A signed request works once.
		$key = 'ayla_sig_' . substr( $sig, 0, 32 );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	public static function handle( WP_REST_Request $request ) {
		$in   = json_decode( $request->get_body(), true );
		$in   = is_array( $in ) ? $in : array();
		$op   = (string) $request['op'];
		$lang = isset( $in['lang'] ) && 0 === strpos( (string) $in['lang'], 'en' ) ? 'en' : 'ar';

		switch ( $op ) {
			case 'slots':
				return self::slots( $in, $lang );
			case 'book':
				return self::book( $in, $lang );
			case 'cancel':
				return self::cancel( $in );
			case 'reschedule':
				return self::reschedule( $in );
			case 'confirm':
				return self::confirm( $in );
		}

		return self::fail( 'unknown_operation', 'Unknown operation.' );
	}

	private static function ok( $data ) {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	private static function fail( $code, $message, $extra = array() ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => array_merge( array( 'code' => $code, 'message' => $message ), $extra ) ), 200 );
	}

	/* ---------------------------------------------------------------- Checks */

	/** @return WP_REST_Response|null An error response when the service or branch is missing or unknown. */
	private static function check_scope( $service, $branch, $doctor ) {
		$services = dcb_services();
		if ( ! $service || ! isset( $services[ $service ] ) ) {
			return self::fail( 'service_required', 'Ask which treatment the patient needs.', array( 'options' => array_slice( array_values( $services ), 0, 25 ) ) );
		}
		if ( $doctor && ! isset( dcb_doctors()[ $doctor ] ) ) {
			return self::fail( 'doctor_not_found', 'Unknown doctor.' );
		}
		$branches = dcb_branches();
		if ( $branch && ! isset( $branches[ $branch ] ) ) {
			return self::fail( 'branch_not_found', 'Unknown branch.', array( 'options' => array_values( $branches ) ) );
		}
		if ( count( $branches ) > 1 && ! $branch ) {
			return self::fail( 'branch_required', 'Ask which branch the patient prefers.', array( 'options' => array_values( $branches ) ) );
		}

		return null;
	}

	private static function query( $service, $doctor, $branch, $ignore = array() ) {
		return array( 'service_id' => $service, 'doctor_id' => $doctor, 'branch_id' => $branch, 'token' => '', 'ignore' => $ignore );
	}

	/** Up to three upcoming times (Y-m-d H:i) after a date, for when a day is full. */
	private static function upcoming( $q, $after ) {
		$out = array();
		$from = $after;
		for ( $i = 0; $i < 3; $i++ ) {
			$next = dcb_next_slot_for( $q, $from );
			if ( ! $next ) {
				break;
			}
			$out[] = $next['date'] . ' ' . $next['slot']['time'];
			$from  = ( new DateTimeImmutable( $next['date'], dcb_tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		}

		return $out;
	}

	private static function phone_matches( $appt, $phone ) {
		global $wpdb;
		$phone = dcb_normalize_phone( (string) $phone );
		if ( '' === $phone ) {
			return false;
		}
		$known = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT booker_phone FROM ' . dcb_table( 'requests' ) . ' WHERE id = %d UNION SELECT phone FROM ' . dcb_table( 'patients' ) . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $appt['request_id'],
				(int) $appt['patient_id']
			)
		);

		return in_array( $phone, $known, true );
	}

	private static function appointment_for( $in ) {
		$appt = dcb_get_appointment( (int) ( $in['id'] ?? 0 ) );

		return $appt && self::phone_matches( $appt, $in['phone'] ?? '' ) ? $appt : null;
	}

	/* ------------------------------------------------------------ Operations */

	private static function slots( $in, $lang ) {
		$service = (int) ( $in['service'] ?? 0 );
		$doctor  = (int) ( $in['doctor'] ?? 0 );
		$branch  = (int) ( $in['branch'] ?? 0 );
		$date    = dcb_clean_date( $in['date'] ?? '' );
		$scope   = self::check_scope( $service, $branch, $doctor );
		if ( $scope ) {
			return $scope;
		}
		$today = dcb_local( dcb_now() )->format( 'Y-m-d' );
		if ( '' === $date || $date < $today ) {
			return self::fail( 'invalid_date', 'Use today or a later date.' );
		}
		$q     = self::query( $service, $doctor, $branch );
		$slots = dcb_day_slots( $q, $date );
		$after = ( new DateTimeImmutable( $date, dcb_tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );

		return self::ok(
			array(
				'date'           => $date,
				'slots'          => wp_list_pluck( $slots, 'time' ),
				'next_available' => $slots ? array() : self::upcoming( $q, $after ),
			)
		);
	}

	private static function book( $in, $lang ) {
		$n = (int) get_transient( 'ayla_bridge_book_hour' );
		if ( $n >= (int) apply_filters( 'ayla_bridge_bookings_per_hour', 40 ) ) {
			return self::fail( 'rate_limited', 'Too many requests for now. Hand the patient to the team.' );
		}
		$service = (int) ( $in['service'] ?? 0 );
		$doctor  = (int) ( $in['doctor'] ?? 0 );
		$branch  = (int) ( $in['branch'] ?? 0 );
		$scope   = self::check_scope( $service, $branch, $doctor );
		if ( $scope ) {
			return $scope;
		}
		$name  = mb_substr( trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) ), 0, 150 );
		$phone = dcb_normalize_phone( (string) ( $in['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );
		if ( mb_strlen( $name ) < 2 || '' === $phone || ! is_email( $email ) ) {
			return self::fail( 'missing_contact_details', 'A full name, a valid mobile number and a valid email are needed.' );
		}
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', (string) ( $in['datetime'] ?? '' ), $m ) || '' === dcb_clean_date( $m[1] ) ) {
			return self::fail( 'invalid_datetime', 'Use a date and time from the available times.' );
		}

		$q    = self::query( $service, $doctor, $branch );
		$slot = null;
		foreach ( dcb_day_slots( $q, $m[1] ) as $s ) {
			if ( $s['time'] === $m[2] ) {
				$slot = $s;
				break;
			}
		}
		if ( ! $slot ) {
			return self::fail( 'slot_unavailable', 'That time is not available.', array( 'alternatives' => self::upcoming( $q, $m[1] ) ) );
		}
		$doctor = $doctor ? $doctor : (int) $slot['doctor_id'];

		$items = dcb_clean_items(
			array(
				array(
					'service_id' => $service,
					'doctor_id'  => $doctor,
					'branch_id'  => $branch,
					'start_utc'  => $slot['start_utc'],
					'person'     => array( 'name' => $name, 'phone' => $phone, 'email' => $email, 'age_group' => 'adult' ),
				),
			),
			1
		);
		if ( is_wp_error( $items ) ) {
			return self::fail( 'slot_unavailable', 'Choose one of the available times.' );
		}
		$note              = mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 300 );
		$items[0]['notes'] = trim( '[Ayla] ' . $note );

		// A deposit can only be arranged with the team, unless the clinic allows paying at the clinic.
		$pay = '';
		$opt = function_exists( 'dcb_payment_options' ) ? dcb_payment_options( array( $service ) ) : array( 'amount' => 0, 'methods' => array() );
		if ( (float) $opt['amount'] > 0 && $opt['methods'] ) {
			foreach ( $opt['methods'] as $method ) {
				if ( 'clinic' === $method['kind'] ) {
					$pay = $method['id'];
					break;
				}
			}
			if ( '' === $pay ) {
				return self::fail( 'payment_required', 'This service needs a deposit. Hand the patient to the team.' );
			}
		}

		set_transient( 'ayla_bridge_book_hour', $n + 1, HOUR_IN_SECONDS );
		$res = dcb_create_request(
			array(
				'booker'         => array( 'name' => $name, 'phone' => $phone, 'email' => $email ),
				'items'          => $items,
				'mode'           => 'single',
				'source'         => 'web' === ( $in['channel'] ?? '' ) ? 'web' : 'whatsapp',
				'lang'           => $lang,
				'pay_method'     => $pay,
				'hold_for_staff' => true,
			)
		);
		if ( is_wp_error( $res ) ) {
			if ( 'dcb_unavailable' === $res->get_error_code() ) {
				return self::fail( 'slot_unavailable', 'That time was just taken.', array( 'alternatives' => self::upcoming( $q, $m[1] ) ) );
			}

			return self::fail( 'booking_failed', $res->get_error_message() );
		}

		$appointment_id = (int) $res['appointment_ids'][0];
		$appt           = dcb_get_appointment( $appointment_id );

		return self::ok(
			array(
				'id'               => $appointment_id,
				'ref'              => $res['ref'],
				'status'           => $appt ? $appt['status'] : 'pending',
				'doctor'           => (string) $doctor,
				'doctor_name'      => dcb_title_lang( $doctor, $lang ),
				'duration_minutes' => (int) dcb_service_meta( $service )['duration_min'],
			)
		);
	}

	private static function cancel( $in ) {
		$appt = self::appointment_for( $in );
		if ( ! $appt ) {
			return self::fail( 'appointment_not_found', 'Appointment not found.' );
		}
		if ( ! dcb_patient_can_change( $appt ) ) {
			return self::fail( 'too_late_to_change', 'This appointment can only be changed through the reception.' );
		}
		$res = dcb_cancel_appointment( (int) $appt['id'], 'patient', 'Ayla', true );

		return is_wp_error( $res ) ? self::fail( 'cancel_failed', $res->get_error_message() ) : self::ok( array( 'status' => 'cancelled' ) );
	}

	private static function reschedule( $in ) {
		$appt = self::appointment_for( $in );
		if ( ! $appt ) {
			return self::fail( 'appointment_not_found', 'Appointment not found.' );
		}
		if ( ! dcb_patient_can_change( $appt ) ) {
			return self::fail( 'too_late_to_change', 'This appointment can only be changed through the reception.' );
		}
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', (string) ( $in['datetime'] ?? '' ), $m ) || '' === dcb_clean_date( $m[1] ) ) {
			return self::fail( 'invalid_datetime', 'Use a date and time from the available times.' );
		}
		$q    = self::query( (int) $appt['service_id'], (int) $appt['doctor_id'], (int) $appt['branch_id'], array( (int) $appt['id'] ) );
		$slot = null;
		foreach ( dcb_day_slots( $q, $m[1] ) as $s ) {
			if ( $s['time'] === $m[2] ) {
				$slot = $s;
				break;
			}
		}
		if ( ! $slot ) {
			return self::fail( 'slot_unavailable', 'That time is not available.', array( 'alternatives' => self::upcoming( $q, $m[1] ) ) );
		}
		$res = dcb_reschedule_appointment( (int) $appt['id'], array( 'doctor_id' => (int) $appt['doctor_id'], 'branch_id' => (int) $appt['branch_id'], 'start_utc' => $slot['start_utc'] ), 'patient', false );
		if ( is_wp_error( $res ) ) {
			return self::fail( 'slot_unavailable', $res->get_error_message(), array( 'alternatives' => self::upcoming( $q, $m[1] ) ) );
		}
		$fresh = dcb_get_appointment( (int) $appt['id'] );

		return self::ok( array( 'status' => $fresh ? $fresh['status'] : $appt['status'] ) );
	}

	private static function confirm( $in ) {
		$appt = self::appointment_for( $in );
		if ( ! $appt || ! in_array( $appt['status'], array( 'pending', 'confirmed' ), true ) ) {
			return self::fail( 'appointment_not_found', 'Appointment not found.' );
		}
		$res = dcb_set_status( (int) $appt['id'], 'confirmed' );

		return is_wp_error( $res ) ? self::fail( 'confirm_failed', $res->get_error_message() ) : self::ok( array( 'status' => 'confirmed' ) );
	}

	/* ------------------------------------------------------- Status to Ayla */

	/** Reception confirms, cancels or moves a booking: Ayla's copy follows a few seconds later. */
	public static function queue_push( $appointment_id ) {
		if ( Ayla_Settings::is_configured() && self::available() ) {
			wp_schedule_single_event( time() + 5, self::PUSH, array( (int) $appointment_id ) );
		}
	}

	public static function push( $appointment_id ) {
		$appt = dcb_get_appointment( (int) $appointment_id );
		if ( ! $appt || ! Ayla_Settings::is_configured() ) {
			return;
		}
		Ayla_Client::site(
			'POST',
			'/appointments/status',
			array(),
			array(
				'id'       => (string) $appt['id'],
				'status'   => $appt['status'],
				'datetime' => dcb_local( $appt['start_utc'] )->format( 'Y-m-d H:i' ),
				'doctor'   => (string) $appt['doctor_id'],
			),
			15
		);
	}
}
