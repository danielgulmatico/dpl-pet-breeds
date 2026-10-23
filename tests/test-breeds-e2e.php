<?php
/**
 * DPL Pet Breeds — end-to-end test runner.
 *
 * Exercises the whole chain against a live site: the API surface, the schema, the shipped
 * vocabulary, resolution correctness, the storage format, and the request flow.
 *
 * Run via WP-CLI:  wp eval-file wp-content/plugins/dpl-pet-breeds/tests/test-breeds-e2e.php
 *
 * READ-ONLY BY DEFAULT, so it is safe to run against production. The two sections that
 * write (seed idempotency, breed requests) are skipped unless you opt in:
 *
 *   DPL_BREEDS_E2E_WRITES=1 wp eval-file wp-content/plugins/dpl-pet-breeds/tests/test-breeds-e2e.php
 *
 * Both write sections clean up after themselves and are idempotent by design, but the
 * default is off because "safe to re-run" is a claim worth proving on staging first.
 */

// ---- Bootstrap ---------------------------------------------------------------
// WP-CLI only:
//   wp eval-file wp-content/plugins/dpl-pet-breeds/tests/test-breeds-e2e.php
//
// There is deliberately NO way to run this from inside a web request. An earlier version
// offered an admin-gated escape hatch so a PHP runner plugin could execute it. That was a
// mistake and it took the site down: Code Snippets evaluates snippets before WordPress has
// established the current user, so the current_user_can() guard returned false for everyone,
// and the die() then fired on every request - front end, admin and wp-login alike. Recovery
// needed file-level access, because there was no working login page left to fix it from.
//
// The lesson is not "write a better capability check". It is that a test runner able to halt
// a request has no business executing during one on a client's site. Under `wp eval-file`
// ABSPATH is already set, so the block below never runs at all.
if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' !== PHP_SAPI ) {
		http_response_code( 403 );
		die( 'This test runner is CLI only.' );
	}
	$root    = dirname( __DIR__, 4 ); // plugin/tests -> plugin -> plugins -> wp-content -> WP root
	$wp_load = $root . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		die( 'Could not locate wp-load.php. Run via WP-CLI instead: wp eval-file ...' );
	}
	require_once $wp_load;
}

// ---- Tiny assertion harness --------------------------------------------------
// function_exists guards: the pet-profiles runner defines the same helpers, and both may
// be loaded in one WP-CLI process.
//
// `global` because `wp eval-file` includes this file inside a function: without it these are
// locals, the assertions below count into the globals instead, and the summary printed
// "PASSED : 0 / FAILED : 0" whatever happened - so a failing run looked clean (found 2026-10-21).
global $pass, $fail, $skip, $errors;
$pass   = 0;
$fail   = 0;
$skip   = 0;
$errors = array();

if ( ! function_exists( 'assert_true' ) ) {
	function assert_true( string $label, bool $result ) : void {
		global $pass, $fail, $errors;
		if ( $result ) {
			$pass++;
			echo "  [PASS] {$label}\n";
		} else {
			$fail++;
			$errors[] = $label;
			echo "  [FAIL] {$label}\n";
		}
	}
}

if ( ! function_exists( 'assert_equals' ) ) {
	function assert_equals( string $label, $expected, $actual ) : void {
		global $pass, $fail, $errors;
		if ( $expected === $actual ) {
			$pass++;
			echo "  [PASS] {$label}\n";
		} else {
			$fail++;
			$errors[] = $label;
			echo "  [FAIL] {$label}  expected=" . var_export( $expected, true )
				. "  got=" . var_export( $actual, true ) . "\n";
		}
	}
}

if ( ! function_exists( 'test_note' ) ) {
	function test_note( string $label ) : void {
		global $skip;
		$skip++;
		echo "  [NOTE] {$label}\n";
	}
}

/** Resolve and return the value that would actually be stored. */
function e2e_stored( $raw, $species = '' ) {
	$r = dpl_breed_resolve( $raw, $species );
	if ( 'resolved' !== $r['status'] ) {
		return '<' . $r['status'] . '>';
	}
	// The whole result. dpl_breed_format( $r['breeds'] ) - the API 1 call - drops the cross
	// flag, and would have made this test report "staffy x" as a pedigree.
	return dpl_breed_format( $r );
}

$writes = ( '1' === getenv( 'DPL_BREEDS_E2E_WRITES' ) );

echo "\n=== DPL Pet Breeds — End-to-End Test ===\n\n";

