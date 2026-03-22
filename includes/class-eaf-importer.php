<?php
/**
 * EAF_Importer – Parses CCP SDE YAML files and populates the plugin's tables.
 *
 * Requires the current SDE format (post-2025-09-22):
 *   All files are at the zip root. Name fields use name: { en: "…" } structure.
 *
 * Required files (SDE zip root):
 *   Factions          factions.yaml
 *   Corporations      npcCorporations.yaml
 *   Agent Types       agentTypes.yaml
 *   Divisions         npcCorporationDivisions.yaml
 *   Constellations    mapConstellations.yaml
 *   Regions           mapRegions.yaml
 *   Solar Systems     mapSolarSystems.yaml
 *   Stargate Jumps    mapStargates.yaml
 *   Station Ops       stationOperations.yaml
 *   NPC Stations      npcStations.yaml
 *   Agents            npcCharacters.yaml
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_Importer {

	// ── Shared helpers ────────────────────────────────────────────────────────

	private static function bulk_insert( string $table, array $cols, array $rows, int $chunk = 300 ): int {
		global $wpdb;
		$inserted = 0;
		foreach ( array_chunk( $rows, $chunk ) as $batch ) {
			if ( empty( $batch ) ) continue;
			$ph   = '(' . implode( ', ', array_fill( 0, count( $cols ), '%s' ) ) . ')';
			$phs  = implode( ', ', array_fill( 0, count( $batch ), $ph ) );
			$flat = [];
			foreach ( $batch as $r ) {
				foreach ( $r as $v ) $flat[] = $v;
			}
			$col_list = '`' . implode( '`, `', $cols ) . '`';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table/column names are internal constants, never user input.
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` ({$col_list}) VALUES {$phs}", $flat
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$inserted += count( $batch );
		}
		return $inserted;
	}

	private static function err( string $msg ): array {
		return [ 'success' => false, 'message' => $msg, 'rows' => 0 ];
	}
	private static function ok( string $msg, int $rows ): array {
		return [ 'success' => true, 'message' => $msg, 'rows' => $rows ];
	}

	/** Read the English name from a record's fields. */
	private static function en( array $fields ): ?string {
		if ( ! empty( $fields['name.en'] ) ) return (string) $fields['name.en']; // localised object
		if ( ! empty( $fields['name'] ) )    return (string) $fields['name'];    // plain string
		return null;
	}

	// ── Step 1: Factions ──────────────────────────────────────────────────────

	public static function import_factions( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'factions' );
		$rows  = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = self::en( $rec['fields'] ) ?? ( 'Faction #' . $rec['id'] );
			$rows[] = [ $rec['id'], sanitize_text_field( $name ) ];
		}
		if ( empty( $rows ) ) {
			return self::err( 'factions.yaml: no records parsed. Is this the right file?' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$count = self::bulk_insert( $table, [ 'faction_id', 'faction_name' ], $rows );
		EAF_DB::log_import( 'factions', $count );
		return self::ok( "Imported {$count} factions.", $count );
	}

	// ── Step 2: Corporations ──────────────────────────────────────────────────

	public static function import_corporations( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'corporations' );
		$rows  = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$f    = $rec['fields'];
			$name = self::en( $f ) ?? ( 'Corp #' . $rec['id'] );
			$fac  = isset( $f['factionID'] ) ? (int) $f['factionID'] : null;
			$rows[] = [ $rec['id'], sanitize_text_field( $name ), $fac ];
		}
		if ( empty( $rows ) ) {
			return self::err( 'npcCorporations.yaml: no records parsed. Is this the right file?' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$count = self::bulk_insert( $table, [ 'corporation_id', 'corporation_name', 'faction_id' ], $rows );
		EAF_DB::log_import( 'corporations', $count );
		return self::ok( "Imported {$count} corporations.", $count );
	}

	// ── Step 3: Agent Types ───────────────────────────────────────────────────

	public static function import_agent_types( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'agent_types' );
		$rows  = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = self::en( $rec['fields'] );
			if ( $name === null ) continue;
			$rows[] = [ $rec['id'], sanitize_text_field( $name ) ];
		}
		if ( empty( $rows ) ) {
			return self::err( 'agentTypes.yaml: no records parsed. Is this the right file?' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$count = self::bulk_insert( $table, [ 'agent_type_id', 'agent_type_name' ], $rows );
		EAF_DB::log_import( 'agent_types', $count );
		return self::ok( "Imported {$count} agent types.", $count );
	}

	// ── Step 4: Divisions ─────────────────────────────────────────────────────

	public static function import_divisions( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'divisions' );
		$rows  = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = self::en( $rec['fields'] ) ?? ( 'Division #' . $rec['id'] );
			$rows[] = [ $rec['id'], sanitize_text_field( $name ) ];
		}
		if ( empty( $rows ) ) {
			return self::err( 'npcCorporationDivisions.yaml: no records parsed. Is this the right file?' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$count = self::bulk_insert( $table, [ 'division_id', 'division_name' ], $rows );
		EAF_DB::log_import( 'divisions', $count );
		return self::ok( "Imported {$count} divisions.", $count );
	}

	// ── Step 4a: Constellations ───────────────────────────────────────────────

	public static function import_constellations( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'constellations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$rows  = [];
		$count = 0;
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = self::en( $rec['fields'] );
			if ( ! $name || ! $rec['id'] ) continue;
			$rows[] = [ (int) $rec['id'], sanitize_text_field( $name ) ];
			if ( count( $rows ) >= 500 ) {
				self::bulk_insert( $table, [ 'constellation_id', 'constellation_name' ], $rows );
				$count += count( $rows );
				$rows   = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table, [ 'constellation_id', 'constellation_name' ], $rows );
			$count += count( $rows );
		}
		if ( $count === 0 ) {
			return self::err( 'mapConstellations.yaml: no records parsed. Wrong file or unexpected format.' );
		}
		EAF_DB::log_import( 'constellations', $count );
		return self::ok( "Imported {$count} constellations.", $count );
	}

	// ── Step 4b: Regions ─────────────────────────────────────────────────────

	public static function import_regions( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'regions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$rows  = [];
		$count = 0;
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = self::en( $rec['fields'] );
			if ( ! $name || ! $rec['id'] ) continue;
			$rows[] = [ (int) $rec['id'], sanitize_text_field( $name ) ];
			if ( count( $rows ) >= 500 ) {
				self::bulk_insert( $table, [ 'region_id', 'region_name' ], $rows );
				$count += count( $rows );
				$rows   = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table, [ 'region_id', 'region_name' ], $rows );
			$count += count( $rows );
		}
		if ( $count === 0 ) {
			return self::err( 'mapRegions.yaml: no records parsed. Wrong file or unexpected format.' );
		}
		EAF_DB::log_import( 'regions', $count );
		return self::ok( "Imported {$count} regions.", $count );
	}

	// ── Step 5: Solar Systems ─────────────────────────────────────────────────

	public static function import_systems( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'systems' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Pre-load region and constellation id → name maps
		$region_map = [];
		$const_map  = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal constants.
		$reg_rows   = $wpdb->get_results( 'SELECT region_id, region_name FROM ' . EAF_DB::t( 'regions' ), ARRAY_A );
		$const_rows = $wpdb->get_results( 'SELECT constellation_id, constellation_name FROM ' . EAF_DB::t( 'constellations' ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( $reg_rows   as $r ) { $region_map[ (int) $r['region_id'] ]        = $r['region_name']; }
		foreach ( $const_rows as $c ) { $const_map[  (int) $c['constellation_id'] ] = $c['constellation_name']; }

		$rows  = [];
		$count = 0;
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$f     = $rec['fields'];
			$name  = self::en( $f ) ?? (string) ( $f['name'] ?? '' );
			$sec   = (float)  ( $f['securityStatus'] ?? 0 );
			$rid   = (int)    ( $f['regionID'] ?? 0 );
			$cid   = (int)    ( $f['constellationID'] ?? 0 );
			$rname = $region_map[ $rid ] ?? '';
			$cname = $const_map[ $cid ]  ?? '';

			if ( $rid >= 11000000 && $rid <= 11999999 ) {
				$class = 'wormhole';
			} else {
				// EVE displays security by flooring to 1 decimal place (not rounding).
				// A true sec of 0.45 displays as 0.4 (lowsec), but round() would give 0.5
				// (highsec) — causing lowsec systems to pass highsec-only filters.
				// Compare directly against the true value; the game boundaries are
				// exactly >= 0.5 for highsec and > 0.0 for lowsec.
				if      ( $sec >= 0.5 ) { $class = 'highsec'; }
				elseif  ( $sec >  0.0 ) { $class = 'lowsec';  }
				else                    { $class = 'nullsec'; }
			}
			$rows[] = [ $rec['id'], sanitize_text_field( $name ), $sec, $rid, sanitize_text_field( $rname ), $cid, sanitize_text_field( $cname ), $class ];
			if ( count( $rows ) >= 500 ) {
				self::bulk_insert( $table,
					[ 'system_id', 'system_name', 'security', 'region_id', 'region_name', 'constellation_id', 'constellation_name', 'sec_class' ],
					$rows );
				$count += count( $rows );
				$rows   = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table,
				[ 'system_id', 'system_name', 'security', 'region_id', 'region_name', 'constellation_id', 'constellation_name', 'sec_class' ],
				$rows );
			$count += count( $rows );
		}
		if ( $count === 0 ) {
			return self::err( 'mapSolarSystems.yaml: no records parsed. Wrong file or unexpected format.' );
		}
		EAF_DB::log_import( 'systems', $count );
		update_option( 'eaf_bfs_done', 0 );
		return self::ok( "Imported {$count} solar systems.", $count );
	}

	// ── Step 6: System Jumps ─────────────────────────────────────────────────
	// mapStargates.yaml — destination.solarSystemID gives the target system directly.

	public static function import_system_jumps( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'system_jumps' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$rows  = [];
		$seen  = [];
		$count = 0;

		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$f        = $rec['fields'];
			$from_sys = (int) ( $f['solarSystemID'] ?? 0 );
			$to_sys   = (int) ( $f['destination.solarSystemID'] ?? 0 );
			if ( $from_sys === 0 || $to_sys === 0 ) continue;
			$key = min( $from_sys, $to_sys ) . '_' . max( $from_sys, $to_sys );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			$rows[] = [ $from_sys, $to_sys ];
			$rows[] = [ $to_sys,   $from_sys ];
			if ( count( $rows ) >= 1000 ) {
				self::bulk_insert( $table, [ 'from_id', 'to_id' ], $rows );
				$count += count( $rows );
				$rows   = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table, [ 'from_id', 'to_id' ], $rows );
			$count += count( $rows );
		}
		if ( $count === 0 ) {
			return self::err( 'mapStargates.yaml: no jump pairs derived. Is this the right file?' );
		}
		EAF_DB::log_import( 'system_jumps', $count );
		update_option( 'eaf_bfs_done', 0 );
		return self::ok( "Derived " . intdiv( $count, 2 ) . " jump connections from mapStargates.yaml.", $count );
	}

	// ── Step 7: Station Operations ────────────────────────────────────────────

	public static function import_station_operations( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'station_operations' );
		$rows  = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$name = $rec['fields']['operationName.en'] ?? self::en( $rec['fields'] ) ?? null;
			if ( $name === null ) continue;
			$rows[] = [ $rec['id'], sanitize_text_field( (string) $name ) ];
		}
		if ( empty( $rows ) ) {
			return self::err( 'stationOperations.yaml: no records parsed. Is this the right file?' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$count = self::bulk_insert( $table, [ 'operation_id', 'operation_name' ], $rows );
		EAF_DB::log_import( 'station_ops', $count );
		return self::ok( "Imported {$count} station operation types.", $count );
	}

	// ── Step 8: NPC Stations ─────────────────────────────────────────────────

	public static function import_stations( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'stations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$rows = [];
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$f       = $rec['fields'];
			$sys_id  = (int) ( $f['solarSystemID'] ?? 0 );
			$corp    = (int) ( $f['corporationID']  ?? 0 );
			$op_id   = isset( $f['operationID'] )     ? (int) $f['operationID']     : null;
			$cel_idx = isset( $f['celestialIndex'] )  ? (int) $f['celestialIndex']  : null;
			$orb_idx = isset( $f['orbitIndex'] )      ? (int) $f['orbitIndex']      : null;
			if ( $sys_id === 0 ) continue;
			$rows[] = [ $rec['id'], '', $sys_id, $corp, $op_id, $cel_idx, $orb_idx ];
			if ( count( $rows ) >= 500 ) {
				self::bulk_insert( $table,
					[ 'station_id', 'station_name', 'system_id', 'corporation_id', 'operation_id', 'celestial_index', 'orbit_index' ],
					$rows );
				$rows = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table,
				[ 'station_id', 'station_name', 'system_id', 'corporation_id', 'operation_id', 'celestial_index', 'orbit_index' ],
				$rows );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count === 0 ) {
			return self::err( 'npcStations.yaml: no records parsed. Is this the right file?' );
		}
		EAF_DB::log_import( 'stations', $count );
		return self::ok( "Imported {$count} NPC stations.", $count );
	}

	// ── Step 9: Agents ────────────────────────────────────────────────────────

	public static function import_agents( string $yaml_path ): array {
		global $wpdb;
		$table = EAF_DB::t( 'agents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		$rows  = [];
		$total = 0;
		foreach ( EAF_YAML::stream( $yaml_path ) as $rec ) {
			$f = $rec['fields'];
			$agent_type = (int) ( $f['agent.agentTypeID'] ?? 0 );
			if ( $agent_type === 0 || $agent_type === 1 ) continue; // 0 = not an agent, 1 = NonAgent
			$level   = (int) ( $f['agent.level']      ?? 0 );
			$div_id  = (int) ( $f['agent.divisionID'] ?? 0 );
			$locator = (int) ( isset( $f['agent.isLocator'] ) ? ( $f['agent.isLocator'] ? 1 : 0 ) : 0 );
			$corp_id = (int) ( $f['corporationID'] ?? 0 );
			$loc_id  = (int) ( $f['locationID']    ?? 0 );
			$name    = self::en( $f );
			$rows[]  = [ $rec['id'], $name !== null ? sanitize_text_field( $name ) : null, $div_id, $corp_id, $loc_id, $level, $agent_type, $locator ];
			$total++;
			if ( count( $rows ) >= 500 ) {
				self::bulk_insert( $table,
					[ 'agent_id', 'agent_name', 'division_id', 'corporation_id', 'location_id', 'level', 'agent_type_id', 'is_locator' ],
					$rows );
				$rows = [];
			}
		}
		if ( ! empty( $rows ) ) {
			self::bulk_insert( $table,
				[ 'agent_id', 'agent_name', 'division_id', 'corporation_id', 'location_id', 'level', 'agent_type_id', 'is_locator' ],
				$rows );
		}
		if ( $total === 0 ) {
			return self::err( 'npcCharacters.yaml: no agent records parsed. Is this the right file?' );
		}
		EAF_DB::log_import( 'agents', $total );
		return self::ok( "Imported {$total} agents.", $total );
	}
}
