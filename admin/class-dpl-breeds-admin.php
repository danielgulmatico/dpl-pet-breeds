<?php
/**
 * Admin screens: the vocabulary, a live search tester, and request moderation.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Admin {

	const CAP = 'manage_woocommerce';

	public static function register_menu() {
		add_menu_page(
			'Pet Breeds', 'Pet Breeds', self::CAP, 'dpl-breeds',
			array( __CLASS__, 'render_breeds' ), 'dashicons-pets', 57
		);
		add_submenu_page( 'dpl-breeds', 'Breeds', 'Breeds', self::CAP, 'dpl-breeds',
			array( __CLASS__, 'render_breeds' ) );
		add_submenu_page( 'dpl-breeds', 'Breed Requests', 'Requests', self::CAP,
			'dpl-breeds-requests', array( __CLASS__, 'render_requests' ) );
	}

	private static function redirect( $page, array $args = array() ) {
		wp_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* -------------------------------------------------------------- handlers */

	public static function handle_reseed() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'dpl_breeds_reseed' );
		$counts = DPL_Breeds_Activator::seed( true );
		DPL_Breeds_Repository::flush();
		// Record it, or the automatic re-seed would still think the data was stale and run again.
		DPL_Breeds_Activator::record_seeded();
		self::redirect( 'dpl-breeds', array( 'reseeded' => 1, 'a' => $counts['added'],
			'u' => $counts['updated'], 'r' => $counts['retired'],
			'am' => $counts['alias_rows'] ?? 0, 'av' => $counts['vbo_rows'] ?? 0 ) );
	}

	public static function handle_request_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'dpl_breeds_request_action' );

		$id  = isset( $_POST['request_id'] ) ? (int) $_POST['request_id'] : 0;
		$do  = sanitize_key( $_POST['do'] ?? '' );

		if ( 'approve' === $do ) {
			$res = DPL_Breeds_Requests::approve(
				$id,
				sanitize_text_field( $_POST['label'] ?? '' ),
				sanitize_key( $_POST['species'] ?? '' )
			);
			if ( is_wp_error( $res ) ) {
				self::redirect( 'dpl-breeds-requests', array( 'error' => $res->get_error_code() ) );
			}
			self::redirect( 'dpl-breeds-requests', array( 'approved' => 1 ) );
		}

		if ( 'reject' === $do ) {
			DPL_Breeds_Requests::reject( $id );
			self::redirect( 'dpl-breeds-requests', array( 'rejected' => 1 ) );
		}

		self::redirect( 'dpl-breeds-requests' );
	}

	/* ---------------------------------------------------------------- breeds */

	public static function render_breeds() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}

		$species_list = DPL_Breeds_Repository::species_with_lists();
		// The browser's species tab. Defaults to the first species, which is alphabetical.
		$species      = sanitize_key( $_GET['species'] ?? ( $species_list[0] ?? '' ) );
		$probe        = isset( $_GET['probe'] ) ? sanitize_text_field( wp_unslash( $_GET['probe'] ) ) : '';
		// The tester's scope is SEPARATE and defaults to all species. Sharing the browser's
		// species silently scoped every test to 'bird', so 'Chi' returned Chicken, not Chihuahua.
		$probe_species = isset( $_GET['probe_species'] ) ? sanitize_key( $_GET['probe_species'] ) : '';

		echo '<div class="wrap"><h1>Pet Breeds</h1>';
		printf(
			'<p>Centralised breed vocabulary. API version <code>%s</code>. Other plugins should call '
			. '<code>dpl_breed_search()</code>, <code>dpl_breed_resolve()</code>, '
			. '<code>dpl_breed_format()</code> and <code>dpl_breed_parse()</code> rather than reading these tables.</p>',
			esc_html( dpl_breed_api_version() )
		);

		if ( isset( $_GET['reseeded'] ) ) {
			printf(
				'<div class="notice notice-success"><p>Re-seeded: %d added, %d updated, %d retired. '
				. 'Aliases: %d from the reviewed map, %d from the ontology.</p></div>',
				intval( $_GET['a'] ?? 0 ), intval( $_GET['u'] ?? 0 ), intval( $_GET['r'] ?? 0 ),
				intval( $_GET['am'] ?? 0 ), intval( $_GET['av'] ?? 0 )
			);
		}

		/* ---- resolve tester: the fastest way to sanity-check the vocabulary ---- */
		echo '<hr><h2>Try it</h2>';
		echo '<form method="get"><input type="hidden" name="page" value="dpl-breeds">';
		printf( '<input type="hidden" name="species" value="%s">', esc_attr( $species ) );
		printf(
			'<input type="text" name="probe" value="%s" placeholder="staffy, dsh, cavoodle, labrador x" '
			. 'style="width:26em" class="regular-text"> ',
			esc_attr( $probe )
		);
		echo '<select name="probe_species">';
		echo '<option value="">All species</option>';
		foreach ( $species_list as $sp ) {
			printf( '<option value="%s"%s>%s</option>',
				esc_attr( $sp ), selected( $sp, $probe_species, false ), esc_html( $sp ) );
		}
		echo '</select> ';
		submit_button( 'Resolve', 'secondary', 'submit', false );
		echo '</form>';

		if ( '' !== $probe ) {
			$r = dpl_breed_resolve( $probe, $probe_species );
			// The whole result, never $r['breeds'] - that drops the cross flag (API 2.0).
			$stored = dpl_breed_format( $r );
			echo '<table class="widefat striped" style="max-width:720px;margin-top:1em"><tbody>';
			printf( '<tr><td>Input</td><td><code>%s</code></td></tr>', esc_html( $probe ) );
			printf( '<tr><td>Species scope</td><td>%s</td></tr>',
				$probe_species ? esc_html( $probe_species ) : '<em>all species</em>' );
			printf( '<tr><td>Status</td><td><strong>%s</strong></td></tr>', esc_html( $r['status'] ) );
			printf( '<tr><td>Matched by</td><td>%s</td></tr>', esc_html( $r['rung'] ?: '-' ) );
			printf( '<tr><td>Breeds</td><td>%s</td></tr>', esc_html( implode( ', ', wp_list_pluck( $r['breeds'], 'label' ) ) ?: '-' ) );
			printf( '<tr><td>Crossbreed</td><td>%s</td></tr>',
				empty( $r['cross'] ) ? 'no' : ( count( $r['breeds'] ) < 2 ? 'yes &mdash; unknown parent implied' : 'yes' ) );
			printf( '<tr><td>Would be stored as</td><td><code>%s</code></td></tr>', esc_html( $stored ?: '(nothing)' ) );
			echo '</tbody></table>';

			$hits = dpl_breed_search( $probe, array( 'species' => $probe_species, 'limit' => 8 ) );
			if ( $hits ) {
				echo '<h4>Search suggestions</h4><table class="widefat striped" style="max-width:720px">'
					. '<thead><tr><th>Label</th><th>Species</th><th>Slug</th><th>Matched on</th><th>Pets</th></tr></thead><tbody>';
				foreach ( $hits as $h ) {
					printf( '<tr><td><strong>%s</strong></td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%d</td></tr>',
						esc_html( $h['label'] ), esc_html( $h['species'] ), esc_html( $h['slug'] ),
						esc_html( $h['matched_on'] ), (int) $h['pet_count'] );
				}
				echo '</tbody></table>';
			}
		}

		/* ---- the list ---- */
		echo '<hr><h2>Vocabulary</h2><p>';
		foreach ( $species_list as $sp ) {
			$rows = DPL_Breeds_Repository::get_list( $sp );
			printf(
				'<a href="%s" class="button %s">%s <span class="count">(%d)</span></a> ',
				esc_url( add_query_arg( array( 'page' => 'dpl-breeds', 'species' => $sp ), admin_url( 'admin.php' ) ) ),
				$sp === $species ? 'button-primary' : '',
				esc_html( $sp ),
				count( $rows )
			);
		}
		echo '</p>';

		$rows = DPL_Breeds_Repository::get_list( $species );
		echo '<table class="widefat striped"><thead><tr><th>Label</th><th>Slug</th><th>Type</th>'
			. '<th>Source</th><th>VBO</th><th>Pets</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td><td>%s</td>'
				. '<td>%s</td><td>%d</td></tr>',
				esc_html( $r['label'] ), esc_html( $r['slug'] ), esc_html( $r['entry_type'] ),
				esc_html( $r['source'] ), esc_html( $r['vbo_id'] ), (int) $r['pet_count']
			);
		}
		echo '</tbody></table>';

		echo '<hr><h2>Re-seed</h2><p>Reloads the vocabulary from the shipped data files. Idempotent, and '
			. 'it never reassigns a slug &mdash; consumers may be holding them. Breeds the seed no longer '
			. 'lists are retired, not deleted.</p>'
			. '<p>It runs <strong>automatically</strong> on the first admin page a member of staff loads after '
			. 'the plugin version or its data files change. This button forces it.</p>';
		$seeded = get_option( DPL_Breeds_Activator::SEEDED_OPTION );
		if ( is_array( $seeded ) ) {
			printf(
				'<p><strong>Last seeded:</strong> version %s, %s. %s</p>',
				esc_html( (string) ( $seeded['version'] ?? '?' ) ),
				esc_html( wp_date( 'j M Y, H:i', (int) ( $seeded['at'] ?? 0 ) ) ),
				DPL_Breeds_Activator::needs_reseed()
					? '<span style="color:#b32d2e">Out of date with the installed files.</span>'
					: '<span style="color:#00a32a">Matches the installed files.</span>'
			);
		} else {
			echo '<p><strong>Last seeded:</strong> not recorded yet.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dpl_breeds_reseed' );
		echo '<input type="hidden" name="action" value="dpl_breeds_reseed">';
		submit_button( 'Re-seed from data files', 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/* -------------------------------------------------------------- requests */

	public static function render_requests() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		$pending = DPL_Breeds_Requests::pending();

		echo '<div class="wrap"><h1>Breed Requests</h1>';
		if ( isset( $_GET['approved'] ) ) {
			echo '<div class="notice notice-success"><p>Approved and added to the vocabulary.</p></div>';
		}
		if ( isset( $_GET['rejected'] ) ) {
			echo '<div class="notice notice-success"><p>Rejected.</p></div>';
		}
		if ( ! empty( $_GET['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $_GET['error'] ) );
		}

		echo '<p>Requests only reach here when the resolver could not match the string, so a '
			. 'misspelling is normally caught before a request is ever created. Approving adds the '
			. 'breed <em>and</em> aliases the requested wording to it, so the same phrasing resolves '
			. 'next time.</p>';

		if ( empty( $pending ) ) {
			echo '<p><em>Nothing pending.</em></p>';
			self::render_declined();
			echo '</div>';
			return;
		}

		$species_list = DPL_Breeds_Repository::species_with_lists();
		echo '<table class="widefat striped"><thead><tr><th>Requested</th><th>Count</th>'
			. '<th>Species</th><th>Label to add</th><th></th></tr></thead><tbody>';
		foreach ( $pending as $r ) {
			echo '<tr><td><code>' . esc_html( $r['requested'] ) . '</code>';
			if ( ! empty( $r['suggestion'] ) ) {
				echo '<br><small>possible match: ' . esc_html( $r['suggestion'] ) . '</small>';
			}
			echo '</td><td>' . (int) $r['request_count'] . '</td>';

			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" '
				. 'style="display:flex;gap:.4em;align-items:center">';
			wp_nonce_field( 'dpl_breeds_request_action' );
			echo '<input type="hidden" name="action" value="dpl_breeds_request_action">';
			printf( '<input type="hidden" name="request_id" value="%d">', (int) $r['id'] );
			echo '<select name="species">';
			foreach ( $species_list as $sp ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $sp ),
					selected( $sp, $r['species'], false ), esc_html( $sp ) );
			}
			echo '</select></td>';
			printf( '<td><input type="text" name="label" value="%s" class="regular-text"></td>',
				esc_attr( $r['requested'] ) );
			echo '<td>';
			submit_button( 'Approve', 'primary', 'do_approve', false, array( 'name' => 'do', 'value' => 'approve' ) );
			echo ' ';
			submit_button( 'Reject', 'delete', 'do_reject', false, array( 'name' => 'do', 'value' => 'reject' ) );
			echo '</td></form></tr>';
		}
		echo '</tbody></table>';
		self::render_declined();
		echo '</div>';
	}

	/**
	 * Declined strings people keep asking for.
	 *
	 * A rejection stops a string cluttering the queue, but requests for it keep being counted.
	 * Repeated demand for something already declined is the signal that the rejection was
	 * wrong, and it is worth nothing if it accumulates where nobody looks.
	 */
	private static function render_declined() {
		$declined = DPL_Breeds_Requests::declined_but_wanted( 3 );
		if ( empty( $declined ) ) {
			return;
		}

		echo '<hr><h2>Declined, but still being asked for</h2>';
		echo '<p>These were reviewed and not added, yet customers keep entering them. Three or '
			. 'more requests usually means the breed is real, or that the wording needs an alias '
			. 'rather than a rejection.</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>'
			. '<th>Requested</th><th>Species</th><th>Times asked</th></tr></thead><tbody>';
		foreach ( $declined as $r ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td><strong>%d</strong></td></tr>',
				esc_html( $r['requested'] ),
				esc_html( $r['species'] ?: '—' ),
				(int) $r['request_count']
			);
		}
		echo '</tbody></table>';
	}
}
