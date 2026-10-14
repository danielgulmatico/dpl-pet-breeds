<?php
/**
 * Add-breed requests.
 *
 * Replaces the free-text escape hatch. Nothing here writes to the breed table directly; a
 * request has to be approved. That approval step is the whole anti-regrowth mechanism.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Requests {

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

		// Dedupe: five people asking for the same breed is one row with a count of five.
		//
		// Status matters here. The original query ignored it, so a REJECTED row matched and
		// absorbed the count - meaning once an admin declined a string, every later request
		// for it was silently swallowed and never reappeared in the queue. Declining "Good"
		// should stop it cluttering the queue; it should not make a genuine breed
		// unrequestable forever, and it should not hide the fact that people keep asking.
		$t        = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, request_count, status FROM `$t` WHERE species = %s AND normalised = %s
			 ORDER BY FIELD(status,'pending','rejected','approved'), id ASC LIMIT 1",
			$species, $norm
		), ARRAY_A );

		if ( $existing && 'pending' === $existing['status'] ) {
			$wpdb->update( $t,
				array( 'request_count' => (int) $existing['request_count'] + 1 ),
				array( 'id' => (int) $existing['id'] )
			);
			return array( 'created' => false, 'request_id' => (int) $existing['id'],
				'suggestion' => null, 'message' => 'Already requested; your request was noted.' );
		}

		if ( $existing && 'rejected' === $existing['status'] ) {
			// Keep counting so repeated demand is visible - declined_but_wanted() surfaces it -
			// but do not silently reopen a decision an admin already made.
			$wpdb->update( $t,
				array( 'request_count' => (int) $existing['request_count'] + 1 ),
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
			'pet_id'        => isset( $context['pet_id'] ) ? (int) $context['pet_id'] : null,
			'user_id'       => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
			'status'        => 'pending',
			'created_date'  => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;

		/**
		 * Fires when a new add-breed request is created.
		 *
		 * @param int   $id
		 * @param array $data
		 */
		do_action( 'dpl_breed_request_created', $id, array(
			'species' => $species, 'requested' => $raw, 'normalised' => $norm,
		) );

		return array( 'created' => true, 'request_id' => $id, 'suggestion' => null,
			'message' => 'Thanks - we will review this breed.' );
	}

	public static function pending() {
		global $wpdb;
		$t = self::table();
		return (array) $wpdb->get_results(
			"SELECT * FROM `$t` WHERE status = 'pending' ORDER BY request_count DESC, created_date ASC",
			ARRAY_A
		);
	}

	/**
	 * Approve a request: add the breed, then alias the requested string to it so the same
	 * wording resolves next time without another request.
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
		$slug    = DPL_Breeds_Repository::slugify( $label );
		if ( '' === $slug || '' === $species ) {
			return new WP_Error( 'invalid', 'A species and a recognised label are required.' );
		}

		// Refuse to create a bin split.
		//
		// Two ways approving a request can quietly damage the vocabulary, both of which we hit
		// for real on 2026-09-02 and spent the evening unpicking:
		//
		//   1. the label duplicates one that already exists under a different slug, so the
		//      picker offers two labels for one breed
		//   2. the label is a reviewed map key pointing somewhere else, so the map (rung 1)
		//      beats it and the new breed can never resolve to itself - it would sit in the
		//      picker silently becoming something else
		//
		// Better to refuse and make the admin pick a distinct name than to add a breed that
		// does not work.
		$norm_label = DPL_Breeds_Repository::normalise( $label );

		$clash = $wpdb->get_var( $wpdb->prepare(
			"SELECT slug FROM `{$wpdb->prefix}" . DPL_BREEDS_TABLE . "`
			 WHERE species = %s AND LOWER(label) = %s AND slug <> %s AND status = 'active' LIMIT 1",
			$species, $norm_label, $slug
		) );
		if ( $clash ) {
			return new WP_Error( 'duplicate_label', sprintf(
				'%s already exists in %s as "%s". Approving it again would put two labels in the picker for one breed.',
				$label, $species, $clash
			) );
		}

		$resolved = DPL_Breeds_Resolver::resolve( $label, $species );
		if ( 'resolved' === $resolved['status'] && 'map' === $resolved['rung'] ) {
			// The whole result, so a cross keeps its flag: "Mixed breed" or "X (cross)" can
			// never equal a plain label, which is exactly the refusal we want.
			$dest = DPL_Breeds_Resolver::format( $resolved );
			if ( $dest !== $label ) {
				return new WP_Error( 'shadowed_by_map', sprintf(
					'The reviewed map already sends "%s" to %s, which takes precedence. Adding it as a breed would leave it unreachable - correct the map instead.',
					$label, $dest
				) );
			}
		}

		$breeds_t = $wpdb->prefix . DPL_BREEDS_TABLE;
		$breed_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `$breeds_t` WHERE species = %s AND slug = %s", $species, $slug
		) );

		if ( $breed_id ) {
			// Reactivate rather than insert a duplicate; the slug is a public identifier.
			$wpdb->update( $breeds_t, array( 'status' => 'active', 'label' => $label ),
				array( 'id' => (int) $breed_id ) );
		} else {
			$wpdb->insert( $breeds_t, array(
				'species'    => $species,
				'slug'       => $slug,
				'label'      => $label,
				'entry_type' => 'breed',
				'source'     => 'request',
				'pet_count'  => 0,
				'sort_order' => 9999,
				'status'     => 'active',
			) );
			$breed_id = (int) $wpdb->insert_id;
		}

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO `{$wpdb->prefix}" . DPL_BREEDS_ALIAS_TABLE . "` (species, alias, breed_id, source)
			 VALUES (%s, %s, %d, 'request')",
			$species, $row['normalised'], (int) $breed_id
		) );

		$wpdb->update( $t, array( 'status' => 'approved' ), array( 'id' => (int) $id ) );
		DPL_Breeds_Repository::flush();

		do_action( 'dpl_breed_request_approved', (int) $id, (int) $breed_id );
		return (int) $breed_id;
	}

	public static function reject( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'rejected' ), array( 'id' => (int) $id ) );
		return true;
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