// =============================================================================
// 1. API surface
// =============================================================================
echo "--- 1. API surface ---\n";

$api = array(
	'dpl_breed_api_version', 'dpl_breed_search', 'dpl_breed_resolve', 'dpl_breed_get',
	'dpl_breed_list', 'dpl_breed_species', 'dpl_breed_format', 'dpl_breed_parse',
	'dpl_breed_decode', 'dpl_breed_request',
);
foreach ( $api as $fn ) {
	assert_true( "{$fn}() exists", function_exists( $fn ) );
}

// API 2.0 changed the result shape (crossbreed is a flag). A consumer written for 1.x that
// passes $r['breeds'] to dpl_breed_format() silently turns crosses into pedigrees, so the
// major version is the thing consumers must gate on.
if ( function_exists( 'dpl_breed_api_version' ) ) {
	assert_true( 'API is 2.x (crossbreed is a flag)', version_compare( dpl_breed_api_version(), '2.0.0', '>=' ) );
}

if ( ! function_exists( 'dpl_breed_resolve' ) ) {
	echo "\nThe breed API is not loaded. Is dpl-pet-breeds active? Aborting.\n";
	return;
}

assert_true( 'API version is a non-empty string', is_string( dpl_breed_api_version() ) && '' !== dpl_breed_api_version() );
assert_true( 'Repository class loaded',            class_exists( 'DPL_Breeds_Repository' ) );
assert_true( 'Resolver class loaded',              class_exists( 'DPL_Breeds_Resolver' ) );
echo "\n";

// =============================================================================
// 2. Schema
// =============================================================================
echo "--- 2. Schema ---\n";

global $wpdb;
foreach ( array( DPL_BREEDS_TABLE, DPL_BREEDS_ALIAS_TABLE, DPL_BREEDS_REQUEST_TABLE ) as $t ) {
	$table  = $wpdb->prefix . $t;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	assert_true( "table {$t} exists", $exists );
}

$cols = $wpdb->get_col( 'DESC ' . $wpdb->prefix . DPL_BREEDS_TABLE, 0 );
foreach ( array( 'id', 'species', 'slug', 'label', 'entry_type', 'source', 'pet_count' ) as $c ) {
	assert_true( "breeds table has column {$c}", in_array( $c, (array) $cols, true ) );
}
echo "\n";

// =============================================================================
// 3. Vocabulary matches the shipped seed
// =============================================================================
// Compared against the seed FILE rather than a hardcoded count, so approving a breed
// request does not make this test fail spuriously. The DB may be a superset; it must
// never be missing something we shipped.
echo "--- 3. Vocabulary vs shipped seed ---\n";

$seed_file = dirname( __DIR__ ) . '/includes/data/breeds-seed.php';
assert_true( 'breeds-seed.php present', file_exists( $seed_file ) );

$seed       = file_exists( $seed_file ) ? include $seed_file : array();
$seed_total = 0;
$missing    = array();

foreach ( $seed as $species => $rows ) {
	$seed_total += count( $rows );
	$live        = dpl_breed_list( $species );
	$live_slugs  = array();
	foreach ( (array) $live as $row ) {
		$live_slugs[ $row['slug'] ] = true;
	}
	assert_true(
		sprintf( 'species %-7s live %3d >= seed %3d', $species, count( (array) $live ), count( $rows ) ),
		count( (array) $live ) >= count( $rows )
	);
	foreach ( $rows as $row ) {
		if ( ! isset( $live_slugs[ $row['slug'] ] ) ) {
			$missing[] = $species . '/' . $row['slug'];
		}
	}
}

assert_equals( 'every seeded slug is live', array(), $missing );
assert_true( 'seed has all ten species', 10 === count( $seed ) );
echo "  [INFO] seed rows: {$seed_total}\n";

// Placeholder labels must never reach the picker. An ontology admin term
// ("Breed Not Listed Canine") became a canonical label once; this is the guard.
$placeholders = array();
$dupes        = array();
foreach ( dpl_breed_species() as $species ) {
	$seen = array();
	foreach ( (array) dpl_breed_list( $species ) as $row ) {
		$label = (string) $row['label'];
		if ( '' === trim( $label ) || preg_match( '/not listed|^unknown$|^n\/?a$|^none$|^other:/i', $label ) ) {
			$placeholders[] = $species . '/' . $label;
		}
		if ( isset( $seen[ $row['slug'] ] ) ) {
			$dupes[] = $species . '/' . $row['slug'];
		}
		$seen[ $row['slug'] ] = true;
	}
}
assert_equals( 'no placeholder labels in the vocabulary', array(), $placeholders );
assert_equals( 'no duplicate slugs within a species',     array(), $dupes );
echo "\n";

