<?php
/**
 * Plugin Name:  EVE Agent Finder
 * Description:  Find optimal EVE Online mission hubs using EVE SDE data. Import agent, system, and faction data, then use the [eve_agent_finder] shortcode to filter for the perfect chain-running location.
 * Version:      1.2.6
 * Author:       C4813
 * License:      GPL-2.0+
 * Text Domain:  eve-agent-finder
 *
 * Data source: CCP Static Data Export — https://developers.eveonline.com/resource/resources
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'EAF_VERSION',  '1.2.6' );
define( 'EAF_DIR',      plugin_dir_path( __FILE__ ) );
define( 'EAF_URL',      plugin_dir_url( __FILE__ ) );
require_once EAF_DIR . 'includes/class-eaf-db.php';
require_once EAF_DIR . 'includes/class-eaf-yaml.php';
require_once EAF_DIR . 'includes/class-eaf-importer.php';
require_once EAF_DIR . 'includes/class-eaf-calculator.php';
require_once EAF_DIR . 'includes/class-eaf-query.php';
require_once EAF_DIR . 'includes/class-eaf-sso.php';
require_once EAF_DIR . 'admin/class-eaf-admin.php';
require_once EAF_DIR . 'public/class-eaf-public.php';
// Activation: create tables (dbDelta is safe to run on updates — won't drop existing data)
register_activation_hook( __FILE__, array( 'EAF_DB', 'create_tables' ) );
// Uninstall: handled by uninstall.php (WordPress runs it automatically on plugin deletion)
add_action( 'init', static function () {
	EAF_SSO::init();
	( new EAF_Admin()  )->init();
	( new EAF_Public() )->init();
} );
