<?php
/**
 * EAF_Query  –  Data retrieval for the EVE Mission Agent Browser.
 *
 * Fix: get_filter_options() was building SQL by appending JOIN clauses after
 * a WHERE clause (invalid SQL).  Rewritten with explicit per-query structure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_Query {

	// ── Main agent loader ─────────────────────────────────────────────────────

	public static function get_agents( array $filters = array() ): array {
		global $wpdb;

		$defaults = array(
			'sec_class'       => array( 'highsec' ),
			'min_lowsec_dist' => 0,
		);
		$filters = wp_parse_args( $filters, $defaults );

		$agents_t = EAF_DB::t( 'agents'       );
		$corps_t  = EAF_DB::t( 'corporations' );
		$fac_t    = EAF_DB::t( 'factions'     );
		$div_t    = EAF_DB::t( 'divisions'    );
		$sta_t    = EAF_DB::t( 'stations'          );
		$sop_t    = EAF_DB::t( 'station_operations' );
		$sys_t    = EAF_DB::t( 'systems'           );
		$at_t     = EAF_DB::t( 'agent_types'  );

		$where  = array( 'a.agent_type_id <> 1' );
		$params = array();

		$allowed = array( 'highsec', 'lowsec', 'nullsec', 'wormhole' );
		$classes = array_intersect( (array) $filters['sec_class'], $allowed );
		if ( ! empty( $classes ) ) {
			$ph = implode( ',', array_fill( 0, count( $classes ), '%s' ) );
			$where[] = "sys.sec_class IN ({$ph})";
			foreach ( $classes as $c ) {
				$params[] = $c;
			}
		}

		if ( $filters['min_lowsec_dist'] > 0 ) {
			$where[]  = "( sys.lowsec_distance >= %d OR sys.sec_class != 'highsec' )";
			$params[] = (int) $filters['min_lowsec_dist'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "
			SELECT
				a.agent_id,
				COALESCE(NULLIF(a.agent_name, ''), CONCAT('Agent #', a.agent_id))  AS agent_name,
				a.level,
				a.is_locator,
				a.agent_type_id,
				at.agent_type_name,
				a.division_id,
				COALESCE(d.division_name, CONCAT('Division #', a.division_id)) AS division_name,
				a.corporation_id,
				c.corporation_name,
				c.faction_id,
				COALESCE(f.faction_name, 'Unknown')  AS faction_name,
				COALESCE(sta.station_id, a.location_id)   AS station_id,
				CASE
					WHEN sta.station_id IS NULL THEN CONCAT('Location #', a.location_id)
					WHEN NULLIF(sta.station_name,'') IS NOT NULL THEN sta.station_name
					ELSE CONCAT(
						COALESCE(sys.system_name, '?'),
						IF(sta.celestial_index IS NOT NULL,
							CONCAT(' ', CASE sta.celestial_index
					WHEN 1  THEN 'I'    WHEN 2  THEN 'II'   WHEN 3  THEN 'III'
					WHEN 4  THEN 'IV'   WHEN 5  THEN 'V'    WHEN 6  THEN 'VI'
					WHEN 7  THEN 'VII'  WHEN 8  THEN 'VIII' WHEN 9  THEN 'IX'
					WHEN 10 THEN 'X'    WHEN 11 THEN 'XI'   WHEN 12 THEN 'XII'
					WHEN 13 THEN 'XIII' WHEN 14 THEN 'XIV'  WHEN 15 THEN 'XV'
					ELSE CAST(sta.celestial_index AS CHAR) END), ''),
						IF(sta.orbit_index IS NOT NULL AND sta.orbit_index > 0,
							CONCAT(' - Moon ', sta.orbit_index), ''),
						IF(c.corporation_name IS NOT NULL,
							CONCAT(' - ', c.corporation_name), ''),
						IF(sop.operation_name IS NOT NULL AND sop.operation_name != '',
							CONCAT(' ', sop.operation_name), '')
					)
				END AS station_name,
				COALESCE(sys.system_id, 0)           AS system_id,
				COALESCE(sys.system_name, 'Unknown') AS system_name,
				COALESCE(sys.region_name, '')        AS region_name,
				COALESCE(sys.constellation_name, '') AS constellation_name,
				ROUND(COALESCE(sys.security, 0), 2)  AS security,
				COALESCE(sys.sec_class, 'unknown')   AS sec_class,
				COALESCE(sys.lowsec_distance, -1)    AS lowsec_distance,
				sys.lowsec_gateway                   AS lowsec_gateway,
				COALESCE(sys.storyline_distance, -1) AS storyline_distance,
				sys.storyline_system                 AS storyline_system,
				sys.storyline_region                 AS storyline_region,
				COALESCE(sys.storyline_lowsec, -1)   AS storyline_lowsec
			FROM {$agents_t} a
			INNER JOIN {$at_t}    at  ON at.agent_type_id  = a.agent_type_id
			LEFT  JOIN {$div_t}   d   ON d.division_id     = a.division_id
			INNER JOIN {$corps_t} c   ON c.corporation_id  = a.corporation_id
			LEFT  JOIN {$fac_t}   f   ON f.faction_id      = c.faction_id
			LEFT  JOIN {$sta_t}   sta ON sta.station_id     = a.location_id
			LEFT  JOIN {$sop_t}   sop ON sop.operation_id   = sta.operation_id
			LEFT  JOIN {$sys_t}   sys ON sys.system_id      = sta.system_id
			{$where_sql}
			ORDER BY sys.lowsec_distance DESC, COALESCE(sys.system_name,''), COALESCE(sta.station_name,''), a.level DESC
		";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built via $wpdb->prepare() with internal table names only.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		foreach ( $rows as &$r ) {
			$r['agent_id']        = (int)   $r['agent_id'];
			$r['level']           = (int)   $r['level'];
			$r['is_locator']      = (int)   $r['is_locator'];
			$r['agent_type_id']   = (int)   $r['agent_type_id'];
			$r['division_id']     = (int)   $r['division_id'];
			$r['corporation_id']  = (int)   $r['corporation_id'];
			$r['faction_id']      = (int)   $r['faction_id'];
			$r['station_id']      = (int)   $r['station_id'];
			$r['system_id']       = (int)   $r['system_id'];
			$r['security']        = (float) $r['security'];
			$r['lowsec_distance']    = (int)   $r['lowsec_distance'];
			$r['storyline_distance'] = (int)   $r['storyline_distance'];
			$r['storyline_lowsec']    = (int)   $r['storyline_lowsec'];
		}
		unset( $r );

		return $rows;
	}

	// ── Filter options ────────────────────────────────────────────────────────

	/**
	 * Returns all distinct values for every filter dropdown.
	 *
	 * FIX: each query is now fully self-contained with all JOINs in the correct
	 * position (before WHERE), rather than appending JOINs after a WHERE clause.
	 */
	public static function get_filter_options(): array {
		global $wpdb;

		$agents_t = EAF_DB::t( 'agents'       );
		$corps_t  = EAF_DB::t( 'corporations' );
		$fac_t    = EAF_DB::t( 'factions'     );
		$div_t    = EAF_DB::t( 'divisions'    );
		$sta_t    = EAF_DB::t( 'stations'          );
		$sop_t    = EAF_DB::t( 'station_operations' );
		$sys_t    = EAF_DB::t( 'systems'           );
		$at_t     = EAF_DB::t( 'agent_types'  );

		// All queries in this method use only internal table names from EAF_DB::t() — never user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Factions that actually have agents in NPC stations
		$factions = $wpdb->get_results( "
			SELECT DISTINCT c.faction_id, COALESCE(f.faction_name, CONCAT('Faction #', c.faction_id)) AS faction_name
			FROM {$agents_t} a
			INNER JOIN {$sta_t}   sta ON sta.station_id   = a.location_id
			INNER JOIN {$sys_t}   sys ON sys.system_id    = sta.system_id
			INNER JOIN {$corps_t} c   ON c.corporation_id = a.corporation_id
			LEFT  JOIN {$fac_t}   f   ON f.faction_id     = c.faction_id
			WHERE a.agent_type_id <> 1
			  AND c.faction_id IS NOT NULL
			ORDER BY faction_name
		" );

		// Corporations that actually have agents
		$corporations = $wpdb->get_results( "
			SELECT DISTINCT c.corporation_id, c.corporation_name, c.faction_id
			FROM {$agents_t} a
			INNER JOIN {$sta_t}   sta ON sta.station_id   = a.location_id
			INNER JOIN {$sys_t}   sys ON sys.system_id    = sta.system_id
			INNER JOIN {$corps_t} c   ON c.corporation_id = a.corporation_id
			WHERE a.agent_type_id <> 1
			ORDER BY c.corporation_name
		" );

		// Divisions present in agents
		$divisions = $wpdb->get_results( "
			SELECT DISTINCT
				a.division_id,
				COALESCE(d.division_name, CONCAT('Division #', a.division_id)) AS division_name
			FROM {$agents_t} a
			INNER JOIN {$sta_t}  sta ON sta.station_id  = a.location_id
			INNER JOIN {$sys_t}  sys ON sys.system_id   = sta.system_id
			LEFT  JOIN {$div_t}  d   ON d.division_id   = a.division_id
			WHERE a.agent_type_id <> 1
			ORDER BY division_name
		" );

		// Agent types present
		$agent_types = $wpdb->get_results( "
			SELECT DISTINCT at.agent_type_id, at.agent_type_name
			FROM {$agents_t} a
			INNER JOIN {$sta_t}  sta ON sta.station_id   = a.location_id
			INNER JOIN {$sys_t}  sys ON sys.system_id    = sta.system_id
			INNER JOIN {$at_t}   at  ON at.agent_type_id = a.agent_type_id
			WHERE a.agent_type_id <> 1
			ORDER BY at.agent_type_name
		" );

		// Agent levels present
		$levels = array_map( 'intval', $wpdb->get_col( "
			SELECT DISTINCT a.level
			FROM {$agents_t} a
			INNER JOIN {$sta_t} sta ON sta.station_id = a.location_id
			INNER JOIN {$sys_t} sys ON sys.system_id  = sta.system_id
			WHERE a.agent_type_id <> 1
			ORDER BY a.level
		" ) );

		// Security class counts
		$sec_counts_raw = $wpdb->get_results( "
			SELECT sys.sec_class, COUNT(DISTINCT a.agent_id) AS cnt
			FROM {$agents_t} a
			INNER JOIN {$sta_t} sta ON sta.station_id = a.location_id
			INNER JOIN {$sys_t} sys ON sys.system_id  = sta.system_id
			WHERE a.agent_type_id <> 1
			GROUP BY sys.sec_class
		", ARRAY_A );

		$sec_counts = array();
		foreach ( $sec_counts_raw as $row ) {
			$sec_counts[ $row['sec_class'] ] = (int) $row['cnt'];
		}

		// Distinct regions that have agents
		$regions = $wpdb->get_col( "
			SELECT DISTINCT sys.region_name
			FROM {$agents_t} a
			INNER JOIN {$sta_t} sta ON sta.station_id = a.location_id
			INNER JOIN {$sys_t} sys ON sys.system_id  = sta.system_id
			WHERE a.agent_type_id <> 1
			  AND sys.region_name IS NOT NULL AND sys.region_name != ''
			ORDER BY sys.region_name
		" );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return compact( 'factions', 'corporations', 'divisions', 'agent_types', 'levels', 'sec_counts', 'regions' );
	}

	/**
	 * Returns up to 10 system names that contain the given substring (case-insensitive).
	 * Used by the "Nearest to…" autocomplete.
	 */
	public static function suggest_systems( string $q ): array {
		global $wpdb;
		$sys_t = EAF_DB::t( 'systems' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT system_name FROM {$sys_t} WHERE system_name LIKE %s ORDER BY system_name LIMIT 10",
			$like
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $rows ?: array();
	}

	// ── Readiness ─────────────────────────────────────────────────────────────

	public static function is_ready(): bool {
		$status   = EAF_DB::get_import_status();
		$required = array(
			'factions', 'corporations', 'agent_types', 'divisions',
			'systems', 'system_jumps', 'stations', 'agents',
		);
		foreach ( $required as $key ) {
			if ( empty( $status[ $key ] ) ) {
				return false;
			}
		}
		return (bool) get_option( 'eaf_bfs_done', 0 );
	}

}
