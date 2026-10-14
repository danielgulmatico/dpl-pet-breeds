<?php
/**
 * The public breed API.
 *
 * This file is the contract. Other plugins call these functions; they never touch the tables
 * and never split the stored delimiter themselves.
 *
 * Guarantees consumers can rely on:
 *   1. No invented names. Every label traces to VBO, VeNom, or a named breed registry.
 *   2. dpl_breed_resolve() returns status 'review' or 'unknown' rather than guessing. A wrong
 *      breed is worse than an absent one, so callers MUST handle those two statuses.
 *   3. Slugs are stable and never reused. Retiring a breed sets a status; rows are not deleted.
 *   4. A cross has at most two KNOWN breeds. There is no three-breed case.
 *   5. dpl_breed_format() output always fits the storage column, or returns ''.
 *   6. Crossbreed is a flag, never a breed. No result, list or stored value contains a
 *      "Crossbreed", "unknown" or "mix" breed; an unknown parent is implied by `cross`.
 *   7. Round-trip: dpl_breed_resolve( dpl_breed_format( $r ) ) gives back the same breeds and
 *      the same `cross` for any resolved $r, so a migration can verify its own output.
 *
 * API 2.0 — BREAKING, from 1.x:
 *   - dpl_breed_resolve() adds `cross`. A 'resolved' result can now have ZERO breeds: that is
 *     a mixed breed with no known parent. Do not treat empty `breeds` as a failure.
 *   - Pass the whole result to dpl_breed_format( $r ). Passing $r['breeds'] (the 1.x habit)
 *     silently drops the flag on a one-parent cross, turning "Staffordshire Bull Terrier
 *     (cross)" into a purebred Staffordshire Bull Terrier.
 *   - The stored form changed: "A x B", "A (cross)", "Mixed breed". The 1.x forms ("A and B",
 *     "Crossbreed and A") are still read, never written.
 *   - Use dpl_breed_decode() where you previously split with dpl_breed_parse() and wrote back.
 *
 * Gate on the major version, not on function_exists() alone:
 *   version_compare( dpl_breed_api_version(), '2.0.0', '>=' )
 *
 * Availability: guard with function_exists(), or hook 'dpl_breeds_ready'.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dpl_breed_api_version' ) ) {
	/**
	 * The API contract version. Semver; the major only moves on a breaking shape change.
	 *
	 * @return string
	 */
	function dpl_breed_api_version() {
		return DPL_BREEDS_API_VERSION;
	}
}

if ( ! function_exists( 'dpl_breed_search' ) ) {
	/**
	 * Typeahead over the vocabulary. Searches labels AND aliases, so 'staffy', 'lab' and 'dsh'
	 * all find their canonical target even though none is a canonical label.
	 *
	 * @param string $query
	 * @param array  $args  species, limit (10), include_aliases (true).
	 * @return array List of {slug,label,species,entry_type,source,vbo_id,venom_code,pet_count,matched_on,tier}.
	 */
	function dpl_breed_search( $query, array $args = array() ) {
		return DPL_Breeds_Repository::search( $query, $args );
	}
}

if ( ! function_exists( 'dpl_breed_resolve' ) ) {
	/**
	 * Resolve a messy free-text breed string. The import and migration workhorse.
	 *
	 * @param string $raw
	 * @param string $species Optional, disambiguates.
	 * @return array {
	 *   status     'resolved' | 'review' | 'unknown'
	 *   breeds     array of {slug,label}; 0, 1 or 2 REAL breeds, never more
	 *   cross      bool. True with 0 breeds = mixed breed; with 1 = one parent known
	 *   colours    array of colour words found in the string
	 *   rung       which mechanism matched: map | stored | alias | label | <search tier>
	 *   confidence 0.0 - 1.0
	 * }
	 */
	function dpl_breed_resolve( $raw, $species = '' ) {
		return DPL_Breeds_Resolver::resolve( $raw, $species );
	}
}

if ( ! function_exists( 'dpl_breed_get' ) ) {
	/**
	 * One canonical breed by slug.
	 *
	 * @return array|null
	 */
	function dpl_breed_get( $slug, $species = '' ) {
		return DPL_Breeds_Repository::get( $slug, $species );
	}
}

if ( ! function_exists( 'dpl_breed_list' ) ) {
	/**
	 * The picker list for a species, ordered most common first.
	 *
	 * @return array
	 */
	function dpl_breed_list( $species, array $args = array() ) {
		return DPL_Breeds_Repository::get_list( $species, $args );
	}
}

if ( ! function_exists( 'dpl_breed_cross_names' ) ) {
	/**
	 * Single-word names that stand for a two-breed cross - "labradoodle", "cavoodle". Since 2.2.
	 *
	 * dpl_breed_search() finds breeds; a cross is two of them, so a picker that should offer
	 * "Labradoodle = Labrador Retriever x Poodle" matches these itself, as the customer types.
	 * Typo variants are included so a search finds the cross; show one row per `value`.
	 *
	 *   [ { key: 'labradoodle', name: 'Labradoodle', species: 'dog',
	 *       breeds: ['Labrador Retriever', 'Poodle'], value: 'Labrador Retriever x Poodle',
	 *       pets: 95 }, ... ]
	 *
	 * `value` is exactly what dpl_breed_canonicalise() stores for the name. `pets` is how many
	 * pets in the live export use that spelling: when several spellings match, name the row
	 * after the one with the most, so a typo never labels a cross.
	 *
	 * @param string $species '' for every species.
	 * @return array[]
	 */
	function dpl_breed_cross_names( $species = '' ) {
		return DPL_Breeds_Repository::cross_names( (string) $species );
	}
}

