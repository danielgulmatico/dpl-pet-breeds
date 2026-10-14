<?php
/**
 * dpl_breed_cross_names(), offline.
 *
 *   php tests/test-cross-names.php
 *
 * Runs the REAL repository and resolver against an in-memory vocabulary built from the shipped
 * seed and map, as the other offline suites do.
 *
 * What it proves:
 *   - "labradoodle", "cavoodle", "spoodle", "groodle", "cockapoo" are offered as the crosses they are
 *   - every offered value is EXACTLY what dpl_breed_canonicalise() stores for that name, so a
 *     picker that offers it and the save path that stores it can never disagree
 *   - both parents of every cross are picker labels in its species
 *   - only single-word names: no spelled-out or squashed pairs, nothing that is itself a breed
 *
 * Exits non-zero on failure.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	die( 'CLI only.' );
}

$root = dirname( __DIR__ );
define( 'ABSPATH', __DIR__ . '/' );
define( 'DPL_BREEDS_PATH', $root . '/' );
define( 'DPL_BREEDS_API_VERSION', '2.2.0' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['dpl_test_vocab'] = null;
$GLOBALS['dpl_test_cache'] = array();
function wp_cache_get( $key, $group = '' ) {
	if ( 'vocabulary' === $key && 'dpl_breeds' === $group ) {
		return $GLOBALS['dpl_test_vocab'];
	}
	return $GLOBALS['dpl_test_cache'][ $group . '|' . $key ] ?? false;
}
function wp_cache_set( $key, $value, $group = '' ) { $GLOBALS['dpl_test_cache'][ $group . '|' . $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['dpl_test_cache'][ $group . '|' . $key ] ); return true; }
function apply_filters( $hook, $value ) { return $value; }
function remove_accents( $s ) { return $s; }

$seed = include $root . '/includes/data/breeds-seed.php';
$map  = include $root . '/includes/data/breed-map.php';
require_once $root . '/includes/class-dpl-breeds-activator.php';
require_once $root . '/includes/class-dpl-breeds-repository.php';
require_once $root . '/includes/class-dpl-breeds-resolver.php';
require_once $root . '/includes/api-breeds.php';

$id = 0; $by_slug = array();
$v  = array( 'by_id' => array(), 'by_species' => array(), 'alias' => array() );
foreach ( $seed as $sp => $rows ) {
	foreach ( $rows as $r ) {
		$id++;
		$row = array( 'id' => $id, 'species' => $sp, 'slug' => $r['slug'], 'label' => $r['label'], 'entry_type' => $r['entry_type'], 'source' => $r['source'], 'vbo_id' => $r['vbo_id'], 'venom_code' => '', 'pet_count' => (int) $r['pet_count'] );
		$v['by_id'][ $id ] = $row; $v['by_species'][ $sp ][] = $row; $by_slug[ $sp . '|' . $r['slug'] ] = $id;
	}
}
foreach ( DPL_Breeds_Activator::map_aliases( $map ) as $source => $label ) {
	$slug = DPL_Breeds_Repository::slugify( $label );
	foreach ( array_keys( $seed ) as $sp ) {
		if ( isset( $by_slug[ $sp . '|' . $slug ] ) && ! isset( $v['alias'][ $sp ][ $source ] ) ) { $v['alias'][ $sp ][ $source ] = $by_slug[ $sp . '|' . $slug ]; }
	}
}
$GLOBALS['dpl_test_vocab'] = $v;

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo '  [' . ( $ok ? 'PASS' : 'FAIL' ) . "] {$label}" . ( ! $ok && '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
}

echo "\n=== dpl_breed_cross_names() ===\n\n";
$all = dpl_breed_cross_names();
$by  = array();
foreach ( $all as $c ) { $by[ $c['key'] ] = $c; }

echo "--- 1. The names people type ---\n";
foreach ( array(
	'labradoodle'  => 'Labrador Retriever x Poodle',
	'cavoodle'     => 'Cavalier King Charles Spaniel x Poodle',
	'spoodle'      => 'English Cocker Spaniel x Poodle',
	'cockapoo'     => 'English Cocker Spaniel x Poodle',
	'groodle'      => 'Golden Retriever x Poodle',
	'goldendoodle' => 'Golden Retriever x Poodle',
	'schnoodle'    => 'Miniature Schnauzer x Poodle',
) as $key => $want ) {
	$got = $by[ $key ]['value'] ?? null;
	check( sprintf( '%-13s -> %s', $key, $want ), $want === $got, 'got ' . var_export( $got, true ) );
}
check( 'labradoodle is a dog cross with both parents listed', 'dog' === ( $by['labradoodle']['species'] ?? '' ) && array( 'Labrador Retriever', 'Poodle' ) === ( $by['labradoodle']['breeds'] ?? null ) );
check( 'the display name is the word, capitalised', 'Labradoodle' === ( $by['labradoodle']['name'] ?? '' ) );
check( 'at least 20 names, no more than 60', count( $all ) >= 20 && count( $all ) <= 60, count( $all ) . ' names' );
echo "\n";

echo "--- 2. Every value is what the save path stores ---\n";
$bad = array(); $not_labels = array();
foreach ( $all as $c ) {
	$saved = dpl_breed_canonicalise( $c['key'], $c['species'] )['value'];
	if ( $saved !== $c['value'] ) { $bad[] = "{$c['key']}: offered \"{$c['value']}\", saved \"{$saved}\""; }
	$labels = wp_list_pluck_local( dpl_breed_list( $c['species'] ) );
	foreach ( $c['breeds'] as $b ) {
		if ( ! in_array( $b, $labels, true ) ) { $not_labels[] = "{$c['key']}: {$b}"; }
	}
	if ( dpl_breed_decode( $c['value'] )['breeds'] !== $c['breeds'] || 2 !== count( $c['breeds'] ) ) { $bad[] = "{$c['key']}: breeds do not decode from value"; }
}
check( 'offered value == dpl_breed_canonicalise( name ) for all ' . count( $all ), ! $bad, implode( ' | ', array_slice( $bad, 0, 4 ) ) );
check( 'both parents are picker labels in the species', ! $not_labels, implode( ' | ', array_slice( $not_labels, 0, 4 ) ) );
echo "\n";

echo "--- 3. Only names someone would search for ---\n";
$shape = array();
foreach ( $all as $c ) {
	if ( ! preg_match( '/^[a-z]{4,}$/', $c['key'] ) || false !== strpos( $c['key'], 'x' ) ) { $shape[] = $c['key']; }
}
check( 'single words, no squashed "AxB" pairs', ! $shape, implode( ', ', $shape ) );
check( 'no spelled-out pair is offered ("lab x staffy")', ! isset( $by['lab x staffy'] ) && ! isset( $by['gsdxlab'] ) );
$is_label = array();
foreach ( $all as $c ) {
	foreach ( wp_list_pluck_local( dpl_breed_list( $c['species'] ) ) as $l ) {
		if ( DPL_Breeds_Repository::normalise( $l ) === $c['key'] ) { $is_label[] = $c['key']; }
	}
}
check( 'no name is itself a breed in its species', ! $is_label, implode( ', ', $is_label ) );
echo "\n";

echo "--- 4. Which spelling names a cross ---\n";
check( 'every entry says how many pets use its spelling', ! array_filter( $all, function ( $c ) { return ! is_int( $c['pets'] ?? null ); } ) );
check( 'labradoodle (95 pets) outranks its typo labradoddle', ( $by['labradoodle']['pets'] ?? 0 ) > ( $by['labradoddle']['pets'] ?? 0 ) && ( $by['labradoddle']['pets'] ?? -1 ) >= 0,
	( $by['labradoodle']['pets'] ?? '?' ) . ' vs ' . ( $by['labradoddle']['pets'] ?? '?' ) );
check( 'cavoodle is the most-used spelling of its cross', ( function () use ( $all, $by ) {
	foreach ( $all as $c ) {
		if ( $c['value'] === $by['cavoodle']['value'] && $c['pets'] > $by['cavoodle']['pets'] ) { return false; }
	}
	return true;
} )() );
$counts = include $root . '/includes/data/cross-name-counts.php';
check( 'the counts file covers exactly the offered spellings', array_keys( $counts ) === array_keys( $by ),
	'only in counts: ' . implode( ',', array_diff( array_keys( $counts ), array_keys( $by ) ) ) . ' / only offered: ' . implode( ',', array_diff( array_keys( $by ), array_keys( $counts ) ) ) );
echo "\n";

echo "--- 5. Species and caching ---\n";
$dogs = dpl_breed_cross_names( 'dog' );
check( 'a species filter returns only that species', $dogs && ! array_filter( $dogs, function ( $c ) { return 'dog' !== $c['species']; } ) );
check( 'a species without crosses returns none', array() === dpl_breed_cross_names( 'rabbit' ) );
check( 'the list is cached under dpl_breeds/cross_names', isset( $GLOBALS['dpl_test_cache']['dpl_breeds|cross_names'] ) );
DPL_Breeds_Repository::flush();
check( 'flush() clears it with the vocabulary', ! isset( $GLOBALS['dpl_test_cache']['dpl_breeds|cross_names'] ) );
echo "\n";

function wp_list_pluck_local( $rows ) { return array_map( function ( $r ) { return $r['label']; }, (array) $rows ); }

echo "=================================\n";
echo "  PASSED : {$pass}\n";
echo "  FAILED : {$fail}\n";
echo "=================================\n\n";
exit( $fail > 0 ? 1 : 0 );
