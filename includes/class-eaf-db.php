<?php
/**
 * EAF_DB  –  Create / drop all plugin tables.
 *
 * Tables:
 *   eaf_factions        – chrFactions
 *   eaf_corporations    – crpNPCCorporations
 *   eaf_agent_types     – agtAgentTypes
 *   eaf_divisions       – crpNPCDivisions
 *   eaf_systems         – mapSolarSystems  (+ computed lowsec_distance)
 *   eaf_system_jumps    – mapSolarSystemJumps
 *   eaf_stations        – staStations
 *   eaf_agents          – agtAgents        (+ agent_name from ESI)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_DB {

	/** Return the table name with WP prefix. */
	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'eaf_' . $name;
	}

	// ── Create ────────────────────────────────────────────────────────────────

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Factions
		dbDelta( "CREATE TABLE " . self::t('factions') . " (
			faction_id   BIGINT UNSIGNED NOT NULL,
			faction_name VARCHAR(120)    NOT NULL DEFAULT '',
			PRIMARY KEY  (faction_id)
		) $charset;" );

		// NPC Corporations
		dbDelta( "CREATE TABLE " . self::t('corporations') . " (
			corporation_id   BIGINT UNSIGNED NOT NULL,
			corporation_name VARCHAR(160)    NOT NULL DEFAULT '',
			faction_id       BIGINT UNSIGNED          DEFAULT NULL,
			PRIMARY KEY (corporation_id),
			KEY idx_faction (faction_id)
		) $charset;" );

		// Agent types  (BasicAgent, StorylineAgent, CosmosAgent, etc.)
		dbDelta( "CREATE TABLE " . self::t('agent_types') . " (
			agent_type_id   INT UNSIGNED NOT NULL,
			agent_type_name VARCHAR(80)  NOT NULL DEFAULT '',
			PRIMARY KEY (agent_type_id)
		) $charset;" );

		// NPC Divisions  (Distribution, Security, Mining, etc.)
		dbDelta( "CREATE TABLE " . self::t('divisions') . " (
			division_id   INT UNSIGNED NOT NULL,
			division_name VARCHAR(80)  NOT NULL DEFAULT '',
			PRIMARY KEY (division_id)
		) $charset;" );

		// Constellations (needed to resolve constellation_name for dotlan links)
		dbDelta( "CREATE TABLE " . self::t('constellations') . " (
			constellation_id   BIGINT UNSIGNED NOT NULL,
			constellation_name VARCHAR(100)    NOT NULL DEFAULT '',
			PRIMARY KEY (constellation_id)
		) $charset;" );

		// Regions (needed to resolve region_name for system cards / dotlan links)
		dbDelta( "CREATE TABLE " . self::t('regions') . " (
			region_id   BIGINT UNSIGNED NOT NULL,
			region_name VARCHAR(100)    NOT NULL DEFAULT '',
			PRIMARY KEY (region_id)
		) $charset;" );

		// Solar systems  (k-space + wormhole – we BFS across all of them)
		dbDelta( "CREATE TABLE " . self::t('systems') . " (
			system_id        BIGINT UNSIGNED NOT NULL,
			system_name      VARCHAR(100)    NOT NULL DEFAULT '',
			security         FLOAT           NOT NULL DEFAULT 0,
			region_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			region_name      VARCHAR(100)             DEFAULT '',
			constellation_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			constellation_name VARCHAR(100)             DEFAULT '',
			sec_class        ENUM('highsec','lowsec','nullsec','wormhole') NOT NULL DEFAULT 'nullsec',
			lowsec_distance  INT                      DEFAULT NULL,
			lowsec_gateway   VARCHAR(100)             DEFAULT NULL,
			storyline_distance INT                    DEFAULT NULL,
			storyline_system   VARCHAR(100)            DEFAULT NULL,
			storyline_region   VARCHAR(100)            DEFAULT NULL,
			storyline_lowsec   INT                    DEFAULT NULL,
			PRIMARY KEY (system_id),
			KEY idx_sec_class (sec_class),
			KEY idx_lowsec    (lowsec_distance),
			KEY idx_storyline (storyline_distance)
		) $charset;" );

		// Stargate adjacency – used only during BFS; kept for re-runs
		dbDelta( "CREATE TABLE " . self::t('system_jumps') . " (
			from_id BIGINT UNSIGNED NOT NULL,
			to_id   BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (from_id, to_id),
			KEY idx_from (from_id)
		) $charset;" );

		// Station operations (type names like 'Assembly Plant', 'Station')
		dbDelta( "CREATE TABLE " . self::t('station_operations') . " (
			operation_id    INT UNSIGNED NOT NULL,
			operation_name  VARCHAR(160)  NOT NULL DEFAULT '',
			PRIMARY KEY (operation_id)
		) $charset;" );

		// NPC Stations
		dbDelta( "CREATE TABLE " . self::t('stations') . " (
			station_id      BIGINT UNSIGNED NOT NULL,
			station_name    VARCHAR(220)    NOT NULL DEFAULT '',
			system_id       BIGINT UNSIGNED NOT NULL,
			corporation_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			operation_id    INT UNSIGNED             DEFAULT NULL,
			celestial_index TINYINT UNSIGNED         DEFAULT NULL,
			orbit_index     TINYINT UNSIGNED         DEFAULT NULL,
			PRIMARY KEY (station_id),
			KEY idx_system (system_id)
		) $charset;" );

		// Agents
		dbDelta( "CREATE TABLE " . self::t('agents') . " (
			agent_id       BIGINT UNSIGNED NOT NULL,
			agent_name     VARCHAR(120)             DEFAULT NULL,
			division_id    INT UNSIGNED    NOT NULL DEFAULT 0,
			corporation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			location_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			level          TINYINT UNSIGNED NOT NULL DEFAULT 1,
			agent_type_id  INT UNSIGNED    NOT NULL DEFAULT 2,
			is_locator     TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (agent_id),
			KEY idx_level        (level),
			KEY idx_agent_type   (agent_type_id),
			KEY idx_division     (division_id),
			KEY idx_location     (location_id),
			KEY idx_corporation  (corporation_id)
		) $charset;" );

		// Record when each table was last imported
		dbDelta( "CREATE TABLE " . self::t('import_log') . " (
			table_key   VARCHAR(60)  NOT NULL,
			imported_at DATETIME     NOT NULL,
			row_count   INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (table_key)
		) $charset;" );

		// Store global options (e.g. BFS status)
		add_option( 'eaf_bfs_done', 0 );
	}

	// ── Drop ─────────────────────────────────────────────────────────────────

	public static function drop_tables(): void {
		global $wpdb;
		foreach ( array( 'agents', 'stations', 'station_operations', 'system_jumps', 'systems',
		                 'divisions', 'agent_types', 'corporations',
		                 'factions', 'regions', 'constellations', 'import_log' ) as $t ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are internal constants from EAF_DB::t(), never user input.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( $t ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
		delete_option( 'eaf_bfs_done' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Log a successful import step. */
	public static function clear_cache(): void {
		delete_transient( 'eaf_filter_options' );
	}

	public static function log_import( string $key, int $rows ): void {
		global $wpdb;
		$wpdb->replace( self::t('import_log'), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'table_key'   => $key,
			'imported_at' => current_time( 'mysql' ),
			'row_count'   => $rows,
		), array( '%s', '%s', '%d' ) );
		self::clear_cache();
	}

	/** Return array of import_log rows keyed by table_key. */
	public static function get_import_status(): array {
		global $wpdb;
		// Table name is an internal constant from self::t(), never user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			'SELECT table_key, imported_at, row_count FROM ' . self::t('import_log'),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['table_key'] ] = $r;
		}
		return $out;
	}
}
