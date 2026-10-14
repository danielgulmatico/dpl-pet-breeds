<?php
/**
 * DPL Pet Breeds — automatic re-seed decision logic, offline.
 *
 *   php tests/test-auto-reseed.php
 *
 * Runs the REAL DPL_Breeds_Activator methods that decide whether to re-seed and that guard it
 * with a lock, against an in-memory stand-in for wp_options. The seed itself is not run here -
 * it needs the breed tables, and the E2E runner covers it against a live site.
 *
 * What it proves:
 *   - the fingerprint is stable, ignores re-uploading identical data, and moves when the plugin
 *     version or any of the three shipped data files changes
 *   - needs_reseed() follows it, including the uncached read used under the lock
 *   - the lock is exclusive, released properly, and a dead request's lock is taken over by
 *     exactly one successor (compare-and-swap), not by everyone at once
 *
 * Exits non-zero on failure, so it can gate a build.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	die( 'CLI only.' );
}

$plugin = dirname( __DIR__ );

// ---- --print-fingerprint <version> <path>: used below to prove the version is in the hash ----
if ( isset( $argv[1] ) && '--print-fingerprint' === $argv[1] ) {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DPL_BREEDS_VERSION', $argv[2] );
	define( 'DPL_BREEDS_PATH', rtrim( $argv[3], '/' ) . '/' );
	// wp_json_encode() is the stub declared further down: top-level functions are hoisted.
	require $plugin . '/includes/class-dpl-breeds-activator.php';
	echo DPL_Breeds_Activator::data_fingerprint();
	exit( 0 );
}

// ---- A throwaway copy of the data files, so the test can change them safely ----
$tmp = sys_get_temp_dir() . '/dpl-breeds-reseed-' . getmypid();
@mkdir( $tmp . '/includes/data', 0777, true );
foreach ( array( 'breeds-seed.php', 'breed-map.php', 'breed-aliases.php' ) as $f ) {
	copy( $plugin . '/includes/data/' . $f, $tmp . '/includes/data/' . $f );
}

register_shutdown_function( function () use ( $tmp ) {
	foreach ( (array) glob( $tmp . '/includes/data/*' ) as $f ) {
		@unlink( $f );
	}
	@rmdir( $tmp . '/includes/data' );
	@rmdir( $tmp . '/includes' );
	@rmdir( $tmp );
} );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DPL_BREEDS_VERSION', '2.2.0' );
define( 'DPL_BREEDS_PATH', $tmp . '/' );
define( 'DPL_BREEDS_TABLE', 'vpp_breeds' );
define( 'DPL_BREEDS_ALIAS_TABLE', 'vpp_breed_aliases' );

/* ---- wp_options, in memory, with the semantics the lock depends on ---- */
class Fake_WPDB {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	public $store   = array();

	public function prepare( $q, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
	}

