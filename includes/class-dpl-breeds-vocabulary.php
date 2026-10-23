<?php
/**
 * Writing to the vocabulary outside the seed: a breed a person adds.
 *
 * Two doors lead here - Pet Breeds -> Breeds "Add a breed" (2.7) and approving an add-breed
 * request - and both go through add_breed(), so the guards that keep the vocabulary sound are in
 * one place. The seed never touches a row with a MANUAL source: it is not in the shipped data,
 * so retiring "everything the seed does not list" would wipe it on every re-seed (that happened
 * to approved requests once; see DPL_Breeds_Activator::seed()).
 *
 * A species is not a table. It is the species column of the active rows, so a species comes
 * into being with its first breed, and "add a species" is add_breed() under a key the vocabulary
 * does not have yet. Which other plugins offer that species in their own forms is their business
 * (dpl-vp-pets 4.9.x hardcodes ten); this plugin only has to know its breeds.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Vocabulary {

	/** Sources of rows a person added. A re-seed never retires or rebuilds them. */
	const MANUAL_SOURCES = array( 'request', 'admin' );

	/** Longest label that still fits the column with " (cross)" after it. */
	const MAX_LABEL = 56;

	/** Quoted for an SQL IN (). */
	public static function manual_sources_sql() {
		return "'" . implode( "','", self::MANUAL_SOURCES ) . "'";
	}

	/**
	 * The species key a new breed goes under.
	 *
	 * An existing species by any spelling ("Canine (Dog)", "guinea pig") is that species. Anything
	 * else becomes a new key - "Cow" -> 'cow' - as long as it is a plain word: lowercase letters,
	 * digits and hyphens, three to 32 characters, so it can be a column value, a URL argument and
	 * a word species_key() can match on.
	 *
	 * @return string|WP_Error
	 */
	public static function species_for_new( $species ) {
		$raw = trim( (string) $species );
		if ( '' === $raw ) {
			return new WP_Error( 'no_species', 'A species is required.' );
		}
		$key = DPL_Breeds_Repository::species_key( $raw );
		if ( '' !== $key ) {
			return $key;
		}
		$key = DPL_Breeds_Repository::slugify( $raw );
		if ( ! preg_match( '/^[a-z][a-z0-9-]{2,31}$/', $key ) ) {
			return new WP_Error( 'invalid_species', sprintf(
				'"%s" cannot be a species key. Use one plain word (letters, digits, hyphens; 3 to 32 characters).',
				$raw
			) );
		}
		return $key;
	}

	/**
	 * Why a label can never be a breed, or '' if it can.
	 *
	 * Crossbreed is a flag, not a breed (API 2.0) - under any name. A label that contains a
	 * separator would break decode(), and one longer than the column allows could never be stored
	 * with the cross suffix.
	 */
	public static function label_problem( $label ) {
		$label = trim( (string) $label );
		$norm  = DPL_Breeds_Repository::normalise( $label );
		if ( '' === $norm ) {
			return 'A breed name is required.';
		}
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) > self::MAX_LABEL : strlen( $label ) > self::MAX_LABEL ) {
			return sprintf( 'A breed name can be at most %d characters.', self::MAX_LABEL );
		}
		foreach ( array( DPL_Breeds_Resolver::DELIMITER, DPL_Breeds_Resolver::CROSS_SUFFIX, DPL_Breeds_Resolver::LEGACY_DELIMITER ) as $sep ) {
			if ( false !== stripos( $label, $sep ) ) {
				return sprintf( 'A breed name cannot contain "%s" - a cross is two breeds, entered separately.', trim( $sep ) );
			}
		}
		$words = array( 'crossbreed', 'cross breed', 'cross', 'crossbred', 'mixed breed', 'mixed', 'mix', 'unknown', 'other', 'none' );
		if ( in_array( $norm, $words, true ) ) {
			return sprintf( '"%s" is not a breed. Crossbreed is recorded as a flag on the pet, and an unknown breed is simply left empty.', $label );
		}
		return '';
	}

	/**
	 * Add a breed, or bring a retired one back. The ONE write path into the breed table that is
	 * not the seed.
	 *
	 * Refuses, rather than quietly damaging the vocabulary (both happened for real on 2026-09-02):
	 *   - a label already active under another slug: two labels in the picker for one breed
	 *   - a label the reviewed map sends somewhere else: the map outranks a label, so the new
	 *     breed could never resolve to itself - it would sit in the picker becoming something else
	 *
	 * @param string   $species Any spelling of an existing species, or a new key (see species_for_new()).
	 * @param string   $label   The canonical label, as it should display.
	 * @param string[] $aliases Other spellings to make searchable and resolvable. Normalised here.
	 * @param string   $source  One of MANUAL_SOURCES: who put it there.
	 * @return int|WP_Error The breed id.
	 */
	public static function add_breed( $species, $label, array $aliases = array(), $source = 'admin' ) {
		global $wpdb;

		$species = self::species_for_new( $species );
		if ( is_wp_error( $species ) ) {
			return $species;
		}
		$label   = trim( preg_replace( '/\s+/', ' ', (string) $label ) );
		$problem = self::label_problem( $label );
		if ( '' !== $problem ) {
			return new WP_Error( 'invalid_label', $problem );
		}
		$slug = DPL_Breeds_Repository::slugify( $label );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_label', 'A breed name needs at least one letter or digit.' );
		}
		if ( ! in_array( $source, self::MANUAL_SOURCES, true ) ) {
			$source = 'admin';
		}

		$breeds_t   = $wpdb->prefix . DPL_BREEDS_TABLE;
		$norm_label = DPL_Breeds_Repository::normalise( $label );

		$clash = $wpdb->get_var( $wpdb->prepare(
			"SELECT slug FROM `$breeds_t`
			 WHERE species = %s AND LOWER(label) = %s AND slug <> %s AND status = 'active' LIMIT 1",
			$species, $norm_label, $slug
		) );
		if ( $clash ) {
			return new WP_Error( 'duplicate_label', sprintf(
				'%s already exists in %s as "%s". Adding it again would put two labels in the picker for one breed.',
				$label, $species, $clash
			) );
		}

		$resolved = DPL_Breeds_Resolver::resolve( $label, $species );
		if ( 'resolved' === $resolved['status'] && 'map' === $resolved['rung'] ) {
			// The whole result, so a cross keeps its flag: "Mixed breed" or "X (cross)" can never
			// equal a plain label, which is exactly the refusal we want. Compared normalised: the
			// map sending "border collie" to Border Collie is the same breed, not a shadow.
			$dest = DPL_Breeds_Resolver::format( $resolved );
			if ( DPL_Breeds_Repository::normalise( $dest ) !== $norm_label ) {
				return new WP_Error( 'shadowed_by_map', sprintf(
					'The reviewed map already sends "%s" to %s, which takes precedence. Adding it as a breed would leave it unreachable - correct the map instead.',
					$label, $dest
				) );
			}
		}

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM `$breeds_t` WHERE species = %s AND slug = %s", $species, $slug
		), ARRAY_A );

		if ( $existing && 'active' === $existing['status'] ) {
			// Already there. Nothing to change on the row - a seed row keeps the seed's label and
			// source - but any aliases given still attach to it: approving a request as an existing
			// breed means "that wording is this breed", which is an alias and nothing more.
			$breed_id = (int) $existing['id'];
		} elseif ( $existing ) {
			// Reactivate rather than insert a duplicate; the slug is a public identifier. The
			// source moves to the manual one, or the next re-seed would retire it again: it is
			// not in the seed file, and only MANUAL_SOURCES are exempt.
			$breed_id = (int) $existing['id'];
			$wpdb->update( $breeds_t,
				array( 'status' => 'active', 'label' => $label, 'source' => $source ),
				array( 'id' => $breed_id )
			);
		} else {
			$wpdb->insert( $breeds_t, array(
				'species'    => $species,
				'slug'       => $slug,
				'label'      => $label,
				'entry_type' => 'breed',
				'source'     => $source,
				'pet_count'  => 0,
				'sort_order' => 9999,
				'status'     => 'active',
			) );
			$breed_id = (int) $wpdb->insert_id;
		}
		$breed_id = (int) $breed_id;

		$aliases_t = $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE;
		foreach ( $aliases as $alias ) {
			$alias = DPL_Breeds_Repository::normalise( $alias );
			if ( '' === $alias || $alias === $norm_label ) {
				continue;
			}
			// INSERT IGNORE against UNIQUE(species, alias): an alias already pointing somewhere
			// is left alone, never repointed.
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO `$aliases_t` (species, alias, breed_id, source) VALUES (%s, %s, %d, %s)",
				$species, $alias, $breed_id, $source
			) );
		}

		DPL_Breeds_Repository::flush();

		/**
		 * Fires when a person adds a breed to the vocabulary (or brings a retired one back).
		 *
		 * @param int    $breed_id
		 * @param string $species  The species key.
		 * @param string $label
		 * @param string $source   'admin' or 'request'.
		 */
		do_action( 'dpl_breed_added', $breed_id, $species, $label, $source );

		return $breed_id;
	}
}
