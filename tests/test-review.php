<?php
/**
 * DPL Pet Breeds — Pet Breeds -> Review (2.6), against the REAL code with no database.
 *
 *   php tests/test-review.php
 *
 * Loads the real repository, resolver and DPL_Breeds_Review with the shipped data, and a
 * consumer that reports breed texts through 'dpl_breed_review_sources' and records what
 * 'dpl_breed_review_apply' hands it - the contract dpl-vp-pets implements.
 *
 * What it proves:
 *   - the queue holds exactly the reported texts that do not resolve, one row per species and
 *     text whatever the spelling, and building it changes nothing
 *   - "means X" makes the resolver answer X for that text and species everywhere - whole, inside
 *     a cross, through canonicalise() - outranking the audit's hold (breed-review.php)
 *   - it reaches the consumer with the value and every spelling; other species are unaffected
 *   - "keep as typed" does NOT lift the hold: a held string whose wrong map entry is still there
 *     stays 'review' instead of falling through to that entry
 *   - answers that are not storable breeds, and texts with no known species, are refused
 *   - undo restores the previous behaviour and puts the text back in the queue
 *
 * Exits non-zero on failure.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	die( 'CLI only.' );
}

define( 'ABSPATH', __DIR__ . '/' );
$plugin = dirname( __DIR__ );
define( 'DPL_BREEDS_PATH', $plugin . '/' );
define( 'DPL_BREEDS_API_VERSION', '2.3.0' );

/* ---- the WordPress functions the real code reaches for ---- */
$GLOBALS['dpl_test_vocab'] = null;
$GLOBALS['opt']   = array();
$GLOBALS['trans'] = array();
$GLOBALS['hooks'] = array();
function wp_cache_get( $key, $group = '' ) { return ( 'vocabulary' === $key && 'dpl_breeds' === $group ) ? $GLOBALS['dpl_test_vocab'] : false; }
function wp_cache_set() { return true; }
function wp_cache_delete() { return true; }
function remove_accents( $s ) { return $s; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $n ); return true; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $f ) { $value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, $f[1] ) ); }
	return $value;
}
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function get_transient( $n ) { return $GLOBALS['trans'][ $n ] ?? false; }
function set_transient( $n, $v, $t = 0 ) { $GLOBALS['trans'][ $n ] = $v; return true; }
function delete_transient( $n ) { unset( $GLOBALS['trans'][ $n ] ); return true; }
function get_current_user_id() { return 7; }

/* ---- the vocabulary, in seed()'s precedence: map aliases first, ontology after ---- */
$seed = include $plugin . '/includes/data/breeds-seed.php';
$map  = include $plugin . '/includes/data/breed-map.php';
$vbo  = file_exists( $plugin . '/includes/data/breed-aliases.php' ) ? include $plugin . '/includes/data/breed-aliases.php' : array();
require_once $plugin . '/includes/class-dpl-breeds-activator.php';
require_once $plugin . '/includes/class-dpl-breeds-repository.php';
require_once $plugin . '/includes/class-dpl-breeds-resolver.php';
require_once $plugin . '/includes/class-dpl-breeds-review.php';
$id = 0; $by_slug = array(); $v = array( 'by_id' => array(), 'by_species' => array(), 'alias' => array() );
foreach ( $seed as $sp => $rows ) { foreach ( $rows as $r ) { $id++;
	$row = array( 'id' => $id, 'species' => $sp, 'slug' => $r['slug'], 'label' => $r['label'], 'entry_type' => $r['entry_type'], 'source' => $r['source'], 'vbo_id' => $r['vbo_id'], 'venom_code' => '', 'pet_count' => (int) $r['pet_count'] );
	$v['by_id'][ $id ] = $row; $v['by_species'][ $sp ][] = $row; $by_slug[ $sp . '|' . $r['slug'] ] = $id; } }
