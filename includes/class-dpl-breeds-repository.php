<?php
/**
 * Data access and search over the breed vocabulary.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Repository {

	/** Matches the slugs in the shipped seed. Kept here so callers never roll their own. */
	public static function slugify( $label ) {
		$s = strtolower( remove_accents( (string) $label ) );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}

	/** The normalisation the map keys use: lowercase, collapsed whitespace, trimmed. */
	public static function normalise( $raw ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $raw ) ) );
	}

	/**
	 * Coerce any spelling of a species into a vocabulary species key.
	 *
	 * Three plugins write wp_vpp_pets.species and each spells it differently: pet-profiles
	 * writes 'dog', the export holds 'Dog', 'Canine' and 'Canine (Dog)', and the AI prescription
	 * scanner writes 'guinea pig' where the vocabulary says 'guinea'. Scoping a breed lookup to a
	 * species nobody uses silently returns nothing, so every consumer goes through this.
	 *
	 * Returns '' rather than guessing when nothing matches - an unscoped lookup still works.
	 *
	 * @param string $species
	 * @return string A key from species_with_lists(), or ''.
	 */
	public static function species_key( $species ) {
		$species = strtolower( trim( (string) $species ) );
		if ( '' === $species ) {
			return '';
		}

		$known = self::species_with_lists();
		if ( in_array( $species, $known, true ) ) {
			return $species;
		}

		// Clinical names appear in the export; they carry none of our keys as substrings.
		// 'bovine' and 'cattle' only answer once a 'cow' species exists (2.7: an admin can add one).
		$latin = array(
			'canine' => 'dog',    'feline'  => 'cat',   'equine'  => 'horse',
			'lapine' => 'rabbit', 'avian'   => 'bird',  'porcine' => 'pig',
			'ovine'  => 'sheep',  'caprine' => 'sheep', 'murine'  => 'rodent',
			'bovine' => 'cow',    'cattle'  => 'cow',
		);
		// Whole words: 'bovine' contains 'ovine', and a substring match sent cattle to the sheep list.
		foreach ( $latin as $word => $key ) {
			if ( in_array( $key, $known, true ) && preg_match( '/(?<![a-z0-9])' . $word . '(?![a-z0-9])/', $species ) ) {
				return $key;
			}
		}

		// 'Canine (Dog)' and 'guinea pig' both resolve by a whole word. $known is alphabetical, so
		// 'guinea' is tested before 'pig' and wins - which is the answer we want. A whole word, not
		// a substring: 'cattle' starts with 'cat', and a plain substring match sent cattle to the
		// cat list.
		foreach ( $known as $key ) {
			if ( preg_match( '/(?<![a-z0-9])' . preg_quote( $key, '/' ) . '(?![a-z0-9])/', $species ) ) {
				return $key;
			}
		}

		return '';
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . DPL_BREEDS_TABLE;
	}

	private static function alias_table() {
		global $wpdb;
		return $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE;
	}

	/**
	 * The whole active vocabulary, cached per request.
	 *
	 * Small enough to hold entirely: ~440 breeds and ~2,000 aliases. Consumers call
	 * resolve() in loops during their own imports, so per-call queries would be wasteful.
	 */
	public static function vocabulary() {
		if ( null !== self::$vocab ) {
			return self::$vocab;
		}
		$cached = wp_cache_get( 'vocabulary', 'dpl_breeds' );
		if ( is_array( $cached ) ) {
			self::$vocab = $cached;
			return self::$vocab;
		}

		global $wpdb;
		$t = self::table();
		$a = self::alias_table();

		$vocab = array( 'by_id' => array(), 'by_species' => array(), 'alias' => array() );

		$rows = $wpdb->get_results(
			"SELECT id, species, slug, label, entry_type, source, vbo_id, venom_code, pet_count
			 FROM `$t` WHERE status = 'active' ORDER BY species ASC, sort_order ASC",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$r['id']        = (int) $r['id'];
			$r['pet_count'] = (int) $r['pet_count'];
			$vocab['by_id'][ $r['id'] ]                = $r;
			$vocab['by_species'][ $r['species'] ][]    = $r;
		}

		$aliases = $wpdb->get_results( "SELECT species, alias, breed_id FROM `$a`", ARRAY_A );
		foreach ( (array) $aliases as $r ) {
			$vocab['alias'][ $r['species'] ][ $r['alias'] ] = (int) $r['breed_id'];
		}

		wp_cache_set( 'vocabulary', $vocab, 'dpl_breeds', 5 * MINUTE_IN_SECONDS );
		self::$vocab = $vocab;
		return $vocab;
	}

	/** The per-request copy. A property, not a static local, so flush() can forget it (2.7). */
	private static $vocab = null;

	/**
	 * Forget the vocabulary, in this request and in the object cache. Until 2.7 only the object
	 * cache was cleared, so a breed added in this request was invisible to resolve() until the
	 * next one - the admin pages always redirected, so nothing noticed, but a test would.
	 */
	public static function flush() {
		self::$vocab       = null;
		self::$cross_names = null;
		wp_cache_delete( 'vocabulary', 'dpl_breeds' );
		wp_cache_delete( 'cross_names', 'dpl_breeds' );
	}

	/**
	 * Names people search by that stand for TWO breeds - "labradoodle", "cavoodle", "spoodle".
	 *
	 * An alias row can only point at one breed, so these were never searchable: the picker found
	 * "Australian Labradoodle" for "labradoodle" but never Labrador Retriever x Poodle. They come
	 * from the breed map (one source of truth, nothing generated), limited to single-word names -
	 * a portmanteau someone would type into a search box. Spelled-out pairs ("lab x staffy") and
	 * squashed ones ("gsdxlab") still resolve when typed; they are just not offered.
	 *
	 * @param string $species '' for every species.
	 * @return array[] {key, name, species, breeds: [A, B], value, pets}, ordered by key. Typo
	 *                 variants of a name are kept (they help a search find it); consumers show one
	 *                 row per value, named after the matching spelling with the most `pets`.
	 */
	public static function cross_names( $species = '' ) {
		if ( null === self::$cross_names ) {
			$cached = wp_cache_get( 'cross_names', 'dpl_breeds' );
			if ( is_array( $cached ) ) {
				self::$cross_names = $cached;
			} else {
				self::$cross_names = self::build_cross_names();
				wp_cache_set( 'cross_names', self::$cross_names, 'dpl_breeds', 5 * MINUTE_IN_SECONDS );
			}
		}
		$all = self::$cross_names;
		if ( '' === $species ) {
			return $all;
		}
		return array_values( array_filter( $all, function ( $c ) use ( $species ) {
			return $c['species'] === $species;
		} ) );
	}

	/** Per-request copy of cross_names(), forgotten by flush(). */
	private static $cross_names = null;

	private static function build_cross_names() {
		$file   = DPL_BREEDS_PATH . 'includes/data/breed-map.php';
		$map    = is_file( $file ) ? include $file : array();
		// Pets per spelling, from the live export: names a cross after "labradoodle" rather than
		// the typo "labradoddle" when a search matches both.
		$file   = DPL_BREEDS_PATH . 'includes/data/cross-name-counts.php';
		$counts = is_file( $file ) ? include $file : array();
		$labels = array();
		foreach ( self::vocabulary()['by_species'] as $sp => $rows ) {
			foreach ( $rows as $r ) {
				$labels[ $sp ][ $r['label'] ] = self::normalise( $r['label'] );
			}
		}

		$out = array();
		foreach ( (array) $map as $source => $breeds ) {
			$source = (string) $source;
			// One word, and not a squashed pair: "gsdxlab" is not a name anyone searches for.
			if ( empty( $breeds['cross'] ) || ! preg_match( '/^[a-z]{4,}$/', $source ) || false !== strpos( $source, 'x' ) ) {
				continue;
			}
			$pair = array();
			foreach ( (array) $breeds as $k => $v ) {
				if ( is_int( $k ) && is_string( $v ) ) {
					$pair[] = $v;
				}
			}
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			foreach ( $labels as $sp => $known ) {
				if ( ! isset( $known[ $pair[0] ], $known[ $pair[1] ] ) ) {
					continue;
				}
				// A name that is itself a breed in this species is that breed, not a cross.
				if ( in_array( $source, $known, true ) ) {
					break;
				}
				$value = DPL_Breeds_Resolver::format( $pair, true );
				$out[] = array(
					'key'     => $source,
					'name'    => ucfirst( $source ),
					'species' => $sp,
					'breeds'  => DPL_Breeds_Resolver::decode( $value )['breeds'],
					'value'   => $value,
					'pets'    => (int) ( $counts[ $source ] ?? 0 ),
				);
				break;
			}
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['key'], $b['key'] );
		} );
		return $out;
	}

	/** Public shape for one breed. Consumers depend on these keys. */
	private static function shape( array $r ) {
		return array(
			'slug'       => $r['slug'],
			'label'      => $r['label'],
			'species'    => $r['species'],
			'entry_type' => $r['entry_type'],
			'source'     => $r['source'],
			'vbo_id'     => $r['vbo_id'],
			'venom_code' => $r['venom_code'],
			'pet_count'  => (int) $r['pet_count'],
		);
	}

	public static function get( $slug, $species = '' ) {
		$vocab = self::vocabulary();
		foreach ( $vocab['by_species'] as $sp => $rows ) {
			if ( '' !== $species && $sp !== $species ) {
				continue;
			}
			foreach ( $rows as $r ) {
				if ( $r['slug'] === $slug ) {
					return self::shape( $r );
				}
			}
		}
		return null;
	}

	public static function get_list( $species, array $args = array() ) {
		$vocab = self::vocabulary();
		$rows  = $vocab['by_species'][ $species ] ?? array();
		$out   = array_map( array( __CLASS__, 'shape' ), $rows );
		if ( ! empty( $args['limit'] ) ) {
			$out = array_slice( $out, 0, (int) $args['limit'] );
		}
		return $out;
	}

	public static function species_with_lists() {
		return array_keys( self::vocabulary()['by_species'] );
	}

	/**
	 * Typeahead over labels then aliases, strict to lax, ranked by pet_count within each tier.
	 *
	 * The tiers are the point: an exact label must never lose to a fuzzy alias, and fuzzy only
	 * runs when everything stricter came back empty.
	 */
	public static function search( $query, array $args = array() ) {
		$q = self::normalise( $query );
		if ( '' === $q ) {
			return array();
		}
		$species  = isset( $args['species'] ) ? (string) $args['species'] : '';
		$limit    = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 10;
		$aliases  = ! isset( $args['include_aliases'] ) || $args['include_aliases'];
		$vocab    = self::vocabulary();

		$pool = array();
		foreach ( $vocab['by_species'] as $sp => $rows ) {
			if ( '' !== $species && $sp !== $species ) {
				continue;
			}
			foreach ( $rows as $r ) {
				$pool[ $sp . '|' . $r['slug'] ] = $r;
			}
		}

		$tiers = array( array(), array(), array(), array(), array() );
		$take  = function ( $tier, $row, $matched ) use ( &$tiers ) {
			$key = $row['species'] . '|' . $row['slug'];
			foreach ( $tiers as $t ) {
				if ( isset( $t[ $key ] ) ) {
					return;
				}
			}
			$tiers[ $tier ][ $key ] = array( 'row' => $row, 'matched_on' => $matched );
		};

		foreach ( $pool as $row ) {
			$label = self::normalise( $row['label'] );
			if ( $label === $q ) {
				$take( 0, $row, 'label' );
			} elseif ( 0 === strpos( $label, $q ) ) {
				$take( 1, $row, 'label-prefix' );
			}
		}

		if ( $aliases ) {
			foreach ( $vocab['alias'] as $sp => $list ) {
				if ( '' !== $species && $sp !== $species ) {
					continue;
				}
				foreach ( $list as $alias => $breed_id ) {
					if ( ! isset( $vocab['by_id'][ $breed_id ] ) ) {
						continue;
					}
					$row = $vocab['by_id'][ $breed_id ];
					if ( $alias === $q ) {
						$take( 1, $row, 'alias' );
					} elseif ( 0 === strpos( $alias, $q ) ) {
						$take( 2, $row, 'alias-prefix' );
					}
				}
			}
		}

		foreach ( $pool as $row ) {
			if ( false !== strpos( self::normalise( $row['label'] ), $q ) ) {
				$take( 3, $row, 'substring' );
			}
		}

		// Fuzzy last, and only if nothing stricter matched at all.
		$found = 0;
		foreach ( $tiers as $t ) {
			$found += count( $t );
		}
		if ( ! $found ) {
			foreach ( $pool as $row ) {
				$label = self::normalise( $row['label'] );
				$max   = max( strlen( $label ), strlen( $q ) );
				if ( ! $max || abs( strlen( $label ) - strlen( $q ) ) > 3 ) {
					continue;
				}
				$score = 1 - ( levenshtein( $q, $label ) / $max );
				if ( $score >= 0.82 ) {
					$take( 4, $row, 'fuzzy' );
				}
			}
		}

		$results = array();
		foreach ( $tiers as $tier => $bucket ) {
			$bucket = array_values( $bucket );
			usort( $bucket, function ( $a, $b ) {
				return $b['row']['pet_count'] <=> $a['row']['pet_count']
					?: strcmp( $a['row']['label'], $b['row']['label'] );
			} );
			foreach ( $bucket as $hit ) {
				$results[] = array_merge(
					self::shape( $hit['row'] ),
					array( 'matched_on' => $hit['matched_on'], 'tier' => $tier )
				);
				if ( count( $results ) >= $limit ) {
					break 2;
				}
			}
		}

		/**
		 * Filter the ranked search results.
		 *
		 * @param array  $results
		 * @param string $query
		 * @param string $species
		 */
		return apply_filters( 'dpl_breed_search_results', $results, $query, $species );
	}
}
