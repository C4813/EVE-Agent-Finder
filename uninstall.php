<?php
/**
 * Uninstall EVE Agent Finder
 *
 * WordPress executes this file when the plugin is deleted via the admin UI.
 * It is NOT run on deactivation — only on full deletion.
 *
 * Removes:
 *   - All eaf_* database tables
 *   - All eaf_* options from wp_options
 */

// Security: only run when WordPress is doing an uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	'eaf_agents',
	'eaf_station_operations',
	'eaf_stations',
	'eaf_system_jumps',
	'eaf_systems',
	'eaf_divisions',
	'eaf_agent_types',
	'eaf_corporations',
	'eaf_factions',
	'eaf_regions',
	'eaf_constellations',
	'eaf_import_log',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names are hardcoded plugin tables, never user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
}

delete_option( 'eaf_bfs_done' );
delete_option( 'eaf_bfs_timestamp' );