	public function query( $sql ) {
		// INSERT IGNORE: affects one row if the name is free, none if it is taken. Atomic in MySQL.
		if ( preg_match( "/INSERT IGNORE INTO \S+ \(option_name, option_value, autoload\) VALUES \('([^']*)', '([^']*)'/", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $this->store ) ) {
				return 0;
			}
			$this->store[ $m[1] ] = $m[2];
			return 1;
		}
		// Compare-and-swap: only succeeds if the value is still the one we read.
		if ( preg_match( "/UPDATE \S+ SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'/", $sql, $m ) ) {
			if ( array_key_exists( $m[2], $this->store ) && (string) $this->store[ $m[2] ] === $m[3] ) {
				$this->store[ $m[2] ] = $m[1];
				return 1;
			}
			return 0;
		}
		throw new Exception( 'unexpected query: ' . $sql );
	}

	/** Runs once, straight after the next read - lets a test interleave a second request. */
	public $after_read = null;

	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \S+ WHERE option_name = '([^']*)'/", $sql, $m ) ) {
			$value = $this->store[ $m[1] ] ?? null;
			if ( is_callable( $this->after_read ) ) {
				$cb               = $this->after_read;
				$this->after_read = null;
				$cb();
			}
			return $value;
		}
		throw new Exception( 'unexpected get_var: ' . $sql );
	}

	public function delete( $table, $where ) {
		unset( $this->store[ $where['option_name'] ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

/* ---- The options API over the same store, serialised as WordPress stores it ---- */
// $GLOBALS['stale_cache'] plays a persistent object cache that has not caught up yet: get_option()
// sees it, the direct SELECT used under the lock does not.
$GLOBALS['stale_cache'] = array();
function get_option( $name, $default = false ) {
	if ( array_key_exists( $name, $GLOBALS['stale_cache'] ) ) {
		return $GLOBALS['stale_cache'][ $name ];
	}
	$s = $GLOBALS['wpdb']->store;
	return array_key_exists( $name, $s ) ? maybe_unserialize( $s[ $name ] ) : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wpdb']->store[ $name ] = is_scalar( $value ) ? (string) $value : serialize( $value );
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['wpdb']->store[ $name ] ); return true; }
function maybe_unserialize( $v ) { return is_string( $v ) && false !== ( $u = @unserialize( $v ) ) ? $u : $v; }
function wp_cache_delete() { return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }

require $plugin . '/includes/class-dpl-breeds-activator.php';

$pass = 0;
$fail = 0;
function check( string $label, bool $ok, string $detail = '' ) : void {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo '  [' . ( $ok ? 'PASS' : 'FAIL' ) . "] {$label}" . ( ! $ok && '' !== $detail ? "\n         {$detail}" : '' ) . "\n";
}
$A    = 'DPL_Breeds_Activator';
$lock = new ReflectionMethod( $A, 'acquire_lock' );
$lock->setAccessible( true );
$free = new ReflectionMethod( $A, 'release_lock' );
$free->setAccessible( true );

echo "\n=== DPL Pet Breeds — Automatic Re-seed ===\n\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 1. The fingerprint ---\n";

$f0 = $A::data_fingerprint();
check( 'is stable across calls', $f0 === $A::data_fingerprint() );

$aliases = $tmp . '/includes/data/breed-aliases.php';
$orig    = file_get_contents( $aliases );
file_put_contents( $aliases, $orig );
touch( $aliases, time() + 3600 );
check( 're-uploading identical data does not move it (content, not mtime)', $f0 === $A::data_fingerprint() );

file_put_contents( $aliases, $orig . "\n// regenerated\n" );
$f1 = $A::data_fingerprint();
check( 'a change to any shipped data file moves it', $f1 !== $f0 );
file_put_contents( $aliases, $orig );
check( '...and restoring the file restores it', $f0 === $A::data_fingerprint() );

rename( $aliases, $aliases . '.gone' );
$f2 = $A::data_fingerprint();
check( 'a missing data file still yields a fingerprint, and a different one', is_string( $f2 ) && 32 === strlen( $f2 ) && $f2 !== $f0 );
rename( $aliases . '.gone', $aliases );

// The version is part of it: same data, different DPL_BREEDS_VERSION, in a separate process.
$php   = escapeshellarg( PHP_BINARY );
$self  = escapeshellarg( __FILE__ );
$other = trim( (string) shell_exec( "$php $self --print-fingerprint 9.9.9 " . escapeshellarg( $tmp ) ) );
$same  = trim( (string) shell_exec( "$php $self --print-fingerprint 2.2.0 " . escapeshellarg( $tmp ) ) );
check( 'a plugin version change moves it, with the data untouched', 32 === strlen( $other ) && $other !== $f0, "got '$other'" );
check( 'the same version and data reproduce it exactly, in another process', $same === $f0, "got '$same'" );
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 2. needs_reseed() ---\n";

check( 'nothing recorded yet -> re-seed (an install that predates this feature)', true === $A::needs_reseed() );
$A::record_seeded();
check( 'just recorded -> nothing to do', false === $A::needs_reseed() );
check( '...and the uncached read under the lock agrees', false === $A::needs_reseed( true ) );

$rec                = get_option( $A::SEEDED_OPTION );
$rec['fingerprint'] = 'stale';
update_option( $A::SEEDED_OPTION, $rec );
check( 'a recorded fingerprint that no longer matches -> re-seed', true === $A::needs_reseed() && true === $A::needs_reseed( true ) );
$A::record_seeded();

file_put_contents( $aliases, $orig . "\n// shipped without a version bump\n" );
check( 'data shipped WITHOUT a version bump is still caught', true === $A::needs_reseed() );
file_put_contents( $aliases, $orig );
check( 'record keeps the version alongside the fingerprint',
	'2.2.0' === ( get_option( $A::SEEDED_OPTION )['version'] ?? null ) );
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 3. The lock ---\n";

check( 'the first request takes it', true === $lock->invoke( null ) );
check( 'a second request is refused while it is held', false === $lock->invoke( null ) );
$free->invoke( null );
check( 'released, it can be taken again', true === $lock->invoke( null ) );
$free->invoke( null );

// A request that died mid-seed never reaches `finally`. Its lock must not block forever...
$stale = (string) ( time() - $A::LOCK_TTL - 5 );
$GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] = $stale;
check( 'a lock older than LOCK_TTL is taken over', true === $lock->invoke( null ) );

// ...but two successors racing for the same dead lock must not BOTH win. Request A reads the
// stale timestamp; before A writes, request B runs its whole acquire and takes over. A's
// compare-and-swap then finds the value changed under it and must back off.
$GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] = $stale;
$b_won                         = null;
$GLOBALS['wpdb']->after_read   = function () use ( $lock, &$b_won ) {
	$b_won = $lock->invoke( null );
};
$a_won = $lock->invoke( null );
check( 'two successors that both saw the dead lock: exactly one wins', true === $b_won && false === $a_won,
	'A=' . var_export( $a_won, true ) . ' B=' . var_export( $b_won, true ) );