// =============================================================================
// 4. Separator invariants
// =============================================================================
// dpl_breed_decode() splits a stored value on ' x ' (and ' and ' for API 1 values) and reads
// '(cross)' as a marker. That is only unambiguous while no LIVE label contains any of them -
// this checks the live vocabulary, including breeds approved since the seed shipped, which the
// offline test cannot see. Black and Tan Coonhound is a real breed; this is the tripwire.
echo "--- 4. Separator invariants ---\n";

$reserved = array( '" x "' => '/(^|\s)x(\s|$)/i', '"(cross)"' => '/\(cross\)/i', '" and "' => '/(^|\s)and(\s|$)/i' );
$hits     = array();
$disguise = array();
foreach ( dpl_breed_species() as $species ) {
	foreach ( (array) dpl_breed_list( $species ) as $row ) {
		foreach ( $reserved as $what => $re ) {
			if ( preg_match( $re, $row['label'] ) ) {
				$hits[ $what ][] = $species . '/' . $row['label'];
			}
		}
		// Crossbreed is a flag, not a breed - including under another name.
		if ( preg_match( '/\b(crossbreed|crossbred|cross|mixed|mix|mongrel|non-pedigree|bitzer)\b/i', $row['label'] ) ) {
			$disguise[] = $species . '/' . $row['label'];
		}
	}
}
foreach ( array_keys( $reserved ) as $what ) {
	assert_equals( "no live label contains {$what}", array(), $hits[ $what ] ?? array() );
}
assert_equals( 'no live label is a crossbreed in disguise', array(), $disguise );
if ( $hits || $disguise ) {
	echo "  [WARN] decode() will mis-read these labels, or a cross has become a breed again.\n";
	echo "         Most likely via an approved add-breed request - check Breed Requests.\n";
}
echo "\n";

// =============================================================================
// 5. Resolution - the regressions that actually happened
// =============================================================================
// Every one of these was a real wrong answer at some point. They are the tail that
// coverage metrics hide, so they are asserted by exact stored value.
echo "--- 5. Resolution regressions ---\n";

$expect = array(
	// input            => stored value
	'dsh'               => 'Domestic Short Hair',            // was German Shepherd Dog (399 cats)
	'domestic'          => 'Domestic Short Hair',            // was Random-Bred Cat (923 cats)
	'domestic short hair' => 'Domestic Short Hair',
	'collie'            => 'Collie',                         // was Border Collie (VeNom: Collie unspecified)
	'toy poodle'        => 'Toy Poodle',                     // was Poodle
	'tabby'             => 'Mixed breed',                    // a coat pattern, not a breed
	'moggy'             => 'Mixed breed',                    // was the "breed" Crossbreed
	'rat'               => 'Rat',                            // was Rat Terrier
	'bernese'           => 'Bernese Mountain Dog',           // was Bernese Hound
	'staffy x'          => 'Staffordshire Bull Terrier (cross)',
	'labrador x'        => 'Labrador Retriever (cross)',
	'shitzu x'          => 'Shih Tzu (cross)',
	'cavoodle'          => 'Cavalier King Charles Spaniel x Poodle',
	'labradoodle'       => 'Labrador Retriever x Poodle',
	// What the old picker submitted when Crossbreed and Labrador were both ticked. Was mapped
	// to a plain Labrador, so the API 1 migration would have turned these into pedigrees.
	'crossbreed and labrador retriever' => 'Labrador Retriever (cross)',
	'labrador x golden' => 'Golden Retriever x Labrador Retriever',  // was Labrador only
);
foreach ( $expect as $raw => $want ) {
	assert_equals( sprintf( '%-21s -> %s', $raw, $want ), $want, e2e_stored( $raw ) );
}

// Colour words must not steer the match.
assert_equals( 'black and tan huntaway mix keeps its breed', false,
	false !== strpos( e2e_stored( 'black and tan huntaway mix' ), 'Coonhound' ) );
assert_equals( 'white and tan dalmatian x is not a Westie', false,
	false !== strpos( e2e_stored( 'white and tan dalmatian x' ), 'West Highland' ) );
echo "\n";

// =============================================================================
// 6. It never invents a breed
// =============================================================================
// The core promise. Junk must come back review/unknown, and any label we DO return must
// exist in the vocabulary - a resolved label that is not in the picker cannot round-trip
// through the edit form.
echo "--- 6. Never invents ---\n";

