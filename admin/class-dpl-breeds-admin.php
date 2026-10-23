<?php
/**
 * Admin screens: the vocabulary, a live search tester, Add a breed, and request moderation.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Admin {

	const CAP = 'manage_woocommerce';

	/**
	 * What the `source` a consumer records on a request means to a person. Consumers name
	 * themselves (dpl_breed_canonicalise() context 'source'); anything not listed shows as is.
	 */
	const SOURCE_NAMES = array(
		'my-pets'      => 'My Pets (customer)',
		'staff-pet'    => 'Pets admin (staff)',
		'prescription' => 'Prescriptions admin',
		'scanner'      => 'Prescription scanner',
	);

	public static function register_menu() {
		add_menu_page(
			'Pet Breeds', 'Pet Breeds', self::CAP, 'dpl-breeds',
			array( __CLASS__, 'render_breeds' ), 'dashicons-pets', 57
		);
		add_submenu_page( 'dpl-breeds', 'Breeds', 'Breeds', self::CAP, 'dpl-breeds',
			array( __CLASS__, 'render_breeds' ) );
		$n = count( DPL_Breeds_Requests::pending() );
		add_submenu_page( 'dpl-breeds', 'Breed Requests',
			'Requests' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), self::CAP,
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

	/**
	 * Add a breed (2.7). A species the vocabulary does not have yet is created by its first
	 * breed - that is what "add a species" means here. Everything that can go wrong is refused
	 * by DPL_Breeds_Vocabulary::add_breed() with a reason, shown back on the page.
	 */
	public static function handle_add_breed() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'dpl_breeds_add_breed' );

		$new_species = isset( $_POST['new_species'] ) ? sanitize_text_field( wp_unslash( $_POST['new_species'] ) ) : '';
		$species     = '' !== trim( $new_species ) ? $new_species : sanitize_key( $_POST['species'] ?? '' );
		$label       = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$aliases     = isset( $_POST['aliases'] ) ? sanitize_text_field( wp_unslash( $_POST['aliases'] ) ) : '';
		$aliases     = array_filter( array_map( 'trim', explode( ',', $aliases ) ) );

		$known_before = DPL_Breeds_Repository::species_with_lists();
		$res          = DPL_Breeds_Vocabulary::add_breed( $species, $label, $aliases, 'admin' );
		if ( is_wp_error( $res ) ) {
			self::redirect( 'dpl-breeds', array(
				'error' => $res->get_error_code(), 'msg' => rawurlencode( $res->get_error_message() ),
				'species' => sanitize_key( $_POST['species'] ?? '' ),
			) );
		}
		$breed = null;
		foreach ( DPL_Breeds_Repository::vocabulary()['by_id'] as $row ) {
			if ( (int) $row['id'] === (int) $res ) {
				$breed = $row;
				break;
			}
		}
		$sp = $breed ? $breed['species'] : '';
		if ( class_exists( 'WC_Logger' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( sprintf( 'Breed added by user #%d: %s / "%s" (id %d, aliases: %s)',
				get_current_user_id(), $sp, $label, (int) $res, implode( ', ', $aliases ) ?: 'none' ),
				array( 'source' => 'dpl-pet-breeds' ) );
		}
		self::redirect( 'dpl-breeds', array(
			'added'       => (int) $res,
			'species'     => $sp,
			'new_species' => in_array( $sp, $known_before, true ) ? 0 : 1,
		) );
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
				sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
				sanitize_key( $_POST['species'] ?? '' )
			);
			if ( is_wp_error( $res ) ) {
				self::redirect( 'dpl-breeds-requests', array(
					'error' => $res->get_error_code(), 'msg' => rawurlencode( $res->get_error_message() ) ) );
			}
			self::redirect( 'dpl-breeds-requests', array( 'approved' => 1 ) );
		}

		if ( 'reject' === $do ) {
			DPL_Breeds_Requests::reject( $id );
			self::redirect( 'dpl-breeds-requests', array( 'rejected' => 1 ) );
		}

		self::redirect( 'dpl-breeds-requests' );
	}

	/** A refusal carried back through the URL: the message when there is one, else the code. */
	private static function error_notice() {
		if ( empty( $_GET['error'] ) ) {
			return;
		}
		$msg = isset( $_GET['msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) : '';
		printf( '<div class="notice notice-error"><p>%s</p></div>',
			esc_html( '' !== $msg ? $msg : sanitize_key( $_GET['error'] ) ) );
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
		if ( ! empty( $_GET['added'] ) ) {
			$added = null;
			foreach ( DPL_Breeds_Repository::vocabulary()['by_id'] as $row ) {
				if ( (int) $row['id'] === (int) $_GET['added'] ) {
					$added = $row;
					break;
				}
			}
			if ( $added ) {
				printf(
					'<div class="notice notice-success"><p>Added <strong>%s</strong> to <strong>%s</strong>. %s</p></div>',
					esc_html( $added['label'] ), esc_html( $added['species'] ),
					! empty( $_GET['new_species'] )
						? esc_html( sprintf( '"%s" is a new species: it exists here now, but the pet forms in the other plugins list their species themselves and will need "%1$s" added before anyone can pick it.', $added['species'] ) )
						: 'It can be picked and searched for straight away.'
				);
			}
		}
		self::error_notice();

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

		/* ---- add a breed (2.7) ---- */
		echo '<hr><h2>Add a breed</h2>';
		echo '<p>Adds a breed straight to the list, with no request to approve. It is checked the same '
			. 'way an approval is: a name already in that species, or one the reviewed map sends '
			. 'elsewhere, is refused with the reason. A breed added here survives re-seeds. '
			. 'To add a <strong>species</strong>, type a new one: it exists once it has its first breed.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dpl_breeds_add_breed' );
		echo '<input type="hidden" name="action" value="dpl_breeds_add_breed">';
		echo '<table class="form-table" style="max-width:720px"><tbody>';
		echo '<tr><th scope="row"><label for="dpl-add-species">Species</label></th><td>';
		echo '<select name="species" id="dpl-add-species">';
		foreach ( $species_list as $sp ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $sp ), selected( $sp, $species, false ), esc_html( $sp ) );
		}
		echo '</select> &nbsp; or a new species: ';
		echo '<input type="text" name="new_species" id="dpl-add-new-species" class="regular-text" style="width:10em" '
			. 'placeholder="cow" pattern="[A-Za-z][A-Za-z0-9 \-]{2,31}" title="One plain word, 3 to 32 letters">';
		echo '<p class="description">One word, lowercase, e.g. <code>cow</code>. Other plugins\' pet forms list species '
			. 'themselves, so a new one needs adding there too before customers can choose it.</p></td></tr>';
		echo '<tr><th scope="row"><label for="dpl-add-label">Breed name</label></th><td>';
		printf( '<input type="text" name="label" id="dpl-add-label" class="regular-text" required maxlength="%d" placeholder="Hereford">', DPL_Breeds_Vocabulary::MAX_LABEL );
		echo '<p class="description">As it should display. Source it (VBO, VeNom, a registry) rather than inventing it; '
			. 'a cross is not a breed - it is two breeds on the pet.</p></td></tr>';
		echo '<tr><th scope="row"><label for="dpl-add-aliases">Also known as</label></th><td>';
		echo '<input type="text" name="aliases" id="dpl-add-aliases" class="regular-text" placeholder="herefords, white face">';
		echo '<p class="description">Optional, comma-separated. Other spellings people type; each one will find and resolve to this breed.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Add breed', 'primary', 'submit', false );
		echo '</form>';

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
			. 'lists are retired, not deleted; breeds added by hand or by approving a request are kept.</p>'
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

	/**
	 * Where a pet record lives, for a link. This plugin never reads another plugin's tables, but it
	 * can point at its screen: dpl-vp-pets' edit screen by default, or whatever the filter says.
	 *
	 * @param int $pet_id
	 * @return string URL or ''.
	 */
	private static function pet_url( $pet_id ) {
		$default = defined( 'DPL_VP_PETS_VERSION' )
			? admin_url( 'admin.php?page=customer-pets-list&action=edit_pet&p_id=' . (int) $pet_id )
			: '';
		/**
		 * The admin URL of a pet, for the Requests page.
		 *
		 * @param string $url
		 * @param int    $pet_id
		 */
		return (string) apply_filters( 'dpl_breed_request_pet_url', $default, (int) $pet_id );
	}

	/** One occasion, as HTML: when, who, which pet, which order, from which screen. */
	private static function occasion_html( array $o ) {
		$bits = array();
		if ( ! empty( $o['at'] ) ) {
			$bits[] = '<span style="color:#646970">' . esc_html( wp_date( 'j M Y H:i', strtotime( $o['at'] ) ) ) . '</span>';
		}
		if ( ! empty( $o['user_id'] ) ) {
			$u    = get_userdata( (int) $o['user_id'] );
			$name = $u ? $u->display_name : 'user #' . (int) $o['user_id'];
			$bits[] = sprintf( '<a href="%s">%s</a>%s',
				esc_url( get_edit_user_link( (int) $o['user_id'] ) ), esc_html( $name ),
				$u && user_can( $u, self::CAP ) ? ' <em>(staff)</em>' : '' );
		} else {
			$bits[] = '<em>not signed in</em>';
		}
		if ( ! empty( $o['pet_id'] ) ) {
			$url    = self::pet_url( (int) $o['pet_id'] );
			$bits[] = $url
				? sprintf( '<a href="%s">pet #%d</a>', esc_url( $url ), (int) $o['pet_id'] )
				: sprintf( 'pet #%d', (int) $o['pet_id'] );
		}
		if ( ! empty( $o['order_id'] ) ) {
			$url    = function_exists( 'wc_get_order' ) && ( $order = wc_get_order( (int) $o['order_id'] ) )
				? $order->get_edit_order_url() : '';
			$bits[] = $url
				? sprintf( '<a href="%s">order #%d</a>', esc_url( $url ), (int) $o['order_id'] )
				: sprintf( 'order #%d', (int) $o['order_id'] );
		}
		if ( ! empty( $o['prescription_id'] ) ) {
			$bits[] = sprintf( 'prescription #%d', (int) $o['prescription_id'] );
		}
		if ( ! empty( $o['source'] ) ) {
			$bits[] = 'via ' . esc_html( self::SOURCE_NAMES[ $o['source'] ] ?? $o['source'] );
		}
		return implode( ' &middot; ', $bits );
	}

	/**
	 * Everything known about who asked for a request. Rows from before 2.7 have no `sources`,
	 * only the first pet and user, so those are shown as the one occasion they are.
	 */
	private static function provenance_html( array $r ) {
		$list = $r['occasions'];
		if ( ! $list && ( ! empty( $r['pet_id'] ) || ! empty( $r['user_id'] ) ) ) {
			$list = array( array(
				'at'      => $r['created_date'],
				'user_id' => (int) $r['user_id'],
				'pet_id'  => (int) $r['pet_id'],
			) );
		}
		if ( ! $list ) {
			return '<em>unknown - asked before this was recorded</em>';
		}
		$list  = array_reverse( $list );     // newest first
		$extra = count( $list ) - 5;
		$out   = array();
		foreach ( array_slice( $list, 0, 5 ) as $o ) {
			$out[] = self::occasion_html( $o );
		}
		$html = implode( '<br>', $out );
		if ( $extra > 0 ) {
			$html .= sprintf( '<br><em>and %d earlier</em>', $extra );
		}
		return $html;
	}

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
		self::error_notice();

		echo '<p>Requests only reach here when the resolver could not match the string, so a '
			. 'misspelling is normally caught before a request is ever created. Approving adds the '
			. 'breed <em>and</em> aliases the requested wording to it, so the same phrasing resolves '
			. 'next time. To add a breed nobody has asked for, use '
			. '<a href="' . esc_url( admin_url( 'admin.php?page=dpl-breeds' ) ) . '">Breeds &rarr; Add a breed</a>.</p>';

		if ( empty( $pending ) ) {
			echo '<p><em>Nothing pending.</em></p>';
			self::render_declined();
			echo '</div>';
			return;
		}

		$species_list = DPL_Breeds_Repository::species_with_lists();
		echo '<table class="widefat striped"><thead><tr><th>Requested</th><th>Count</th>'
			. '<th>Asked by</th><th>Species</th><th>Label to add</th><th></th></tr></thead><tbody>';
		foreach ( $pending as $r ) {
			echo '<tr><td><code>' . esc_html( $r['requested'] ) . '</code>';
			if ( ! empty( $r['suggestion'] ) ) {
				echo '<br><small>possible match: ' . esc_html( $r['suggestion'] ) . '</small>';
			}
			echo '</td><td>' . (int) $r['request_count'] . '</td>';
			echo '<td style="font-size:12px;line-height:1.7">' . self::provenance_html( $r ) . '</td>';

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
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
			. '<th>Requested</th><th>Species</th><th>Times asked</th><th>Last asked by</th></tr></thead><tbody>';
		foreach ( $declined as $r ) {
			$r['occasions'] = DPL_Breeds_Requests::sources( $r['sources'] ?? '' );
			$last           = $r['occasions'] ? array( end( $r['occasions'] ) ) : array();
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td><strong>%d</strong></td><td style="font-size:12px">%s</td></tr>',
				esc_html( $r['requested'] ),
				esc_html( $r['species'] ?: '—' ),
				(int) $r['request_count'],
				$last ? self::occasion_html( $last[0] ) : '<em>unknown</em>'
			);
		}
		echo '</tbody></table>';
	}
}