if ( ! function_exists( 'dpl_breed_species' ) ) {
	/**
	 * Species that have a breed list.
	 *
	 * @return string[]
	 */
	function dpl_breed_species() {
		return DPL_Breeds_Repository::species_with_lists();
	}
}

if ( ! function_exists( 'dpl_breed_format' ) ) {
	/**
	 * The stored and display form. Never build it yourself.
	 *
	 *   Labrador Retriever                     purebred
	 *   Staffordshire Bull Terrier (cross)     cross, one parent known
	 *   Labrador Retriever x Poodle            cross, both known
	 *   Mixed breed                            cross, neither known
	 *
	 * @param array     $breeds A dpl_breed_resolve() result (preferred), or a list of labels /
	 *                          {slug,label} pairs together with $cross.
	 * @param bool|null $cross  Ignored when a result is passed. Otherwise null infers it from
	 *                          two breeds, which cannot see a one-parent cross - pass it.
	 * @return string '' if it would not fit the storage column, or exceeds two breeds.
	 */
	function dpl_breed_format( $breeds, $cross = null ) {
		return DPL_Breeds_Resolver::format( $breeds, $cross );
	}
}

if ( ! function_exists( 'dpl_breed_decode' ) ) {
	/**
	 * A stored value back to {breeds, cross}. The exact inverse of dpl_breed_format().
	 *
	 * Reads the shape of the string only; it does not check the labels against the
	 * vocabulary. To get canonical breeds, use dpl_breed_resolve(), which round-trips any
	 * value dpl_breed_format() produced.
	 *
	 * @return array{breeds:string[],cross:bool}
	 */
	function dpl_breed_decode( $stored ) {
		return DPL_Breeds_Resolver::decode( $stored );
	}
}

if ( ! function_exists( 'dpl_breed_parse' ) ) {
	/**
	 * The breed labels in a stored value, WITHOUT the cross flag. Kept for 1.x callers.
	 *
	 * Do not parse, edit and write back with this: "Staffordshire Bull Terrier (cross)"
	 * parses to ['Staffordshire Bull Terrier'] and would be re-stored as a purebred. Use
	 * dpl_breed_decode() whenever the value is going back to the database.
	 *
	 * @return string[]
	 */
	function dpl_breed_parse( $stored ) {
		return DPL_Breeds_Resolver::parse( $stored );
	}
}

if ( ! function_exists( 'dpl_breed_canonicalise' ) ) {
	/**
	 * Turn ANY input into the value to store in wp_vpp_pets.breed. Since 2.1.
	 *
	 * Every plugin that writes a breed should call this rather than combining resolve() and
	 * format() itself: pet profiles, the prescriptions admin, and the AI prescription scanner
	 * all write that column, and three writers with three ideas of a breed value is how the
	 * free text got there.
	 *
	 *   $c = dpl_breed_canonicalise( $_POST['pet_breed'], $species );
	 *   $wpdb->update( ..., array( 'breed' => $c['value'] ), ... );   // always fits the column
	 *   if ( 'resolved' !== $c['status'] ) { ...show a human... }
	 *
	 * It never guesses: only a 'resolved' answer is stored, otherwise the supplied text is kept
	 * (fitted to the column) and `suggestion` says what it might have been.
	 *
	 * @param string $raw
	 * @param string $species Any spelling - 'dog', 'Canine (Dog)', 'guinea pig'.
	 * @param array  $context request (bool), accept_suggestion (bool), pet_id, user_id.
	 * @return array {value, status, breeds, cross, changed, species, suggestion, request}
	 */
	function dpl_breed_canonicalise( $raw, $species = '', array $context = array() ) {
		return DPL_Breeds_Resolver::canonicalise( $raw, $species, $context );
	}
}

if ( ! function_exists( 'dpl_breed_species_key' ) ) {
	/**
	 * Any spelling of a species -> a vocabulary key, or '' if none matches. Since 2.1.
	 *
	 * 'Canine (Dog)' -> 'dog', 'guinea pig' -> 'guinea', 'Feline' -> 'cat'.
	 *
	 * @return string
	 */
	function dpl_breed_species_key( $species ) {
		return DPL_Breeds_Repository::species_key( $species );
	}
}

if ( ! function_exists( 'dpl_breed_tokens' ) ) {
	/**
	 * Separators and markers for a UI that composes or splits stored values client-side.
	 * Since 2.1. Localise these into the script; never hardcode " x " or "(cross)".
	 *
	 * @return array {delimiter, cross_suffix, mixed, legacy_delimiter, legacy_cross, max_breeds, max_length}
	 */
	function dpl_breed_tokens() {
		return DPL_Breeds_Resolver::tokens();
	}
}

if ( ! function_exists( 'dpl_breed_request' ) ) {
	/**
	 * File a request for a breed that is not in the vocabulary.
	 *
	 * Runs the resolver first: if the string is really a misspelling, this returns the
	 * suggestion instead of creating a request, so most requests never get created.
	 *
	 * @param string $raw
	 * @param string $species
	 * @param array  $context pet_id, user_id.
	 * @return array {created:bool, request_id:int, suggestion:array|null}
	 */
	function dpl_breed_request( $raw, $species = '', array $context = array() ) {
		return DPL_Breeds_Requests::create( $raw, $species, $context );
	}
}
