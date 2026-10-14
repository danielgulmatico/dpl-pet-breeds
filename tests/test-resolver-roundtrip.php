<?php
/**
 * DPL Pet Breeds — resolver test, against the REAL code with no database.
 *
 *   php tests/test-resolver-roundtrip.php
 *
 * Loads the real repository and resolver. DPL_Breeds_Repository::vocabulary() consults the
 * WordPress object cache before it touches $wpdb, so answering wp_cache_get() with a vocabulary
 * built from the shipped seed and alias files means every line under test is production code -
 * including search() and its fuzzy tier, which an earlier stubbed version could not reach.
 *
 * It proves guarantee 7 (resolve(format(resolve(x))) keeps breeds AND the cross flag, for every
 * map entry) and the write-side contract of dpl_breed_canonicalise(), which three plugins now
 * share. The failure it really watches for is a one-parent cross losing its flag on the way
 * round - "Staffordshire Bull Terrier (cross)" coming back as a pedigree.
 *
 * Exits non-zero on failure, so it can gate a build.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	die( 'CLI only.' );
}

define( 'ABSPATH', __DIR__ . '/' );
$plugin = dirname( __DIR__ );
define( 'DPL_BREEDS_PATH', $plugin . '/' );

/* ---- the handful of WordPress functions the real code reaches for ---- */
$GLOBALS['dpl_test_vocab'] = null;
function wp_cache_get( $key, $group = '' ) {
	return ( 'vocabulary' === $key && 'dpl_breeds' === $group ) ? $GLOBALS['dpl_test_vocab'] : false;
}
function wp_cache_set() { return true; }
function wp_cache_delete() { return true; }
function apply_filters( $hook, $value ) { return $value; }
function remove_accents( $s ) { return $s; }

/* ---- the vocabulary, in exactly the shape vocabulary() builds from the tables ---- */
$seed    = include $plugin . '/includes/data/breeds-seed.php';
$aliases = file_exists( $plugin . '/includes/data/breed-aliases.php' ) ? include $plugin . '/includes/data/breed-aliases.php' : array();

$id      = 0;
$by_slug = array();
$v       = array( 'by_id' => array(), 'by_species' => array(), 'alias' => array() );
foreach ( $seed as $sp => $rows ) {
	foreach ( $rows as $r ) {
		$id++;
		$row = array(
			'id'         => $id,
			'species'    => $sp,
			'slug'       => $r['slug'],
			'label'      => $r['label'],
			'entry_type' => $r['entry_type'],
			'source'     => $r['source'],
			'vbo_id'     => $r['vbo_id'],
			'venom_code' => '',
			'pet_count'  => (int) $r['pet_count'],
		);
		$v['by_id'][ $id ]                 = $row;
		$v['by_species'][ $sp ][]          = $row;
		$by_slug[ $sp . '|' . $r['slug'] ] = $id;
	}
}
require_once $plugin . '/includes/class-dpl-breeds-activator.php';
require_once $plugin . '/includes/class-dpl-breeds-repository.php';
require_once $plugin . '/includes/class-dpl-breeds-resolver.php';

// Aliases in seed()'s order, because the order IS the precedence: map aliases first, then
// ontology aliases, with the first row for a (species, alias) winning - the INSERT IGNORE
// against UNIQUE(species, alias). This used to load the ontology first and let it win, the
// reverse of production and of the guard that keeps VBO's "DSH" off German Shepherd.
// Which map entries qualify comes from the activator itself, so the rule cannot drift.
$map = include $plugin . '/includes/data/breed-map.php';
foreach ( DPL_Breeds_Activator::map_aliases( $map ) as $source => $label ) {
	$slug = DPL_Breeds_Repository::slugify( $label );
	foreach ( array_keys( $seed ) as $sp ) {
		if ( isset( $by_slug[ $sp . '|' . $slug ] ) && ! isset( $v['alias'][ $sp ][ $source ] ) ) {
			$v['alias'][ $sp ][ $source ] = $by_slug[ $sp . '|' . $slug ];
		}
	}
}
foreach ( (array) $aliases as $sp => $pairs ) {
	foreach ( (array) $pairs as $alias => $slug ) {
		if ( isset( $by_slug[ $sp . '|' . $slug ] ) && ! isset( $v['alias'][ $sp ][ $alias ] ) ) {
			$v['alias'][ $sp ][ $alias ] = $by_slug[ $sp . '|' . $slug ];
		}
	}
}
$GLOBALS['dpl_test_vocab'] = $v;

