<?php
/**
 * Template: admin import panel.
 * Included by EAF_Admin::render_page(). No direct access.
 *
 * Available variables:
 *   $status   (array)  — import log keyed by table_key
 *   $bfs_done (int)    — 1 if BFS has been run
 *   $dist     (array)  — distribution stats (safe/medium/close system counts)
 *   $steps    (array)  — [ step_key => [ label, yaml_hint ] ]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap eaf-admin-wrap">
	<h1>EVE Agent Finder — SDE Import</h1>

	<div class="eaf-info-box">
		<p>Upload the complete <a href="https://developers.eveonline.com/static-data" target="_blank">CCP Static Data Export (SDE)</a> zip file. All required YAML files will be imported automatically, then click <strong>Run BFS</strong>.</p>
	</div>

	<?php
	// Check for unnamed agents
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal table name, no user input.
	$unnamed_count = (int) $wpdb->get_var(
		'SELECT COUNT(*) FROM ' . EAF_DB::t( 'agents' ) . ' WHERE agent_name IS NULL OR agent_name = ""'
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	?>
	<?php if ( $unnamed_count > 0 ) : ?>
	<div class="notice notice-warning"><p>⚠ <strong><?php echo esc_html( number_format( $unnamed_count ) ); ?> agents have no name</strong> in the database. For named agents, upload <code>npcCharacters.yaml</code> (not <code>agtAgents.yaml</code>).</p></div>
	<?php endif; ?>

	<?php if ( $bfs_done && ! empty( $dist ) ) : ?>
	<div class="eaf-stat-row">
		<div class="eaf-stat"><span><?php echo intval( $dist['safe'] ); ?></span> Highsec ≥7 jumps from Lowsec</div>
		<div class="eaf-stat"><span><?php echo intval( $dist['medium'] ); ?></span> systems 4–6 jumps</div>
		<div class="eaf-stat"><span><?php echo intval( $dist['close'] ); ?></span> systems 1–3 jumps</div>
	</div>
	<?php endif; ?>

	<!-- ── SDE ZIP Upload ─────────────────────────────────────────── -->
	<div class="eaf-zip-upload-area" id="eaf-zip-area">
		<div class="eaf-zip-icon">📦</div>
		<div class="eaf-zip-label">
			<label for="eaf-sde-zip-input">
				<strong>Choose SDE zip file</strong> or drag &amp; drop here
			</label>
			<input type="file" id="eaf-sde-zip-input" accept=".zip">
		</div>
		<div class="eaf-zip-filename" id="eaf-zip-filename"></div>
		<button class="button button-primary eaf-zip-import-btn" id="eaf-zip-import-btn" disabled>⬆ Import SDE</button>
	</div>

	<!-- ── Import results ────────────────────────────────────────── -->
	<div class="eaf-import-results" id="eaf-import-results" style="display:none">
		<h3>Import Results</h3>
		<table class="eaf-results-table">
			<thead><tr><th>File</th><th>Status</th><th>Rows</th></tr></thead>
			<tbody id="eaf-results-tbody"></tbody>
		</table>
	</div>

	<!-- ── Import status grid (read-only) ────────────────────────── -->
	<div id="eaf-global-status" class="eaf-global-status"></div>
	<div class="eaf-import-grid" id="eaf-import-grid">
		<?php foreach ( $steps as $key => [ $label, $hint ] ) :
			$done = isset( $status[ $key ] );
			$rows = $done ? esc_html( number_format( $status[ $key ]['row_count'] ) ) : '—';
			$ts   = $done
				? esc_html( human_time_diff( strtotime( $status[ $key ]['imported_at'] ) ) . ' ago' )
				: 'Not imported';
		?>
		<div class="eaf-step <?php echo esc_attr( $done ? 'done' : 'pending' ); ?>" data-step="<?php echo esc_attr( $key ); ?>">
			<div class="eaf-step-header">
				<span class="eaf-step-status"><?php echo esc_html( $done ? '✅' : '⏳' ); ?></span>
				<strong><?php echo esc_html( $label ); ?></strong>
				<button class="button button-small eaf-drop-btn" data-step="<?php echo esc_attr( $key ); ?>" title="Drop table">🗑</button>
			</div>
			<div class="eaf-step-meta">
				<code><?php echo esc_html( $hint ); ?></code><br>
				<small><?php echo esc_html( $rows ); ?> rows &middot; <?php echo esc_html( $ts ); ?></small>
			</div>
			<div class="eaf-step-log"></div>
		</div>
		<?php endforeach; ?>

		<!-- BFS card -->
		<div class="eaf-step <?php echo esc_attr( $bfs_done ? 'done' : 'pending' ); ?>" data-step="bfs">
			<div class="eaf-step-header">
				<span class="eaf-step-status"><?php echo esc_html( $bfs_done ? '✅' : '⏳' ); ?></span>
				<strong>Jump Distance BFS</strong>
			</div>
			<div class="eaf-step-meta">
				<code>Computed from imported data</code><br>
				<small><?php echo esc_html( $bfs_done ? 'Complete' : 'Not run' ); ?></small>
			</div>
			<div class="eaf-upload-row">
				<button id="eaf-run-bfs" class="button button-primary">♻ Run BFS</button>
			</div>
			<div class="eaf-step-log"></div>
		</div>
	</div>

	<hr>
	<p class="description">
		Shortcode: <code>[eve_agent_finder]</code>
	</p>
</div>