foreach ( DPL_Breeds_Activator::map_aliases( $map ) as $source => $label ) { $slug = DPL_Breeds_Repository::slugify( $label );
	foreach ( array_keys( $seed ) as $sp ) { if ( isset( $by_slug[ $sp . '|' . $slug ] ) && ! isset( $v['alias'][ $sp ][ $source ] ) ) { $v['alias'][ $sp ][ $source ] = $by_slug[ $sp . '|' . $slug ]; } } }
foreach ( (array) $vbo as $sp => $pairs ) { foreach ( (array) $pairs as $alias => $slug ) {
	if ( isset( $by_slug[ $sp . '|' . $slug ] ) && ! isset( $v['alias'][ $sp ][ $alias ] ) ) { $v['alias'][ $sp ][ $alias ] = $by_slug[ $sp . '|' . $slug ]; } } }
$GLOBALS['dpl_test_vocab'] = $v;

/* ---- a consumer, as dpl-vp-pets is one ---- */
$GLOBALS['reported'] = array(
	array( 'species' => 'horse', 'text' => 'Bush Pony', 'count' => 2 ),          // held by the audit
	array( 'species' => 'Equine', 'text' => 'bush  pony', 'count' => 1 ),        // same, other spellings
	array( 'species' => 'dog', 'text' => 'Xyzzy Hound', 'count' => 1 ),          // no match
	array( 'species' => 'dog', 'text' => 'Labrador', 'count' => 5 ),             // resolves: not for review
	array( 'species' => 'guinea', 'text' => 'Short hair', 'count' => 4 ),        // held; its wrong map entry is still there
	array( 'species' => '', 'text' => 'Penguin', 'count' => 1 ),                 // no species
);
$GLOBALS['applied'] = array();
add_filter( 'dpl_breed_review_sources', function ( $rows ) { return array_merge( $rows, $GLOBALS['reported'] ); } );
add_filter( 'dpl_breed_review_apply', function ( $n, $decision ) { $GLOBALS['applied'][] = $decision; return $n + 3; }, 10, 2 );

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo '  [' . ( $ok ? 'PASS' : 'FAIL' ) . "] {$label}" . ( ! $ok && '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
}
$R = 'DPL_Breeds_Review';
$C = function ( $raw, $sp ) { return DPL_Breeds_Resolver::canonicalise( $raw, $sp ); };
$group = function ( $sp, $text ) use ( $R ) {
	foreach ( $R::queue( true ) as $g ) { if ( $g['species'] === $sp && $g['norm'] === DPL_Breeds_Repository::normalise( $text ) ) { return $g; } }
	return null;
};

echo "Before any decision\n";
$c = $C( 'Bush Pony', 'horse' );
check( 'a held string is review, kept as typed', 'review' === $c['status'] && 'Bush Pony' === $c['value'] && '' !== ( $c['reason'] ?? '' ) );
$q = $R::queue( true );
check( 'the queue holds the four unresolved texts, not the resolved one', 4 === count( $q ), implode( ', ', array_column( $q, 'key' ) ) );
$bp = $group( 'horse', 'Bush Pony' );
check( 'spellings and species spellings make one row with every pet', $bp && 3 === $bp['pets'] && 2 === count( $bp['variants'] ), $bp ? json_encode( $bp ) : 'missing' );
check( 'the audit\'s reason is carried to the page', $bp && '' !== $bp['reason'] );
check( 'building the queue decides nothing', array() === $R::decisions() && ! $GLOBALS['applied'] );