$pass = 0;
$fail = 0;
function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo '  [' . ( $ok ? 'PASS' : 'FAIL' ) . "] {$label}" . ( ! $ok && '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
}

/** A comparable signature: sorted labels plus the flag. */
function sig( array $r ) : string {
	$l = array_map( fn( $b ) => $b['label'], $r['breeds'] );
	sort( $l, SORT_STRING );
	return implode( '|', $l ) . ( ! empty( $r['cross'] ) ? ' #cross' : '' );
}

echo "\n=== DPL Pet Breeds — Resolver (real code, no database) ===\n\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 1. Every map entry survives resolve -> format -> resolve ---\n";

$broken = array();
$lost   = array();
$rungs  = array();
foreach ( array_keys( $map ) as $key ) {
	$first = DPL_Breeds_Resolver::resolve( (string) $key );
	if ( 'resolved' !== $first['status'] ) {
		$broken[] = "{$key}: first pass {$first['status']}";
		continue;
	}
	$stored = DPL_Breeds_Resolver::format( $first );
	if ( '' === $stored ) {
		$broken[] = "{$key}: format() returned ''";
		continue;
	}
	$again                   = DPL_Breeds_Resolver::resolve( $stored );
	$rungs[ $again['rung'] ] = ( $rungs[ $again['rung'] ] ?? 0 ) + 1;
	if ( 'resolved' !== $again['status'] || sig( $first ) !== sig( $again ) ) {
		$broken[] = "{$key} -> \"{$stored}\" -> {$again['status']}/{$again['rung']} " . sig( $again );
		if ( ! empty( $first['cross'] ) && empty( $again['cross'] ) ) {
			$lost[] = $key;
		}
	}
}
check( 'all ' . count( $map ) . ' map entries round-trip, breeds and flag', ! $broken, implode( ' | ', array_slice( $broken, 0, 4 ) ) );
check( 'no cross loses its flag on the way round', ! $lost, implode( ', ', array_slice( $lost, 0, 6 ) ) );
echo '  [INFO] second pass resolved via: ' . json_encode( $rungs ) . "\n\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 2. The four shapes, end to end ---\n";

$cases = array(
	'labrador'    => array( 'Labrador Retriever', 'Labrador Retriever' ),
	'staffy x'    => array( 'Staffordshire Bull Terrier (cross)', 'Staffordshire Bull Terrier #cross' ),
	'labradoodle' => array( 'Labrador Retriever x Poodle', 'Labrador Retriever|Poodle #cross' ),
	'moggy'       => array( 'Mixed breed', ' #cross' ),
);
foreach ( $cases as $in => $want ) {
	$r = DPL_Breeds_Resolver::resolve( $in );
	check( sprintf( '%-12s -> %s', $in, $want[0] ),
		'resolved' === $r['status'] && $want[0] === DPL_Breeds_Resolver::format( $r ) && $want[1] === sig( $r ),
		'got ' . DPL_Breeds_Resolver::format( $r ) . ' / ' . sig( $r ) );
}
check( 'a resolved mixed breed has zero breeds - and that is an answer',
	array() === DPL_Breeds_Resolver::resolve( 'moggy' )['breeds'] && true === DPL_Breeds_Resolver::resolve( 'moggy' )['cross'] );
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 3. Values written under API 1 still read correctly ---\n";

$legacy = array(
	'Crossbreed and Staffordshire Bull Terrier' => 'Staffordshire Bull Terrier #cross',
	'Crossbreed and Schnauzer'                  => 'Schnauzer #cross',
	'Cavalier King Charles Spaniel and Poodle'  => 'Cavalier King Charles Spaniel|Poodle #cross',
	'Crossbreed'                                => ' #cross',
);
foreach ( $legacy as $in => $want ) {
	$r = DPL_Breeds_Resolver::resolve( $in );
	check( sprintf( '%-42s -> %s', $in, trim( $want ) ), 'resolved' === $r['status'] && $want === sig( $r ),
		"got {$r['status']}/{$r['rung']} " . sig( $r ) );
}
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 4. The stored rung never reassembles free text ---\n";

