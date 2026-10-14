<?php
/**
 * DPL Pet Breeds — offline data test.
 *
 * Checks the shipped data files and the pure resolver logic with NO WordPress and NO
 * database, so it runs anywhere: a dev machine, a pre-commit hook, CI.
 *
 *   php tests/test-data-offline.php
 *
 * Exits non-zero on failure, so it can gate a build.
 *
 * This is the cheap layer. It cannot see the live vocabulary, aliases, or the search
 * tiers - tests/test-breeds-e2e.php covers those against a real site. What it CAN do is
 * prove the invariants that make the whole design safe, across all 2,083 map entries at
 * once rather than by sampling.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	die( 'CLI only.' );
}

// The data files guard on ABSPATH. Define it so they can be included standalone.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$plugin = dirname( __DIR__ );
$map    = include $plugin . '/includes/data/breed-map.php';
$seed   = include $plugin . '/includes/data/breeds-seed.php';
require_once $plugin . '/includes/class-dpl-breeds-resolver.php';

$pass   = 0;
$fail   = 0;
$errors = array();

function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail, $errors;
	if ( $ok ) {
		$pass++;
		echo "  [PASS] {$label}\n";
	} else {
		$fail++;
		$errors[] = $label;
		echo "  [FAIL] {$label}" . ( '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
	}
}

/** Map keys are normalised: lowercase, collapsed whitespace. */
function map_key( string $s ) : string {
	return trim( preg_replace( '/\s+/', ' ', strtolower( $s ) ) );
}

/**
 * The breeds in a map entry: its positional values. 'cross' is a flag, not a breed (API 2.0).
 *
 * Deliberately does NOT filter out a legacy "Crossbreed" token. If one ever reappears, it is
 * treated as a breed here - and section 2 then fails, because Crossbreed is no longer a label.
 */
function entry_breeds( $entry ) : array {
	$out = array();
	foreach ( (array) $entry as $k => $v ) {
		if ( is_int( $k ) && is_string( $v ) ) {
			$out[] = $v;
		}
	}
	return $out;
}

/** Two breeds is always a cross; one or none only when flagged. */
function entry_cross( $entry ) : bool {
	return ! empty( $entry['cross'] ) || count( entry_breeds( $entry ) ) >= 2;
}

echo "\n=== DPL Pet Breeds — Offline Data Test ===\n\n";

// =============================================================================
// 1. The data loads
// =============================================================================
echo "--- 1. Data files ---\n";
check( 'breed-map.php returns an array',   is_array( $map ) && $map );
check( 'breeds-seed.php returns an array', is_array( $seed ) && $seed );
check( 'seed covers ten species',          10 === count( $seed ) );

$labels = array();   // lowercase label => true
$rows   = 0;
foreach ( $seed as $species => $species_rows ) {
	$rows += count( $species_rows );
	foreach ( $species_rows as $r ) {
		$labels[ strtolower( $r['label'] ) ] = true;
	}
}
echo '  [INFO] ' . count( $map ) . " map keys, {$rows} seed rows, " . count( $labels ) . " distinct labels\n\n";

// =============================================================================
// 2. Never invents a breed
// =============================================================================
// The core promise, checked exhaustively: every destination of every map entry must be a
// label that actually exists in the vocabulary. A destination that is not in the picker
// cannot round-trip through the edit form.
echo "--- 2. Never invents ---\n";

$unknown = array();
foreach ( $map as $key => $breeds ) {
	foreach ( entry_breeds( $breeds ) as $b ) {
		if ( ! isset( $labels[ strtolower( $b ) ] ) ) {
			$unknown[ $b ][] = $key;
		}
	}
}
check(
	'every map destination exists in the seed',
	! $unknown,
	$unknown ? count( $unknown ) . ' unknown: ' . implode( ', ', array_slice( array_keys( $unknown ), 0, 8 ) ) : ''
);

$placeholders = array();
foreach ( array_keys( $labels ) as $l ) {
	if ( preg_match( '/not listed|^unknown$|^n\/?a$|^none$|^other:/i', $l ) ) {
		$placeholders[] = $l;
	}
}
check(
	'no placeholder labels in the vocabulary',
	! $placeholders,
	implode( ', ', $placeholders )
);
echo "\n";