foreach ( array( 'zzzznotabreed', 'asdfhdfdsh', 'Good', 'Test', 'Pup', '@SUM(A1)', '???' ) as $junk ) {
	$r = dpl_breed_resolve( $junk );
	// '???' is a genuine map entry; everything else must not resolve. It inherited the old
	// "Crossbreed" and so reads as Mixed breed - whether it should be UNKNOWN instead is open.
	if ( '???' === $junk ) {
		test_note( "'???' resolves to " . e2e_stored( $junk ) . ' (mapped deliberately; unknown-vs-mixed is open)' );
		continue;
	}
	assert_true( sprintf( '%-14s does not resolve (got %s)', $junk, $r['status'] ), 'resolved' !== $r['status'] );
}

$invented = array();
$sample   = array_keys( $expect );
$sample   = array_merge( $sample, array( 'labxstaffy', 'jack russel x corgi', 'other: pembroke corgi' ) );
foreach ( $sample as $raw ) {
	$r = dpl_breed_resolve( $raw );
	if ( 'resolved' !== $r['status'] ) {
		continue;
	}
	foreach ( $r['breeds'] as $b ) {
		if ( null === dpl_breed_get( $b['slug'] ) ) {
			$invented[] = $raw . ' -> ' . $b['label'] . ' (' . $b['slug'] . ')';
		}
	}
}
assert_equals( 'every resolved breed exists in the vocabulary', array(), $invented );
echo "\n";

// =============================================================================
// 7. At most two breeds
// =============================================================================
echo "--- 7. Two-breed cap ---\n";

$over = array();
foreach ( array_merge( $sample, array( 'lab x staffy x huntaway', 'other: maltese shih tzu japanese spitz' ) ) as $raw ) {
	$r = dpl_breed_resolve( $raw );
	if ( count( (array) $r['breeds'] ) > 2 ) {
		$over[] = $raw . ' -> ' . count( $r['breeds'] );
	}
}
assert_equals( 'resolve() never returns more than two breeds', array(), $over );
echo "\n";

// =============================================================================
// 8. The storage format
// =============================================================================
echo "--- 8. format() / decode() contract ---\n";

// The four shapes, as agreed.
assert_equals( 'purebred',             'Labrador Retriever',                 dpl_breed_format( array( 'Labrador Retriever' ) ) );
assert_equals( 'one parent known',     'Staffordshire Bull Terrier (cross)', dpl_breed_format( array( 'Staffordshire Bull Terrier' ), true ) );
assert_equals( 'both parents known',   'Labrador Retriever x Poodle',        dpl_breed_format( array( 'Poodle', 'Labrador Retriever' ) ) );
assert_equals( 'neither parent known', 'Mixed breed',                        dpl_breed_format( array(), true ) );

assert_equals( 'format() of the same pair reversed matches', dpl_breed_format( array( 'Pug', 'Poodle' ) ), dpl_breed_format( array( 'Poodle', 'Pug' ) ) );
assert_equals( 'format([]) without a cross is empty',        '', dpl_breed_format( array() ) );
assert_equals( 'format() accepts a whole resolve() result',  'Pug (cross)', dpl_breed_format( array( 'breeds' => array( array( 'slug' => 'pug', 'label' => 'Pug' ) ), 'cross' => true ) ) );
assert_equals( 'format() refuses three breeds',               '', dpl_breed_format( array( 'Akita', 'Boxer', 'Pug' ) ) );
assert_equals( 'format() returns "" rather than truncating',  '', dpl_breed_format( array( str_repeat( 'A', 40 ), str_repeat( 'B', 40 ) ) ) );

assert_equals( 'decode() reads a one-parent cross',
	array( 'breeds' => array( 'Pug' ), 'cross' => true ), dpl_breed_decode( 'Pug (cross)' ) );
assert_equals( 'decode() reads an API 1 value',
	array( 'breeds' => array( 'Pug' ), 'cross' => true ), dpl_breed_decode( 'Crossbreed and Pug' ) );

$fits = true;
foreach ( $sample as $raw ) {
	$stored = e2e_stored( $raw );
	if ( 0 !== strpos( $stored, '<' ) && strlen( $stored ) > 64 ) {
		$fits = false;
	}
}
assert_true( 'every stored value fits varchar(64)', $fits );

assert_equals( 'parse() keeps its API 1 shape: labels only', array( 'Poodle', 'Pug' ), dpl_breed_parse( 'Poodle x Pug' ) );
assert_equals( 'parse("") is empty',                         array(), dpl_breed_parse( '' ) );

