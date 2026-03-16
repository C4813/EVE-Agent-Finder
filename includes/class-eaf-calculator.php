<?php
/**
 * EAF_Calculator  –  Multi-source BFS across the k-space stargate graph.
 *
 * Computes per system:
 *   lowsec_distance    – jumps to the nearest low-sec system
 *   storyline_distance – jumps to the nearest storyline-agent system
 *   storyline_lowsec   – lowsec_distance of that nearest storyline system
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_Calculator {

	public static function run_bfs( ?callable $progress = null ): array {
		global $wpdb;

		$sys_table    = EAF_DB::t( 'systems'      );
		$jump_table   = EAF_DB::t( 'system_jumps' );
		$agents_table = EAF_DB::t( 'agents'       );
		$sta_table    = EAF_DB::t( 'stations'     );
		$at_table     = EAF_DB::t( 'agent_types'  );

		// All queries in run_bfs() use only internal table names from EAF_DB::t() — never user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 1. Load adjacency list
		$jumps = $wpdb->get_results( "SELECT from_id, to_id FROM {$jump_table}", ARRAY_A );
		if ( empty( $jumps ) ) {
			return array( 'success' => false, 'message' => 'No jump data. Import system_jumps first.', 'updated' => 0 );
		}
		$adj = array();
		foreach ( $jumps as $j ) {
			$adj[ (int) $j['from_id'] ][] = (int) $j['to_id'];
		}

		// 2. BFS: low-sec distance + which lowsec system is the gateway
		$lowsec_rows = $wpdb->get_results( "SELECT system_id, system_name FROM {$sys_table} WHERE sec_class = 'lowsec'", ARRAY_A );
		if ( empty( $lowsec_rows ) ) {
			return array( 'success' => false, 'message' => 'No Lowsec systems found.', 'updated' => 0 );
		}
		$lowsec_ids  = array_column( $lowsec_rows, 'system_id' );
		$lowsec_name = array_column( $lowsec_rows, 'system_name', 'system_id' ); // id → name
		list( $lowsec_dist, $lowsec_seed_map ) = self::bfs_tracked( $adj, $lowsec_ids );

		// 3. BFS: storyline distance + track which seed reached each system
		$storyline_dist     = array();
		$storyline_seed_map = array(); // system_id → seed system_id that reached it

		// Fetch ALL storyline agent type IDs (new SDE has GenericStorylineMissionAgent and StorylineMissionAgent)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sl_type_ids = array_map( 'intval', $wpdb->get_col(
			"SELECT agent_type_id FROM {$at_table} WHERE LOWER(agent_type_name) LIKE '%storyline%'"
		) );
		if ( ! empty( $sl_type_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $sl_type_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built dynamically via array_fill(); $sl_type_ids are all intval'd integers.
			$sl_sys_ids   = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT sta.system_id
				 FROM {$agents_table} a
				 INNER JOIN {$sta_table} sta ON sta.station_id = a.location_id
				 WHERE a.agent_type_id IN ({$placeholders})",
				...$sl_type_ids
			) );
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			if ( ! empty( $sl_sys_ids ) ) {
				list( $storyline_dist, $storyline_seed_map ) = self::bfs_tracked( $adj, $sl_sys_ids );
			}
		}

		unset( $adj );

		// 4. Build a map: storyline seed system_id → its own lowsec_distance, system_name, region_name
		$seed_lowsec   = array();
		$seed_sys_name = array();
		$seed_reg_name = array();
		foreach ( array_unique( array_values( $storyline_seed_map ) ) as $seed_id ) {
			$seed_lowsec[ $seed_id ]   = isset( $lowsec_dist[ $seed_id ] ) ? (int) $lowsec_dist[ $seed_id ] : null;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT system_name, region_name FROM {$sys_table} WHERE system_id = %d", $seed_id
			), ARRAY_A );
			$seed_sys_name[ $seed_id ] = $row['system_name'] ?? null;
			$seed_reg_name[ $seed_id ] = $row['region_name'] ?? null;
		}

		// 5. Write to DB
		$all_sys_ids = array_map( 'intval', $wpdb->get_col( "SELECT system_id FROM {$sys_table}" ) );
		$wpdb->query( "UPDATE {$sys_table} SET lowsec_distance = NULL, lowsec_gateway = NULL, storyline_distance = NULL, storyline_system = NULL, storyline_region = NULL, storyline_lowsec = NULL" );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching

		$updated = 0;
		$chunk   = array();

		foreach ( $all_sys_ids as $sid ) {
			$ld  = isset( $lowsec_dist[ $sid ]     ) ? (int) $lowsec_dist[ $sid ]    : null;
			$lgw = null;
			if ( $ld !== null && isset( $lowsec_seed_map[ $sid ] ) ) {
				$seed_id = $lowsec_seed_map[ $sid ];
				$lgw     = $lowsec_name[ $seed_id ] ?? null;
			}
			$sd   = isset( $storyline_dist[ $sid ] ) ? (int) $storyline_dist[ $sid ] : null;
			$ssys = null;
			$sreg = null;
			$sld  = null;
			if ( $sd !== null && isset( $storyline_seed_map[ $sid ] ) ) {
				$seed = $storyline_seed_map[ $sid ];
				$ssys = $seed_sys_name[ $seed ] ?? null;
				$sreg = $seed_reg_name[ $seed ] ?? null;
				$sld  = $seed_lowsec[ $seed ]   ?? null;
			}

			$chunk[] = '(' . $sid
				. ', ' . ( $ld   !== null ? $ld   : 'NULL' )
				. ', ' . ( $lgw  !== null ? "'" . esc_sql( $lgw )  . "'" : 'NULL' )
				. ', ' . ( $sd   !== null ? $sd   : 'NULL' )
				. ', ' . ( $ssys !== null ? "'" . esc_sql( $ssys ) . "'" : 'NULL' )
				. ', ' . ( $sreg !== null ? "'" . esc_sql( $sreg ) . "'" : 'NULL' )
				. ', ' . ( $sld  !== null ? $sld  : 'NULL' )
				. ')';

			if ( count( $chunk ) >= 500 ) {
				self::flush_chunk( $sys_table, $chunk );
				$updated += count( $chunk );
				$chunk    = array();
			}
		}
		if ( ! empty( $chunk ) ) {
			self::flush_chunk( $sys_table, $chunk );
			$updated += count( $chunk );
		}

		update_option( 'eaf_bfs_done', 1 );
		update_option( 'eaf_bfs_timestamp', time() );
		EAF_DB::clear_cache();

		return array(
			'success' => true,
			'message' => "BFS complete. Updated {$updated} systems (Lowsec + storyline distances).",
			'updated' => $updated,
		);
	}

	/**
	 * Standard multi-source BFS.
	 * Returns [ system_id => distance ]
	 */
	private static function bfs( array $adj, array $seeds ): array {
		$dist  = array();
		$queue = new SplQueue();
		$queue->setIteratorMode( SplDoublyLinkedList::IT_MODE_FIFO );
		foreach ( $seeds as $sid ) {
			$sid = (int) $sid;
			$dist[ $sid ] = 0;
			$queue->enqueue( $sid );
		}
		while ( ! $queue->isEmpty() ) {
			$curr = $queue->dequeue();
			if ( isset( $adj[ $curr ] ) ) {
				foreach ( $adj[ $curr ] as $next ) {
					if ( ! isset( $dist[ $next ] ) ) {
						$dist[ $next ] = $dist[ $curr ] + 1;
						$queue->enqueue( $next );
					}
				}
			}
		}
		return $dist;
	}

	/**
	 * Multi-source BFS that also tracks which seed reached each system.
	 * Returns [ dist_map, seed_map ] where seed_map[ system_id ] = seed_system_id
	 */
	private static function bfs_tracked( array $adj, array $seeds ): array {
		$dist  = array();
		$seed  = array(); // system_id → which seed reached it
		$queue = new SplQueue();
		$queue->setIteratorMode( SplDoublyLinkedList::IT_MODE_FIFO );
		foreach ( $seeds as $sid ) {
			$sid = (int) $sid;
			$dist[ $sid ] = 0;
			$seed[ $sid ] = $sid; // a seed reached itself
			$queue->enqueue( $sid );
		}
		while ( ! $queue->isEmpty() ) {
			$curr = $queue->dequeue();
			if ( isset( $adj[ $curr ] ) ) {
				foreach ( $adj[ $curr ] as $next ) {
					if ( ! isset( $dist[ $next ] ) ) {
						$dist[ $next ] = $dist[ $curr ] + 1;
						$seed[ $next ] = $seed[ $curr ]; // propagate which seed started this wave
						$queue->enqueue( $next );
					}
				}
			}
		}
		return array( $dist, $seed );
	}

	/** Write a chunk of pre-built value rows to the systems table. */
	private static function flush_chunk( string $table, array $chunk ): void {
		global $wpdb;
		// Values are integers, NULL literals, and pre-escaped strings only — no raw user input.
		// Table name comes from EAF_DB::t() — an internal whitelist, never user input.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"INSERT INTO {$table} (system_id, lowsec_distance, lowsec_gateway, storyline_distance, storyline_system, storyline_region, storyline_lowsec)
			 VALUES " . implode( ', ', $chunk ) . "
			 ON DUPLICATE KEY UPDATE
			   lowsec_distance    = VALUES(lowsec_distance),
			   lowsec_gateway     = VALUES(lowsec_gateway),
			   storyline_distance = VALUES(storyline_distance),
			   storyline_system   = VALUES(storyline_system),
			   storyline_region   = VALUES(storyline_region),
			   storyline_lowsec   = VALUES(storyline_lowsec)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Single-source BFS from a named system.
	 * Returns an array of system_id => jump_distance for every reachable system.
	 * Used by the "Nearest to" sort option on the front end.
	 *
	 * @param string $system_name  Case-insensitive system name entered by the user.
	 * @return array { success, origin_id, distances } or { success, message }
	 */
	public static function bfs_from_system( string $system_name ): array {
		global $wpdb;

		$sys_table  = EAF_DB::t( 'systems'      );
		$jump_table = EAF_DB::t( 'system_jumps' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 1. Resolve system name → ID (case-insensitive)
		$origin_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT system_id FROM {$sys_table} WHERE LOWER(system_name) = LOWER(%s) LIMIT 1",
			$system_name
		) );

		if ( ! $origin_id ) {
			return array( 'success' => false, 'message' => 'System not found.' );
		}

		// 2. Load full adjacency list
		$jumps = $wpdb->get_results( "SELECT from_id, to_id FROM {$jump_table}", ARRAY_A );

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $jumps ) ) {
			return array( 'success' => false, 'message' => 'No jump data available.' );
		}

		$adj = array();
		foreach ( $jumps as $j ) {
			$adj[ (int) $j['from_id'] ][] = (int) $j['to_id'];
		}

		// 3. Single-source BFS
		$distances = self::bfs( $adj, array( (int) $origin_id ) );

		return array(
			'success'   => true,
			'origin_id' => (int) $origin_id,
			'distances' => $distances,
		);
	}

	/** Distribution summary for the admin panel. */
	public static function get_distribution(): array {
		global $wpdb;
		$table = EAF_DB::t( 'systems' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$rows  = $wpdb->get_results(
			"SELECT
				SUM(lowsec_distance BETWEEN 1 AND 3) AS close,
				SUM(lowsec_distance BETWEEN 4 AND 6) AS medium,
				SUM(lowsec_distance >= 7)             AS safe
			 FROM {$table}
			 WHERE sec_class = 'highsec' AND lowsec_distance IS NOT NULL",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $rows[0] ?? array( 'close' => 0, 'medium' => 0, 'safe' => 0 );
	}
}