// =============================================================================
// 3. Separator invariants
// =============================================================================
// decode() splits a stored value on ' x ' (and, for API 1 values, on ' and '), and reads
// '(cross)' and 'Mixed breed' as markers. All of that is only safe while no canonical label
// contains any of them. Black and Tan Coonhound is a real breed, so this is a live risk -
// if any of these fires, decode() needs a whole-string guard before it ships.
echo "--- 3. Separator invariants ---\n";

$reserved = array(
	'" x " (joins two parents)'      => '/(^|\s)x(\s|$)/',
	'"(cross)" (one parent known)'   => '/\(cross\)/',
	'" and " (API 1, still read)'    => '/(^|\s)and(\s|$)/',
);
foreach ( $reserved as $what => $re ) {
	$hit = array();
	foreach ( array_keys( $labels ) as $l ) {
		if ( preg_match( $re, $l ) ) {
			$hit[] = $l;
		}
	}
	check( "no canonical label contains {$what}", ! $hit, implode( ', ', $hit ) );
}
check( 'no canonical label is "Mixed breed"', ! isset( $labels['mixed breed'] ) );
echo "\n";

// =============================================================================
// 4. The storage contract
// =============================================================================
// breed is varchar(64) and a pet may have at most two breeds. Checked over every entry,
// because a single overflow silently drops a pet from the migration.
echo "--- 4. Storage contract ---\n";

$over_two = array();
$too_long = array();
$empty    = array();
foreach ( $map as $key => $breeds ) {
	$real = entry_breeds( $breeds );
	if ( count( $real ) > 2 ) {
		$over_two[] = $key;
	}
	// A mixed breed has no breeds but IS an answer. An entry with neither is meaningless.
	if ( ! $real && ! entry_cross( $breeds ) ) {
		$empty[] = $key;
		continue;
	}
	if ( '' === DPL_Breeds_Resolver::format( $breeds ) ) {
		$too_long[] = $key;
	}
}
check( 'no map entry yields more than two breeds', ! $over_two, implode( ', ', array_slice( $over_two, 0, 5 ) ) );
check( 'no map entry is empty (neither breeds nor cross)', ! $empty, implode( ', ', array_slice( $empty, 0, 5 ) ) );
check( 'every stored value fits varchar(64)',      ! $too_long, implode( ', ', array_slice( $too_long, 0, 5 ) ) );

$dupes = array();
foreach ( $map as $key => $breeds ) {
	$real = entry_breeds( $breeds );
	if ( count( $real ) !== count( array_unique( $real ) ) ) {
		$dupes[] = $key;
	}
}
check( 'no map entry repeats the same breed twice', ! $dupes, implode( ', ', array_slice( $dupes, 0, 5 ) ) );
echo "\n";

// =============================================================================
// 5. format() / parse()
// =============================================================================
echo "--- 5. format() / decode() ---\n";

$F = function () { return call_user_func_array( array( 'DPL_Breeds_Resolver', 'format' ), func_get_args() ); };

// The four shapes, exactly as agreed.
check( 'purebred              -> "Labrador Retriever"',
	'Labrador Retriever' === $F( array( 'Labrador Retriever' ) ) );
check( 'one parent known      -> "Staffordshire Bull Terrier (cross)"',
	'Staffordshire Bull Terrier (cross)' === $F( array( 'Staffordshire Bull Terrier' ), true ) );
check( 'both parents known    -> "Labrador Retriever x Poodle"',
	'Labrador Retriever x Poodle' === $F( array( 'Poodle', 'Labrador Retriever' ) ) );
check( 'neither parent known  -> "Mixed breed"',
	'Mixed breed' === $F( array(), true ) );

check( 'format() is order-insensitive',
	$F( array( 'Pug', 'Poodle' ) ) === $F( array( 'Poodle', 'Pug' ) ) );
check( 'two breeds are a cross even without the flag',
	'Poodle x Pug' === $F( array( 'Pug', 'Poodle' ), false ) );
check( 'format([]) without a cross is empty',
	'' === $F( array() ) );
