<?php
/**
 * Resolution: messy free-text breed string -> canonical breeds.
 *
 * This is the import/migration workhorse. It is a lookup against a pre-computed map, NOT a
 * re-implementation of the offline pipeline. The offline pipeline (resolve3.mjs) does the hard
 * matching once, against the ontology, and ships its conclusions as breed-map.php. That way
 * production can never reach a different answer than the one that was reviewed and signed off.
 *
 * Crossbreed is a FLAG, not a breed (API 2.0). A result carries `cross` alongside `breeds`, and
 * `breeds` only ever holds real breeds. An unknown parent is implied by `cross` with fewer than
 * two breeds; it is never stored as "unknown", "mix" or "Crossbreed", because any of those would
 * need a row in the breeds table and rebuild the original problem under a new name.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Resolver {

	/** Joins two known parents: "Labrador Retriever x Poodle". No canonical label contains it. */
	const DELIMITER = ' x ';

	/** A cross with one known parent: "Staffordshire Bull Terrier (cross)". The other is implied. */
	const CROSS_SUFFIX = ' (cross)';

	/** A cross with no known parent. A display form only - there is no breed row by this name. */
	const MIXED = 'Mixed breed';

	/**
	 * API 1 wrote crosses as "A and B", and treated "Crossbreed" as if it were a breed. Both are
	 * still READ, so values already stored that way resolve, but neither is ever written.
	 */
	const LEGACY_DELIMITER = ' and ';
	const LEGACY_CROSS     = 'Crossbreed';

	/** Column width in wp_vpp_pets.breed. A truncated breed is worse than a skipped one. */
	const MAX_LEN = 64;

	private static function map() {
		static $map = null;
		if ( null === $map ) {
			$path = DPL_BREEDS_PATH . 'includes/data/breed-map.php';
			$map  = file_exists( $path ) ? (array) require $path : array();
		}
		return $map;
	}

	/* ------------------------------------------------------------ map entries */

	/**
	 * The real breeds in a map entry: its positional values. The 'cross' key is not a breed,
	 * and neither is the legacy "Crossbreed" token, should an un-converted entry ever appear.
	 */
	private static function entry_breeds( $entry ) {
		$out = array();
		foreach ( (array) $entry as $k => $v ) {
			if ( is_int( $k ) && is_string( $v ) && '' !== trim( $v ) && 0 !== strcasecmp( $v, self::LEGACY_CROSS ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/** Two breeds is always a cross. One or none is a cross only when the entry says so. */
	private static function entry_cross( $entry ) {
		$entry = (array) $entry;
		if ( ! empty( $entry['cross'] ) || count( self::entry_breeds( $entry ) ) >= 2 ) {
			return true;
		}
		foreach ( $entry as $k => $v ) {
			if ( is_int( $k ) && is_string( $v ) && 0 === strcasecmp( $v, self::LEGACY_CROSS ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ resolve */

	/**
	 * Resolve a raw string to canonical breeds.
	 *
	 * @param string $raw     Whatever is stored or was typed.
	 * @param string $species Optional VP species, used to disambiguate.
	 * @return array{status:string,breeds:array,cross:bool,colours:array,rung:string,confidence:float}
	 */
	public static function resolve( $raw, $species = '' ) {
		$out = array(
			'status'     => 'unknown',
			'breeds'     => array(),
			'cross'      => false,
			'colours'    => array(),
			'rung'       => '',
			'confidence' => 0.0,
		);

		$key = DPL_Breeds_Repository::normalise( $raw );
		if ( '' === $key ) {
			return $out;
		}

		// 1. the reviewed map
		$map = self::map();
		if ( isset( $map[ $key ] ) ) {
			$out['status']     = 'resolved';
			$out['rung']       = 'map';
			$out['confidence'] = 1.0;
			$out['breeds']     = self::hydrate( self::entry_breeds( $map[ $key ] ), $species );
			$out['cross']      = self::entry_cross( $map[ $key ] );
			return apply_filters( 'dpl_breed_resolve_result', $out, $raw, $species );
		}

		// 2. a value this plugin itself stored - "Labrador Retriever x Poodle",
		//    "Staffordshire Bull Terrier (cross)", "Mixed breed", or the API 1 forms. Without this
		//    rung a stored cross does not resolve back to itself, so the migration cannot verify
		//    its own output and every cross the picker writes lands in the unmatched pile.
		$stored = self::resolve_stored( $raw, $species );
		if ( $stored ) {
			return apply_filters( 'dpl_breed_resolve_result', array_merge( $out, $stored ), $raw, $species );
		}

		// 3. an alias added since the map was generated, i.e. an approved add-breed request
		$vocab = DPL_Breeds_Repository::vocabulary();
		foreach ( $vocab['alias'] as $sp => $list ) {
			if ( '' !== $species && $sp !== $species ) {
				continue;
			}
			if ( isset( $list[ $key ] ) && isset( $vocab['by_id'][ $list[ $key ] ] ) ) {
				$row               = $vocab['by_id'][ $list[ $key ] ];
				$out['status']     = 'resolved';
				$out['rung']       = 'alias';
				$out['confidence'] = 1.0;
				$out['breeds']     = array( array( 'slug' => $row['slug'], 'label' => $row['label'] ) );
				return apply_filters( 'dpl_breed_resolve_result', $out, $raw, $species );
			}
		}

		// 4. the string IS a canonical label. That is an answer, not a proposal.
		//
		// This tier exists because format() writes canonical labels, so a value we ourselves
		// stored must resolve back to itself. Without it, a label that happens not to be a map
		// key fell through to search and came back 'review' - so the migration wrote correct
		// values that its own resolver would not confirm, and they showed as needing human
		// review on every future pass. Found on staging: 'Collie Smooth' and 'Mini Shetland
		// Pony' were both written by Apply and both came back 'review'.
		//
		// Placed after the map and aliases so it can never override a reviewed decision, and
		// before search so it can only ever upgrade a 'review' to 'resolved'.
		$exact = self::exact_label( $key, $species );
		if ( $exact ) {
			$out['status']     = 'resolved';
			$out['rung']       = 'label';
			$out['confidence'] = 1.0;
			$out['breeds']     = array( array( 'slug' => $exact['slug'], 'label' => $exact['label'] ) );
			return apply_filters( 'dpl_breed_resolve_result', $out, $raw, $species );
		}

		// 5. close enough to propose, never to apply. The caller decides.
		$hits = DPL_Breeds_Repository::search( $raw, array( 'species' => $species, 'limit' => 1 ) );
		if ( ! empty( $hits ) ) {
			$out['status']     = 'review';
			$out['rung']       = $hits[0]['matched_on'];
			$out['confidence'] = 'fuzzy' === $hits[0]['matched_on'] ? 0.82 : 0.9;
			$out['breeds']     = array( array( 'slug' => $hits[0]['slug'], 'label' => $hits[0]['label'] ) );
			return apply_filters( 'dpl_breed_resolve_result', $out, $raw, $species );
		}

		return apply_filters( 'dpl_breed_resolve_result', $out, $raw, $species );
	}

	/**
	 * Resolve a value in a stored form, but only if it is ALREADY canonical.
	 *
	 * Every component must be a canonical label verbatim - not merely resolve to one. That
	 * admits only values this plugin could have written and rejects free text: "lab x staffy"
	 * splits into "lab" and "staffy", neither of which is a label, so it falls through to the
	 * later rungs rather than being reassembled from guesses.
	 *
	 * A single plain name is left to the alias and label rungs, so their precedence holds.
	 *
	 * @return array|null Partial result to merge, or null.
	 */
	private static function resolve_stored( $raw, $species ) {
		$d = self::decode( $raw );
		if ( ! $d['cross'] && count( $d['breeds'] ) < 2 ) {
			return null;
		}
		if ( count( $d['breeds'] ) > 2 ) {
			return null;
		}

		$breeds = array();
		$seen   = array();
		foreach ( $d['breeds'] as $part ) {
			$row = self::exact_label( DPL_Breeds_Repository::normalise( $part ), $species );
			if ( ! $row || isset( $seen[ $row['slug'] ] ) ) {
				return null;
			}
			$seen[ $row['slug'] ] = true;
			$breeds[]             = array( 'slug' => $row['slug'], 'label' => $row['label'] );
		}

		return array(
			'status'     => 'resolved',
			'rung'       => 'stored',
			'confidence' => 1.0,
			'breeds'     => $breeds,
			'cross'      => true,
		);
	}

	/**
	 * A breed whose label, normalised, is exactly the given key.
	 *
	 * Prefers the requested species but falls back to the same label under another species,
	 * matching what hydrate() already does. The fallback matters in practice because the pets
	 * table holds species values the vocabulary does not use - 'Canine', 'Canine (Dog)', 'Dog'
	 * - so a strict species match would miss rows whose breed name is unambiguous anyway.
	 *
	 * @param string $key     Already normalised.
	 * @param string $species Optional.
	 * @return array|null Raw vocabulary row.
	 */
	private static function exact_label( $key, $species = '' ) {
		$vocab    = DPL_Breeds_Repository::vocabulary();
		$fallback = null;
		foreach ( $vocab['by_species'] as $sp => $rows ) {
			foreach ( $rows as $r ) {
				if ( DPL_Breeds_Repository::normalise( $r['label'] ) !== $key ) {
					continue;
				}
				if ( '' === $species || $sp === $species ) {
					return $r;
				}
				if ( null === $fallback ) {
					$fallback = $r;
				}
			}
		}
		return $fallback;
	}

	/** Turn labels from the map into {slug,label} pairs, preferring the given species. */
	private static function hydrate( array $labels, $species = '' ) {
		$out = array();
		foreach ( $labels as $label ) {
			$slug = DPL_Breeds_Repository::slugify( $label );
			$row  = DPL_Breeds_Repository::get( $slug, $species );
			if ( ! $row && '' !== $species ) {
				$row = DPL_Breeds_Repository::get( $slug );
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => $row ? $row['label'] : $label,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------- stored / display */

	/**
	 * The stored and display form.
	 *
	 *   purebred                 Labrador Retriever
	 *   cross, one parent known  Staffordshire Bull Terrier (cross)
	 *   cross, both known        Labrador Retriever x Poodle
	 *   cross, neither known     Mixed breed
	 *
	 * Pass a whole dpl_breed_resolve() result, or a list of labels / {slug,label} pairs with
	 * $cross. Passing only $result['breeds'] drops the flag for a one-parent cross - that is
	 * the API 1 calling convention, and the reason this is a major version.
	 *
	 * Order-insensitive, and returns '' rather than a truncated or over-cap value.
	 *
	 * @param array     $breeds A resolve() result, a list of labels, or {slug,label} pairs.
	 * @param bool|null $cross  Null = take it from the result, or infer it from two breeds.
	 */
	public static function format( $breeds, $cross = null ) {
		if ( is_array( $breeds ) && array_key_exists( 'breeds', $breeds ) && is_array( $breeds['breeds'] ) ) {
			if ( null === $cross ) {
				$cross = ! empty( $breeds['cross'] );
			}
			$breeds = $breeds['breeds'];
		}

		$labels = array();
		$legacy = false;
		foreach ( (array) $breeds as $k => $b ) {
			if ( 'cross' === $k ) {                       // a raw map entry was passed in
				if ( null === $cross ) {
					$cross = (bool) $b;
				}
				continue;
			}
			$label = trim( is_array( $b ) ? (string) ( $b['label'] ?? '' ) : (string) $b );
			if ( '' === $label ) {
				continue;
			}
			if ( 0 === strcasecmp( $label, self::LEGACY_CROSS ) ) {
				$legacy = true;                           // API 1 input: means "cross", not a breed
				continue;
			}
			$labels[] = $label;
		}

		$labels = array_values( array_unique( $labels ) );
		sort( $labels, SORT_STRING );                     // "lab x staffy" == "staffy x lab"
		$cross = (bool) $cross || $legacy || count( $labels ) >= 2;

		if ( count( $labels ) > 2 ) {
			return '';                                    // the two-breed cap is part of the contract
		}
		if ( 0 === count( $labels ) ) {
			$out = $cross ? self::MIXED : '';
		} elseif ( 1 === count( $labels ) ) {
			$out = $labels[0] . ( $cross ? self::CROSS_SUFFIX : '' );
		} else {
			$out = implode( self::DELIMITER, $labels );
		}

		return strlen( $out ) > self::MAX_LEN ? '' : $out;
	}

	/**
	 * The inverse of format(): a stored value back to {breeds, cross}. Pure - it reads the
	 * string's shape and does not check the vocabulary, so callers that need canonical labels
	 * should resolve() instead.
	 *
	 * Reads every form format() has ever written, including the API 1 ones ("A and B",
	 * "Crossbreed and A", "Crossbreed"), so values already in the pets table keep working.
	 *
	 * @return array{breeds:string[],cross:bool}
	 */
	public static function decode( $stored ) {
		$s   = trim( preg_replace( '/\s+/', ' ', (string) $stored ) );
		$out = array( 'breeds' => array(), 'cross' => false );
		if ( '' === $s ) {
			return $out;
		}

		if ( 0 === strcasecmp( $s, self::MIXED ) || 0 === strcasecmp( $s, self::LEGACY_CROSS ) ) {
			$out['cross'] = true;
			return $out;
		}

		$suffix = trim( self::CROSS_SUFFIX );
		$slen   = strlen( $suffix );
		if ( strlen( $s ) > $slen && 0 === strcasecmp( substr( $s, -$slen ), $suffix ) ) {
			$out['cross'] = true;
			$s            = trim( substr( $s, 0, -$slen ) );
		}

		// No canonical label contains " x " or " and " - tests/test-data-offline.php asserts
		// both - so splitting on either cannot cut a real breed name in half.
		$parts = preg_split( '/\s+x\s+/i', $s );
		if ( count( $parts ) < 2 ) {
			$parts = preg_split( '/\s+and\s+/i', $s );
		}

		$real = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			if ( 0 === strcasecmp( $p, self::LEGACY_CROSS ) ) {
				$out['cross'] = true;
				continue;
			}
			$real[] = $p;
		}

		$out['breeds'] = array_values( array_unique( $real ) );
		if ( count( $out['breeds'] ) >= 2 ) {
			$out['cross'] = true;
		}
		return $out;
	}

	/**
	 * The breed labels in a stored value, without the cross flag. Kept for API 1 callers;
	 * use decode() when the flag matters - and it does whenever the value is written back.
	 *
	 * @return string[]
	 */
	public static function parse( $stored ) {
		return self::decode( $stored )['breeds'];
	}

	/* ------------------------------------------------------------------ writing */

	/**
	 * Separators and markers, for a UI that must compose or split a stored value itself - a
	 * picker rendering chips cannot round-trip to PHP on every keystroke. Consumers localise
	 * this rather than hardcoding any of it.
	 *
	 * @return array
	 */
	public static function tokens() {
		return array(
			'delimiter'        => self::DELIMITER,
			'cross_suffix'     => self::CROSS_SUFFIX,
			'mixed'            => self::MIXED,
			'legacy_delimiter' => self::LEGACY_DELIMITER,
			'legacy_cross'     => self::LEGACY_CROSS,
			'max_breeds'       => 2,
			'max_length'       => self::MAX_LEN,
		);
	}

	/**
	 * THE way to turn any input into a value for wp_vpp_pets.breed.
	 *
	 * Three plugins write that column: dpl-vp-pets (My Pets and the staff pet screen), vet-pharmacy-prescriptions,
	 * and the AI prescription scanner. Before this existed the first two carried near-identical
	 * copies of this logic and the third wrote raw OCR text straight in. Three writers each
	 * with their own idea of a breed value is how the free text got there in the first place,
	 * so they all call this instead.
	 *
	 * It never guesses. Only a 'resolved' answer is stored; anything else keeps the supplied
	 * text (fitted to the column) and reports why, so the caller can show it to a human.
	 *
	 * @param string $raw     Anything: a picker value, OCR text, a legacy stored value.
	 * @param string $species Any spelling - 'dog', 'Canine (Dog)', 'guinea pig'.
	 * @param array  $context {
	 *   @type bool $request           File an add-breed request for a genuinely new breed typed
	 *                                 as "Other: X". Default false - OCR noise must not reach
	 *                                 the moderation queue.
	 *   @type bool $accept_suggestion Store a 'review'-level suggestion as if resolved. Default
	 *                                 false, because that is guessing. Opt in deliberately.
	 *   @type int  $pet_id, $user_id  Context recorded on a request.
	 * }
	 * @return array {
	 *   value      string  What to store. Always fits the column.
	 *   status     string  'resolved' | 'review' | 'unknown'
	 *   breeds     array   {slug,label} pairs, when resolved
	 *   cross      bool
	 *   changed    bool    value differs from what was supplied - worth showing a human
	 *   species    string  the species key used, '' if none matched
	 *   suggestion array|null  a {slug,label} worth offering when not resolved
	 *   request    array|null  the dpl_breed_request() result, if one was filed
	 * }
	 */
	public static function canonicalise( $raw, $species = '', array $context = array() ) {
		$raw     = trim( preg_replace( '/\s+/', ' ', (string) $raw ) );
		$species = DPL_Breeds_Repository::species_key( $species );
		$out     = array(
			'value'      => '',
			'status'     => 'unknown',
			'breeds'     => array(),
			'cross'      => false,
			'changed'    => false,
			'species'    => $species,
			'suggestion' => null,
			'request'    => null,
		);
		if ( '' === $raw ) {
			return $out;
		}

		// 1. The whole value: the reviewed map, anything this plugin stored, a plain label.
		$best = self::resolve( $raw, $species );

		// 2. Otherwise part by part. A picker posts "Labrador Retriever x Other: Wolfdog"; OCR
		//    gives "Border Collie Cross". Every part must resolve, or none of it is used.
		if ( 'resolved' !== $best['status'] ) {
			$d      = self::decode( $raw );
			$parts  = $d['breeds'] ? $d['breeds'] : array( $raw );
			$breeds = array();
			$cross  = $d['cross'];
			$all_ok = true;
			foreach ( $parts as $part ) {
				$r = self::resolve_part( $part, $species, $context, $out );
				if ( 'resolved' !== $r['status'] ) {
					$all_ok = false;
					if ( 'review' === $r['status'] && ! empty( $r['breeds'] ) && null === $out['suggestion'] ) {
						$out['suggestion'] = $r['breeds'][0];
					}
					continue;
				}
				foreach ( $r['breeds'] as $b ) {
					$breeds[ $b['slug'] ] = $b;            // keyed: a repeated breed collapses
				}
				$cross = $cross || ! empty( $r['cross'] );
			}
			if ( $all_ok && count( $breeds ) <= 2 && ( $breeds || $cross ) ) {
				$best = array(
					'status' => 'resolved',
					'rung'   => 'parts',
					'breeds' => array_values( $breeds ),
					'cross'  => $cross || count( $breeds ) >= 2,
				);
			}
		}

		if ( 'resolved' === $best['status'] ) {
			$value = self::format( $best );
			if ( '' !== $value ) {
				$out['value']   = $value;
				$out['status']  = 'resolved';
				$out['breeds']  = $best['breeds'];
				$out['cross']   = ! empty( $best['cross'] );
				$out['changed'] = ( $value !== $raw );
				return $out;
			}
		}

		// 3. Not placed. Keep what was supplied - losing what a customer or a member of staff
		//    typed is worse than storing something not yet mapped - but never let MySQL truncate
		//    it mid-character.
		if ( 'review' === $best['status'] && ! empty( $best['breeds'] ) && null === $out['suggestion'] ) {
			$out['suggestion'] = $best['breeds'][0];
		}
		$out['status']  = ( 'review' === $best['status'] || null !== $out['suggestion'] ) ? 'review' : 'unknown';
		$out['value']   = self::fit( $raw );
		$out['changed'] = ( $out['value'] !== $raw );
		return $out;
	}

	/**
	 * One component of a value, with the free-text conventions the pickers use.
	 *
	 * @param array $out canonicalise()'s result, so a filed request can be reported.
	 */
	private static function resolve_part( $part, $species, array $context, array &$out ) {
		$r = self::resolve( $part, $species );
		if ( 'resolved' === $r['status'] ) {
			return $r;
		}

		// "Other: Budgie" is the pickers' free-text form. Resolve what was typed, not the prefix.
		$base  = $part;
		$typed = trim( preg_replace( '/^other\s*:\s*/i', '', $part ) );
		if ( '' !== $typed && $typed !== $part ) {
			$base = $typed;
			$r2   = self::resolve( $typed, $species );
			if ( 'resolved' === $r2['status'] ) {
				return $r2;
			}
			$r = $r2;
		}

		// "Border Collie Cross", "kelpie x", "Mixed breed - Husky": an explicit cross marker around
		// a breed the vocabulary knows. Strip the marker and resolve what is left, accepting it
		// only if THAT resolves - so the marker can add the cross flag, but never a breed. A
		// trailing "x" must stand alone, so "Manx" keeps its x.
		$core = preg_replace(
			array(
				'/^(?:mixed\s+breed|crossbreed|crossbred|cross|mixed|mix)[\s:\-]+/i',
				'/[\s\-]+(?:x|cross|crossbreed|crossbred|mix|mixed)\.?$/i',
			),
			'',
			$base
		);
		$core = trim( $core );
		if ( '' !== $core && strcasecmp( $core, $base ) !== 0 ) {
			$c = self::resolve( $core, $species );
			if ( 'resolved' === $c['status'] && ! empty( $c['breeds'] ) ) {
				$c['cross'] = true;
				$c['rung']  = 'marker';
				return $c;
			}
		}

		// Still nothing. A genuinely new breed typed as "Other: X" reaches the moderation queue -
		// only when asked, and create() re-runs the resolver so a near-miss never queues.
		if ( $base !== $part && ! empty( $context['request'] ) && class_exists( 'DPL_Breeds_Requests' ) ) {
			$out['request'] = DPL_Breeds_Requests::create( $base, $species, $context );
		}

		if ( ! empty( $context['accept_suggestion'] ) && 'review' === $r['status'] && ! empty( $r['breeds'] ) ) {
			$r['status'] = 'resolved';
			$r['rung']   = 'suggestion';
		}
		return $r;
	}

	/**
	 * Fit free text to wp_vpp_pets.breed. varchar counts characters, not bytes, so a byte cut
	 * could split a multibyte character and store invalid UTF-8.
	 */
	private static function fit( $value ) {
		$value = (string) $value;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > self::MAX_LEN
				? rtrim( mb_substr( $value, 0, self::MAX_LEN, 'UTF-8' ) )
				: $value;
		}
		return strlen( $value ) > self::MAX_LEN ? rtrim( substr( $value, 0, self::MAX_LEN ) ) : $value;
	}
}
