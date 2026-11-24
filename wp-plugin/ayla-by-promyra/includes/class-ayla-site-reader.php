<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads what the website already publishes, so the owner does not have to type it again: contact details,
 * opening hours, the "about" text and FAQs. It works on any WordPress site and uses only public data:
 * schema.org JSON-LD printed in the pages (Yoast, Rank Math and most themes do this), FAQ post types and the
 * FAQ blocks of Yoast, Rank Math and the core Details block.
 *
 * Nothing is saved from here. The dashboard shows the result and the owner reviews it before saving.
 */
class Ayla_Site_Reader {

	const MAX_PAGES = 8;
	const MAX_FAQS  = 100;

	/** schema.org and the two-letter forms used by "openingHours" => our day keys. */
	private static $days = array(
		'monday' => 'mon', 'tuesday' => 'tue', 'wednesday' => 'wed', 'thursday' => 'thu', 'friday' => 'fri', 'saturday' => 'sat', 'sunday' => 'sun',
		'mo' => 'mon', 'tu' => 'tue', 'we' => 'wed', 'th' => 'thu', 'fr' => 'fri', 'sa' => 'sat', 'su' => 'sun',
	);

	private static $order = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	public static function register() {
		add_action( 'wp_ajax_ayla_site_scan', array( __CLASS__, 'ajax' ) );
	}