check( 'format() takes a whole resolve() result, flag included',
	'Pug (cross)' === $F( array( 'breeds' => array( array( 'slug' => 'pug', 'label' => 'Pug' ) ), 'cross' => true ) ) );
check( 'format() takes a raw map entry, flag included',
	'Pug (cross)' === $F( array( 'Pug', 'cross' => true ) ) );
check( 'API 1 input "Crossbreed" is read as the flag, never written',
	'Pug (cross)' === $F( array( 'Crossbreed', 'Pug' ) ) );
check( 'format() refuses three breeds rather than storing them',
	'' === $F( array( 'Akita', 'Boxer', 'Pug' ) ) );
check( 'format() returns "" rather than truncating',
	'' === $F( array( str_repeat( 'A', 40 ), str_repeat( 'B', 40 ) ) ) );

$D = function ( $s ) { return DPL_Breeds_Resolver::decode( $s ); };
check( 'decode("Pug")                      -> purebred',     array( 'breeds' => array( 'Pug' ), 'cross' => false ) === $D( 'Pug' ) );
check( 'decode("Pug (cross)")              -> cross, Pug',   array( 'breeds' => array( 'Pug' ), 'cross' => true ) === $D( 'Pug (cross)' ) );
check( 'decode("Poodle x Pug")             -> cross, both',  array( 'breeds' => array( 'Poodle', 'Pug' ), 'cross' => true ) === $D( 'Poodle x Pug' ) );
check( 'decode("Mixed breed")              -> cross, none',  array( 'breeds' => array(), 'cross' => true ) === $D( 'Mixed breed' ) );
check( 'decode("") is empty and not a cross',                array( 'breeds' => array(), 'cross' => false ) === $D( '' ) );

// Values the migration already wrote under API 1 must still read correctly.
check( 'legacy "Crossbreed and Pug"       -> cross, Pug',   array( 'breeds' => array( 'Pug' ), 'cross' => true ) === $D( 'Crossbreed and Pug' ) );
check( 'legacy "Poodle and Pug"           -> cross, both',  array( 'breeds' => array( 'Poodle', 'Pug' ), 'cross' => true ) === $D( 'Poodle and Pug' ) );
check( 'legacy "Crossbreed"               -> cross, none',  array( 'breeds' => array(), 'cross' => true ) === $D( 'Crossbreed' ) );

check( 'parse() keeps its API 1 shape: labels only',
	array( 'Pug' ) === DPL_Breeds_Resolver::parse( 'Pug (cross)' ) );

// Round-trip every map entry: what we would store must decode back to the same breeds AND
// the same cross flag. A one-parent cross losing its flag is the failure that matters most,
// because it silently turns a "Staffy cross" into a pedigree Staffordshire Bull Terrier.
$broken = array();
foreach ( $map as $key => $breeds ) {
	$stored = DPL_Breeds_Resolver::format( $breeds );
	if ( '' === $stored ) {
		continue;
	}
	$want = entry_breeds( $breeds );
	sort( $want, SORT_STRING );
	$want = array( 'breeds' => $want, 'cross' => entry_cross( $breeds ) );
	if ( DPL_Breeds_Resolver::decode( $stored ) !== $want ) {
		$broken[] = $key . ' -> ' . $stored;
	}
}
check(
	'format/decode round-trips breeds AND flag for all ' . count( $map ) . ' map entries',
	! $broken,
	implode( ' | ', array_slice( $broken, 0, 5 ) )
);
echo "\n";

// =============================================================================
// 5b. Crossbreed is a flag, not a breed
// =============================================================================
// Until 2026-10 "Crossbreed" was a breed: a row in all ten species lists, the most common
// breed in the export (~1,894 pets), and a value that took one of the two breed slots. These
// guard against it coming back - including under a new name like "unknown" or "mix".
echo "--- 5b. Crossbreed is a flag ---\n";

foreach ( array( 'crossbreed', 'cross breed', 'mixed breed', 'mix', 'unknown', 'mixed' ) as $not_a_breed ) {
	check( sprintf( '"%s" is not a label in any species', $not_a_breed ), ! isset( $labels[ $not_a_breed ] ) );
}

