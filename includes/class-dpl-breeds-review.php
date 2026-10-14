<?php
/**
 * Breed Review: breed text the resolver cannot place, for VetPost's admins to decide by hand.
 *
 * What a breed text MEANS is this plugin's concern; the records holding it are not. So the
 * texts come from the plugins that store breeds, through the 'dpl_breed_review_sources' filter
 * (dpl-vp-pets reports its pets' breeds and how many pets hold each), and a decision goes back
 * to them through 'dpl_breed_review_apply', for each to update its own records.
 *
 * A decision is a rule about one text for one species:
 *   map   "Bush Pony" (horse) means Pony. The resolver answers with it from then on - rung
 *         'decided', ahead of every other rung and of the audit's hold - so the same text typed
 *         again on any screen is stored correctly by itself.
 *   keep  Keep it as typed. Nothing resolves differently; the text just leaves the queue.
 * Decisions live in an option, not in the shipped data, so a re-seed never undoes them, and an
 * admin can undo one from the page.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Review {

	/** species key => normalised text => {action, value, text, pets, by, at} */
	const OPTION      = 'dpl_breeds_review_decisions';
	const QUEUE_CACHE = 'dpl_breeds_review_queue';
	const QUEUE_TTL   = 900;
	/** How many texts waited when the queue was last built - the menu badge between builds. */
	const COUNT_OPTION = 'dpl_breeds_review_count';

	private static $decisions = null;

	/* --------------------------------------------------------------- decisions */

	public static function decisions() {
		if ( null === self::$decisions ) {
			// Guarded: the offline tools and tests load the resolver without WordPress.
			$d = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
			self::$decisions = is_array( $d ) ? $d : array();
		}
		return self::$decisions;
	}

	/** Forget the per-request copy - after a write, or in tests. */
	public static function flush() {
		self::$decisions = null;
	}

	/**
	 * The decision for a text, or null.
	 *
	 * @param string $raw     Any spelling; normalised here.
	 * @param string $species Any spelling; a decision is always for one vocabulary species.
	 * @return array|null
	 */
	public static function decision( $raw, $species ) {
		$sp  = DPL_Breeds_Repository::species_key( $species );
		$key = DPL_Breeds_Repository::normalise( $raw );
		$d   = self::decisions();
		return ( '' !== $sp && '' !== $key && isset( $d[ $sp ][ $key ] ) ) ? $d[ $sp ][ $key ] : null;
	}

	/** The canonical value a text was decided to mean - null when undecided or kept as typed. */
	public static function decided_value( $raw, $species ) {
		$dec = self::decision( $raw, $species );
		return ( $dec && 'map' === $dec['action'] && '' !== (string) $dec['value'] ) ? (string) $dec['value'] : null;
	}

	/* -------------------------------------------------------------------- queue */

	/**
	 * Every text waiting for a person, most pets first: reported by a consumer, not resolved,
	 * not decided. One row per species and text, ignoring case, spacing and the species spelling.
	 *
	 * @return array<int, array{key: string, species: string, text: string, norm: string, pets: int,
	 *               status: string, reason: string, suggestion: string, variants: array}>
	 */
	public static function queue( $fresh = false ) {
		$stamp = self::stamp();
		if ( ! $fresh ) {
			$cached = get_transient( self::QUEUE_CACHE );
			if ( is_array( $cached ) && isset( $cached['stamp'], $cached['groups'] ) && $cached['stamp'] === $stamp ) {
				return $cached['groups'];
			}
		}

		$rows     = apply_filters( 'dpl_breed_review_sources', array() );
		$groups   = array();
		$answered = array();
		foreach ( (array) $rows as $r ) {
			$text    = trim( preg_replace( '/\s+/', ' ', (string) ( $r['text'] ?? '' ) ) );
			$sp_raw  = (string) ( $r['species'] ?? '' );
			$sp      = DPL_Breeds_Repository::species_key( $sp_raw );
			$norm    = DPL_Breeds_Repository::normalise( $text );
			if ( '' === $norm ) {
				continue;
			}
			$key = ( '' !== $sp ? $sp : '?' . strtolower( trim( $sp_raw ) ) ) . '|' . $norm;
			if ( isset( $answered[ $key ] ) ) {
				continue;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				if ( self::decision( $text, $sp ) ) {
					$answered[ $key ] = true;
					continue;
				}
				$c = DPL_Breeds_Resolver::canonicalise( $text, $sp_raw );
				if ( 'resolved' === $c['status'] ) {
					$answered[ $key ] = true;
					continue;
				}
				$groups[ $key ] = array(
					'key'        => $key,
					'species'    => $sp,
					'text'       => $text,
					'norm'       => $norm,
					'pets'       => 0,
					'status'     => (string) $c['status'],
					'reason'     => (string) ( $c['reason'] ?? '' ),
					'suggestion' => (string) ( $c['suggestion']['label'] ?? '' ),
					'variants'   => array(),
				);
			}
			$groups[ $key ]['pets']      += max( 1, (int) ( $r['count'] ?? 1 ) );
			$groups[ $key ]['variants'][] = array( $sp_raw, (string) ( $r['text'] ?? '' ) );
		}

		$groups = array_values( $groups );
		usort( $groups, function ( $a, $b ) {
			return $b['pets'] <=> $a['pets'] ?: strcasecmp( $a['text'], $b['text'] );
		} );
		set_transient( self::QUEUE_CACHE, array( 'stamp' => $stamp, 'groups' => $groups ), self::QUEUE_TTL );
		if ( (int) get_option( self::COUNT_OPTION, -1 ) !== count( $groups ) ) {
			update_option( self::COUNT_OPTION, count( $groups ), true );
		}
		return $groups;
	}

	/**
	 * How many texts wait, for the menu badge, without building the queue: the cache when it is
	 * current, otherwise the count from the last build. Null only before the queue was ever built.
	 * The badge used to read the cache alone, so it vanished for a page load after every decision
	 * and whenever the cache had expired.
	 */
	public static function cached_count() {
		$cached = get_transient( self::QUEUE_CACHE );
		if ( is_array( $cached ) && isset( $cached['groups'], $cached['stamp'] ) && $cached['stamp'] === self::stamp() ) {
			return count( $cached['groups'] );
		}
		$last = get_option( self::COUNT_OPTION, null );
		return null === $last ? null : (int) $last;
	}

	public static function forget_queue() {
		delete_transient( self::QUEUE_CACHE );
	}

	/* ------------------------------------------------------------------ deciding */

	/**
	 * Decide what a text means, and hand it to the plugins holding it.
	 *
	 * The answer must be a value this plugin would store for that species on its own: one or two
	 * labels from the list, a "(cross)", or "Mixed breed". Anything else is refused - a decision
	 * that does not resolve would teach the resolver nothing.
	 *
	 * @param array  $group  A queue() row.
	 * @param string $answer The admin's answer, already composed (format()).
	 * @return array{ok: bool, error: string, value: string, applied: int}
	 */
	public static function decide( array $group, $answer ) {
		$out = array( 'ok' => false, 'error' => '', 'value' => '', 'applied' => 0 );
		if ( '' === $group['species'] ) {
			$out['error'] = 'no_species';
			return $out;
		}
		$c = DPL_Breeds_Resolver::canonicalise( (string) $answer, $group['species'] );
		if ( 'resolved' !== $c['status'] || '' === $c['value'] ) {
			$out['error'] = 'not_a_breed';
			return $out;
		}
		self::store( $group, 'map', $c['value'] );

		/**
		 * Apply a decision to records holding the text. Return how many records changed.
		 *
		 * @param int   $applied  Changed so far by other consumers.
		 * @param array $decision {species, text, key, value, variants: [[species as stored, text as stored]]}
		 */
		$applied = (int) apply_filters( 'dpl_breed_review_apply', 0, array(
			'species'  => $group['species'],
			'text'     => $group['text'],
			'key'      => $group['norm'],
			'value'    => $c['value'],
			'variants' => $group['variants'],
		) );
		self::log( 'map', $group, $c['value'], $applied );
		return array( 'ok' => true, 'error' => '', 'value' => $c['value'], 'applied' => $applied );
	}

	/** Keep a text as typed: no record changes, it leaves the queue. */
	public static function keep( array $group ) {
		if ( '' === $group['species'] ) {
			return false;
		}
		self::store( $group, 'keep', '' );
		self::log( 'keep', $group, '', 0 );
		return true;
	}

	/**
	 * Take a decision back. The text returns to the queue and resolves as it did before. Records
	 * already changed keep their new value; their original is still in the consumer's own copy
	 * (dpl-vp-pets keeps it in breed_raw).
	 */
	public static function undo( $species, $norm ) {
		$d = self::decisions();
		if ( ! isset( $d[ $species ][ $norm ] ) ) {
			return false;
		}
		$was = $d[ $species ][ $norm ];
		unset( $d[ $species ][ $norm ] );
		if ( ! $d[ $species ] ) {
			unset( $d[ $species ] );
		}
		update_option( self::OPTION, $d, false );
		self::flush();
		self::forget_queue();
		self::log( 'undo', array( 'species' => $species, 'text' => (string) $was['text'] ), (string) $was['value'], 0 );
		return true;
	}

	private static function store( array $group, $action, $value ) {
		$d = self::decisions();
		$d[ $group['species'] ][ $group['norm'] ] = array(
			'action' => $action,
			'value'  => $value,
			'text'   => $group['text'],
			'pets'   => (int) $group['pets'],
			'by'     => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'at'     => time(),
		);
		update_option( self::OPTION, $d, false );
		self::flush();
		self::forget_queue();
	}

	private static function log( $action, array $group, $value, $applied ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->info(
			sprintf( 'Breed review: %s %s "%s"%s%s by user %d',
				$action, $group['species'], $group['text'],
				'' !== $value ? ' -> "' . $value . '"' : '',
				$applied ? " ({$applied} records updated)" : '',
				function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ),
			array( 'source' => 'dpl-pet-breeds' )
		);
	}

	/** New breed data or a new decision means a new queue. */
	private static function stamp() {
		$seeded = get_option( DPL_Breeds_Activator::SEEDED_OPTION );
		return ( is_array( $seeded ) ? (string) ( $seeded['fingerprint'] ?? '' ) : '' ) . ':' . md5( serialize( self::decisions() ) );
	}
}