// Round-trip through the LIVE resolver (guarantee 7): what we store must resolve back to the
// same breeds AND the same cross flag, or the edit form and any re-run of the migration will
// disagree with the first run. A one-parent cross losing its flag is the case that matters.
$broken = array();
foreach ( $sample as $raw ) {
	$r = dpl_breed_resolve( $raw );
	if ( 'resolved' !== $r['status'] ) {
		continue;
	}
	$stored = dpl_breed_format( $r );
	if ( '' === $stored ) {
		continue;
	}
	$again = dpl_breed_resolve( $stored );
	$a = wp_list_pluck( $r['breeds'], 'label' );
	$b = wp_list_pluck( $again['breeds'], 'label' );
	sort( $a, SORT_STRING );
	sort( $b, SORT_STRING );
	if ( 'resolved' !== $again['status'] || $a !== $b || (bool) $r['cross'] !== (bool) $again['cross'] ) {
		$broken[] = $raw . ' -> ' . $stored . ' -> ' . $again['status'] . '/' . $again['rung'];
	}
}
assert_equals( 'resolve(format(resolve(x))) keeps breeds and flag', array(), $broken );

// Every canonical label must resolve to ITSELF, with status 'resolved'.
//
// This is the guarantee the migration depends on: format() writes canonical labels, so a
// value we wrote has to come back as an answer rather than a proposal. It was not true
// until the 'label' rung was added - 'Collie Smooth' and 'Mini Shetland Pony' were both
// written by Apply on staging and both came back 'review', which meant they would show as
// needing human review on every future pass despite already being correct.
$not_self   = array();
$not_solid  = array();
foreach ( dpl_breed_species() as $species ) {
	foreach ( (array) dpl_breed_list( $species ) as $row ) {
		$r = dpl_breed_resolve( $row['label'], $species );
		if ( 'resolved' !== $r['status'] ) {
			$not_solid[] = $species . '/' . $row['label'] . ' (' . $r['status'] . ')';
			continue;
		}
		if ( dpl_breed_format( $r ) !== $row['label'] ) {
			$not_self[] = $species . '/' . $row['label'] . ' -> ' . dpl_breed_format( $r );
		}
	}
}
// 'sheltie' is Shetland Sheepdog in dogs and a real coat variety in guinea pigs, but
// breed-map.php has no species dimension, so it cannot hold both readings. The map keeps
// the dog case; the guinea pig label stays in the vocabulary. Proper fix is a
// species-scoped alias. Anything NOT on this list is a real defect.
$accepted_rewrites = array( 'Sheltie' );
$not_self = array_values( array_filter(
	$not_self,
	function ( $entry ) use ( $accepted_rewrites ) {
		foreach ( $accepted_rewrites as $a ) {
			if ( false !== strpos( $entry, '/' . $a . ' -> ' ) ) {
				return false;
			}
		}
		return true;
	}
) );

assert_equals( 'every canonical label resolves, not merely proposes', array(), $not_solid );
assert_equals( 'every canonical label resolves to itself',           array(), $not_self );
echo "\n";

// =============================================================================
// 9. Species scoping
// =============================================================================
// The bug this section exists for: the admin tester shared its species variable with the
// vocabulary browser, which defaults to the first species alphabetically. So "Chi"
// searched birds only and returned Chicken instead of Chihuahua.
echo "--- 9. Species scoping ---\n";

$chi_all  = dpl_breed_search( 'chi', array( 'limit' => 5 ) );
$chi_bird = dpl_breed_search( 'chi', array( 'species' => 'bird', 'limit' => 5 ) );

assert_equals( 'unscoped "chi" ranks Chihuahua first', 'Chihuahua', $chi_all[0]['label'] ?? '' );
assert_equals( 'bird-scoped "chi" finds Chicken',      'Chicken',   $chi_bird[0]['label'] ?? '' );

$bird_only = true;
foreach ( $chi_bird as $hit ) {
	if ( 'bird' !== $hit['species'] ) {
		$bird_only = false;
	}
}
assert_true( 'a scoped search returns only that species', $bird_only );

// No species is open-matched: an empty species set must not fall through to every breed.
// 'rat' resolving to Rat Terrier, and 'black berkshire' to a pig, both came from that.
assert_equals( 'rat scoped to rodent is not a terrier', false,
	false !== strpos( e2e_stored( 'rat', 'rodent' ), 'Terrier' ) );