	public static function ajax() {
		check_ajax_referer( 'ayla_proxy', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => Ayla_I18n::t( 'forbidden' ) ), 403 );
		}

		wp_send_json_success( self::scan() );
	}

	/** @return array{business:array,hours:array,faqs:array,scanned:int} */
	public static function scan() {
		$business = array(
			'name'    => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
			'website' => esc_url_raw( home_url( '/' ) ),
			'email'   => '',
			'phone'   => '',
			'about'   => '',
		);
		$hours    = array();
		$faqs     = array();
		$scanned  = 0;

		foreach ( self::pages() as $url ) {
			$html = self::fetch( $url );
			if ( '' === $html ) {
				continue;
			}
			++$scanned;

			$parsed = self::parse_html( $html );
			foreach ( array( 'email', 'phone' ) as $key ) {
				if ( '' === $business[ $key ] && '' !== $parsed[ $key ] ) {
					$business[ $key ] = $parsed[ $key ];
				}
			}
			if ( ! $hours && $parsed['hours'] ) {
				$hours = $parsed['hours'];
			}
			$faqs = self::merge_faqs( $faqs, $parsed['faqs'] );
		}

		$business['about'] = self::about();
		$faqs              = self::merge_faqs( $faqs, self::faq_posts() );
		$faqs              = self::merge_faqs( $faqs, self::faq_blocks() );

		return array(
			'business' => $business,
			'hours'    => $hours,
			'faqs'     => array_slice( $faqs, 0, self::MAX_FAQS ),
			'scanned'  => $scanned,
		);
	}

	/**
	 * Everything the pure parsers can find in one page: JSON-LD first, then plain mailto: and tel: links.
	 *
	 * @return array{email:string,phone:string,hours:array,faqs:array}
	 */
	public static function parse_html( $html ) {
		$out = array( 'email' => '', 'phone' => '', 'hours' => array(), 'faqs' => array() );

		foreach ( self::json_ld( $html ) as $node ) {
			self::read_node( $node, $out );
		}

		if ( '' === $out['email'] && preg_match( '#href=["\']mailto:([^"\'?\s]+)#i', $html, $m ) && is_email( rawurldecode( $m[1] ) ) ) {
			$out['email'] = sanitize_email( rawurldecode( $m[1] ) );
		}
		if ( '' === $out['phone'] && preg_match( '#href=["\']tel:([^"\']+)#i', $html, $m ) ) {
			$out['phone'] = self::clean_phone( rawurldecode( $m[1] ) );
		}

		return $out;
	}

	/** @return list<array> every decoded JSON-LD block of a page */
	public static function json_ld( $html ) {
		$nodes = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $blocks ) ) {
			return $nodes;
		}

		foreach ( $blocks[1] as $raw ) {
			$data = json_decode( trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) ), true );
			if ( is_array( $data ) ) {
				$nodes[] = $data;
			}
		}

		return $nodes;
	}

	/** Walks a JSON-LD structure (lists and @graph included) and collects what we know how to use. */
	private static function read_node( $node, array &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
			foreach ( $node['@graph'] as $item ) {
				self::read_node( $item, $out );
			}
		}

		if ( array_keys( $node ) === range( 0, count( $node ) - 1 ) ) {
			foreach ( $node as $item ) {
				self::read_node( $item, $out );
			}

			return;
		}

		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();

		if ( in_array( 'FAQPage', $types, true ) && ! empty( $node['mainEntity'] ) ) {
			$entities = isset( $node['mainEntity']['@type'] ) ? array( $node['mainEntity'] ) : (array) $node['mainEntity'];
			foreach ( $entities as $q ) {
				$item = self::faq_from_question( $q );
				if ( $item ) {
					$out['faqs'] = self::merge_faqs( $out['faqs'], array( $item ) );
				}
			}
		}

		if ( '' === $out['phone'] && ! empty( $node['telephone'] ) && is_string( $node['telephone'] ) ) {
			$out['phone'] = self::clean_phone( $node['telephone'] );
		}
		if ( '' === $out['email'] && ! empty( $node['email'] ) && is_string( $node['email'] ) && is_email( preg_replace( '#^mailto:#i', '', $node['email'] ) ) ) {
			$out['email'] = sanitize_email( preg_replace( '#^mailto:#i', '', $node['email'] ) );
		}

		if ( ! $out['hours'] ) {
			$hours = self::hours_from_node( $node );
			if ( $hours ) {
				$out['hours'] = $hours;
			}
		}

		foreach ( array( 'contactPoint', 'location', 'department', 'parentOrganization' ) as $key ) {
			if ( isset( $node[ $key ] ) ) {
				self::read_node( $node[ $key ], $out );
			}
		}
	}

	private static function faq_from_question( $q ) {
		if ( ! is_array( $q ) || empty( $q['name'] ) ) {
			return null;
		}

		$answer = isset( $q['acceptedAnswer'] ) ? $q['acceptedAnswer'] : ( isset( $q['suggestedAnswer'] ) ? $q['suggestedAnswer'] : null );
		if ( is_array( $answer ) && isset( $answer[0] ) ) {
			$answer = $answer[0];
		}
		$text = is_array( $answer ) && isset( $answer['text'] ) ? $answer['text'] : '';

		return self::faq( (string) $q['name'], (string) $text );
	}

	/** @return array<string,list<array{0:string,1:string}>> */
	public static function hours_from_node( array $node ) {
		$hours = array();

		if ( ! empty( $node['openingHoursSpecification'] ) ) {
			$specs = isset( $node['openingHoursSpecification']['@type'] ) ? array( $node['openingHoursSpecification'] ) : (array) $node['openingHoursSpecification'];
			foreach ( $specs as $spec ) {
				if ( ! is_array( $spec ) || empty( $spec['opens'] ) || empty( $spec['closes'] ) ) {
					continue;
				}
				foreach ( (array) ( isset( $spec['dayOfWeek'] ) ? $spec['dayOfWeek'] : array() ) as $name ) {
					$key = self::day_key( (string) $name );
					if ( $key ) {
						self::add_range( $hours, $key, (string) $spec['opens'], (string) $spec['closes'] );
					}
				}
			}
		}

		if ( ! $hours && ! empty( $node['openingHours'] ) ) {
			foreach ( (array) $node['openingHours'] as $line ) {
				self::read_hours_line( (string) $line, $hours );
			}
		}

		return self::tidy( $hours );
	}

	/** "Mo-Fr 09:00-17:00", "Sa,Su 10:00-14:00" or "Mo 09:00-12:00,14:00-18:00". */
	private static function read_hours_line( $line, array &$hours ) {
		if ( ! preg_match( '#^\s*([A-Za-z,\-\s]+?)\s+(\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}(?:\s*,\s*\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2})*)\s*$#', $line, $m ) ) {
			return;
		}

		$days = array();
		foreach ( explode( ',', $m[1] ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '#^([A-Za-z]{2})\s*-\s*([A-Za-z]{2})$#', $part, $r ) ) {
				$from = array_search( self::day_key( $r[1] ), self::$order, true );
				$to   = array_search( self::day_key( $r[2] ), self::$order, true );
				if ( false === $from || false === $to ) {
					continue;
				}
				for ( $i = $from, $guard = 0; $guard < 7; $i = ( $i + 1 ) % 7, ++$guard ) {
					$days[] = self::$order[ $i ];
					if ( $i === $to ) {
						break;
					}
				}
			} elseif ( self::day_key( $part ) ) {
				$days[] = self::day_key( $part );
			}
		}

		foreach ( array_unique( $days ) as $day ) {
			foreach ( explode( ',', $m[2] ) as $range ) {
				$times = array_map( 'trim', explode( '-', $range ) );
				if ( 2 === count( $times ) ) {
					self::add_range( $hours, $day, $times[0], $times[1] );
				}
			}
		}
	}

	private static function day_key( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '#^.*/#', '', $name );

		return isset( self::$days[ $name ] ) ? self::$days[ $name ] : '';
	}

	private static function time( $value ) {
		if ( ! preg_match( '#^\s*(\d{1,2}):(\d{2})#', (string) $value, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return '';
		}

		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	private static function add_range( array &$hours, $day, $opens, $closes ) {
		// Open until midnight is written "24:00"; the stored format stops at 23:59.
		$from = self::time( 0 === strpos( trim( (string) $opens ), '24:' ) ? '23:59' : $opens );
		$to   = self::time( 0 === strpos( trim( (string) $closes ), '24:' ) ? '23:59' : $closes );

		// Overnight shifts cannot be stored (the end must be after the start), so they are skipped.
		if ( '' === $from || '' === $to || $to <= $from ) {
			return;
		}

		$hours[ $day ][] = array( $from, $to );
	}

	/** Sorted, de-duplicated, at most two periods a day, in week order. */
	private static function tidy( array $hours ) {
		$out = array();
		foreach ( self::$order as $day ) {
			if ( empty( $hours[ $day ] ) ) {
				continue;
			}
			$ranges = array_map( 'unserialize', array_unique( array_map( 'serialize', $hours[ $day ] ) ) );
			usort( $ranges, static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			} );

			$kept = array();
			foreach ( $ranges as $range ) {
				if ( $kept && $range[0] < end( $kept )[1] ) {
					continue;
				}
				$kept[] = $range;
			}
			$out[ $day ] = array_slice( $kept, 0, 2 );
		}

		return $out;
	}

	private static function clean_phone( $value ) {
		$value = trim( preg_replace( '#[^0-9+()\-\s]#', '', (string) $value ) );

		return strlen( preg_replace( '#\D#', '', $value ) ) >= 6 ? mb_substr( $value, 0, 32 ) : '';
	}

	private static function faq( $question, $answer ) {
		$question = trim( preg_replace( '#\s+#u', ' ', wp_strip_all_tags( html_entity_decode( (string) $question, ENT_QUOTES, 'UTF-8' ) ) ) );
		$answer   = trim( preg_replace( "#[ \t]+#", ' ', wp_strip_all_tags( preg_replace( '#<\s*br\s*/?>|</p>#i', "\n", html_entity_decode( (string) $answer, ENT_QUOTES, 'UTF-8' ) ) ) ) );
		$answer   = trim( preg_replace( "#\n{3,}#", "\n\n", $answer ) );

		if ( '' === $question || '' === $answer ) {
			return null;
		}

		return array( 'question' => mb_substr( $question, 0, 500 ), 'answer' => mb_substr( $answer, 0, 2000 ) );
	}

	/** @param list<array> $into @param list<array> $more */
	public static function merge_faqs( array $into, array $more ) {
		$seen = array();
		foreach ( $into as $item ) {
			$seen[ mb_strtolower( $item['question'] ) ] = true;
		}
		foreach ( $more as $item ) {
			if ( ! $item || isset( $seen[ mb_strtolower( $item['question'] ) ] ) ) {
				continue;
			}
			$seen[ mb_strtolower( $item['question'] ) ] = true;
			$into[]                                      = $item;
		}

		return $into;
	}

	/** The home page plus the About, Contact and FAQ pages, so the scan stays small. */
	private static function pages() {
		$urls = array( home_url( '/' ) );

		$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids', 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
		foreach ( $ids as $id ) {
			$hint = rawurldecode( (string) get_post_field( 'post_name', $id ) ) . ' ' . get_the_title( $id );
			if ( preg_match( '#about|contact|faq|question|hours|who-we|من نحن|من-نحن|عن |اتصل|تواصل|الأسئلة|اسئلة|أسئلة|عنا#iu', $hint ) ) {
				$urls[] = get_permalink( $id );
			}
		}

		return array_slice( array_values( array_unique( array_filter( $urls ) ) ), 0, self::MAX_PAGES );
	}

	private static function fetch( $url ) {
		// Same-site requests: a local or self-signed certificate must not stop the scan.
		$res = wp_remote_get( $url, array(
			'timeout'             => 8,
			'redirection'         => 3,
			'limit_response_size' => 700000,
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'          => 'AylaSiteReader/' . AYLA_VERSION,
		) );

		return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ? (string) wp_remote_retrieve_body( $res ) : '';
	}

	/** Read visible text from a published same-site page, including content rendered from public custom fields. */
	public static function read_public_content( $url ) {
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$page_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $home_host || $home_host !== $page_host ) {
			return '';
		}

		$response = wp_safe_remote_get( $url, array(
			'timeout'             => 8,
			'redirection'         => 0,
			'limit_response_size' => 700000,
			'user-agent'          => 'AylaSiteReader/' . AYLA_VERSION,
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			$has_main = preg_match( '#<main\b[^>]*>(.*?)</main>#is', $html, $match );
			$content  = $has_main ? $match[1] : $html;

			return self::visible_text( $content, ! $has_main );
		}

		$previous_errors = libxml_use_internal_errors( true );
		$document        = new DOMDocument();
		$loaded          = $document->loadHTML( $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		if ( ! $loaded ) {
			return '';
		}

		$xpath = new DOMXPath( $document );
		$root  = $xpath->query( '//main | //*[@role="main"]' )->item( 0 );
		if ( ! $root ) {
			$root = $document->getElementsByTagName( 'body' )->item( 0 );
		}
		if ( ! $root ) {
			return '';
		}

		$remove_query = './/script | .//style | .//noscript | .//svg | .//nav | .//footer | .//aside | .//form | .//*[@hidden] | .//*[@aria-hidden="true"]';
		if ( 'body' === strtolower( $root->nodeName ) ) {
			$remove_query .= ' | .//header';
		}
		$remove = $xpath->query( $remove_query, $root );
		for ( $i = $remove->length - 1; $i >= 0; --$i ) {
			$node = $remove->item( $i );
			if ( $node && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		return self::visible_text( $document->saveHTML( $root ) );
	}

	private static function visible_text( $content, $whole_document = false ) {
		$removed = 'head|script|style|noscript|nav|footer|aside|form|svg';
		if ( $whole_document ) {
			$removed .= '|header';
		}
		$content = preg_replace( '#<(' . $removed . ')\b[^>]*>.*?</\1>#is', ' ', $content );
		$content = preg_replace( '#<\s*br\b[^>]*>#i', "\n", $content );
		$content = preg_replace( '#</\s*(p|h[1-6]|li|div|section|article|tr|dt|dd|ul|ol)>#i', "\n", $content );
		$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content = preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $content );
		$content = preg_replace( '/[ \t]+/u', ' ', $content );
		$content = preg_replace( '/[ \t]*\n[ \t]*/u', "\n", $content );
		$content = trim( preg_replace( '/\n{3,}/u', "\n\n", $content ) );

		return mb_substr( $content, 0, 20000 );
	}

	private static function about() {
		foreach ( array( 'about', 'about-us', 'about-company', 'who-we-are', 'من-نحن', 'عن-الشركة', 'عنا', 'نبذة' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$text = $page->post_excerpt ? $page->post_excerpt : $page->post_content;
				$text = trim( preg_replace( '#\s+#u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $text ) ) ) );
				if ( '' !== $text ) {
					return mb_substr( $text, 0, 600 );
				}
			}
		}

		return sanitize_text_field( (string) get_bloginfo( 'description' ) );
	}

	/** FAQ custom post types: the title is the question and the content is the answer. */
	private static function faq_posts() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( ! preg_match( '#faq|question#i', $type ) ) {
				continue;
			}
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => self::MAX_FAQS, 'orderby' => 'menu_order date', 'order' => 'ASC' ) ) as $post ) {
				$item = self::faq( $post->post_title, strip_shortcodes( (string) $post->post_content ) );
				if ( $item ) {
					$out[] = $item;
				}
			}
		}

		return $out;
	}

	/** FAQ blocks of Yoast and Rank Math, and the core Details block, found in pages and posts. */
	private static function faq_blocks() {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND (post_content LIKE '%yoast/faq-block%' OR post_content LIKE '%rank-math/faq-block%' OR post_content LIKE '%wp:details%') LIMIT 40" // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				self::read_blocks( parse_blocks( (string) $post->post_content ), $out );
			}
		}

		return $out;
	}

	private static function read_blocks( array $blocks, array &$out ) {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( 'yoast/faq-block' === $name ) {
				foreach ( isset( $attrs['questions'] ) ? (array) $attrs['questions'] : array() as $q ) {
					$item = self::faq( isset( $q['jsonQuestion'] ) ? $q['jsonQuestion'] : '', isset( $q['jsonAnswer'] ) ? $q['jsonAnswer'] : '' );
					if ( $item ) {
						$out[] = $item;
					}
				}
			} elseif ( 'rank-math/faq-block' === $name ) {
				foreach ( isset( $attrs['questions'] ) ? (array) $attrs['questions'] : array() as $q ) {
					$item = self::faq( isset( $q['title'] ) ? $q['title'] : '', isset( $q['content'] ) ? $q['content'] : '' );
					if ( $item ) {
						$out[] = $item;
					}
				}
			} elseif ( 'core/details' === $name && preg_match( '#<summary[^>]*>(.*?)</summary>#is', (string) $block['innerHTML'], $m ) ) {
				$answer = '';
				foreach ( (array) $block['innerBlocks'] as $inner ) {
					$answer .= ' ' . ( isset( $inner['innerHTML'] ) ? $inner['innerHTML'] : '' );
				}
				$item = self::faq( $m[1], $answer );
				if ( $item ) {
					$out[] = $item;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && 'core/details' !== $name ) {
				self::read_blocks( (array) $block['innerBlocks'], $out );
			}
		}
	}
}
