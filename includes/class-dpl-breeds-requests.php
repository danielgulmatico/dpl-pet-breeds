<?php
/**
 * Add-breed requests.
 *
 * Replaces the free-text escape hatch. Nothing here writes to the breed table directly; a
 * request has to be approved. That approval step is the whole anti-regrowth mechanism.
 *
 * Since 2.7 a request remembers where it came from. Until then a row held the first pet and
 * user only, and the admin page showed neither, so "Testbreed Notarealdog x3" gave the person
 * approving it nothing to go on. Now every occasion the string is asked for is appended to
 * `sources` - who, which pet, which order, which screen - and the Requests page shows them.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Requests {

	/** Occasions kept per request, newest last. The first requester is also in pet_id/user_id. */
	const MAX_SOURCES = 50;

	/** The ids a caller can attach to a request. Anything else in the context is ignored. */
	const CONTEXT_IDS = array( 'user_id', 'pet_id', 'order_id', 'prescription_id' );

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . DPL_BREEDS_REQUEST_TABLE;
	}

	/**
	 * Coerce whatever the pets table calls a species into a vocabulary species key.
	 *
	 * wp_vpp_pets.species is free-ish text: 'dog', 'Dog', 'Canine', 'Canine (Dog)' all occur.
	 * Storing it raw had two consequences - the same breed requested as 'Dog' and 'dog' made
	 * two rows that never deduped, and approve() would then insert a breed under a species no
	 * picker asks for, so it existed but was unreachable.
	 *
	 * Returns '' rather than guessing when nothing matches. An unscoped request is reviewable;
	 * a request filed under 'Canine (Dog)' is not.
	 *
	 * Now a thin wrapper: the implementation lives in DPL_Breeds_Repository::species_key(),
	 * because every consumer writing a breed needs the same answer.
	 */
	private static function normalise_species( $species ) {
		return DPL_Breeds_Repository::species_key( $species );
	}

	/**
	 * One occasion a string was asked for, from the caller's context. Pure: no database.
	 *
	 * Ids are kept when they are positive integers; `source` names the screen ('my-pets',
	 * 'staff-pet', 'prescription', 'scanner' - whatever the caller calls itself, as a key). The
	 * current user is recorded when the caller gave none, because the member of staff or the
	 * customer saving the pet is who asked.
	 *
	 * @param array $context As passed to dpl_breed_request() / dpl_breed_canonicalise().
	 * @return array {at, user_id?, pet_id?, order_id?, prescription_id?, source?}
	 */
	public static function occasion( array $context ) {
		$o = array( 'at' => current_time( 'mysql' ) );
		foreach ( self::CONTEXT_IDS as $k ) {
			if ( isset( $context[ $k ] ) && (int) $context[ $k ] > 0 ) {
				$o[ $k ] = (int) $context[ $k ];
			}
		}
		if ( empty( $o['user_id'] ) && (int) get_current_user_id() > 0 ) {
			$o['user_id'] = (int) get_current_user_id();
		}
		if ( ! empty( $context['source'] ) && is_string( $context['source'] ) ) {
			$s = substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $context['source'] ) ), 0, 32 );
			if ( '' !== $s ) {
				$o['source'] = $s;
			}
		}
		return $o;
	}

	/**
	 * The occasions stored on a row, oldest first. Pure.
	 *
	 * @param string|null $json The `sources` column.
	 * @return array[]
	 */
	public static function sources( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$list = json_decode( $json, true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/** Append an occasion, keeping the newest MAX_SOURCES. Pure. */
	public static function with_occasion( $json, array $occasion ) {
		$list   = self::sources( $json );
		$list[] = $occasion;
		if ( count( $list ) > self::MAX_SOURCES ) {
			$list = array_slice( $list, -self::MAX_SOURCES );
		}
		return wp_json_encode( $list );
	}

	/**
	 * Declined strings people keep asking for.
	 *
	 * Repeated demand for something an admin rejected is the signal that the rejection was
	 * wrong. Without this the count accumulates where nobody looks.
	 *
	 * @param int $min Minimum request_count to report.
	 */
	public static function declined_but_wanted( $min = 3 ) {
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `$t` WHERE status = 'rejected' AND request_count >= %d
			 ORDER BY request_count DESC",
			(int) $min
		), ARRAY_A );
	}

	/**
	 * File a request, or return a suggestion instead.
	 *
	 * @param array $context pet_id, user_id, order_id, prescription_id, source - see occasion().
	 * @return array {created:bool, request_id:int, suggestion:array|null, message:string}
	 */
	public static function create( $raw, $species = '', array $context = array() ) {
		global $wpdb;

		$raw  = trim( (string) $raw );
		$norm = DPL_Breeds_Repository::normalise( $raw );
		if ( '' === $norm ) {
			return array( 'created' => false, 'request_id' => 0, 'suggestion' => null,
				'message' => 'Empty breed.' );
		}

		// Do this before anything reads $species: resolution, dedupe and the stored row must
		// all agree on one spelling of the species.
		$species = self::normalise_species( $species );

		// Already known? Then there is nothing to request.
		$resolved = DPL_Breeds_Resolver::resolve( $raw, $species );
		if ( 'resolved' === $resolved['status'] ) {
			// API 2.0: a resolved mixed breed has no breed at all. That is an answer, not a
			// gap - "moggy" is fully understood - so it must not fall through to a request.
			$message = empty( $resolved['breeds'] ) && ! empty( $resolved['cross'] )
				? 'Recorded as a mixed breed.'
				: 'That breed is already in the list.';
			return array( 'created' => false, 'request_id' => 0,
				'suggestion' => $resolved['breeds'][0] ?? null,
				'message'    => $message );
		}

		// Most requests are misspellings. Offer the suggestion instead of creating a row.
		if ( 'review' === $resolved['status'] && ! empty( $resolved['breeds'] ) ) {
			return array( 'created' => false, 'request_id' => 0,
				'suggestion' => $resolved['breeds'][0],
				'message'    => 'Did you mean ' . $resolved['breeds'][0]['label'] . '?' );
		}

		$occasion = self::occasion( $context );

		// Dedupe: five people asking for the same breed is one row with a count of five.
		//
		// Status matters here. The original query ignored it, so a REJECTED row matched and
		// absorbed the count - meaning once an admin declined a string, every later request
		// for it was silently swallowed and never reappeared in the queue. Declining "Good"
		// should stop it cluttering the queue; it should not make a genuine breed
		// unrequestable forever, and it should not hide the fact that people keep asking.
		$t        = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, request_count, status, sources FROM `$t` WHERE species = %s AND normalised = %s
			 ORDER BY FIELD(status,'pending','rejected','approved'), id ASC LIMIT 1",
			$species, $norm
		), ARRAY_A );

		if ( $existing && 'pending' === $existing['status'] ) {
			$wpdb->update( $t,
				array(
					'request_count' => (int) $existing['request_count'] + 1,
					'sources'       => self::with_occasion( $existing['sources'] ?? '', $occasion ),
				),
				array( 'id' => (int) $existing['id'] )
			);
			return array( 'created' => false, 'request_id' => (int) $existing['id'],
				'suggestion' => null, 'message' => 'Already requested; your request was noted.' );
		}

		if ( $existing && 'rejected' === $existing['status'] ) {
			// Keep counting so repeated demand is visible - declined_but_wanted() surfaces it -
			// but do not silently reopen a decision an admin already made.
			$wpdb->update( $t,
				array(
					'request_count' => (int) $existing['request_count'] + 1,
					'sources'       => self::with_occasion( $existing['sources'] ?? '', $occasion ),
				),
				array( 'id' => (int) $existing['id'] )
			);
			return array( 'created' => false, 'request_id' => (int) $existing['id'],
				'suggestion' => null, 'message' => 'That breed was reviewed and not added.' );
		}

		$wpdb->insert( $t, array(
			'species'       => $species,
			'requested'     => $raw,
			'normalised'    => $norm,
			'suggestion'    => '',
			'confidence'    => 0,
			'request_count' => 1,
			'pet_id'        => $occasion['pet_id'] ?? null,
			'user_id'       => $occasion['user_id'] ?? null,
			'sources'       => self::with_occasion( '', $occasion ),
			'status'        => 'pending',
			'created_date'  => $occasion['at'],
		) );
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires when a new add-breed request is created.
		 *
		 * @param int   $id
		 * @param array $data species, requested, normalised, and (2.7) the occasion: who asked,
		 *                    from which pet, order and screen.
		 */
		do_action( 'dpl_breed_request_created', $id, array(
			'species' => $species, 'requested' => $raw, 'normalised' => $norm,
		) + $occasion );

		return array( 'created' => true, 'request_id' => $id, 'suggestion' => null,
			'message' => 'Thanks - we will review this breed.' );
	}

	/**
	 * The queue, most asked-for first. Each row carries `occasions`: sources() decoded.
	 */
	public static function pending() {
		global $wpdb;
		$t    = self::table();
		$rows = (array) $wpdb->get_results(
			"SELECT * FROM `$t` WHERE status = 'pending' ORDER BY request_count DESC, created_date ASC",
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['occasions'] = self::sources( $r['sources'] ?? '' );
		}
		return $rows;
	}

	/**
	 * Approve a request: add the breed, then alias the requested string to it so the same
	 * wording resolves next time without another request.
	 *
	 * The guards against a bin split (a duplicate label, a label the map shadows) live in
	 * DPL_Breeds_Vocabulary::add_breed(), shared with the admin's Add a breed form.
	 */
	public static function approve( $id, $label = '', $species = '' ) {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$t` WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Request not found.' );
		}

		$species = self::normalise_species( $species ?: $row['species'] );
		$label   = trim( $label ) ?: trim( $row['requested'] );
		if ( '' === $species ) {
			return new WP_Error( 'invalid', 'A species is required.' );
		}

		$breed_id = DPL_Breeds_Vocabulary::add_breed( $species, $label, array( $row['normalised'] ), 'request' );
		if ( is_wp_error( $breed_id ) ) {
			return $breed_id;
		}

		$wpdb->update( $t, array( 'status' => 'approved' ), array( 'id' => (int) $id ) );

		do_action( 'dpl_breed_request_approved', (int) $id, (int) $breed_id );
		return (int) $breed_id;
	}

	public static function reject( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'rejected' ), array( 'id' => (int) $id ) );
		return true;
	}

	/** One line saying where a request came from, for an email. */
	public static function describe_occasion( array $o ) {
		$bits = array();
		if ( ! empty( $o['user_id'] ) ) {
			$bits[] = 'user #' . (int) $o['user_id'];
		}
		if ( ! empty( $o['pet_id'] ) ) {
			$bits[] = 'pet #' . (int) $o['pet_id'];
		}
		if ( ! empty( $o['order_id'] ) ) {
			$bits[] = 'order #' . (int) $o['order_id'];
		}
		if ( ! empty( $o['prescription_id'] ) ) {
			$bits[] = 'prescription #' . (int) $o['prescription_id'];
		}
		if ( ! empty( $o['source'] ) ) {
			$bits[] = 'via ' . $o['source'];
		}
		if ( ! empty( $o['at'] ) ) {
			$bits[] = $o['at'];
		}
		return implode( ', ', $bits );
	}

	/** One digest a day, not one email per request. */
	public static function send_digest() {
		$pending = self::pending();
		if ( empty( $pending ) ) {
			return;
		}
		$to = apply_filters( 'dpl_breed_request_digest_recipients', array( get_option( 'admin_email' ) ) );
		if ( empty( $to ) ) {
			return;
		}

		$lines = array( 'Pending breed requests:', '' );
		foreach ( array_slice( $pending, 0, 50 ) as $r ) {
			$lines[] = sprintf(
				'  %s  x%d  (%s)%s',
				$r['requested'],
				(int) $r['request_count'],
				$r['species'] ?: 'no species',
				$r['suggestion'] ? '  - possible match: ' . $r['suggestion'] : ''
			);
			$last = end( $r['occasions'] );
			if ( $last ) {
				$lines[] = '      last asked: ' . self::describe_occasion( $last );
			}
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=dpl-breeds-requests' );

		wp_mail(
			$to,
			sprintf( '[%s] %d pending breed request(s)', get_bloginfo( 'name' ), count( $pending ) ),
			implode( "\n", $lines )
		);
	}
}