echo "\n";

// =============================================================================
// 10. Search tiers
// =============================================================================
echo "--- 10. Search tiers ---\n";

foreach ( array( 'staffy' => 'Staffordshire Bull Terrier', 'dsh' => 'Domestic Short Hair' ) as $q => $want ) {
	$hits = dpl_breed_search( $q, array( 'limit' => 3 ) );
	assert_equals( "search('{$q}') top hit", $want, $hits[0]['label'] ?? '' );
	assert_true( "search('{$q}') reports how it matched", ! empty( $hits[0]['matched_on'] ) );
}

assert_equals( 'search() invents nothing for junk', array(), dpl_breed_search( 'zzzznotabreed', array( 'limit' => 5 ) ) );

$limited = dpl_breed_search( 'a', array( 'limit' => 3 ) );
assert_true( 'search() honours limit', count( (array) $limited ) <= 3 );
echo "\n";

// =============================================================================
// 11. Known gaps
// =============================================================================
// Asserted as the CURRENT behaviour on purpose. When either is fixed this section fails,
// which is the reminder to update it - better than a gap nobody is tracking.
echo "--- 11. Known gaps (current behaviour, not desired behaviour) ---\n";

$cav_resolves  = 'resolved' === dpl_breed_resolve( 'cavoodle' )['status'];
$cav_findable  = ! empty( dpl_breed_search( 'cavoodle', array( 'limit' => 3 ) ) );
assert_true( 'cavoodle resolves', $cav_resolves );
assert_equals( 'KNOWN GAP: cavoodle is not findable by search', false, $cav_findable );
if ( ! $cav_findable ) {
	echo "  [NOTE] Cross names resolve but cannot be searched: an alias can only hang off a\n";
	echo "         single breed row. ~30 names people type (cavoodle, cockapoo, spoodle)\n";
	echo "         return nothing in the picker. Fix = multi-breed aliases.\n";
}

// Was "KNOWN GAP: labxstaffy is recorded as a PUREBRED Labrador". Since 2.5 it is held for
// VetPost's review with the rest of the 2026-10-14 audit's strings (includes/data/breed-review.php).
assert_equals( 'labxstaffy on a dog is held for review, not stored as a Labrador', '<review>', e2e_stored( 'labxstaffy', 'dog' ) );
assert_equals( 'KNOWN GAP: "cross bred" is unmapped', '<unknown>', e2e_stored( 'cross bred' ) );
echo "\n";

// =============================================================================
// 12. Seed idempotency and slug stability  (writes)
// =============================================================================
echo "--- 12. Seed idempotency (writes) ---\n";

if ( ! $writes ) {
	test_note( 'skipped - set DPL_BREEDS_E2E_WRITES=1 to run' );
} else {
	$before = array();
	foreach ( dpl_breed_species() as $species ) {
		foreach ( (array) dpl_breed_list( $species ) as $row ) {
			$before[ $species . '/' . $row['slug'] ] = (int) $row['id'];
		}
	}

	DPL_Breeds_Activator::seed( true );
	DPL_Breeds_Repository::flush();

	$after = array();
	foreach ( dpl_breed_species() as $species ) {
		foreach ( (array) dpl_breed_list( $species ) as $row ) {
			$after[ $species . '/' . $row['slug'] ] = (int) $row['id'];
		}
	}

	assert_equals( 'a re-seed adds no rows and removes none', count( $before ), count( $after ) );

	// Slugs are the public identifier - consumers may be holding them. Ids must survive.
	$reassigned = array();
	foreach ( $before as $key => $id ) {
		if ( isset( $after[ $key ] ) && $after[ $key ] !== $id ) {
			$reassigned[] = $key;
		}
	}
	assert_equals( 'a re-seed never reassigns an id', array(), $reassigned );
}
echo "\n";

// =============================================================================
// 13. Breed requests  (writes)
// =============================================================================
echo "--- 13. Breed requests (writes) ---\n";