foreach ( array( 'lab x staffy', 'staffy x mix', 'bichon x thing', 'Golden x Something' ) as $in ) {
	$r = DPL_Breeds_Resolver::resolve( $in );
	check( sprintf( '"%s" is not accepted by the stored rung', $in ), 'stored' !== $r['rung'], "rung={$r['rung']}" );
}
check( 'a verbatim canonical cross IS accepted',
	in_array( DPL_Breeds_Resolver::resolve( 'Golden Retriever x Labrador Retriever' )['rung'], array( 'stored', 'map' ), true ) );
check( 'the same breed twice is refused, not stored as a cross',
	'stored' !== DPL_Breeds_Resolver::resolve( 'Poodle x Poodle' )['rung'] );
check( 'search is live: "chi" proposes Chihuahua at review, never resolves it',
	'review' === DPL_Breeds_Resolver::resolve( 'chi' )['status']
	&& 'Chihuahua' === ( DPL_Breeds_Resolver::resolve( 'chi' )['breeds'][0]['label'] ?? '' ) );
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 5. dpl_breed_canonicalise(): the shared write path ---\n";

$C = function ( $raw, $species = '', $ctx = array() ) {
	return DPL_Breeds_Resolver::canonicalise( $raw, $species, $ctx );
};

// [ input, species, expected value, expected status ]
$write = array(
	array( 'staffy x',                      '',             'Staffordshire Bull Terrier (cross)', 'resolved' ),
	array( 'Border Collie Cross',           'dog',          'Border Collie (cross)',              'resolved' ),
	array( 'Jack Russell x',                'dog',          'Jack Russell Terrier (cross)',       'resolved' ),
	array( 'Mixed breed - Siberian Husky',  'dog',          'Siberian Husky (cross)',             'resolved' ),
	array( 'Other: staffy',                 'dog',          'Staffordshire Bull Terrier',         'resolved' ),
	array( 'Labrador Retriever x Poodle',   'dog',          'Labrador Retriever x Poodle',        'resolved' ),
	array( 'Poodle x Labrador Retriever',   'dog',          'Labrador Retriever x Poodle',        'resolved' ),
	array( 'labrador and poodle',           'dog',          'Labrador Retriever x Poodle',        'resolved' ),
	array( 'Staffordshire Bull Terrier (cross)', 'dog',     'Staffordshire Bull Terrier (cross)', 'resolved' ),
	array( 'Mixed breed',                   'dog',          'Mixed breed',                        'resolved' ),
	array( 'moggy',                         'Feline',       'Mixed breed',                        'resolved' ),
	array( 'Manx',                          'cat',          'Manx',                               'resolved' ),
	array( 'Crossbreed and Labrador Retriever', 'Canine (Dog)', 'Labrador Retriever (cross)',     'resolved' ),
);
foreach ( $write as $w ) {
	list( $in, $sp, $want, $status ) = $w;
	$c = $C( $in, $sp );
	check( sprintf( '%-36s -> %s', $in, $want ), $want === $c['value'] && $status === $c['status'],
		"got \"{$c['value']}\" status={$c['status']}" );
}

// The cross-marker rule must be exercised by inputs the map does NOT already know - otherwise
// a test passes through rung 1 and proves nothing about the new code. Each is checked absent
// from the map first, so this section cannot quietly stop testing what it claims to.
$marker = array(
	'Weimaraner Cross'        => 'Weimaraner (cross)',
	'basenji x'               => 'Basenji (cross)',
	'Cross - Beagle'          => 'Beagle (cross)',
	'Mixed breed: Samoyed'    => 'Samoyed (cross)',
	'whippet mix'             => 'Whippet (cross)',
);
foreach ( $marker as $in => $want ) {
	$key = strtolower( trim( preg_replace( '/\s+/', ' ', $in ) ) );
	if ( isset( $map[ $key ] ) ) {
		check( "\"{$in}\" must not be a map key, or this test proves nothing", false );
		continue;
	}
	$c = $C( $in, 'dog' );
	check( sprintf( 'marker, not in the map: %-22s -> %s', $in, $want ), $want === $c['value'] && 'resolved' === $c['status'],
		"got \"{$c['value']}\" {$c['status']}" );
}
$c = $C( 'Manx', 'cat' );
check( 'a trailing "x" inside a word is not a marker ("Manx" stays purebred)', 'Manx' === $c['value'] && ! $c['cross'] );
$c = $C( 'Zzqfoo Cross', 'dog' );
check( 'a marker never invents the breed: unknown core stays unresolved', 'Zzqfoo Cross' === $c['value'] && 'resolved' !== $c['status'] );