$legacy_token = array();
$unflagged    = array();
$shape        = array( 'purebred' => 0, 'one known' => 0, 'both known' => 0, 'mixed' => 0 );
foreach ( $map as $key => $breeds ) {
	if ( in_array( 'Crossbreed', (array) $breeds, true ) ) {
		$legacy_token[] = $key;
	}
	$n = count( entry_breeds( $breeds ) );
	if ( $n >= 2 && empty( $breeds['cross'] ) ) {
		$unflagged[] = $key;                          // implied, but must be explicit
	}
	if ( ! entry_cross( $breeds ) ) {
		$shape['purebred']++;
	} elseif ( 0 === $n ) {
		$shape['mixed']++;
	} elseif ( 1 === $n ) {
		$shape['one known']++;
	} else {
		$shape['both known']++;
	}
}
check( 'no map entry still lists "Crossbreed" as a breed', ! $legacy_token, implode( ', ', array_slice( $legacy_token, 0, 5 ) ) );
check( 'every two-breed entry carries the cross flag explicitly', ! $unflagged, implode( ', ', array_slice( $unflagged, 0, 5 ) ) );

// Crossbreed in disguise. VBO itself models some crosses as breeds - "Horse crossbreed"
// (VBO:0017446) and "Maltese Jack Russell Mix" (VBO:0200858) both reached the vocabulary that
// way. An exact-name check misses them, so look for the words.
$disguised = array();
foreach ( array_keys( $labels ) as $l ) {
	if ( preg_match( '/\b(crossbreed|crossbred|cross|mixed|mix|mongrel|non-pedigree|bitzer)\b/i', $l ) ) {
		$disguised[] = $l;
	}
}
check( 'no label is a crossbreed in disguise ("X crossbreed", "Y Mix")', ! $disguised, implode( ', ', $disguised ) );

// The source string SAYS it is a cross, but the entry recorded a purebred. 45 entries were like
// this in the signed-off map: "crossbreed and labrador retriever" - what the old picker
// submitted when both boxes were ticked - mapped to a plain Labrador, so the API 1 migration
// would have quietly turned every such cross into a pedigree. Non-pedigree cat designations
// are excluded because whether DSH/DLH are breeds at all is still an open question.
$non_pedigree = array( 'Domestic Short Hair', 'Domestic Long Hair', 'Domestic Medium Hair' );
$dropped      = array();
foreach ( $map as $key => $breeds ) {
	$real = entry_breeds( $breeds );
	if ( entry_cross( $breeds ) || 1 !== count( $real ) || in_array( $real[0], $non_pedigree, true ) ) {
		continue;
	}
	if ( preg_match( '/\b(crossbreed|crossbred|cross\s?bread|cross|mixed\s?breed|mixed|mix)\b|(^|\s)x(\s|$)/i', $key ) ) {
		$dropped[] = $key . ' -> ' . $real[0];
	}
}
check( 'no source string that says "cross" is recorded as a purebred', ! $dropped, implode( ' ; ', array_slice( $dropped, 0, 5 ) ) );
echo '  [INFO] ' . implode( ', ', array_map( fn( $k, $v ) => "{$v} {$k}", array_keys( $shape ), $shape ) ) . "\n\n";

// =============================================================================
// 6. Regressions that actually happened
// =============================================================================
// Each of these was a real wrong answer at some point. They are asserted by exact stored
// value because they are the tail that a coverage metric hides.
echo "--- 6. Regressions ---\n";

