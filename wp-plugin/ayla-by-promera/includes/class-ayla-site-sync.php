<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the clinic website's public content to Ayla: clinic details, branches, services, doctors,
 * FAQ, offers, pages and blog posts. The site owns this content; Ayla keeps a read copy.
 * Only runs with the DentaCare theme. No patient data and no drafts are read.
 */
class Ayla_Site_Sync {

	const OPTION = 'ayla_site_sync';
	const CRON   = 'ayla_site_sync_run';
	const DAILY  = 'ayla_site_sync_daily';
	const CHUNK  = 100;

	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( self::DAILY, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_daily' ) );
		add_action( 'admin_post_ayla_sync_site', array( __CLASS__, 'handle_manual' ) );
		add_action( 'save_post', array( __CLASS__, 'on_post' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'queue' ) );
		add_action( 'trashed_post', array( __CLASS__, 'queue' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 20, 1 );
	}

	public static function available() {
		return function_exists( 'dentacare_opt' ) && Ayla_Settings::is_configured();
	}

	/** Catches changes that fire no hook, such as doctor schedules kept in the booking tables. */
	public static function ensure_daily() {
		if ( self::available() && ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	/** @return array{at:int,ok:bool,error:string,counts:array} */
	public static function status() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();

		return array(
			'at'     => isset( $s['at'] ) ? (int) $s['at'] : 0,
			'ok'     => ! empty( $s['ok'] ),
			'error'  => isset( $s['error'] ) ? (string) $s['error'] : '',
			'counts' => isset( $s['counts'] ) && is_array( $s['counts'] ) ? $s['counts'] : array(),
			'over_limit' => isset( $s['over_limit'] ) ? (int) $s['over_limit'] : 0,
		);
	}

	public static function on_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'post', 'page', 'dc_treatment', 'dc_doctor', 'dc_faq', 'dc_branch', 'dc_offer' ), true ) ) {
			self::queue();
		}
	}

	public static function on_option( $option ) {
		if ( 0 === strpos( (string) $option, 'dentacare_' ) ) {
			self::queue();
		}
	}

	/** Changes made close together become one sync, two minutes after the last change. */
	public static function queue() {
		if ( ! self::available() || wp_next_scheduled( self::CRON ) ) {
			return;
		}
		wp_schedule_single_event( time() + 120, self::CRON );
	}

	public static function handle_manual() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( Ayla_I18n::t( 'forbidden' ) ) );
		}
		check_admin_referer( 'ayla_sync_site' );

		$ok = self::run();

		wp_safe_redirect( admin_url( 'admin.php?page=' . Ayla_Admin::SETTINGS_SLUG . '&synced=' . ( $ok ? '1' : '0' ) ) );
		exit;
	}

	/** @return bool */
	public static function run() {
		if ( ! self::available() ) {
			return false;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$data   = self::collect();
		$counts = array();
		foreach ( array( 'branches', 'services', 'doctors', 'faqs', 'knowledge' ) as $section ) {
			$counts[ $section ] = count( $data[ $section ] );
		}

		$rounds = (int) max( 1, ceil( max( count( $data['services'] ), count( $data['faqs'] ), count( $data['branches'] ), count( $data['knowledge'] ) ) / self::CHUNK ) );

		for ( $i = 0; $i < $rounds; $i++ ) {
			$body = array();
			if ( 0 === $i ) {
				$body['clinic'] = $data['clinic'];
				$body['bridge'] = Ayla_Bridge::announce();
			}
			foreach ( array( 'services', 'faqs', 'branches', 'knowledge' ) as $section ) {
				$body[ $section ] = array_slice( $data[ $section ], $i * self::CHUNK, self::CHUNK );
			}
			if ( ! self::send( $body, $counts ) ) {
				return false;
			}
		}

		// Doctors go last so the services they point to already exist; the id lists retire what the site no longer has.
		$final = array(
			'doctors' => array_slice( $data['doctors'], 0, self::CHUNK ),
			'keep'    => array(
				'services'  => wp_list_pluck( $data['services'], 'id' ),
				'doctors'   => wp_list_pluck( $data['doctors'], 'id' ),
				'faqs'      => wp_list_pluck( $data['faqs'], 'id' ),
				'branches'  => wp_list_pluck( $data['branches'], 'id' ),
				'knowledge' => self::keep_knowledge( $data['knowledge'] ),
			),
		);

		return self::send( $final, $counts );
	}

	private static function send( array $body, array $counts ) {
		$res = Ayla_Client::site( 'POST', '/sync', array(), $body );

		if ( ! $res['ok'] ) {
			update_option(
				self::OPTION,
				array(
					'at'     => time(),
					'ok'     => false,
					'error'  => isset( $res['error']['message'] ) ? (string) $res['error']['message'] : Ayla_I18n::t( 'error_generic' ),
					'counts' => $counts,
				),
				false
			);

			return false;
		}

		update_option( self::OPTION, array( 'at' => time(), 'ok' => true, 'error' => '', 'counts' => $counts, 'over_limit' => isset( $res['data']['report']['doctors']['over_limit'] ) ? (int) $res['data']['report']['doctors']['over_limit'] : (int) self::status()['over_limit'] ), false );

		return true;
	}

	private static function keep_knowledge( array $items ) {
		$keep = array( 'post' => array(), 'page' => array(), 'offer' => array() );
		foreach ( $items as $item ) {
			$keep[ $item['type'] ][] = $item['id'];
		}

		return $keep;
	}

	/* ------------------------------------------------------------ Collecting */

	/** @return array<string,mixed> */
	public static function collect() {
		return array(
			'clinic'    => self::clinic(),
			'branches'  => self::branches(),
			'services'  => self::services(),
			'doctors'   => self::doctors(),
			'faqs'      => self::faqs(),
			'knowledge' => self::knowledge(),
		);
	}

	private static function plain( $text, $max ) {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		return mb_substr( $text, 0, $max );
	}

	private static function opt( $key ) {
		return function_exists( 'dentacare_opt' ) ? (string) dentacare_opt( $key ) : '';
	}

	private static function clinic() {
		$out = array(
			'address' => self::plain( self::opt( 'address' ), 500 ),
			'phone'   => self::plain( self::opt( 'phone' ), 64 ),
			'email'   => self::plain( self::opt( 'email' ), 160 ),
			'map_url' => self::opt( 'map_url' ),
		);

		if ( function_exists( 'dentacare_clinic_hours' ) ) {
			$out['hours'] = self::hours( dentacare_clinic_hours() );
		}
		$out['days_off'] = self::days_off();

		return $out;
	}

	/** Closed special days in the next 120 days, as dates. */
	private static function days_off() {
		if ( ! function_exists( 'dentacare_days_clean' ) ) {
			return array();
		}
		$today = wp_date( 'Y-m-d' );
		$until = wp_date( 'Y-m-d', time() + 120 * DAY_IN_SECONDS );
		$days  = array();

		foreach ( dentacare_days_clean( (string) get_option( 'dentacare_special_days', '' ) ) as $row ) {
			if ( 'closed' !== $row['kind'] || empty( $row['from'] ) ) {
				continue;
			}
			$to  = ! empty( $row['to'] ) ? $row['to'] : $row['from'];
			$day = max( $row['from'], $today );
			while ( $day <= $to && $day <= $until && count( $days ) < 100 ) {
				$days[] = $day;
				$day    = wp_date( 'Y-m-d', strtotime( $day . ' +1 day' ) );
			}
		}

		return array_values( array_unique( $days ) );
	}

	/** Theme hours {"sat":[["09:00","13:00"]],"fri":[]} as Ayla hours (only open days, sorted). */
	private static function hours( $theme_hours ) {
		$out = array();
		foreach ( (array) $theme_hours as $day => $periods ) {
			if ( ! in_array( $day, array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), true ) || ! is_array( $periods ) || ! $periods ) {
				continue;
			}
			$list = array();
			foreach ( $periods as $p ) {
				if ( is_array( $p ) && isset( $p[0], $p[1] ) ) {
					$list[] = array( (string) $p[0], (string) $p[1] );
				}
			}
			if ( $list ) {
				sort( $list );
				$out[ $day ] = $list;
			}
		}

		return $out;
	}

	private static function branches() {
		$out = array();
		if ( ! function_exists( 'dcb_branches' ) ) {
			return $out;
		}
		foreach ( array_keys( dcb_branches() ) as $id ) {
			$hours = function_exists( 'dentacare_post_hours' ) ? self::hours( dentacare_post_hours( $id ) ) : array();
			$out[] = array(
				'id'            => (string) $id,
				'name'          => self::plain( get_the_title( $id ), 160 ),
				'address'       => self::plain( trim( get_post_meta( $id, 'dc_address', true ) . ' ' . get_post_meta( $id, 'dc_area', true ) ), 500 ),
				'phone'         => self::plain( get_post_meta( $id, 'dc_phone', true ), 64 ),
				'whatsapp'      => preg_replace( '/\D+/', '', (string) get_post_meta( $id, 'dc_whatsapp', true ) ),
				'email'         => self::plain( get_post_meta( $id, 'dc_email', true ), 160 ),
				'map_url'       => (string) get_post_meta( $id, 'dc_map', true ),
				'working_hours' => $hours ? $hours : null,
			);
		}

		return $out;
	}

	private static function services() {
		$out = array();
		if ( ! function_exists( 'dcb_services' ) ) {
			return $out;
		}
		$show_price = ! function_exists( 'dentacare_show_prices' ) || dentacare_show_prices();
		$currency   = function_exists( 'dentacare_currency' ) ? dentacare_currency() : '';

		foreach ( array_keys( dcb_services() ) as $id ) {
			$post = get_post( $id );
			$meta = function_exists( 'dcb_service_meta' ) ? dcb_service_meta( $id ) : array( 'duration_min' => 30 );
			$desc = $post ? self::plain( '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content, 900 ) : '';
			$item = array(
				'id'               => (string) $id,
				'name'             => self::plain( get_the_title( $id ), 160 ),
				'duration_minutes' => (int) $meta['duration_min'],
			);

			if ( $show_price && function_exists( 'dentacare_post_price_fields' ) && function_exists( 'dentacare_price_parts' ) ) {
				$f     = dentacare_post_price_fields( $id );
				$parts = dentacare_price_parts( $f['price'], $f['price_old'], $f['price_type'] );
				if ( null !== $parts['price'] ) {
					$item['price']    = $parts['price'];
					$item['currency'] = $currency;
					if ( $parts['from'] ) {
						$desc = trim( 'Price starts from this amount. ' . $desc );
					}
				} elseif ( 'text' === $parts['state'] ) {
					$desc = trim( 'Price: ' . self::plain( $parts['raw'], 100 ) . '. ' . $desc );
				}
			}

			$item['description'] = $desc;
			$out[]               = $item;
		}

		return $out;
	}

	private static function doctors() {
		$out = array();
		if ( ! function_exists( 'dcb_doctors' ) ) {
			return $out;
		}
		$services = function_exists( 'dcb_services' ) ? array_keys( dcb_services() ) : array();

		foreach ( array_keys( dcb_doctors() ) as $id ) {
			$meta = function_exists( 'dcb_doctor_meta' ) ? dcb_doctor_meta( $id ) : array( 'online' => 1 );
			$svc  = array();
			foreach ( $services as $sid ) {
				if ( function_exists( 'dcb_doctors_for_service' ) && in_array( (int) $id, array_map( 'intval', dcb_doctors_for_service( $sid, 0, false ) ), true ) ) {
					$svc[] = (string) $sid;
				}
			}
			$post  = get_post( $id );
			$hours = self::doctor_hours( $id );
			$out[] = array(
				'id'            => (string) $id,
				'name'          => self::plain( get_the_title( $id ), 160 ),
				'specialty'     => $post ? self::plain( $post->post_excerpt, 160 ) : '',
				'service_ids'   => $svc,
				'working_hours' => $hours ? $hours : null,
				'active'        => ! empty( $meta['online'] ),
			);
		}

		return $out;
	}

	/** Work periods of the doctor (every branch merged) minus breaks, from the booking add-on's schedule. */
	private static function doctor_hours( $doctor_id ) {
		if ( ! function_exists( 'dcb_get_schedule' ) ) {
			return array();
		}
		$days = array( 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun' );
		$work = array();
		$gaps = array();

		foreach ( dcb_get_schedule( 'doctor', $doctor_id, null ) as $row ) {
			$w = (int) $row['weekday'];
			if ( ! isset( $days[ $w ] ) ) {
				continue;
			}
			if ( 'break' === $row['kind'] ) {
				$gaps[ $w ][] = array( (int) $row['start_min'], (int) $row['end_min'] );
			} else {
				$work[ $w ][] = array( (int) $row['start_min'], (int) $row['end_min'] );
			}
		}

		$out = array();
		foreach ( $work as $w => $ranges ) {
			$free = self::subtract( self::merge( $ranges ), isset( $gaps[ $w ] ) ? $gaps[ $w ] : array() );
			foreach ( $free as $r ) {
				$out[ $days[ $w ] ][] = array( self::hhmm( $r[0] ), self::hhmm( min( $r[1], 1439 ) ) );
			}
		}

		return $out;
	}

	private static function merge( array $ranges ) {
		usort( $ranges, static function ( $a, $b ) {
			return $a[0] - $b[0];
		} );
		$out = array();
		foreach ( $ranges as $r ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $r[0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $r[1] );
			} else {
				$out[] = $r;
			}
		}

		return $out;
	}

	private static function subtract( array $ranges, array $gaps ) {
		foreach ( self::merge( $gaps ) as $g ) {
			$next = array();
			foreach ( $ranges as $r ) {
				if ( $g[1] <= $r[0] || $g[0] >= $r[1] ) {
					$next[] = $r;
					continue;
				}
				if ( $g[0] > $r[0] ) {
					$next[] = array( $r[0], $g[0] );
				}
				if ( $g[1] < $r[1] ) {
					$next[] = array( $g[1], $r[1] );
				}
			}
			$ranges = $next;
		}

		return array_values( array_filter( $ranges, static function ( $r ) {
			return $r[1] > $r[0];
		} ) );
	}

	private static function hhmm( $minutes ) {
		return sprintf( '%02d:%02d', floor( $minutes / 60 ), $minutes % 60 );
	}

	private static function faqs() {
		$out   = array();
		$args  = array(
			'post_type'   => 'dc_faq',
			'post_status' => 'publish',
			'numberposts' => 150,
			'orderby'     => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
		);
		if ( function_exists( 'pll_default_language' ) ) {
			$args['lang'] = pll_default_language();
		}
		foreach ( get_posts( $args ) as $p ) {
			$question = self::plain( get_the_title( $p ), 500 );
			$answer   = self::plain( $p->post_content, 4000 );
			if ( '' !== $question && '' !== $answer ) {
				$out[] = array( 'id' => (string) $p->ID, 'question' => $question, 'answer' => $answer, 'sort' => (int) $p->menu_order );
			}
		}

		return $out;
	}

	/** @return string[] language codes to read; one empty entry means "no language plugin". */
	private static function languages() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list();
			if ( is_array( $list ) && $list ) {
				return $list;
			}
		}

		return array( '' );
	}

	private static function knowledge() {
		$out = array();

		foreach ( self::languages() as $lang ) {
			$base = array( 'post_status' => 'publish', 'has_password' => false, 'suppress_filters' => false );
			if ( '' !== $lang ) {
				$base['lang'] = $lang;
			}

			$sets = array(
				array( 'post', 'post', '', 150 ),
				array( 'page', 'page', '', 80 ),
				array( 'dc_treatment', 'page', 't', 100 ),
				array( 'dc_doctor', 'page', 'd', 60 ),
			);
			foreach ( $sets as $set ) {
				$posts = get_posts( $base + array( 'post_type' => $set[0], 'numberposts' => $set[3], 'orderby' => 'date', 'order' => 'DESC' ) );
				foreach ( $posts as $p ) {
					$content = self::plain( $p->post_content, 20000 );
					if ( '' === $content && '' === trim( $p->post_excerpt ) ) {
						continue;
					}
					$cats = 'post' === $set[0] ? get_the_category( $p->ID ) : array();
					$out[] = array(
						'type'         => $set[1],
						'id'           => $set[2] . $p->ID,
						'lang'         => $lang,
						'title'        => self::plain( get_the_title( $p ), 300 ),
						'summary'      => self::plain( $p->post_excerpt, 600 ),
						'content'      => $content,
						'url'          => (string) get_permalink( $p ),
						'category'     => $cats ? self::plain( $cats[0]->name, 160 ) : '',
						'published_at' => mysql2date( 'c', $p->post_date_gmt . ' +0000', false ),
						'updated_at'   => mysql2date( 'c', $p->post_modified_gmt . ' +0000', false ),
					);
				}
			}

			$out = array_merge( $out, self::offers( $base ) );
		}

		return $out;
	}

	private static function offers( array $base ) {
		$out   = array();
		$today = wp_date( 'Y-m-d' );
		$posts = get_posts( $base + array( 'post_type' => 'dc_offer', 'numberposts' => 40, 'orderby' => 'date', 'order' => 'DESC' ) );

		foreach ( $posts as $p ) {
			$end   = (string) get_post_meta( $p->ID, 'dc_offer_expiry', true );
			$start = (string) get_post_meta( $p->ID, 'dc_offer_start', true );
			if ( ( '' !== $end && $end < $today ) || ( '' !== $start && $start > $today ) ) {
				continue;
			}

			$text = self::plain( $p->post_content, 3000 );
			$inc  = trim( (string) get_post_meta( $p->ID, 'dc_offer_includes', true ) );
			$terms = trim( (string) get_post_meta( $p->ID, 'dc_offer_terms', true ) );
			$price = trim( (string) get_post_meta( $p->ID, 'dc_price', true ) );
			$parts = array_filter( array(
				$text,
				'' !== $price ? 'Price: ' . self::plain( $price, 60 ) : '',
				'' !== $inc ? 'Includes: ' . self::plain( str_replace( "\n", ', ', $inc ), 600 ) : '',
				'' !== $terms ? 'Terms: ' . self::plain( $terms, 600 ) : '',
			) );

			$expires = '';
			if ( '' !== $end ) {
				try {
					$expires = ( new DateTime( $end . ' 23:59:59', wp_timezone() ) )->format( 'c' );
				} catch ( Exception $e ) {
					$expires = '';
				}
			}

			$out[] = array(
				'type'       => 'offer',
				'id'         => (string) $p->ID,
				'lang'       => isset( $base['lang'] ) ? $base['lang'] : '',
				'title'      => self::plain( get_the_title( $p ), 300 ),
				'summary'    => self::plain( '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : $text, 600 ),
				'content'    => implode( ' ', $parts ),
				'url'        => '',
				'updated_at' => mysql2date( 'c', $p->post_modified_gmt . ' +0000', false ),
				'expires_at' => $expires,
			);
		}

		return $out;
	}
}
