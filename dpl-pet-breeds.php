<?php
/**
 * Plugin Name:       DPL Pet Breeds
 * Plugin URI:        https://digitalpie.co.nz
 * Description:       Centralised pet breed vocabulary. Owns the canonical breed list, resolves messy free-text breed strings to it, and exposes both to other plugins via a stable API.
 * Version:           2.7.0
 * Author:            Digital Pie
 * License:           GPL-2.0+
 * Text Domain:       dpl-pet-breeds
 *
 * @package dpl-pet-breeds
 */

defined( 'ABSPATH' ) || exit;

define( 'DPL_BREEDS_VERSION', '2.7.0' );

/**
 * The API contract version, separate from the plugin version.
 *
 * Consumers check this, not DPL_BREEDS_VERSION. Semver: the major only changes when a
 * documented return shape changes. Never break a shape within a major.
 */
define( 'DPL_BREEDS_API_VERSION', '2.4.0' );

define( 'DPL_BREEDS_FILE', __FILE__ );
define( 'DPL_BREEDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPL_BREEDS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The breed table this plugin owns.
 *
 * Deliberately the table dpl-pet-profiles already created (v3.5) rather than a second breed
 * table. It is already the right shape and already holds data, so this plugin adopts it and
 * pet-profiles stops seeding it. Changing this constant orphans existing data.
 */
define( 'DPL_BREEDS_TABLE', 'vpp_breeds' );
define( 'DPL_BREEDS_ALIAS_TABLE', 'vpp_breed_aliases' );
define( 'DPL_BREEDS_REQUEST_TABLE', 'vpp_breed_requests' );

require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-activator.php';
require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-repository.php';
require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-resolver.php';
// The one write path into the breed table outside the seed: Add a breed, and approving a request.
require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-vocabulary.php';
require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-requests.php';
// Always: the resolver reads Review's decisions on every request, not only in wp-admin.
require_once DPL_BREEDS_PATH . 'includes/class-dpl-breeds-review.php';

register_activation_hook( __FILE__, array( 'DPL_Breeds_Activator', 'activate' ) );

/**
 * Load the public API early, then announce availability.
 *
 * Priority 5 so consumers hooking at the default 10 can rely on the functions existing.
 */
add_action( 'plugins_loaded', function () {
	require_once DPL_BREEDS_PATH . 'includes/api-breeds.php';

	// Catch the case where the plugin is updated without deactivate/reactivate.
	DPL_Breeds_Activator::maybe_upgrade();

	/**
	 * Fires once the breed API is available.
	 *
	 * @param string $api_version DPL_BREEDS_API_VERSION.
	 */
	do_action( 'dpl_breeds_ready', DPL_BREEDS_API_VERSION );
}, 5 );

/**
 * Re-seed automatically when the plugin version or the shipped data changes.
 *
 * Replacing the plugin's files does not run the seed, so without this an update ships new code
 * against the old vocabulary until someone remembers to press Re-seed - which is exactly what
 * happened on staging with 2.1. Staff-only and never on AJAX or cron: see maybe_reseed_admin().
 */
add_action( 'admin_init', array( 'DPL_Breeds_Activator', 'maybe_reseed_admin' ) );
add_action( 'admin_notices', array( 'DPL_Breeds_Activator', 'reseed_notice' ) );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// A deploy by `wp plugin install --force` should not have to wait for someone to log in.
	add_action( 'init', array( 'DPL_Breeds_Activator', 'maybe_reseed' ) );
}

add_action( 'admin_menu', function () {
	require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-admin.php';
	DPL_Breeds_Admin::register_menu();
	require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-review-admin.php';
	DPL_Breeds_Review_Admin::register_menu();
} );

// Pet Breeds -> Review: an admin decides what a breed text means. Nothing there runs by itself.
foreach ( array( 'save', 'keep', 'undo', 'refresh' ) as $dpl_breeds_review_action ) {
	add_action( 'admin_post_dpl_breeds_review_' . $dpl_breeds_review_action, function () use ( $dpl_breeds_review_action ) {
		require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-review-admin.php';
		call_user_func( array( 'DPL_Breeds_Review_Admin', 'handle_' . $dpl_breeds_review_action ) );
	} );
}

add_action( 'admin_post_dpl_breeds_reseed', function () {
	require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-admin.php';
	DPL_Breeds_Admin::handle_reseed();
} );
add_action( 'admin_post_dpl_breeds_request_action', function () {
	require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-admin.php';
	DPL_Breeds_Admin::handle_request_action();
} );
// Pet Breeds -> Breeds -> Add a breed (2.7). Under a new species key, this is also "add a species".
add_action( 'admin_post_dpl_breeds_add_breed', function () {
	require_once DPL_BREEDS_PATH . 'admin/class-dpl-breeds-admin.php';
	DPL_Breeds_Admin::handle_add_breed();
} );

// Daily digest of pending add-breed requests.
add_action( 'dpl_breeds_request_digest', array( 'DPL_Breeds_Requests', 'send_digest' ) );