if ( ! $writes ) {
	test_note( 'skipped - set DPL_BREEDS_E2E_WRITES=1 to run' );
} else {
	// A near-miss must be answered with a suggestion, not queued for a human.
	$near = dpl_breed_request( 'labradoor retreiver', 'dog' );
	assert_true( 'a misspelling returns a suggestion instead of a request',
		empty( $near['created'] ) && ! empty( $near['suggestion'] ) );

	// A genuinely unknown string should queue once, then dedupe.
	$novel = 'zzz-e2e-test-breed-' . gmdate( 'His' );
	$first = dpl_breed_request( $novel, 'dog' );
	assert_true( 'a novel string creates a request', ! empty( $first['created'] ) );

	$again = dpl_breed_request( $novel, 'dog' );
	assert_true( 'the same string does not create a second request', empty( $again['created'] ) );

	$requests_t = $wpdb->prefix . DPL_BREEDS_REQUEST_TABLE;
	$breeds_tt  = $wpdb->prefix . DPL_BREEDS_TABLE;

	// Species must be coerced to a vocabulary key. wp_vpp_pets.species holds 'Dog',
	// 'Canine' and 'Canine (Dog)'; storing those raw meant the same breed requested twice
	// never deduped, and approving one created a breed under a species no picker asks for.
	$msg = 'zzz-e2e-species-' . gmdate( 'His' );
	dpl_breed_request( $msg, 'Canine (Dog)' );
	$stored_species = $wpdb->get_var( $wpdb->prepare(
		"SELECT species FROM `$requests_t` WHERE normalised = %s", strtolower( $msg )
	) );
	assert_equals( "'Canine (Dog)' is stored as 'dog'", 'dog', $stored_species );

	$dupe = dpl_breed_request( $msg, 'dog' );
	assert_true( 'the same breed under a species alias dedupes', empty( $dupe['created'] ) );

	// A rejected request must not silently swallow later requests. Before the fix the dedupe
	// query ignored status, so declining a string made it unrequestable forever and the
	// growing count was invisible.
	$rej_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM `$requests_t` WHERE normalised = %s", strtolower( $msg )
	) );
	DPL_Breeds_Requests::reject( $rej_id );
	$after_reject = dpl_breed_request( $msg, 'dog' );
	assert_true( 'a rejected string reports that it was declined',
		empty( $after_reject['created'] ) && false !== stripos( $after_reject['message'], 'not added' ) );
	$rej_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT request_count FROM `$requests_t` WHERE id = %d", $rej_id ) );
	assert_true( 'repeat demand for a declined string is still counted', $rej_count >= 2 );
	assert_true( 'declined_but_wanted() surfaces it',
		! empty( DPL_Breeds_Requests::declined_but_wanted( 2 ) ) );

	// 2.7: every occasion is recorded on the request - who, which pet, which order, which screen.
	$ctx_req = dpl_breed_request( $novel, 'dog', array( 'pet_id' => 331, 'order_id' => 987654, 'source' => 'prescription' ) );
	$srcs    = DPL_Breeds_Requests::sources( $wpdb->get_var( $wpdb->prepare(
		"SELECT sources FROM `$requests_t` WHERE id = %d", (int) $first['request_id'] ) ) );
	assert_true( 'a repeat request appends where it came from',
		count( $srcs ) >= 3 && 331 === (int) end( $srcs )['pet_id'] && 987654 === (int) end( $srcs )['order_id']
		&& 'prescription' === end( $srcs )['source'] );

	// Approving as a breed that is already there means "that wording is this breed": an alias,
	// and the breed's row untouched. (Until 2.7 this was meant to be refused, but the guard only
	// ever caught a label under a DIFFERENT slug, so it quietly re-sourced the existing row.)
	$as_bc_text = 'zzz-e2e-asbc-' . gmdate( 'His' );
	$as_bc_req  = dpl_breed_request( $as_bc_text, 'dog' );
	$bc_before  = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM `$breeds_tt` WHERE species = 'dog' AND slug = %s", 'border-collie' ), ARRAY_A );
	$as_bc = DPL_Breeds_Requests::approve( (int) $as_bc_req['request_id'], 'Border Collie', 'dog' );
	assert_true( 'approving as an existing breed names that breed', (int) $as_bc === (int) $bc_before['id'] );
	assert_equals( 'and leaves its row as it was', $bc_before, $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM `$breeds_tt` WHERE id = %d", (int) $bc_before['id'] ), ARRAY_A ) );
	// By the exact text filed, never a recomputed timestamp: the second can tick over in between.
	$wpdb->delete( $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE, array( 'species' => 'dog', 'alias' => strtolower( $as_bc_text ) ) );
	$clash = dpl_breed_request( 'zzz-e2e-clash-' . gmdate( 'His' ), 'dog' );

	// approve() must refuse a label the reviewed map already sends elsewhere, which would leave
	// the new breed unreachable behind rung 1.
	// "Staffy": a map key sending the word to Staffordshire Bull Terrier. (This used "Collie" until
	// 2026-10-14, when Collie became a breed of its own.)
	$err2 = DPL_Breeds_Requests::approve( (int) $clash['request_id'], 'Staffy', 'dog' );
	assert_true( 'approving a map-shadowed label is refused',
		is_wp_error( $err2 ) && 'shadowed_by_map' === $err2->get_error_code() );

	// THE critical one: an approved breed lives outside the shipped seed, so a re-seed used
	// to retire it - every time, silently. Approve, re-seed, and it must still be active.
	$keep_label = 'ZZZ E2E Keep ' . gmdate( 'His' );
	$keep_req   = dpl_breed_request( $keep_label, 'dog' );
	$keep_id    = DPL_Breeds_Requests::approve( (int) $keep_req['request_id'], $keep_label, 'dog' );
	assert_true( 'a novel breed can be approved', is_int( $keep_id ) && $keep_id > 0 );

	DPL_Breeds_Activator::seed( true );
	DPL_Breeds_Repository::flush();
	$still = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$breeds_tt` WHERE id = %d", (int) $keep_id ) );
	assert_equals( 'a re-seed does NOT retire an approved breed', 'active', $still );

	// The approval also aliased the requested wording, so it must resolve now.
	$res = dpl_breed_resolve( $keep_label, 'dog' );
	assert_equals( 'an approved breed resolves afterwards', 'resolved', $res['status'] );

	// 2.7: Add a breed, under a species that does not exist yet, survives a re-seed the same way.
	$cow_sp = 'zzzcow' . gmdate( 'His' );
	$cow_id = DPL_Breeds_Vocabulary::add_breed( $cow_sp, 'ZZZ Hereford', array( 'zzz white face' ), 'admin' );
	assert_true( 'a breed can be added by hand under a new species', is_int( $cow_id ) && $cow_id > 0 );
	assert_true( 'the species exists at once', in_array( $cow_sp, dpl_breed_species(), true ) );
	assert_equals( 'its alias resolves at once', 'ZZZ Hereford', dpl_breed_canonicalise( 'zzz white face', $cow_sp )['value'] );
	DPL_Breeds_Activator::seed( true );
	DPL_Breeds_Repository::flush();
	assert_equals( 'a re-seed does NOT retire a breed added by hand', 'active',
		$wpdb->get_var( $wpdb->prepare( "SELECT status FROM `$breeds_tt` WHERE id = %d", (int) $cow_id ) ) );
	assert_equals( 'nor drop its alias', 'ZZZ Hereford', dpl_breed_canonicalise( 'zzz white face', $cow_sp )['value'] );
	$wpdb->delete( $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE, array( 'breed_id' => (int) $cow_id ) );
	$wpdb->delete( $breeds_tt, array( 'id' => (int) $cow_id ) );

	// Clean up every artefact this section created.
	$wpdb->delete( $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE, array( 'breed_id' => (int) $keep_id ) );
	$wpdb->delete( $breeds_tt, array( 'id' => (int) $keep_id ) );
	$removed = 0;
	foreach ( array( $first['request_id'] ?? 0, $rej_id, $as_bc_req['request_id'] ?? 0, $clash['request_id'] ?? 0, $keep_req['request_id'] ?? 0 ) as $rid ) {
		if ( $rid ) {
			$removed += (int) $wpdb->delete( $requests_t, array( 'id' => (int) $rid ) );
		}
	}
	assert_true( 'test artefacts cleaned up', $removed >= 5 );
	// The count above only proves what this run remembered to delete. On 2026-10-21 a reused
	// variable left one request behind while it still passed, so check the tables themselves.
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$requests_t` WHERE normalised LIKE 'zzz-e2e-%'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}" . DPL_BREEDS_ALIAS_TABLE . "` WHERE alias LIKE 'zzz%'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$breeds_tt` WHERE label LIKE 'ZZZ %' OR species LIKE 'zzzcow%'" );
	assert_equals( 'no test row is left in any breed table', 0, $left );
	DPL_Breeds_Repository::flush();
}
echo "\n";

// =============================================================================
// Summary
// =============================================================================
echo "=================================\n";
echo "  PASSED : {$pass}\n";
echo "  FAILED : {$fail}\n";
echo "  NOTES  : {$skip}\n";
if ( $fail > 0 ) {
	echo "\nFailed tests:\n";
	foreach ( $errors as $e ) {
		echo "  - {$e}\n";
	}
}
echo "=================================\n\n";

// Non-zero on failure, so the live run can gate a release like the offline suites do.
exit( $fail > 0 ? 1 : 0 );