$expect = array(
	'dsh'                 => 'Domestic Short Hair',            // was German Shepherd Dog, 399 cats
	'domestic'            => 'Domestic Short Hair',            // was Random-Bred Cat, 923 cats
	'domestic short hair' => 'Domestic Short Hair',
	'collie'              => 'Collie',                         // was Border Collie: a guess (VeNom "Collie unspecified")
	'boarder collie x'    => 'Border Collie (cross)',          // the misspelling still says Border Collie
	'toy poodle'          => 'Toy Poodle',                     // was Poodle: Dogs NZ registers the sizes
	'mini poodle x'       => 'Miniature Poodle (cross)',
	'cavoodle'            => 'Cavalier King Charles Spaniel x Poodle',   // a designer name keeps plain Poodle
	'cavachon'            => 'Bichon Frise x Cavalier King Charles Spaniel', // was the "breed" Cavachon
	'tabby'               => 'Mixed breed',                    // a coat pattern, not a breed
	'bengal and other: tabby' => 'Bengal',                     // the colour is not a second parent
	'shorthair tabby'     => 'Domestic Short Hair',            // a coat length was given
	'exotic'              => 'Exotic Shorthair',               // one breed, two names
	'quarter horse x'     => 'Quarter Horse (cross)',          // was "Horse (cross)"
	'moggy'               => 'Mixed breed',                    // was the "breed" Crossbreed
	'rat'                 => 'Rat',                            // was Rat Terrier
	'bernese'             => 'Bernese Mountain Dog',           // was Bernese Hound
	'staffy x'            => 'Staffordshire Bull Terrier (cross)',
	'labrador x'          => 'Labrador Retriever (cross)',
	'shitzu x'            => 'Shih Tzu (cross)',
	'cavoodle'            => 'Cavalier King Charles Spaniel x Poodle',
	'labradoodle'         => 'Labrador Retriever x Poodle',
);
foreach ( $expect as $raw => $want ) {
	$key = map_key( $raw );
	$got = isset( $map[ $key ] ) ? DPL_Breeds_Resolver::format( $map[ $key ] ) : '<not a map key>';
	check( sprintf( '%-21s -> %s', $raw, $want ), $want === $got, '' === $got ? '' : "got '{$got}'" );
}

// Colour words must not steer the match.
foreach ( array( 'black and tan huntaway mix' => 'Coonhound', 'white and tan dalmatian x' => 'West Highland' ) as $raw => $wrong ) {
	$key = map_key( $raw );
	$got = isset( $map[ $key ] ) ? DPL_Breeds_Resolver::format( $map[ $key ] ) : '';
	check( sprintf( '%-27s is not a %s', $raw, $wrong ), false === strpos( $got, $wrong ), "got '{$got}'" );
}

// Junk must be absent from the map entirely.
foreach ( array( 'zzzznotabreed', 'asdfhdfdsh', 'good', 'test', 'pup', '@sum(a1)' ) as $junk ) {
	check( sprintf( '%-14s is not mapped', $junk ), ! isset( $map[ map_key( $junk ) ] ) );
}
echo "\n";

// =============================================================================
// 6b. Map vs vocabulary conflicts
// =============================================================================
// A string that is BOTH a canonical breed label AND a map key pointing elsewhere is a
// silent rewrite: the map is rung 1 and beats the label rung, so the migration converts a
// real breed into something else. Found live on staging - "Quarter Horse" (5 pets) was
// being rewritten to "Crossbreed and Horse", and "Australian Labradoodle" (a recognised
// breed, VBO:0200092, 3 pets) to "Labrador Retriever and Poodle".
//
// This is the bin-split defect class wearing different clothes, and guard-split.mjs does
// not catch it: that looks for one breed under two labels via the ontology, whereas this is
// the map and the vocabulary disagreeing with each other.
echo "--- 6b. Map vs vocabulary ---\n";

$label_of = array();   // normalised label => array(label, pets)
foreach ( $seed as $species => $species_rows ) {
	foreach ( $species_rows as $r ) {
		$k = strtolower( $r['label'] );
		$label_of[ $k ]['label'] = $r['label'];
		$label_of[ $k ]['pets']  = ( $label_of[ $k ]['pets'] ?? 0 ) + (int) $r['pet_count'];
	}
}

$conflicts = array();
foreach ( $map as $key => $breeds ) {
	if ( ! isset( $label_of[ $key ] ) ) {
		continue;
	}
	$dest = DPL_Breeds_Resolver::format( $breeds );
	if ( $dest !== $label_of[ $key ]['label'] ) {
		$conflicts[ $label_of[ $key ]['label'] ] = $dest . ' [' . (int) $label_of[ $key ]['pets'] . ' pets]';
	}
}

