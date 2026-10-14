<?php
/**
 * Tables and seeding.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Activator {

	/** The SCHEMA version. Bump only when a table changes shape. */
	const VERSION_OPTION = 'dpl_breeds_db_version';
	const DB_VERSION     = '1.0.0';

	/** What was last seeded: plugin version + a fingerprint of the shipped data files. */
	const SEEDED_OPTION = 'dpl_breeds_seeded';

	/** Atomic lock held while a seed runs. Lives in wp_options for its UNIQUE key. */
	const LOCK_OPTION = 'dpl_breeds_reseed_lock';

	/** One-time admin notice describing an automatic re-seed. */
	const NOTICE_OPTION = 'dpl_breeds_reseed_notice';

	/** A lock older than this belongs to a request that died mid-seed. */
	const LOCK_TTL = 600;

	public static function activate() {
		self::create_tables();
		self::seed( true );
		update_option( self::VERSION_OPTION, self::DB_VERSION );
		self::record_seeded();
	}

	/**
	 * Schema only. Runs on plugins_loaded so an update without reactivation still lands.
	 *
	 * This used to re-seed as well, keyed on DB_VERSION - a schema constant that never moved
	 * past 1.0.0. So 1.0 -> 2.0 -> 2.1 never re-seeded, and on staging 2.1 ran for a while
	 * against the 1.x vocabulary with "Crossbreed" still listed as a dog breed. Seeding is now
	 * maybe_reseed()'s job, keyed on what actually matters. A schema change still forces one,
	 * by forgetting what was seeded.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::VERSION_OPTION, self::DB_VERSION );
		delete_option( self::SEEDED_OPTION );
	}

	/* ------------------------------------------------------- automatic re-seed */

	/**
	 * A fingerprint of everything seed() reads: the plugin version and the content of the three
	 * shipped data files.
	 *
	 * Content, not just the version, because the data can be regenerated and shipped without
	 * anyone bumping the version - and relying on a person remembering a step is precisely the
	 * failure this exists to remove. Content rather than mtimes, so re-uploading an identical
	 * build does not trigger a pointless seed.
	 *
	 * @return string
	 */
	public static function data_fingerprint() {
		$parts = array( 'v' => DPL_BREEDS_VERSION );
		foreach ( array( 'breeds-seed.php', 'breed-map.php', 'breed-aliases.php' ) as $file ) {
			$path           = DPL_BREEDS_PATH . 'includes/data/' . $file;
			$parts[ $file ] = file_exists( $path ) ? md5_file( $path ) : 'missing';
		}
		return md5( wp_json_encode( $parts ) );
	}

	/**
	 * Has the plugin version or the shipped data changed since the last seed?
	 *
	 * @param bool $fresh Read past the object cache - used under the lock, where another request
	 *                    may have just finished seeding and this one's cache is stale.
	 */
	public static function needs_reseed( $fresh = false ) {
		if ( $fresh ) {
			global $wpdb;
			$seeded = maybe_unserialize( $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::SEEDED_OPTION
			) ) );
		} else {
			$seeded = get_option( self::SEEDED_OPTION );
		}
		return ! is_array( $seeded ) || ( $seeded['fingerprint'] ?? '' ) !== self::data_fingerprint();
	}

	/** Note what has just been seeded, so the next request knows it is current. */
	public static function record_seeded() {
		update_option( self::SEEDED_OPTION, array(
			'version'     => DPL_BREEDS_VERSION,
			'fingerprint' => self::data_fingerprint(),
			'at'          => time(),
		), true );
	}

	/**
	 * Entry point from admin_init. Staff only, never an AJAX or cron request.
	 *
	 * A seed is ~1,800 writes. Running it on plugins_loaded meant whichever customer arrived
	 * first after a deploy paid for it - and admin-ajax.php fires admin_init too, so the
	 * customer-facing breed search would have done the same. The member of staff who just
	 * deployed loads an admin page within seconds, so in practice the gap is negligible, and
	 * they are the right person to see the notice.
	 */
	public static function maybe_reseed_admin() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::maybe_reseed();
	}

	/**
	 * Re-seed if the version or the data changed. Safe to call from anywhere.
	 *
	 * Locked, because two staff loading pages together would otherwise run two seeds at once,
	 * and interleaved upserts can insert alias rows pointing at a breed id of 0.
	 *
	 * @return array|null The seed counts if it ran, otherwise null.
	 */
	public static function maybe_reseed() {
		if ( ! self::needs_reseed() || ! self::acquire_lock() ) {
			return null;
		}

		$counts = null;
		try {
			// Re-check under the lock: the request that held it may have just finished.
			if ( self::needs_reseed( true ) ) {
				$before = get_option( self::SEEDED_OPTION );
				$counts = self::seed( true );
				DPL_Breeds_Repository::flush();
				self::record_seeded();
				update_option( self::NOTICE_OPTION, array(
					'from'   => is_array( $before ) ? (string) ( $before['version'] ?? '' ) : '',
					'to'     => DPL_BREEDS_VERSION,
					'counts' => $counts,
					'at'     => time(),
				), false );

				/**
				 * Fires after an automatic re-seed.
				 *
				 * @param array $counts added, updated, retired, alias_rows, vbo_rows.
				 */
				do_action( 'dpl_breeds_auto_reseeded', $counts );
			}
		} finally {
			self::release_lock();
		}
		return $counts;
	}

	/**
	 * Take the lock, or report that someone else holds it.
	 *
	 * INSERT IGNORE against wp_options' UNIQUE(option_name) is atomic, which get_option() and
	 * add_option() are not - both consult the object cache first. A PHP fatal mid-seed never
	 * reaches the `finally`, so a lock older than LOCK_TTL is taken over, with a compare-and-swap
	 * so only one request wins the takeover.
	 */
	private static function acquire_lock() {
		global $wpdb;
		$now = time();

		$got = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::LOCK_OPTION,
			(string) $now
		) );
		if ( 1 === (int) $got ) {
			return true;
		}

		$held = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			self::LOCK_OPTION
		) );
		if ( $held && ( $now - $held ) > self::LOCK_TTL ) {
			$taken = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $now,
				self::LOCK_OPTION,
				(string) $held
			) );
			return 1 === (int) $taken;
		}
		return false;
	}

	private static function release_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Say what an automatic re-seed did, once, to staff. A silent data change is worse than a
	 * slightly noisy one: someone should be able to see that the vocabulary moved.
	 */
	public static function reseed_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = get_option( self::NOTICE_OPTION );
		if ( ! is_array( $n ) ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );

		$c = (array) ( $n['counts'] ?? array() );
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'DPL Pet Breeds updated its breed vocabulary automatically.', 'dpl-pet-breeds' ),
			'' !== $n['from']
				? esc_html( sprintf( __( 'Version %1$s to %2$s:', 'dpl-pet-breeds' ), $n['from'], $n['to'] ) )
				: esc_html( sprintf( __( 'Version %s:', 'dpl-pet-breeds' ), $n['to'] ) ),
			esc_html( sprintf(
				/* translators: 1: added, 2: updated, 3: retired, 4: aliases */
				__( '%1$d breeds added, %2$d updated, %3$d retired; %4$d aliases rebuilt.', 'dpl-pet-breeds' ),
				(int) ( $c['added'] ?? 0 ),
				(int) ( $c['updated'] ?? 0 ),
				(int) ( $c['retired'] ?? 0 ),
				(int) ( $c['alias_rows'] ?? 0 ) + (int) ( $c['vbo_rows'] ?? 0 )
			) )
		);
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$breeds   = $wpdb->prefix . DPL_BREEDS_TABLE;
		$aliases  = $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE;
		$requests = $wpdb->prefix . DPL_BREEDS_REQUEST_TABLE;

		// This table may already exist, created by dpl-pet-profiles v3.5 with a narrower shape.
		// dbDelta on CREATE TABLE reconciles the columns without touching existing rows.
		dbDelta( "CREATE TABLE $breeds (
			id          mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
			species     varchar(32)           NOT NULL DEFAULT '',
			slug        varchar(128)          NOT NULL DEFAULT '',
			label       varchar(128)          NOT NULL DEFAULT '',
			entry_type  varchar(16)           NOT NULL DEFAULT 'breed',
			source      varchar(32)           NOT NULL DEFAULT '',
			vbo_id      varchar(32)           NOT NULL DEFAULT '',
			venom_code  varchar(32)           NOT NULL DEFAULT '',
			pet_count   mediumint(8) UNSIGNED NOT NULL DEFAULT 0,
			sort_order  smallint(5)  UNSIGNED NOT NULL DEFAULT 0,
			status      varchar(16)           NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY species_slug (species(32), slug(64)),
			KEY species (species),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE $aliases (
			id       mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
			species  varchar(32)           NOT NULL DEFAULT '',
			alias    varchar(190)          NOT NULL DEFAULT '',
			breed_id mediumint(8) UNSIGNED NOT NULL DEFAULT 0,
			source   varchar(32)           NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY species_alias (species(32), alias(150)),
			KEY breed_id (breed_id)
		) $charset;" );

		dbDelta( "CREATE TABLE $requests (
			id            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			species       varchar(32)         NOT NULL DEFAULT '',
			requested     varchar(190)        NOT NULL DEFAULT '',
			normalised    varchar(190)        NOT NULL DEFAULT '',
			suggestion    varchar(190)        NOT NULL DEFAULT '',
			confidence    decimal(4,3)        NOT NULL DEFAULT 0,
			request_count mediumint(8) UNSIGNED NOT NULL DEFAULT 1,
			pet_id        bigint(20) UNSIGNED          DEFAULT NULL,
			user_id       bigint(20) UNSIGNED          DEFAULT NULL,
			status        varchar(16)         NOT NULL DEFAULT 'pending',
			created_date  datetime                     DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY species_norm (species(32), normalised(150)),
			KEY status (status)
		) $charset;" );

		if ( ! wp_next_scheduled( 'dpl_breeds_request_digest' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dpl_breeds_request_digest' );
		}
	}

	/**
	 * Seed breeds and aliases from the shipped data files.
	 *
	 * Idempotent and identifier-preserving: upserts on (species, slug), never DELETE+INSERT,
	 * because a consumer may already hold a slug. Retires by status, never deletes.
	 *
	 * @param bool $retire_absent Retire active rows the seed no longer lists.
	 * @return array counts
	 */
	public static function seed( $retire_absent = true ) {
		global $wpdb;
		$breeds_t  = $wpdb->prefix . DPL_BREEDS_TABLE;
		$aliases_t = $wpdb->prefix . DPL_BREEDS_ALIAS_TABLE;

		$seed = self::load( 'breeds-seed.php' );
		$map  = self::load( 'breed-map.php' );

		$added = 0; $updated = 0; $retired = 0; $alias_rows = 0;
		$seen = array();
		$breed_id = array();   // "species|slug" => id

		foreach ( $seed as $species => $rows ) {
			$order = 0;
			foreach ( $rows as $row ) {
				$order++;
				$slug = $row['slug'];
				$seen[ $species . '|' . $slug ] = true;

				$data = array(
					'species'    => $species,
					'slug'       => $slug,
					'label'      => $row['label'],
					'entry_type' => $row['entry_type'],
					'source'     => $row['source'],
					'vbo_id'     => $row['vbo_id'],
					'pet_count'  => (int) $row['pet_count'],
					'sort_order' => $order,
					'status'     => 'active',
				);

				$id = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM `$breeds_t` WHERE species = %s AND slug = %s",
					$species, $slug
				) );

				if ( ! $id ) {
					// A row created by pet-profiles v3.5 will match on label but have no slug.
					// Adopt it so its id survives.
					$id = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM `$breeds_t` WHERE species = %s AND slug = '' AND LOWER(label) = %s LIMIT 1",
						$species, strtolower( $row['label'] )
					) );
				}

				if ( $id ) {
					$wpdb->update( $breeds_t, $data, array( 'id' => (int) $id ) );
					$updated++;
				} else {
					$wpdb->insert( $breeds_t, $data );
					$id = $wpdb->insert_id;
					$added++;
				}
				$breed_id[ $species . '|' . $slug ] = (int) $id;
			}
		}

		if ( $retire_absent ) {
			// source = 'request' is excluded deliberately. An approved add-breed request lives
			// OUTSIDE the shipped seed by definition, so retiring everything absent from the
			// seed file wiped every breed an admin had approved - on every re-seed, silently.
			// The alias cleanup below already had this exemption ("WHERE source <> 'request'");
			// the breed table did not, and that asymmetry was the bug.
			$rows = $wpdb->get_results(
				"SELECT id, species, slug FROM `$breeds_t` WHERE status = 'active' AND source <> 'request'",
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				if ( '' !== $r['slug'] && isset( $seen[ $r['species'] . '|' . $r['slug'] ] ) ) {
					continue;
				}
				$wpdb->update( $breeds_t, array( 'status' => 'retired' ), array( 'id' => (int) $r['id'] ) );
				$retired++;
			}
		}

		// Approved requests are not in the seed file, so their ids were never collected above.
		// The alias pass needs them, or a re-seed drops the aliases pointing at them.
		$req_rows = $wpdb->get_results(
			"SELECT id, species, slug FROM `$breeds_t` WHERE status = 'active' AND source = 'request'",
			ARRAY_A
		);
		foreach ( (array) $req_rows as $r ) {
			if ( '' !== $r['slug'] ) {
				$breed_id[ $r['species'] . '|' . $r['slug'] ] = (int) $r['id'];
			}
		}

		/* ---- aliases: source strings become searchable aliases of their destination ---- */
		$slugify = array( 'DPL_Breeds_Repository', 'slugify' );
		$wpdb->query( "DELETE FROM `$aliases_t` WHERE source <> 'request'" );
		$batch = array();
		foreach ( self::map_aliases( $map ) as $source => $label ) {
			$slug = call_user_func( $slugify, $label );
			foreach ( array_keys( $seed ) as $species ) {
				$key = $species . '|' . $slug;
				if ( ! isset( $breed_id[ $key ] ) ) {
					continue;
				}
				$batch[] = $wpdb->prepare( '(%s, %s, %d, %s)', $species, $source, $breed_id[ $key ], 'map' );
			}
		}
		foreach ( array_chunk( $batch, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT IGNORE INTO `$aliases_t` (species, alias, breed_id, source) VALUES "
				. implode( ',', $chunk )
			);
			$alias_rows += count( $chunk );
		}

		/* ---- ontology aliases: VBO synonyms, at the LOWEST precedence ---- */
		// Inserted AFTER the map batch deliberately. UNIQUE(species, alias) plus INSERT IGNORE
		// means whichever row lands first wins, so a curated alias can never be displaced by an
		// ontology synonym. That ordering is the guard that matters: VBO lists 'DSH' as a
		// synonym of German Shepherd Dog, which is what sent 399 cats to the wrong breed.
		// gen-vbo-aliases.mjs also rejects it outright, so this is the second of two defences.
		//
		// These add no breeds - every alias points at a breed already in breeds-seed.php. A
		// breed retired since the file was generated is skipped rather than resurrected.
		$vbo_rows = 0;
		$batch    = array();
		foreach ( self::load( 'breed-aliases.php' ) as $species => $pairs ) {
			foreach ( (array) $pairs as $alias => $slug ) {
				$key = $species . '|' . $slug;
				if ( ! isset( $breed_id[ $key ] ) ) {
					continue;
				}
				$batch[] = $wpdb->prepare( '(%s, %s, %d, %s)', $species, $alias, $breed_id[ $key ], 'vbo' );
			}
		}
		foreach ( array_chunk( $batch, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT IGNORE INTO `$aliases_t` (species, alias, breed_id, source) VALUES "
				. implode( ',', $chunk )
			);
			$vbo_rows += count( $chunk );
		}

		do_action( 'dpl_breeds_reseeded', $added, $retired );

		return compact( 'added', 'updated', 'retired', 'alias_rows', 'vbo_rows' );
	}

	/**
	 * Which map entries become searchable aliases: source string => the one breed label.
	 *
	 * The ONLY place this rule lives - seed() and the round-trip test both call it, so the
	 * test cannot drift from what production seeds. Resolution never needs these aliases (the
	 * map rung matches first); they exist so search finds a breed by the words people type.
	 *
	 * Excluded:
	 *  - Crosses. A cross is not a synonym of either parent. Under API 2.0 the test is the flag,
	 *    not the count: "staffy x" is array('Staffordshire Bull Terrier', 'cross' => true) -
	 *    one breed, but aliasing it would make "staffy x" claim to BE a Staffordshire. A mixed
	 *    breed, array('cross' => true), has no breed to point at.
	 *  - Anything but exactly one breed.
	 *  - The old picker's own serialisation: "american bulldog and other: american bulldog".
	 *    126 of those are purebred, so they were seeded as aliases, and a customer typing
	 *    "other" to find the Other option got Labrador, Poodle, Border Collie... offered as
	 *    "matched from other spellings". Nobody types these; the picker wrote them. A string a
	 *    person did type, such as "other horse", is kept.
	 *
	 * @param array $map breed-map.php.
	 * @return array<string,string>
	 */
	public static function map_aliases( array $map ) {
		$out = array();
		foreach ( $map as $source => $breeds ) {
			$source = (string) $source;
			if ( ! empty( $breeds['cross'] ) || preg_match( '/\bother\s*:/i', $source ) ) {
				continue;
			}
			$real = array();
			foreach ( (array) $breeds as $k => $v ) {
				if ( is_int( $k ) && is_string( $v ) && 'Crossbreed' !== $v ) {
					$real[] = $v;
				}
			}
			if ( 1 === count( $real ) ) {
				$out[ $source ] = $real[0];
			}
		}
		return $out;
	}

	private static function load( $file ) {
		$path = DPL_BREEDS_PATH . 'includes/data/' . $file;
		return file_exists( $path ) ? (array) require $path : array();
	}
}
