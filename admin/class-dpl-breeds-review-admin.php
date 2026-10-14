<?php
/**
 * Pet Breeds -> Review: the breed texts the resolver cannot place, for an admin to decide.
 * See DPL_Breeds_Review for what a decision is and where it goes.
 *
 * Two views on one page: the queue (one row per typed text), and a screen per text with the
 * breed picker, "Save" and "Keep as typed". Below the queue, every decision with an Undo.
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

class DPL_Breeds_Review_Admin {

	const CAP      = 'manage_woocommerce';
	const PAGE     = 'dpl-breeds-review';
	const PER_PAGE = 50;

	public static function register_menu() {
		$n    = DPL_Breeds_Review::cached_count();
		$hook = add_submenu_page(
			'dpl-breeds',
			'Breed Review',
			'Review' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			add_action( "load-{$hook}", function () {
				// The picker searches with WooCommerce's selectWoo when it is there; plain lists if not.
				add_action( 'admin_enqueue_scripts', function () {
					if ( wp_script_is( 'selectWoo', 'registered' ) ) {
						wp_enqueue_script( 'selectWoo' );
					}
					if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
						wp_enqueue_style( 'woocommerce_admin_styles' );
					}
				} );
			} );
		}
	}

	/** A short, URL-safe id for a queue key or decision (both hold a customer's text). */
	private static function id_for( $key ) {
		return substr( md5( $key ), 0, 16 );
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function redirect( array $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * After a change: rebuild the queue before going back, so the page that loads next - its menu
	 * badge is drawn before the page itself - reads a current count from the cache.
	 */
	private static function done( array $args ) {
		DPL_Breeds_Review::queue( true );
		self::redirect( $args );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( $nonce );
	}

	private static function group_from_request() {
		$id = isset( $_REQUEST['g'] ) ? sanitize_key( wp_unslash( $_REQUEST['g'] ) ) : '';
		if ( '' === $id ) {
			return null;
		}
		foreach ( DPL_Breeds_Review::queue() as $g ) {
			if ( self::id_for( $g['key'] ) === $id ) {
				return $g;
			}
		}
		return null;
	}

	private static function why( array $g ) {
		if ( '' !== $g['reason'] ) {
			return 'Held for review: ' . $g['reason'];
		}
		return 'review' === $g['status'] ? 'Close to a breed, but not close enough to be sure' : 'No match in the breed list';
	}

	/* ------------------------------------------------------------------ handlers */

	public static function handle_save() {
		self::guard( 'dpl_breeds_review_save' );
		$g = self::group_from_request();
		if ( ! $g ) {
			self::redirect( array( 'error' => 'gone' ) );
		}
		$labels = array();
		foreach ( array( 'breed_1', 'breed_2' ) as $f ) {
			$v = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
			if ( '' !== $v ) {
				$labels[] = $v;
			}
		}
		$cross = ! empty( $_POST['cross'] );
		if ( ! $labels && ! $cross ) {
			self::redirect( array( 'g' => self::id_for( $g['key'] ), 'error' => 'nobreed' ) );
		}
		$r = DPL_Breeds_Review::decide( $g, DPL_Breeds_Resolver::format( $labels, $cross ) );
		if ( ! $r['ok'] ) {
			self::redirect( array( 'g' => self::id_for( $g['key'] ), 'error' => $r['error'] ) );
		}
		self::done( array( 'done' => 'map', 'applied' => $r['applied'], 'd' => self::id_for( $g['key'] ) ) );
	}

	public static function handle_keep() {
		self::guard( 'dpl_breeds_review_keep' );
		$g = self::group_from_request();
		if ( ! $g || ! DPL_Breeds_Review::keep( $g ) ) {
			self::redirect( array( 'error' => $g ? 'no_species' : 'gone' ) );
		}
		self::done( array( 'done' => 'keep', 'd' => self::id_for( $g['key'] ) ) );
	}

	public static function handle_undo() {
		self::guard( 'dpl_breeds_review_undo' );
		$id = isset( $_POST['d'] ) ? sanitize_key( wp_unslash( $_POST['d'] ) ) : '';
		foreach ( DPL_Breeds_Review::decisions() as $sp => $list ) {
			foreach ( array_keys( $list ) as $norm ) {
				if ( self::id_for( $sp . '|' . $norm ) === $id ) {
					DPL_Breeds_Review::undo( $sp, $norm );
					self::done( array( 'done' => 'undo' ) );
				}
			}
		}
		self::redirect( array( 'error' => 'gone' ) );
	}

	public static function handle_refresh() {
		self::guard( 'dpl_breeds_review_refresh' );
		DPL_Breeds_Review::forget_queue();
		self::done( array() );
	}

	/* -------------------------------------------------------------------- render */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Unauthorized' );
		}
		echo '<div class="wrap"><h1>Breed Review</h1>';
		self::render_notices();
		$g = isset( $_GET['g'] ) ? self::group_from_request() : null;
		if ( isset( $_GET['g'] ) && ! $g ) {
			echo '<div class="notice notice-info"><p>That breed is no longer waiting for review - it was decided, '
				. 'or the records holding it changed.</p></div>';
		}
		if ( $g ) {
			self::render_group( $g );
		} else {
			self::render_queue();
		}
		echo '</div>';
	}

	private static function render_notices() {
		$done = isset( $_GET['done'] ) ? sanitize_key( $_GET['done'] ) : '';
		$dec  = null;
		if ( isset( $_GET['d'] ) ) {
			foreach ( DPL_Breeds_Review::decisions() as $sp => $list ) {
				foreach ( $list as $norm => $row ) {
					if ( self::id_for( $sp . '|' . $norm ) === sanitize_key( $_GET['d'] ) ) {
						$dec = $row;
					}
				}
			}
		}
		if ( 'map' === $done ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <strong>%d</strong> pet(s) updated.</p></div>',
				$dec ? '&ldquo;' . esc_html( $dec['text'] ) . '&rdquo; now means <strong>' . esc_html( $dec['value'] ) . '</strong>, here and wherever it is typed from now on.' : 'Saved.',
				intval( $_GET['applied'] ?? 0 )
			);
		} elseif ( 'keep' === $done ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s No pet was changed.</p></div>',
				$dec ? '&ldquo;' . esc_html( $dec['text'] ) . '&rdquo; is kept as typed.' : 'Kept as typed.'
			);
		} elseif ( 'undo' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>Decision undone - the text is back in the queue. '
				. 'Pets already changed keep their new breed; their original is kept with the pet (breed_raw).</p></div>';
		}
		$errors = array(
			'gone'        => 'That breed is no longer waiting for review.',
			'nobreed'     => 'Choose a breed, or tick the cross box for &ldquo;Mixed breed&rdquo; - or use &ldquo;Keep as typed&rdquo;.',
			'not_a_breed' => 'That answer is not something the breed list can store for this species. Choose from the lists.',
			'no_species'  => 'These pets have no species the breed list knows. Correct their species in Pets first.',
		);
		$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
		if ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . $errors[ $error ] . '</p></div>';
		}
	}

	private static function render_queue() {
		$all     = DPL_Breeds_Review::queue();
		$species = isset( $_GET['species'] ) ? sanitize_key( $_GET['species'] ) : '';
		$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$rows    = array_values( array_filter( $all, function ( $g ) use ( $species, $q ) {
			return ( '' === $species || $g['species'] === $species ) && ( '' === $q || false !== stripos( $g['text'], $q ) );
		} ) );

		echo '<p style="max-width:780px">Breeds people typed that the breed list cannot place with confidence. Nothing here '
			. 'changes by itself. For each one, say which breed it means &mdash; the pets that have it are updated, and the '
			. 'same text typed again anywhere is stored as that breed from then on &mdash; or keep it as typed.</p>';

		if ( ! has_filter( 'dpl_breed_review_sources' ) ) {
			echo '<div class="notice notice-warning inline"><p>No plugin is reporting its breeds. The pets plugin '
				. '(Vet Pharmacy Pets 4.9.65 or later) fills this list.</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf(
			'<strong>%s</strong> breed texts on <strong>%s</strong> pets are waiting. ',
			number_format_i18n( count( $all ) ),
			number_format_i18n( array_sum( array_column( $all, 'pets' ) ) )
		);
		wp_nonce_field( 'dpl_breeds_review_refresh' );
		echo '<input type="hidden" name="action" value="dpl_breeds_review_refresh">';
		submit_button( 'Refresh', 'secondary small', 'submit', false );
		echo '</form>';

		$species_values = array_values( array_unique( array_filter( array_column( $all, 'species' ) ) ) );
		sort( $species_values );
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label>Species <select name="species"><option value="">All</option>';
		foreach ( $species_values as $sp ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $sp ), selected( $species, $sp, false ), esc_html( $sp ) );
		}
		echo '</select></label> ';
		printf( '<label>Breed contains <input type="search" name="q" value="%s"></label> ', esc_attr( $q ) );
		submit_button( 'Filter', 'secondary', '', false );
		echo '</form>';

		if ( ! $rows ) {
			echo '<p><em>' . ( $all ? 'Nothing matches the filter.' : 'Nothing is waiting for review.' ) . '</em></p>';
		} else {
			$pages = (int) ceil( count( $rows ) / self::PER_PAGE );
			$paged = min( max( 1, intval( $_GET['paged'] ?? 1 ) ), $pages );
			echo '<table class="widefat striped"><thead><tr><th>Typed as</th><th>Species</th><th>Pets</th>'
				. '<th>Why it is here</th><th>Closest name in the list (not applied)</th><th></th></tr></thead><tbody>';
			foreach ( array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $g ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td><a class="button" href="%s">Review</a></td></tr>',
					esc_html( $g['text'] ),
					'' !== $g['species'] ? esc_html( $g['species'] ) : '<em>none recorded</em>',
					(int) $g['pets'],
					esc_html( self::why( $g ) ),
					'' !== $g['suggestion'] ? esc_html( $g['suggestion'] ) : '&mdash;',
					esc_url( self::url( array( 'g' => self::id_for( $g['key'] ) ) ) )
				);
			}
			echo '</tbody></table>';
			if ( $pages > 1 ) {
				$base = array_filter( array( 'species' => $species, 'q' => $q ) );
				echo '<p>';
				if ( $paged > 1 ) {
					printf( '<a class="button" href="%s">&larr; Previous</a> ', esc_url( self::url( $base + array( 'paged' => $paged - 1 ) ) ) );
				}
				printf( 'Page %d of %d ', $paged, $pages );
				if ( $paged < $pages ) {
					printf( '<a class="button" href="%s">Next &rarr;</a>', esc_url( self::url( $base + array( 'paged' => $paged + 1 ) ) ) );
				}
				echo '</p>';
			}
		}

		self::render_decisions();
	}

	private static function render_group( array $g ) {
		printf( '<p><a href="%s">&larr; Back to Breed Review</a></p>', esc_url( self::url() ) );
		printf(
			'<h2>&ldquo;%s&rdquo; <span style="font-weight:normal">&mdash; %s, %d pet(s)</span></h2>',
			esc_html( $g['text'] ),
			'' !== $g['species'] ? esc_html( $g['species'] ) : 'no species recorded',
			(int) $g['pets']
		);
		echo '<p>' . esc_html( self::why( $g ) ) . '.';
		if ( '' !== $g['suggestion'] ) {
			echo ' The closest name in the list is <strong>' . esc_html( $g['suggestion'] ) . '</strong> &mdash; not applied, '
				. 'because a close spelling is not always the same breed.';
		}
		echo '</p>';
		$spellings = array_unique( array_column( $g['variants'], 1 ) );
		if ( count( $spellings ) > 1 ) {
			echo '<p>Typed as: <code>' . implode( '</code>, <code>', array_map( 'esc_html', $spellings ) ) . '</code></p>';
		}

		if ( '' === $g['species'] ) {
			echo '<div class="notice notice-warning inline"><p>These pets have no species the breed list knows, so a decision '
				. 'cannot be recorded for them. Correct their species in Pets, then refresh this page.</p></div>';
			return;
		}

		$labels = array_values( array_filter( wp_list_pluck( DPL_Breeds_Repository::get_list( $g['species'] ), 'label' ), function ( $l ) {
			return 'Other' !== $l;
		} ) );

		echo '<h3>What breed is it?</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:520px">';
		wp_nonce_field( 'dpl_breeds_review_save' );
		echo '<input type="hidden" name="action" value="dpl_breeds_review_save">';
		printf( '<input type="hidden" name="g" value="%s">', esc_attr( self::id_for( $g['key'] ) ) );
		foreach ( array( 1 => 'Breed', 2 => 'Second breed, for a cross of two (optional)' ) as $n => $label ) {
			printf( '<p><label for="dpl-review-breed-%1$d">%2$s</label><br><select class="dpl-review-pick" name="breed_%1$d" id="dpl-review-breed-%1$d" style="width:100%%">', $n, esc_html( $label ) );
			echo '<option value="">' . ( 1 === $n ? '&mdash; Choose &mdash;' : '&mdash; None &mdash;' ) . '</option>';
			// Nothing pre-selected, the closest name included: a near spelling is the guess this page replaces.
			foreach ( $labels as $l ) {
				printf( '<option value="%1$s">%1$s</option>', esc_attr( $l ) );
			}
			echo '</select></p>';
		}
		echo '<p><label><input type="checkbox" name="cross" value="1"> Crossbreed &mdash; tick when a parent is unknown. '
			. 'With no breed chosen this means &ldquo;Mixed breed&rdquo;.</label></p>';
		echo '<p class="description">This also teaches the breed list: anyone typing &ldquo;' . esc_html( $g['text'] )
			. '&rdquo; for a ' . esc_html( $g['species'] ) . ' from now on gets this breed.</p>';
		submit_button( sprintf( 'Save for %d pet(s)', (int) $g['pets'] ) );
		echo '</form>';
		?>
		<script>
		jQuery(function ($) {
			if ($.fn.selectWoo) { $('.dpl-review-pick').selectWoo({ width: '100%', allowClear: true, placeholder: '' }); }
		});
		</script>
		<?php

		echo '<hr><h3>Or keep it as typed</h3><p>The pets keep &ldquo;' . esc_html( $g['text'] ) . '&rdquo; and it leaves the '
			. 'queue. Use it for a breed the list does not have, or text that is not a breed. It can be undone below the queue.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dpl_breeds_review_keep' );
		echo '<input type="hidden" name="action" value="dpl_breeds_review_keep">';
		printf( '<input type="hidden" name="g" value="%s">', esc_attr( self::id_for( $g['key'] ) ) );
		submit_button( 'Keep as typed', 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function render_decisions() {
		$rows = array();
		foreach ( DPL_Breeds_Review::decisions() as $sp => $list ) {
			foreach ( $list as $norm => $d ) {
				$rows[] = array( 'sp' => $sp, 'norm' => $norm ) + $d;
			}
		}
		usort( $rows, function ( $a, $b ) {
			return (int) $b['at'] <=> (int) $a['at'];
		} );
		printf( '<h2 style="margin-top:28px">Decided (%d)</h2>', count( $rows ) );
		if ( ! $rows ) {
			echo '<p><em>None yet.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Typed as</th><th>Species</th><th>Decision</th><th>Pets then</th>'
			. '<th>When</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user = get_userdata( (int) $r['by'] );
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>',
				esc_html( $r['text'] ),
				esc_html( $r['sp'] ),
				'map' === $r['action'] ? 'Means <strong>' . esc_html( $r['value'] ) . '</strong>' : 'Kept as typed',
				(int) $r['pets'],
				esc_html( wp_date( 'j M Y H:i', (int) $r['at'] ) . ( $user ? ' by ' . $user->display_name : '' ) )
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'dpl_breeds_review_undo' );
			echo '<input type="hidden" name="action" value="dpl_breeds_review_undo">';
			printf( '<input type="hidden" name="d" value="%s">', esc_attr( self::id_for( $r['sp'] . '|' . $r['norm'] ) ) );
			submit_button( 'Undo', 'secondary small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}
}