// One accepted conflict, for a structural reason rather than an unmade decision.
//
// 'sheltie' is genuinely ambiguous - Shetland Sheepdog in dogs, and a real coat variety in
// guinea pigs - but breed-map.php has no species dimension, so it cannot hold both. The map
// keeps the dog reading, which is overwhelmingly the common case, and the guinea pig label
// stays in the vocabulary. The proper fix is a species-scoped alias, since the alias table
// IS keyed by species; that is a code change and no pet is affected today.
//
// Everything else on this list was resolved by fix-conflicts.mjs. Anything NEW appearing
// here is a real defect and fails the build.
$accepted = array( 'Sheltie' );
$unexpected = array_diff_key( $conflicts, array_flip( $accepted ) );

check(
	'no unreviewed map/vocabulary conflict',
	! $unexpected,
	$unexpected ? implode( ' ; ', array_map( fn( $k, $v ) => $k . ' -> ' . $v, array_keys( $unexpected ), $unexpected ) ) : ''
);
echo '  [INFO] ' . count( $conflicts ) . ' conflict(s) total, ' . count( $accepted ) . " accepted\n";
echo "         Sheltie is accepted because the map has no species dimension, not because\n";
echo "         the decision is outstanding. See fix-conflicts.mjs for the reasoning.\n\n";

// =============================================================================
// 6c. Ontology aliases
// =============================================================================
// breed-aliases.php carries VBO EXACT synonyms for breeds we already hold. It adds no
// breeds, so it needs no sign-off round - but it is machine-generated from an outside
// source, so it gets the strictest checks in this file.
//
// The case that matters: VBO lists "DSH" as a synonym of German Shepherd Dog. That exact
// synonym sent 399 cats to the wrong breed. If it ever appears here, the import filter has
// regressed and this fails.
echo "--- 6c. Ontology aliases ---\n";

$alias_file = $plugin . '/includes/data/breed-aliases.php';
if ( ! file_exists( $alias_file ) ) {
	echo "  [SKIP] breed-aliases.php not present\n\n";
} else {
	$vbo_aliases = include $alias_file;
	$n           = 0;
	$shadow_map  = array();
	$shadow_lbl  = array();
	$bad_target  = array();
	$claims      = array();

	// slug => label, for checking every alias points at a real breed
	$slug_label = array();
	foreach ( $seed as $species => $species_rows ) {
		foreach ( $species_rows as $r ) {
			$slug_label[ $species . '|' . $r['slug'] ] = $r['label'];
		}
	}

	foreach ( (array) $vbo_aliases as $species => $pairs ) {
		foreach ( (array) $pairs as $alias => $slug ) {
			$n++;
			if ( isset( $map[ $alias ] ) ) {
				$shadow_map[] = $alias;                       // would shadow a reviewed decision
			}
			if ( isset( $label_of[ $alias ] ) ) {
				$shadow_lbl[] = $alias;                       // already a canonical label
			}
			if ( ! isset( $slug_label[ $species . '|' . $slug ] ) ) {
				$bad_target[] = $species . '/' . $alias . ' -> ' . $slug;
			} else {
				$claims[ $alias ][ $slug_label[ $species . '|' . $slug ] ] = true;
			}
		}
	}

	$ambiguous = array();
	foreach ( $claims as $alias => $targets ) {
		if ( count( $targets ) > 1 ) {
			$ambiguous[] = $alias . ' -> ' . implode( ' | ', array_keys( $targets ) );
		}
	}

	check( 'no ontology alias shadows a reviewed map key', ! $shadow_map, implode( ', ', array_slice( $shadow_map, 0, 6 ) ) );
	check( 'no ontology alias duplicates a canonical label', ! $shadow_lbl, implode( ', ', array_slice( $shadow_lbl, 0, 6 ) ) );
	check( 'every ontology alias points at a live breed', ! $bad_target, implode( ', ', array_slice( $bad_target, 0, 6 ) ) );
	check( 'no ontology alias is claimed by two breeds', ! $ambiguous, implode( ' ; ', array_slice( $ambiguous, 0, 4 ) ) );
	check( "'dsh' is NOT an ontology alias (the 399-cat bug)", ! isset( $vbo_aliases['dog']['dsh'] ) && ! isset( $vbo_aliases['cat']['dsh'] ) );
	echo "  [INFO] {$n} ontology aliases across " . count( (array) $vbo_aliases ) . " species\n\n";
}