echo "\n\"Means X\"\n";
$r = $R::decide( $bp, 'Pony' );
check( 'the decision is accepted and the consumer\'s count reported', $r['ok'] && 'Pony' === $r['value'] && 3 === $r['applied'], json_encode( $r ) );
check( 'the consumer gets the value and every spelling', 'Pony' === $GLOBALS['applied'][0]['value'] && 2 === count( $GLOBALS['applied'][0]['variants'] ) );
$res = DPL_Breeds_Resolver::resolve( 'Bush Pony', 'horse' );
check( 'resolve() answers it, rung "decided", outranking the hold', 'resolved' === $res['status'] && 'decided' === $res['rung'] && 'Pony' === $res['breeds'][0]['label'], json_encode( $res ) );
$c = $C( 'bush  PONY', 'Equine' );
check( 'canonicalise() stores it, whatever the spelling', 'resolved' === $c['status'] && 'Pony' === $c['value'], json_encode( $c ) );
$c = $C( 'Bush Pony and Thoroughbred', 'horse' );
check( 'it works inside a cross too', 'resolved' === $c['status'] && 'Pony x Thoroughbred' === $c['value'], json_encode( $c ) );
$c = $C( 'Bush Pony', 'dog' );
check( 'another species is not affected', 'Pony' !== $c['value'] );
check( 'the text leaves the queue', null === $group( 'horse', 'Bush Pony' ) );

$xy = $group( 'dog', 'Xyzzy Hound' );
$r  = $R::decide( $xy, DPL_Breeds_Resolver::format( array( 'Poodle', 'Labrador Retriever' ), true ) );
check( 'a cross of two is a valid answer', $r['ok'] && 'Labrador Retriever x Poodle' === $C( 'Xyzzy Hound', 'dog' )['value'], json_encode( $r ) );

echo "\nRefused\n";
$GLOBALS['opt'] = array(); $R::flush(); $R::forget_queue();
$xy = $group( 'dog', 'Xyzzy Hound' );
$r  = $R::decide( $xy, 'Not A Real Breed' );
check( 'an answer that is not a storable breed is refused', ! $r['ok'] && 'not_a_breed' === $r['error'] && array() === $R::decisions() );
$pg = $group( '', 'Penguin' );
check( 'a text with no known species is listed', null !== $pg );
check( '... but cannot be decided or kept', $pg && ! $R::decide( $pg, 'Pony' )['ok'] && ! $R::keep( $pg ) && array() === $R::decisions() );

echo "\nKeep as typed\n";
$GLOBALS['applied'] = array();
$sh = $group( 'guinea', 'Short hair' );
check( 'keep is accepted and hands nothing to the consumer', $R::keep( $sh ) && ! $GLOBALS['applied'] );
$c = $C( 'Short hair', 'guinea' );
check( 'the hold stays: a guinea pig is NOT given the cat label its map entry still has', 'review' === $c['status'] && 'Short hair' === $c['value'], json_encode( $c ) );
check( 'the text leaves the queue', null === $group( 'guinea', 'Short hair' ) );
check( 'a cat typed "Short hair" is unaffected', 'Domestic Short Hair' === $C( 'Short hair', 'cat' )['value'] );

echo "\nUndo\n";
$bp = $group( 'horse', 'Bush Pony' );
$R::decide( $bp, 'Pony' );
check( 'undo is accepted', $R::undo( 'horse', DPL_Breeds_Repository::normalise( 'Bush Pony' ) ) );
check( 'the string is held again', 'review' === $C( 'Bush Pony', 'horse' )['status'] );
check( 'and back in the queue', null !== $group( 'horse', 'Bush Pony' ) );
check( 'undo of something not decided is refused', false === $R::undo( 'horse', 'never decided' ) );

echo "\nMenu badge\n";
$n = count( $R::queue( true ) );
check( 'it reads the cache when current', $n === $R::cached_count() );
$GLOBALS['trans'] = array();
check( 'with the cache expired it shows the last count, not nothing', $n === $R::cached_count() );
$R::keep( $group( 'horse', 'Bush Pony' ) );
check( 'right after a decision it still shows a count', null !== $R::cached_count() );
$R::queue( true );   // what the page's handler does before redirecting
check( 'and once the handler rebuilds the queue, the new one', $n - 1 === $R::cached_count(), $R::cached_count() . ' vs ' . ( $n - 1 ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