// Never guesses, never invents, never splits a real name in half.
$c = $C( 'Black and Tan Coonhound', 'dog' );
check( '"Black and Tan Coonhound" is kept, not split into "Black" / "Tan Coonhound"',
	'Black and Tan Coonhound' === $c['value'] && 'resolved' !== $c['status'], "got \"{$c['value']}\" {$c['status']}" );
$c = $C( 'Labrador Retriever x Other: Zzqwolfdog', 'dog' );
check( 'one unplaceable part leaves the whole value untouched',
	'Labrador Retriever x Other: Zzqwolfdog' === $c['value'] && 'resolved' !== $c['status'], "got \"{$c['value']}\"" );
$c = $C( 'labradoor retreiver', 'dog' );
check( 'a near-miss is SUGGESTED, not stored',
	'labradoor retreiver' === $c['value'] && 'review' === $c['status'] && ! empty( $c['suggestion'] ),
	"got \"{$c['value']}\" {$c['status']} suggestion=" . json_encode( $c['suggestion'] ) );
$c = $C( 'labradoor retreiver', 'dog', array( 'accept_suggestion' => true ) );
check( '...and stored only when the caller explicitly opts in to guessing',
	'resolved' === $c['status'] && '' !== $c['value'] && 'labradoor retreiver' !== $c['value'], "got \"{$c['value']}\" {$c['status']}" );

// The column.
$c = $C( str_repeat( 'zq', 50 ), 'dog' );
check( 'unknown text is fitted to 64 characters, never truncated by MySQL', 64 === strlen( $c['value'] ) && $c['changed'] );
$c = $C( str_repeat( 'é', 70 ), 'dog' );
check( 'multibyte text is cut on a character, leaving valid UTF-8',
	64 === mb_strlen( $c['value'], 'UTF-8' ) && 1 === preg_match( '//u', $c['value'] ) );
check( 'empty in, empty out', '' === $C( '   ' )['value'] && 'unknown' === $C( '' )['status'] );

// Species, as the three writers spell it.
foreach ( array( 'guinea pig' => 'guinea', 'Canine (Dog)' => 'dog', 'Feline' => 'cat', 'Dog' => 'dog', 'Equine' => 'horse', 'spaceship' => '' ) as $in => $want ) {
	check( sprintf( 'species "%s" -> "%s"', $in, $want ), $want === DPL_Breeds_Repository::species_key( $in ) );
}

// Idempotent: canonicalising a stored value is a no-op. Exhaustive over the map, because a
// write path that keeps nudging values would rewrite pets on every save.
$drift = array();
foreach ( array_keys( $map ) as $key ) {
	$once = $C( (string) $key );
	if ( 'resolved' !== $once['status'] ) {
		continue;
	}
	$twice = $C( $once['value'] );
	if ( $twice['value'] !== $once['value'] || 'resolved' !== $twice['status'] ) {
		$drift[] = "{$key}: \"{$once['value']}\" -> \"{$twice['value']}\" ({$twice['status']})";
	}
}
check( 'canonicalise(canonicalise(x)) == canonicalise(x) for all ' . count( $map ) . ' map entries', ! $drift, implode( ' | ', array_slice( $drift, 0, 4 ) ) );