// =============================================================================
// 6d. Map aliases
// =============================================================================
// Which map entries become search aliases is decided by DPL_Breeds_Activator::map_aliases()
// alone; seed() and the round-trip test both call it. These are its invariants over all of
// the map. The search-level proof is section 6 of test-resolver-roundtrip.php.
echo "--- 6d. Map aliases ---\n";

require_once $plugin . '/includes/class-dpl-breeds-activator.php';
$map_aliases = DPL_Breeds_Activator::map_aliases( $map );

$picker = array();
$crossy = array();
foreach ( $map_aliases as $source => $label ) {
	if ( preg_match( '/\bother\s*:/i', $source ) ) {
		$picker[] = $source;
	}
	if ( ! empty( $map[ $source ]['cross'] ) ) {
		$crossy[] = $source;
	}
}
$excluded_picker = 0;
foreach ( $map as $source => $entry ) {
	if ( preg_match( '/\bother\s*:/i', (string) $source ) && empty( $entry['cross'] ) ) {
		$excluded_picker++;
	}
}
check( 'no alias is a string the old picker wrote ("x and other: y", "other: y")', ! $picker, implode( ', ', array_slice( $picker, 0, 4 ) ) );
check( 'no cross or mixed breed is aliased to a single breed', ! $crossy, implode( ', ', array_slice( $crossy, 0, 4 ) ) );
check( 'a plain spelling is still an alias (labrador -> Labrador Retriever)',
	'Labrador Retriever' === ( $map_aliases['labrador'] ?? null ) );
// The rule itself, on a fixed input: the live data no longer holds a purebred typed phrase that
// merely contains "other" ("other horse" now follows the unknown-breed decision).
$rule = DPL_Breeds_Activator::map_aliases( array(
	'other horse'      => array( 'Horse' ),
	'other: lab'       => array( 'Labrador Retriever' ),
	'x and other: lab' => array( 'Labrador Retriever' ),
	'mother hen'       => array( 'Chicken' ),
) );
check( 'a typed phrase that merely contains "other" is kept; only the picker\'s "other:" form is dropped',
	array( 'other horse', 'mother hen' ) === array_keys( $rule ) );
echo '  [INFO] ' . count( $map_aliases ) . " map aliases; {$excluded_picker} purebred picker strings left to the map rung\n\n";

// =============================================================================
// 6e. Held for review (2.5)
// =============================================================================
// breed-review.php: species => strings the 2026-10-14 audit found mapping to a wrong or lossy
// breed. canonicalise() answers 'review' for them and VetPost's admins decide by hand. The
// behaviour is proven in test-resolver-roundtrip.php section 7; these are the file's invariants.
echo "--- 6e. Held for review ---\n";

$review_file = $plugin . '/includes/data/breed-review.php';
$review      = file_exists( $review_file ) ? include $review_file : null;
check( 'breed-review.php returns a non-empty array', is_array( $review ) && $review );
$review = (array) $review;

$bad_species = array_diff( array_keys( $review ), array_keys( $seed ) );
check( 'every held species has a breed list', ! $bad_species, implode( ', ', $bad_species ) );

