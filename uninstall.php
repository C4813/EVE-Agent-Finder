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
 *   - All eaf_* transients (including per-character SSO/standings transients)
 */

// Security: only run when WordPress is doing an uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop all plugin tables ────────────────────────────────────────────────────
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

// ── Delete named options ──────────────────────────────────────────────────────
$options = array(
	'eaf_bfs_done',
	'eaf_bfs_timestamp',
	'eaf_sso_client_id',
	'eaf_sso_client_secret',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Delete all eaf_* transients ───────────────────────────────────────────────
// This covers:
//   eaf_filter_options          — cached filter dropdown data
//   eaf_sso_state_{token}       — OAuth state tokens (10-min TTL, but clean up anyway)
//   eaf_char_auth_{token}       — per-visitor character identity (24-h TTL)
//   eaf_access_token_{char_id}  — ESI access tokens (19-min TTL)
//   eaf_standings_{char_id}     — ESI standings cache (30-min TTL)
//
// WordPress stores transients in wp_options as _transient_{name} and
// _transient_timeout_{name}. A single LIKE query clears them all cleanly.
//
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uninstall context; no caching layer applies; table name not user input.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_eaf_' )     . '%',
		$wpdb->esc_like( '_transient_timeout_eaf_' ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