// The pet-profiles edit form puts any stored value that is not a plain list item into its
// "Other" box, and posts it back as "Other: <value>". So a cross or mixed-breed pet comes back
// as "Other: Staffordshire Bull Terrier (cross)". If that did not canonicalise to exactly the
// stored value, editing a pet's weight would quietly rewrite or drop its breed. Exhaustive,
// because this is the property the whole edit-form safety argument rests on.
$other_broken = array();
foreach ( array_keys( $map ) as $key ) {
	$stored = $C( (string) $key );
	if ( 'resolved' !== $stored['status'] ) {
		continue;
	}
	$back = $C( 'Other: ' . $stored['value'] );
	if ( $back['value'] !== $stored['value'] ) {
		$other_broken[] = "\"Other: {$stored['value']}\" -> \"{$back['value']}\" ({$back['status']})";
	}
}
check( 'the edit form\'s "Other: <stored>" round-trips for all ' . count( $map ) . ' map entries',
	! $other_broken, implode( ' | ', array_slice( $other_broken, 0, 4 ) ) );

// And values already written under API 1, which the same form will now meet.
foreach ( array(
	'Other: Crossbreed and Staffordshire Bull Terrier' => 'Staffordshire Bull Terrier (cross)',
	'Other: Crossbreed'                               => 'Mixed breed',
	'Other: Labrador Retriever and Poodle'            => 'Labrador Retriever x Poodle',
) as $in => $want ) {
	$c = $C( $in, 'dog' );
	check( sprintf( 'legacy via the Other box: %-46s -> %s', $in, $want ), $want === $c['value'], "got \"{$c['value']}\"" );
}
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 6. Search does not offer the old picker's strings ---\n";
// Found on staging, 2.2.0: a customer typing "other" to reach the Other option was offered
// Labrador, Poodle, Border Collie... as "matched from other spellings", because strings the old
// picker wrote ("other: labrador", "x and other: y") had been seeded as aliases.
$S = array( 'DPL_Breeds_Repository', 'search' );
foreach ( array( 'dog', 'cat', 'bird', 'rabbit' ) as $sp ) {
	$hits  = call_user_func( $S, 'other', array( 'species' => $sp, 'limit' => 20 ) );
	$alias = array();
	foreach ( $hits as $h ) {
		if ( 0 === strpos( $h['matched_on'], 'alias' ) ) {
			$alias[] = $h['label'];
		}
	}
	check( "\"other\" in {$sp}: no breed offered via a picker-string alias", ! $alias, implode( ', ', array_slice( $alias, 0, 6 ) ) );
}
$setter = array_column( call_user_func( $S, 'red setter', array( 'species' => 'dog' ) ), 'label' );
check( '...while ordinary aliases still find their breed ("red setter")', in_array( 'Irish Setter', $setter, true ), implode( ', ', $setter ) );
$poodle = array_column( call_user_func( $S, 'toy poodle', array( 'species' => 'dog' ) ), 'label' );
check( '"toy poodle" finds Toy Poodle first, not Poodle', 'Toy Poodle' === ( $poodle[0] ?? null ), implode( ', ', $poodle ) );

// Resolution never depended on those aliases: the map rung matches first. Prove it for every
// picker string the change dropped, rather than assuming it.
$dropped = array_diff_key( $map, DPL_Breeds_Activator::map_aliases( $map ) );
$moved   = array();
$n       = 0;
foreach ( $dropped as $source => $entry ) {
	if ( ! preg_match( '/\bother\s*:/i', (string) $source ) ) {
		continue;
	}
	$n++;
	$r = DPL_Breeds_Resolver::resolve( (string) $source );
	if ( 'resolved' !== $r['status'] || DPL_Breeds_Resolver::format( $r ) !== DPL_Breeds_Resolver::format( $entry ) ) {
		$moved[] = "{$source} -> " . DPL_Breeds_Resolver::format( $r ) . " ({$r['status']}/{$r['rung']})";
	}
}
check( "all {$n} picker strings still resolve exactly as the map says, without their aliases",
	$n > 100 && ! $moved, implode( ' | ', array_slice( $moved, 0, 4 ) ) );
echo "\n";

echo "=================================\n";
echo "  PASSED : {$pass}\n";
echo "  FAILED : {$fail}\n";
echo "=================================\n\n";
exit( $fail > 0 ? 1 : 0 );