$unnormal = array();
$is_label = array();
$in_map   = array();
// Map entries a held string keeps because other species use them correctly, or because
// apply-signoff-research.mjs would put them back (see fix-audit-2026-10-14.mjs, KEEP).
$shared   = array( 'short hair', 'dsh', 'staffy', 'burmese and other: tabby' );
$held_n   = 0;
foreach ( $review as $species => $held ) {
	$own = array();
	foreach ( $seed[ $species ] ?? array() as $r ) {
		$own[ map_key( $r['label'] ) ] = true;
	}
	foreach ( (array) $held as $key => $why ) {
		$held_n++;
		if ( map_key( (string) $key ) !== (string) $key || '' === trim( (string) $why ) ) {
			$unnormal[] = "{$species}/{$key}";
		}
		// A held canonical label could never be saved from the picker again.
		if ( isset( $own[ (string) $key ] ) ) {
			$is_label[] = "{$species}/{$key}";
		}
		// A map entry is also offered by the pickers - as a search alias or a crossbreed name -
		// so a held string left in the map keeps offering the answer the hold withholds.
		if ( isset( $map[ (string) $key ] ) && ! in_array( (string) $key, $shared, true ) ) {
			$in_map[] = "{$species}/{$key}";
		}
	}
}
check( 'every held string is normalised and has a reason', ! $unnormal, implode( ', ', array_slice( $unnormal, 0, 4 ) ) );
check( 'no held string is a canonical label of its species (the picker could not save it)', ! $is_label, implode( ', ', $is_label ) );
check( 'no held string is still a map entry, apart from the shared ones', ! $in_map, implode( ', ', array_slice( $in_map, 0, 6 ) ) );
$aliased = array();
foreach ( (array) ( $vbo_aliases ?? array() ) as $species => $pairs ) {
	foreach ( array_keys( (array) $pairs ) as $alias ) {
		if ( isset( $review[ $species ][ $alias ] ) ) {
			$aliased[] = "{$species}/{$alias}";
		}
	}
}
check( 'no held string is an ontology alias of its species', ! $aliased, implode( ', ', $aliased ) );
check( 'the two bad ontology aliases behind held strings are gone (horse miniature, dog bully)',
	! isset( $vbo_aliases['horse']['miniature'] ) && ! isset( $vbo_aliases['dog']['bully'] ) );
echo "  [INFO] {$held_n} (species, string) pairs held across " . count( $review ) . " species\n\n";

// =============================================================================
// 7. Known gaps
// =============================================================================
// Asserted as CURRENT behaviour on purpose: when one is fixed this section fails, which is
// the prompt to update it. A gap nobody is tracking is worse than a failing test.
echo "--- 7. Known gaps (current behaviour, not desired) ---\n";

// "labxstaffy recorded as a purebred Labrador" was tracked here. Since 2.5 it is held for
// VetPost's review with the rest of the 2026-10-14 audit's strings, and its map entry is gone.
check( 'labxstaffy is no longer a map entry; dogs typing it are held for review',
	! isset( $map['labxstaffy'] ) && isset( $review['dog']['labxstaffy'] ) );

// Open decision, not yet made: "?", "???" and "cat" mean nobody recorded the breed, not that
// the pet is a cross. They inherited "Crossbreed" and so now read as "Mixed breed". Modelling
// them as UNKNOWN (no flag, no breeds) is the likely answer; this fails when it is settled.
check( 'KNOWN GAP: "?" is treated as mixed rather than unknown',
	isset( $map['?'] ) && 'Mixed breed' === DPL_Breeds_Resolver::format( $map['?'] ) );
check( 'KNOWN GAP: "cross bred" is unmapped',
	! isset( $map['cross bred'] ) );
check( 'KNOWN GAP: "border collie x fox terrier" is unmapped',
	! isset( $map['border collie x fox terrier'] ) );

// Cross names resolve but cannot be searched, because an alias hangs off one breed row.
$two_breed   = 0;
$portmanteau = array();
foreach ( $map as $key => $breeds ) {
	if ( 2 === count( entry_breeds( $breeds ) ) ) {
		$two_breed++;
		if ( preg_match( '/^[a-z]+$/', $key ) ) {
			$portmanteau[] = $key;
		}
	}
}
echo '  [INFO] ' . $two_breed . ' map entries yield two breeds; ' . count( $portmanteau )
	. " are single words people type\n";
echo "         (" . implode( ', ', array_slice( $portmanteau, 0, 8 ) ) . " ...)\n";
echo "         None are reachable by dpl_breed_search(). Fix = multi-breed aliases.\n\n";

// =============================================================================
// Summary
// =============================================================================
echo "=================================\n";
echo "  PASSED : {$pass}\n";
echo "  FAILED : {$fail}\n";
if ( $fail > 0 ) {
	echo "\nFailed tests:\n";
	foreach ( $errors as $e ) {
		echo "  - {$e}\n";
	}
}
echo "=================================\n\n";

exit( $fail > 0 ? 1 : 0 );