$free->invoke( null );

// A plain overwrite (no compare-and-swap) would let both through - prove the test can tell.
$GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] = $stale;
$naive = function () use ( $A ) {
	$db   = $GLOBALS['wpdb'];
	$held = (int) $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name = %s", $A::LOCK_OPTION ) );
	if ( ( time() - $held ) > $A::LOCK_TTL ) {
		$db->store[ $A::LOCK_OPTION ] = (string) time();
		return true;
	}
	return false;
};
$nb                          = null;
$GLOBALS['wpdb']->after_read = function () use ( $naive, &$nb ) { $nb = $naive(); };
$na                          = $naive();
check( '...whereas a takeover without compare-and-swap lets both in (the harness detects it)', true === $na && true === $nb );
$free->invoke( null );

$GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] = (string) ( time() - 60 );
check( 'a fresh lock held by a live request is respected', false === $lock->invoke( null ) );
$free->invoke( null );
check( 'release leaves no lock behind', ! array_key_exists( $A::LOCK_OPTION, $GLOBALS['wpdb']->store ) );
echo "\n";

/* ---------------------------------------------------------------------------------- */
echo "--- 4. maybe_reseed() ---\n";
// seed() itself is not runnable here: its first breed-table query hits the fake, which throws on
// anything it does not recognise. So "the seed ran" shows up as that exception, and "it did not
// run" as a clean null - which is exactly what these checks need to tell apart.
$store = function () { return $GLOBALS['wpdb']->store; };
$run   = function () use ( $A ) {
	try {
		return array( 'ok', $A::maybe_reseed() );
	} catch ( Exception $e ) {
		return array( 'seeded', $e->getMessage() );
	}
};

$A::record_seeded();
$before = $store();
$r      = $run();
check( 'current: does nothing, touches nothing', array( 'ok', null ) === $r && $before === $store() );

delete_option( $A::SEEDED_OPTION );
$GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] = (string) ( time() - 30 );
$r = $run();
check( 'stale but another request is mid-seed: backs off without seeding', array( 'ok', null ) === $r );
check( '...and leaves that request\'s lock alone', isset( $GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] ) );
$free->invoke( null );

// This request's cache still holds the old record; another request finished the seed meanwhile.
$A::record_seeded();
$GLOBALS['stale_cache'][ $A::SEEDED_OPTION ] = array( 'version' => '2.1.0', 'fingerprint' => 'old', 'at' => 1 );
$r = $run();
check( 'stale cache, but the DB says another request just seeded: the re-check under the lock skips it',
	array( 'ok', null ) === $r, var_export( $r, true ) );
check( '...and releases the lock', ! isset( $GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] ) );
$GLOBALS['stale_cache'] = array();

delete_option( $A::SEEDED_OPTION );
$r = $run();
check( 'stale and free: it seeds', 'seeded' === $r[0], var_export( $r, true ) );
check( 'a seed that throws still releases the lock (finally)', ! isset( $GLOBALS['wpdb']->store[ $A::LOCK_OPTION ] ) );
check( '...records nothing, so the next request retries', true === $A::needs_reseed( true ) );
check( '...and leaves no "updated" notice for staff', false === get_option( $A::NOTICE_OPTION ) );
echo "\n";

echo "=================================\n";
echo "  PASSED : {$pass}\n";
echo "  FAILED : {$fail}\n";
echo "=================================\n\n";
exit( $fail > 0 ? 1 : 0 );
