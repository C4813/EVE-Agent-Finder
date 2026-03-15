<?php
/**
 * EAF_Admin – Admin panel + AJAX handlers for CCP SDE import.
 *
 * Accepts a single SDE zip upload. Extracts required YAML files automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAF_Admin {

	public function init(): void {
		add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_eaf_sde_zip_import', [ $this, 'ajax_zip_import' ] );
		add_action( 'wp_ajax_eaf_import_bfs',     [ $this, 'ajax_bfs'        ] );
		add_action( 'wp_ajax_eaf_drop_step',      [ $this, 'ajax_drop_step'  ] );
	}

	public function register_menu(): void {
		add_menu_page(
			'EVE Agent Finder', 'EVE Agent Finder', 'manage_options',
			'eve-agent-finder', [ $this, 'render_page' ],
			'dashicons-location-alt', 80
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_eve-agent-finder' ) return;
		wp_enqueue_style(  'eaf-admin', EAF_URL . 'admin/css/eaf-admin.css', [], EAF_VERSION );
		wp_enqueue_script( 'eaf-admin', EAF_URL . 'admin/js/eaf-admin.js',   [ 'jquery' ], EAF_VERSION, true );
		wp_localize_script( 'eaf-admin', 'EAF_Admin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'eaf_import' ),
		] );
	}

	// ── Page render ──────────────────────────────────────────────────────────

	public function render_page(): void {
		EAF_DB::create_tables();

		$status   = EAF_DB::get_import_status();
		$bfs_done = get_option( 'eaf_bfs_done', 0 );
		$dist     = $bfs_done ? EAF_Calculator::get_distribution() : [];

		$steps = [
			'factions'       => [ 'Factions',            'factions.yaml'                ],
			'corporations'   => [ 'Corporations',         'npcCorporations.yaml'         ],
			'agent_types'    => [ 'Agent Types',          'agentTypes.yaml'              ],
			'divisions'      => [ 'Divisions',            'npcCorporationDivisions.yaml' ],
			'constellations' => [ 'Constellations',       'mapConstellations.yaml'       ],
			'regions'        => [ 'Regions',              'mapRegions.yaml'              ],
			'systems'        => [ 'Solar Systems',        'mapSolarSystems.yaml'         ],
			'system_jumps'   => [ 'Stargate Jumps',       'mapStargates.yaml'            ],
			'station_ops'    => [ 'Station Operations',   'stationOperations.yaml'       ],
			'stations'       => [ 'NPC Stations',         'npcStations.yaml'             ],
			'agents'         => [ 'Agents',               'npcCharacters.yaml'           ],
		];

		require EAF_DIR . 'admin/templates/admin-page.php';
	}

	// ── AJAX: SDE zip import ─────────────────────────────────────────────────

	public function ajax_zip_import(): void {
		check_ajax_referer( 'eaf_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorised.' ] );
		}

		$upload_error = isset( $_FILES['sde_zip']['error'] ) ? (int) $_FILES['sde_zip']['error'] : -1; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $upload_error !== UPLOAD_ERR_OK ) {
			wp_send_json_error( [ 'message' => 'Upload error code ' . $upload_error . '.' ] );
		}

		$tmp = isset( $_FILES['sde_zip']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['sde_zip']['tmp_name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_file( $tmp ) ) {
			wp_send_json_error( [ 'message' => 'Uploaded file not accessible.' ] );
		}

		// Check uploaded zip size (guard against extremely large uploads before any processing)
		$max_zip_bytes = 2 * 1024 * 1024 * 1024; // 2 GB
		if ( filesize( $tmp ) > $max_zip_bytes ) {
			wp_send_json_error( [ 'message' => 'Uploaded file exceeds the 2 GB size limit.' ] );
		}

		// Verify file is actually a zip via magic bytes (not just the extension)
		// WP_Filesystem has no partial-read method; fread of 4 bytes is the only option here.
		$fh = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$magic = $fh ? fread( $fh, 4 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( $fh ) fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( substr( $magic, 0, 2 ) !== 'PK' ) {
			wp_send_json_error( [ 'message' => 'File does not appear to be a valid zip archive.' ] );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_send_json_error( [ 'message' => 'PHP ZipArchive extension is required but not available on this server.' ] );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $tmp ) !== true ) {
			wp_send_json_error( [ 'message' => 'Could not open zip file. Is it a valid SDE zip?' ] );
		}

		// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky
		if ( function_exists( 'set_time_limit' ) ) set_time_limit( 600 );
		@ini_set( 'memory_limit', '512M' );
		// phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky

		// Map: yaml filename → [ step_key, label, importer_method ]
		$manifest = [
			'factions.yaml'                => [ 'factions',       'Factions',          'import_factions'           ],
			'npcCorporations.yaml'         => [ 'corporations',   'Corporations',       'import_corporations'       ],
			'agentTypes.yaml'              => [ 'agent_types',    'Agent Types',        'import_agent_types'        ],
			'npcCorporationDivisions.yaml' => [ 'divisions',      'Divisions',          'import_divisions'          ],
			'mapConstellations.yaml'       => [ 'constellations', 'Constellations',     'import_constellations'     ],
			'mapRegions.yaml'              => [ 'regions',        'Regions',            'import_regions'            ],
			'mapSolarSystems.yaml'         => [ 'systems',        'Solar Systems',      'import_systems'            ],
			'mapStargates.yaml'            => [ 'system_jumps',   'Stargate Jumps',     'import_system_jumps'       ],
			'stationOperations.yaml'       => [ 'station_ops',    'Station Operations', 'import_station_operations' ],
			'npcStations.yaml'             => [ 'stations',       'NPC Stations',       'import_stations'           ],
			'npcCharacters.yaml'           => [ 'agents',         'Agents',             'import_agents'             ],
		];

		// Extract matching files to a temp directory
		$tmp_dir = get_temp_dir() . 'eaf_sde_' . uniqid() . '/';
		wp_mkdir_p( $tmp_dir );

		$found   = [];
		$missing = [];

		$max_file_bytes = 600 * 1024 * 1024; // 600 MB per extracted file
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name     = $zip->getNameIndex( $i );
			$basename = basename( $name );
			if ( isset( $manifest[ $basename ] ) ) {
				$stat = $zip->statIndex( $i );
				if ( $stat && $stat['size'] > $max_file_bytes ) {
					$results[] = [
						'key'     => $manifest[ $basename ][0],
						'label'   => $manifest[ $basename ][1],
						'success' => false,
						'message' => 'File exceeds 600 MB limit, skipped.',
						'rows'    => 0,
					];
					continue;
				}
				$dest = $tmp_dir . $basename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $dest, $zip->getFromIndex( $i ) );
				$found[ $basename ] = $dest;
			}
		}
		$zip->close();

		// Run importers in manifest order
		$results = [];
		foreach ( $manifest as $filename => [ $key, $label, $method ] ) {
			if ( ! isset( $found[ $filename ] ) ) {
				$missing[] = $filename;
				$results[] = [
					'key'     => $key,
					'label'   => $label,
					'success' => false,
					'message' => 'Not found in zip.',
					'rows'    => 0,
				];
				continue;
			}
			$result    = EAF_Importer::$method( $found[ $filename ] );
			$results[] = [
				'key'     => $key,
				'label'   => $label,
				'success' => $result['success'],
				'message' => $result['message'],
				'rows'    => $result['rows'] ?? 0,
			];
		}

		// Clean up extracted files
		foreach ( $found as $dest ) {
			@unlink( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		@rmdir( $tmp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		// Reset BFS since data changed
		update_option( 'eaf_bfs_done', 0 );

		$any_success = array_filter( $results, fn( $r ) => $r['success'] );
		wp_send_json_success( [
			'results' => $results,
			'missing' => $missing,
			'message' => count( $any_success ) . ' of ' . count( $manifest ) . ' files imported successfully.',
		] );
	}

	// ── AJAX: Drop individual table ──────────────────────────────────────────

	public function ajax_drop_step(): void {
		check_ajax_referer( 'eaf_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorised.' ] );
		}
		$step_tables = [
			'factions'       => 'factions',
			'corporations'   => 'corporations',
			'agent_types'    => 'agent_types',
			'divisions'      => 'divisions',
			'constellations' => 'constellations',
			'regions'        => 'regions',
			'systems'        => 'systems',
			'system_jumps'   => 'system_jumps',
			'station_ops'    => 'station_operations',
			'stations'       => 'stations',
			'agents'         => 'agents',
		];
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( ! array_key_exists( $step, $step_tables ) ) {
			wp_send_json_error( [ 'message' => 'Unknown step.' ] );
		}
		global $wpdb;
		$table = EAF_DB::t( $step_tables[ $step ] );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table.
		$wpdb->delete( EAF_DB::t( 'import_log' ), [ 'table_key' => $step ], [ '%s' ] );
		update_option( 'eaf_bfs_done', 0 );
		wp_send_json_success( [ 'message' => 'Table cleared.' ] );
	}

	// ── AJAX: BFS ────────────────────────────────────────────────────────────

	public function ajax_bfs(): void {
		check_ajax_referer( 'eaf_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorised.' ] );
		}
		// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky
		if ( function_exists( 'set_time_limit' ) ) set_time_limit( 300 );
		@ini_set( 'memory_limit', '256M' );
		// phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.IniSet.Risky
		$result = EAF_Calculator::run_bfs();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
