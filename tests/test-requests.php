<?php
/**
 * DPL Pet Breeds — add-breed requests and Add a breed (2.7), against the REAL code with no database.
 *
 *   php tests/test-requests.php
 *
 * Runs DPL_Breeds_Requests, DPL_Breeds_Vocabulary, the resolver and the seed against an
 * in-memory stand-in for the three tables that answers the exact queries they issue. The shipped
 * data is loaded the way seed() loads it, so the vocabulary is the real one.
 *
 * What it proves:
 *   - every occasion a string is asked for is recorded on its request - who, which pet, which
 *     order, which screen - on the first request and on every repeat, pending or rejected
 *   - a misspelling or a known breed still creates nothing
 *   - approving adds the breed under 'request', aliases the wording, and the string resolves
 *   - Add a breed goes through the same guards (duplicate label, map shadow, not-a-breed words,
 *     separators, length) and the breed is searchable and resolvable at once
 *   - a new species comes into being with its first breed; species_key() then finds it by its
 *     clinical name, and 'cattle' no longer lands on the cat list by substring
 *   - a re-seed keeps breeds added by hand and by approval, with their aliases
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
define( 'DPL_BREEDS_VERSION', '2.7.0' );
define( 'DPL_BREEDS_API_VERSION', '2.4.0' );
define( 'DPL_BREEDS_TABLE', 'vpp_breeds' );
define( 'DPL_BREEDS_ALIAS_TABLE', 'vpp_breed_aliases' );
define( 'DPL_BREEDS_REQUEST_TABLE', 'vpp_breed_requests' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---- the WordPress surface the real code reaches for ---- */
$GLOBALS['opt'] = array(); $GLOBALS['trans'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['actions'] = array();
$GLOBALS['user'] = 0; $GLOBALS['mail'] = array();
function wp_cache_get() { return false; }
function wp_cache_set() { return true; }
function wp_cache_delete() { return true; }
function remove_accents( $s ) { return $s; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $n ); return true; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $f ) { $value = call_user_func_array( $f[0], array_slice( array_merge( array( $value ), $args ), 0, $f[1] ) ); }
	return $value;
}
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = array_merge( array( $hook ), $args ); }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['opt'][ $n ] = $v; return true; }
function get_transient( $n ) { return $GLOBALS['trans'][ $n ] ?? false; }
function set_transient( $n, $v, $t = 0 ) { $GLOBALS['trans'][ $n ] = $v; return true; }
function delete_transient( $n ) { unset( $GLOBALS['trans'][ $n ] ); return true; }
function get_current_user_id() { return $GLOBALS['user']; }
function current_time( $t ) { return '2026-10-21 10:00:00'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function get_bloginfo( $k ) { return 'Test Site'; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['mail'][] = compact( 'to', 'subject', 'body' ); return true; }
class WP_Error {
	private $code; private $msg;
	public function __construct( $code, $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
}

/* ---- three tables in memory, answering the queries the plugin issues ---- */
class Fake_WPDB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $tables = array( 'wp_vpp_breeds' => array(), 'wp_vpp_breed_aliases' => array(), 'wp_vpp_breed_requests' => array() );
	private $next = array( 'wp_vpp_breeds' => 1, 'wp_vpp_breed_aliases' => 1, 'wp_vpp_breed_requests' => 1 );
	public $log = array();

	public function prepare( $q, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $q );
	}
	private function unq( $v ) { return str_replace( "''", "'", $v ); }

	/** WHERE a = 'x' AND LOWER(b) = 'y' AND c <> 'z' AND d >= 3 AND e IN ('p','q') AND f NOT IN (...) */
	private function matches( array $row, $where ) {
		if ( '' === trim( $where ) ) { return true; }
		foreach ( preg_split( '/\s+AND\s+/i', trim( $where ) ) as $clause ) {
			if ( preg_match( "/^(\w+)\s+(NOT\s+)?IN\s*\(([^)]*)\)$/i", trim( $clause ), $m ) ) {
				preg_match_all( "/'((?:[^']|'')*)'/", $m[3], $vals );
				$in = in_array( (string) ( $row[ $m[1] ] ?? '' ), array_map( array( $this, 'unq' ), $vals[1] ), true );
				if ( $in === ( '' !== trim( $m[2] ) ) ) { return false; }
				continue;
			}
			if ( ! preg_match( "/^(LOWER\()?(\w+)\)?\s*(=|<>|>=)\s*(?:'((?:[^']|'')*)'|(-?\d+))$/", trim( $clause ), $m ) ) {
				throw new Exception( 'unparsed clause: ' . $clause );
			}
			$have = (string) ( $row[ $m[2] ] ?? '' );
			if ( '' !== $m[1] ) { $have = strtolower( $have ); }
			$want = isset( $m[5] ) && '' !== $m[5] ? $m[5] : $this->unq( $m[4] ?? '' );
			$ok   = '=' === $m[3] ? $have === $want : ( '<>' === $m[3] ? $have !== $want : (int) $have >= (int) $want );
			if ( ! $ok ) { return false; }
		}
		return true;
	}

	private function select( $sql ) {
		if ( ! preg_match( '/^\s*SELECT\s+(.+?)\s+FROM\s+`?(\w+)`?\s*(?:WHERE\s+(.+?))?\s*(?:ORDER BY\s+(.+?))?\s*(?:LIMIT\s+(\d+))?\s*$/is', $sql, $m ) ) {
			throw new Exception( 'unparsed select: ' . $sql );
		}
		$rows = array_values( array_filter( $this->tables[ $m[2] ], function ( $r ) use ( $m ) { return $this->matches( $r, $m[3] ?? '' ); } ) );
		if ( ! empty( $m[4] ) ) {
			$order = $m[4];
			usort( $rows, function ( $a, $b ) use ( $order ) {
				if ( preg_match( "/FIELD\(status,'pending','rejected','approved'\)/", $order ) ) {
					$rank = array( 'pending' => 0, 'rejected' => 1, 'approved' => 2 );
					$c = ( $rank[ $a['status'] ] ?? 9 ) <=> ( $rank[ $b['status'] ] ?? 9 );
					return $c ?: ( $a['id'] <=> $b['id'] );
				}
				foreach ( explode( ',', $order ) as $part ) {
					list( $col, $dir ) = array_pad( preg_split( '/\s+/', trim( $part ) ), 2, 'ASC' );
					$c = is_numeric( $a[ $col ] ?? null ) && is_numeric( $b[ $col ] ?? null ) ? ( $a[ $col ] <=> $b[ $col ] ) : strcmp( (string) ( $a[ $col ] ?? '' ), (string) ( $b[ $col ] ?? '' ) );
					if ( $c ) { return 'DESC' === strtoupper( $dir ) ? -$c : $c; }
				}
				return 0;
			} );
		}
		if ( ! empty( $m[5] ) ) { $rows = array_slice( $rows, 0, (int) $m[5] ); }
		if ( '*' !== trim( $m[1] ) ) {
			$cols = array_map( 'trim', explode( ',', $m[1] ) );
			$rows = array_map( function ( $r ) use ( $cols ) { $o = array(); foreach ( $cols as $c ) { $o[ $c ] = $r[ $c ] ?? null; } return $o; }, $rows );
		}
		return $rows;
	}
	public function get_results( $sql, $fmt = null ) { return $this->select( $sql ); }
	public function get_row( $sql, $fmt = null ) { $r = $this->select( $sql ); return $r ? $r[0] : null; }
	public function get_var( $sql ) { $r = $this->select( $sql ); return $r ? reset( $r[0] ) : null; }

	public function insert( $table, array $data ) {
		$data['id'] = $this->next[ $table ]++;
		$this->tables[ $table ][ $data['id'] ] = $data;
		$this->insert_id = $data['id'];
		$this->log[] = "insert $table " . json_encode( $data );
		return 1;
	}
	public function update( $table, array $data, array $where ) {
		$n = 0;
		foreach ( $this->tables[ $table ] as $id => $row ) {
			foreach ( $where as $k => $v ) { if ( (string) $row[ $k ] !== (string) $v ) { continue 2; } }
			$this->tables[ $table ][ $id ] = array_merge( $row, $data ); $n++;
		}
		$this->log[] = "update $table " . json_encode( $data ) . ' where ' . json_encode( $where );
		return $n;
	}
	public function delete( $table, array $where ) {
		$n = 0;
		foreach ( $this->tables[ $table ] as $id => $row ) {
			foreach ( $where as $k => $v ) { if ( (string) $row[ $k ] !== (string) $v ) { continue 2; } }
			unset( $this->tables[ $table ][ $id ] ); $n++;
		}
		return $n;
	}
	public function query( $sql ) {
		if ( preg_match( '/^\s*INSERT IGNORE INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*(.+)$/is', $sql, $m ) ) {
			$cols = array_map( 'trim', explode( ',', $m[2] ) );
			preg_match_all( "/\(((?:[^()']|'(?:[^']|'')*')+)\)/", $m[3], $tuples );
			$n = 0;
			foreach ( $tuples[1] as $t ) {
				preg_match_all( "/'((?:[^']|'')*)'|(-?\d+)/", $t, $vals, PREG_SET_ORDER );
				$row = array();
				foreach ( $cols as $i => $c ) { $row[ $c ] = isset( $vals[ $i ][2] ) && '' !== $vals[ $i ][2] ? (int) $vals[ $i ][2] : $this->unq( $vals[ $i ][1] ); }
				// UNIQUE(species, alias): the first row wins, later ones are ignored.
				foreach ( $this->tables[ $m[1] ] as $have ) {
					if ( $have['species'] === $row['species'] && $have['alias'] === $row['alias'] ) { continue 2; }
				}
				$this->insert( $m[1], $row ); $n++;
			}
			return $n;
		}
		if ( preg_match( '/^\s*DELETE FROM\s+`?(\w+)`?\s*(?:WHERE\s+(.+))?$/is', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->tables[ $m[1] ] as $id => $row ) {
				if ( $this->matches( $row, $m[2] ?? '' ) ) { unset( $this->tables[ $m[1] ][ $id ] ); $n++; }
			}
			return $n;
		}
		throw new Exception( 'unparsed query: ' . $sql );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once $plugin . '/includes/class-dpl-breeds-activator.php';
require_once $plugin . '/includes/class-dpl-breeds-repository.php';
require_once $plugin . '/includes/class-dpl-breeds-resolver.php';
require_once $plugin . '/includes/class-dpl-breeds-review.php';
require_once $plugin . '/includes/class-dpl-breeds-vocabulary.php';
require_once $plugin . '/includes/class-dpl-breeds-requests.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo '  [' . ( $ok ? 'PASS' : 'FAIL' ) . "] {$label}" . ( ! $ok && '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
}
$wpdb = $GLOBALS['wpdb'];
$req  = function ( $id ) use ( $wpdb ) { return $wpdb->tables['wp_vpp_breed_requests'][ $id ] ?? null; };
$R    = 'DPL_Breeds_Requests';
$V    = 'DPL_Breeds_Vocabulary';

/* ---- the vocabulary, loaded the way production loads it ---- */
$counts = DPL_Breeds_Activator::seed( true );
DPL_Breeds_Repository::flush();
echo "Seed\n";
check( 'the real seed loads into the stand-in', $counts['added'] > 400 && $counts['alias_rows'] > 500 && $counts['vbo_rows'] > 400, json_encode( $counts ) );
check( 'ten species', 10 === count( DPL_Breeds_Repository::species_with_lists() ) );
check( 'the resolver works against it', 'Labrador Retriever' === DPL_Breeds_Resolver::canonicalise( 'lab', 'dog' )['value'] );

echo "\nAn occasion (pure)\n";
$GLOBALS['user'] = 63;
$o = $R::occasion( array( 'pet_id' => 331, 'order_id' => '987654', 'user_id' => 0, 'source' => 'My Pets!', 'secret' => 'x', 'prescription_id' => -4 ) );
check( 'positive ids are kept as integers, non-positive ones dropped', 331 === $o['pet_id'] && 987654 === $o['order_id'] && ! isset( $o['prescription_id'] ), json_encode( $o ) );
check( 'the current user fills in when the caller gave none', 63 === $o['user_id'] );
check( 'source is a key; unknown context keys are ignored', 'mypets' === $o['source'] && ! isset( $o['secret'] ) );
check( 'it is stamped', '2026-10-21 10:00:00' === $o['at'] );
$GLOBALS['user'] = 0;
$o = $R::occasion( array( 'source' => array( 'not', 'a', 'string' ) ) );
check( 'no user, no source: only the stamp', array( 'at' => '2026-10-21 10:00:00' ) === $o, json_encode( $o ) );
check( 'garbage in the column reads as no occasions', array() === $R::sources( '{"oops' ) && array() === $R::sources( null ) && array() === $R::sources( '[1,2]' ) );
$json = '';
for ( $i = 1; $i <= $R::MAX_SOURCES + 5; $i++ ) { $json = $R::with_occasion( $json, array( 'at' => "t$i" ) ); }
$list = $R::sources( $json );
check( 'the newest MAX_SOURCES are kept', $R::MAX_SOURCES === count( $list ) && 't' . ( $R::MAX_SOURCES + 5 ) === end( $list )['at'] && 't6' === $list[0]['at'] );

echo "\nFiling a request\n";
$GLOBALS['user'] = 63;
$r = $R::create( 'Testbreed Notarealdog', 'Canine (Dog)', array( 'pet_id' => 331, 'source' => 'my-pets' ) );
check( 'a novel string creates a request', $r['created'] && $r['request_id'] > 0, json_encode( $r ) );
$row = $req( $r['request_id'] );
check( 'under the species key, with the first pet and user in their columns', 'dog' === $row['species'] && 331 === $row['pet_id'] && 63 === $row['user_id'] );
$occ = $R::sources( $row['sources'] );
check( 'and the occasion recorded', 1 === count( $occ ) && 331 === $occ[0]['pet_id'] && 63 === $occ[0]['user_id'] && 'my-pets' === $occ[0]['source'], $row['sources'] );
$created = array_values( array_filter( $GLOBALS['actions'], function ( $a ) { return 'dpl_breed_request_created' === $a[0]; } ) );
check( 'dpl_breed_request_created carries where it came from', 1 === count( $created ) && 331 === $created[0][2]['pet_id'] && 'my-pets' === $created[0][2]['source'], json_encode( $created ) );

$GLOBALS['user'] = 12354;
$r2 = $R::create( 'testbreed  NOTAREALDOG', 'dog', array( 'pet_id' => 4567, 'order_id' => 987654, 'source' => 'prescription' ) );
check( 'the same string again is counted, not duplicated', ! $r2['created'] && $r2['request_id'] === $r['request_id'] && 2 === $req( $r['request_id'] )['request_count'] );
$occ = $R::sources( $req( $r['request_id'] )['sources'] );
check( 'with its own occasion appended: user, pet, order, screen', 2 === count( $occ ) && 12354 === $occ[1]['user_id'] && 4567 === $occ[1]['pet_id'] && 987654 === $occ[1]['order_id'] && 'prescription' === $occ[1]['source'], json_encode( $occ ) );
check( 'the first requester stays in the columns', 331 === $req( $r['request_id'] )['pet_id'] && 63 === $req( $r['request_id'] )['user_id'] );
check( 'one request row for the string', 1 === count( $wpdb->tables['wp_vpp_breed_requests'] ) );

$GLOBALS['user'] = 0;
$near = $R::create( 'labradoor retreiver', 'dog' );
check( 'a misspelling is answered with a suggestion and creates nothing', ! $near['created'] && 'Labrador Retriever' === $near['suggestion']['label'] && 1 === count( $wpdb->tables['wp_vpp_breed_requests'] ) );
$known = $R::create( 'staffy x', 'dog' );
check( 'a known breed creates nothing', ! $known['created'] && 1 === count( $wpdb->tables['wp_vpp_breed_requests'] ) );
$mixed = $R::create( 'moggy', 'cat' );
check( 'a mixed breed is an answer, not a request', ! $mixed['created'] && false !== stripos( $mixed['message'], 'mixed' ) );

$pending = $R::pending();
check( 'pending() decodes the occasions', 1 === count( $pending ) && 2 === count( $pending[0]['occasions'] ) && 987654 === $pending[0]['occasions'][1]['order_id'] );
$R::send_digest();
check( 'the digest says where it was last asked from', 1 === count( $GLOBALS['mail'] ) && false !== strpos( $GLOBALS['mail'][0]['body'], 'last asked: user #12354, pet #4567, order #987654, via prescription' ), $GLOBALS['mail'][0]['body'] ?? '' );

echo "\nRejected, still counted\n";
$R::reject( $r['request_id'] );
$GLOBALS['user'] = 77;
$r3 = $R::create( 'Testbreed Notarealdog', 'dog', array( 'pet_id' => 9 ) );
check( 'a declined string says so and is not reopened', ! $r3['created'] && false !== stripos( $r3['message'], 'not added' ) && 'rejected' === $req( $r['request_id'] )['status'] );
$occ = $R::sources( $req( $r['request_id'] )['sources'] );
check( 'but who asked is still recorded', 3 === count( $occ ) && 77 === $occ[2]['user_id'] && 9 === $occ[2]['pet_id'] && 3 === $req( $r['request_id'] )['request_count'] );
check( 'declined_but_wanted() surfaces it', 1 === count( $R::declined_but_wanted( 3 ) ) && array() === $R::declined_but_wanted( 4 ) );

echo "\nApproving\n";
$GLOBALS['user'] = 1;
// "Label to add" naming a breed that is already there means: that wording IS this breed. An alias,
// and the breed's own row untouched.
$bc = null;
foreach ( $wpdb->tables['wp_vpp_breeds'] as $cand ) { if ( 'dog' === $cand['species'] && 'border-collie' === $cand['slug'] ) { $bc = $cand; break; } }
$kiwi = $R::create( 'Kiwi Collie', 'dog', array( 'pet_id' => 501 ) );
$res  = $R::approve( $kiwi['request_id'], 'Border Collie', 'dog' );
check( 'approving as an existing breed is accepted and names that breed', $res === $bc['id'], is_wp_error( $res ) ? $res->get_error_message() : (string) $res );
check( 'the existing row is untouched', $bc === $wpdb->tables['wp_vpp_breeds'][ $bc['id'] ] );
check( 'the wording became its alias', 'Border Collie' === DPL_Breeds_Resolver::canonicalise( 'kiwi collie', 'dog' )['value'] && 'approved' === $req( $kiwi['request_id'] )['status'] );

// A label equal to an existing one under a DIFFERENT slug is the bin split the guard exists for.
// No shipped row is like that (slugs are slugify(label)), so plant one.
$wpdb->insert( 'wp_vpp_breeds', array( 'species' => 'dog', 'slug' => 'bc-old', 'label' => 'Blue Collie', 'entry_type' => 'breed', 'source' => 'seed', 'vbo_id' => '', 'venom_code' => '', 'pet_count' => 0, 'sort_order' => 1, 'status' => 'active' ) );
DPL_Breeds_Repository::flush();
$want = $R::create( 'Xoloitzcuintle', 'dog', array( 'pet_id' => 500, 'source' => 'staff-pet' ) );
check( 'a real breed the list lacks is queued', $want['created'] );
$err = $R::approve( $want['request_id'], 'Blue Collie', 'dog' );
check( 'approving as a label that exists under another slug is refused', is_wp_error( $err ) && 'duplicate_label' === $err->get_error_code(), is_wp_error( $err ) ? $err->get_error_code() : 'no error' );
$err = $R::approve( $want['request_id'], 'Staffy', 'dog' );
check( 'approving as a label the map shadows is refused', is_wp_error( $err ) && 'shadowed_by_map' === $err->get_error_code(), is_wp_error( $err ) ? $err->get_error_code() : 'no error' );
$err = $R::approve( $want['request_id'], 'Crossbreed', 'dog' );
check( 'approving as "Crossbreed" is refused - it is a flag', is_wp_error( $err ) && 'invalid_label' === $err->get_error_code() );
check( '... and nothing was written', 'pending' === $req( $want['request_id'] )['status'] );
$id = $R::approve( $want['request_id'], 'Xoloitzcuintli', 'dog' );
check( 'approving under a corrected label adds the breed', is_int( $id ) && $id > 0, is_wp_error( $id ) ? $id->get_error_message() : '' );
$b = $wpdb->tables['wp_vpp_breeds'][ $id ];
check( 'under source "request", active', 'request' === $b['source'] && 'active' === $b['status'] && 'xoloitzcuintli' === $b['slug'] && 'dog' === $b['species'] );
check( 'the request is marked approved', 'approved' === $req( $want['request_id'] )['status'] );
$res = DPL_Breeds_Resolver::resolve( 'xoloitzcuintle', 'dog' );
check( 'the wording that was asked for now resolves, by alias', 'resolved' === $res['status'] && 'alias' === $res['rung'] && 'Xoloitzcuintli' === $res['breeds'][0]['label'], json_encode( $res ) );
check( 'and so does the label', 'Xoloitzcuintli' === DPL_Breeds_Resolver::canonicalise( 'XOLOITZCUINTLI', 'dog' )['value'] );
check( 'dpl_breed_added fired', in_array( 'dpl_breed_added', array_column( $GLOBALS['actions'], 0 ), true ) );

echo "\nAdd a breed, and a species with it\n";
check( 'no cow species yet; "cattle" is not a cat', ! in_array( 'cow', DPL_Breeds_Repository::species_with_lists(), true ) && '' === DPL_Breeds_Repository::species_key( 'cattle' ) && '' === DPL_Breeds_Repository::species_key( 'Bovine' ) );
check( 'species_key() still answers the spellings it did', 'dog' === DPL_Breeds_Repository::species_key( 'Canine (Dog)' ) && 'guinea' === DPL_Breeds_Repository::species_key( 'guinea pig' ) && 'cat' === DPL_Breeds_Repository::species_key( 'Feline' ) && 'cat' === DPL_Breeds_Repository::species_key( 'cat' ) );
foreach ( array( 'c' => 'invalid_species', '' => 'no_species', 'cow!' => null, 'Cow' => null ) as $sp => $code ) {
	$k = $V::species_for_new( $sp );
	if ( null === $code ) { check( "species_for_new('$sp') is 'cow'", 'cow' === $k, is_wp_error( $k ) ? $k->get_error_message() : (string) $k ); }
	else { check( "species_for_new('$sp') is refused: $code", is_wp_error( $k ) && $code === $k->get_error_code() ); }
}
check( 'an existing species by any spelling is itself', 'dog' === $V::species_for_new( 'Canine (Dog)' ) && 'guinea' === $V::species_for_new( 'Guinea Pig' ) );
foreach ( array( 'Crossbreed', 'Mixed breed', 'unknown', 'Other', 'mix', 'Labrador x Poodle', 'Kelpie (cross)', 'Lab and Poodle', '', str_repeat( 'a', 57 ) ) as $bad ) {
	check( sprintf( '"%s" cannot be a breed', mb_substr( $bad, 0, 20 ) ), '' !== $V::label_problem( $bad ) );
}
check( 'an ordinary label can', '' === $V::label_problem( 'Hereford' ) && '' === $V::label_problem( 'Manx' ) && '' === $V::label_problem( 'Mixed Up Terrier' ) );

$id = $V::add_breed( 'Cow', 'Hereford', array( ' White Face ', 'hereford', 'herefords' ), 'admin' );
check( 'adding Hereford under a new species "Cow" works', is_int( $id ) && $id > 0, is_wp_error( $id ) ? $id->get_error_message() : '' );
$b = $wpdb->tables['wp_vpp_breeds'][ $id ];
check( 'stored under key "cow", source "admin"', 'cow' === $b['species'] && 'admin' === $b['source'] && 'Hereford' === $b['label'] && 'hereford' === $b['slug'] );
$al = array_values( array_filter( $wpdb->tables['wp_vpp_breed_aliases'], function ( $a ) use ( $id ) { return $a['breed_id'] === $id; } ) );
check( 'aliases normalised, the label itself not aliased', 2 === count( $al ) && 'white face' === $al[0]['alias'] && 'herefords' === $al[1]['alias'] && 'admin' === $al[0]['source'], json_encode( $al ) );
check( 'the species exists now', in_array( 'cow', DPL_Breeds_Repository::species_with_lists(), true ) && 1 === count( DPL_Breeds_Repository::get_list( 'cow' ) ) );
check( 'species_key() finds it by its clinical names', 'cow' === DPL_Breeds_Repository::species_key( 'Bovine' ) && 'cow' === DPL_Breeds_Repository::species_key( 'cattle' ) && 'cow' === DPL_Breeds_Repository::species_key( 'Cow' ) );
$c = DPL_Breeds_Resolver::canonicalise( 'white face', 'cattle' );
check( 'it resolves at once, by alias, through canonicalise()', 'resolved' === $c['status'] && 'Hereford' === $c['value'] && 'cow' === $c['species'], json_encode( $c ) );
// (Not "Angus": the shipped list already has Angus and Cattle under 'other', and a label of
// another species still answers - a known gap. A cow species would need those moved.)
$c = DPL_Breeds_Resolver::canonicalise( 'Hereford x Zebu', 'cow' );
check( 'an unknown partner keeps the whole value for a person', 'resolved' !== $c['status'] && 'Hereford x Zebu' === $c['value'], json_encode( $c ) );
$hits = DPL_Breeds_Repository::search( 'here', array( 'species' => 'cow' ) );
check( 'and search finds it', 1 === count( $hits ) && 'Hereford' === $hits[0]['label'] );
check( '"Hereford" asked for as a request is now already in the list', ! $R::create( 'Hereford', 'cow' )['created'] );

$again = $V::add_breed( 'cow', 'HEREFORD', array(), 'admin' );
check( 'adding it again is the same row, not a duplicate', $again === $id && 1 === count( DPL_Breeds_Repository::get_list( 'cow' ) ) );
$same = $V::add_breed( 'dog', 'Border collie', array( 'bc' ), 'admin' );
check( 'adding a breed already there is that breed, its row untouched', $same === $bc['id'] && $bc === $wpdb->tables['wp_vpp_breeds'][ $bc['id'] ], json_encode( array( $same, $bc, $wpdb->tables['wp_vpp_breeds'][ $bc['id'] ] ?? null ) ) );
check( '... and the alias attached', 'Border Collie' === DPL_Breeds_Resolver::canonicalise( 'bc', 'dog' )['value'], json_encode( DPL_Breeds_Resolver::canonicalise( 'bc', 'dog' ) ) );
$err = $V::add_breed( 'dog', 'blue collie', array(), 'admin' );
check( 'a label already there under another slug is refused', is_wp_error( $err ) && 'duplicate_label' === $err->get_error_code() );
$err = $V::add_breed( 'dog', 'Staffy', array(), 'admin' );
check( 'a label the map sends elsewhere is refused', is_wp_error( $err ) && 'shadowed_by_map' === $err->get_error_code() );
$err = $V::add_breed( 'dog', 'Mixed breed', array(), 'admin' );
check( 'a crossbreed under any name is refused', is_wp_error( $err ) && 'invalid_label' === $err->get_error_code() );
$err = $V::add_breed( 'x', 'Hereford', array(), 'admin' );
check( 'a bad species key is refused', is_wp_error( $err ) && 'invalid_species' === $err->get_error_code() );

// A retired seed row brought back must survive the next re-seed: its source moves to the
// manual one. "Cavoodle" was retired on purpose, but is shadowed by the map, so use a seed row
// retired here for the test.
$row = null;
foreach ( $wpdb->tables['wp_vpp_breeds'] as $cand ) { if ( 'dog' === $cand['species'] && 'seed' !== $cand['source'] && 'admin' !== $cand['source'] && 'request' !== $cand['source'] && 'Huntaway' === $cand['label'] ) { $row = $cand; break; } }
if ( ! $row ) { foreach ( $wpdb->tables['wp_vpp_breeds'] as $cand ) { if ( 'dog' === $cand['species'] && 'Huntaway' === $cand['label'] ) { $row = $cand; break; } } }
$wpdb->update( 'wp_vpp_breeds', array( 'status' => 'retired' ), array( 'id' => $row['id'] ) );
DPL_Breeds_Repository::flush();
$back = $V::add_breed( 'dog', 'Huntaway', array(), 'admin' );
check( 'a retired breed is reactivated under the same id', $back === $row['id'] && 'active' === $wpdb->tables['wp_vpp_breeds'][ $row['id'] ]['status'] );
check( 'with the manual source, so the re-seed will not retire it again', 'admin' === $wpdb->tables['wp_vpp_breeds'][ $row['id'] ]['source'] );

echo "\nA re-seed keeps what people added\n";
$before_aliases = count( $wpdb->tables['wp_vpp_breed_aliases'] );
$counts = DPL_Breeds_Activator::seed( true );
DPL_Breeds_Repository::flush();
check( 'the seed ran', $counts['updated'] > 400, json_encode( $counts ) );
check( 'Hereford is still active, with its aliases', 'active' === $wpdb->tables['wp_vpp_breeds'][ $id ]['status'] && 'Hereford' === DPL_Breeds_Resolver::canonicalise( 'white face', 'cow' )['value'] );
check( 'the approved request\'s breed too', 'Xoloitzcuintli' === DPL_Breeds_Resolver::canonicalise( 'xoloitzcuintle', 'dog' )['value'] );
check( 'Huntaway, brought back by hand, was NOT retired again', 'active' === $wpdb->tables['wp_vpp_breeds'][ $row['id'] ]['status'] );
check( 'the alias count is unchanged', $before_aliases === count( $wpdb->tables['wp_vpp_breed_aliases'] ), $before_aliases . ' vs ' . count( $wpdb->tables['wp_vpp_breed_aliases'] ) );
check( 'cow is still a species', in_array( 'cow', DPL_Breeds_Repository::species_with_lists(), true ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
