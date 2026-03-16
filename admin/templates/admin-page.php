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

<?php
// ── EVE SSO Settings ───────────────────────────────────────────────────────
// Rendered as a separate section below the import panel.
$callback_url       = admin_url( 'admin-post.php?action=eaf_sso_callback' );
$client_id_saved    = (string) get_option( 'eaf_sso_client_id', '' );
$client_secret_saved = get_option( 'eaf_sso_client_secret', '' ) !== '';
$sso_configured     = EAF_SSO::is_configured();
?>
<div class="wrap eaf-admin-wrap eaf-sso-settings">
	<h1>EVE Agent Finder — EVE SSO Settings</h1>

	<div class="eaf-info-box">
		<p>
			When a Client ID and Client Secret are saved here, front-end visitors will see a
			<strong>LOG IN with EVE Online</strong> button in the EVE Agent Finder toolbar.
			After authenticating, an <em>Available to my character only</em> filter becomes
			available in Advanced options, hiding agents the character cannot access based on
			their current standings.
		</p>
		<?php if ( $sso_configured ) : ?>
		<p class="eaf-sso-status eaf-sso-status-ok">✅ EVE SSO is configured and active.</p>
		<?php else : ?>
		<p class="eaf-sso-status eaf-sso-status-warn">⚠ EVE SSO is not yet configured — complete the steps below.</p>
		<?php endif; ?>
	</div>

	<!-- ── Step 1: Create developer app ─────────────────────────────────── -->
	<div class="eaf-sso-step-card">
		<h2><span class="eaf-sso-step-num">1</span> Create an EVE Online Developer Application</h2>
		<ol class="eaf-sso-instructions">
			<li>
				Go to the <a href="https://developers.eveonline.com/applications" target="_blank" rel="noopener">EVE Online Developer Portal</a>
				and sign in with an EVE account.
			</li>
			<li>Click <strong>Create New Application</strong>.</li>
			<li>Give it a descriptive name (e.g. <em><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — Agent Finder</em>).</li>
			<li>
				Under <strong>Connection Type</strong>, choose <strong>Authentication &amp; API Access</strong>
				(not "Authentication Only" — we need the standings scope).
			</li>
			<li>
				Under <strong>Permissions / Scopes</strong>, add exactly one scope:<br>
				<code class="eaf-sso-scope">esi-characters.read_standings.v1</code>
			</li>
			<li>
				Set the <strong>Callback URL</strong> to the value shown below. Copy it exactly — it
				must match character-for-character.
			</li>
			<li>Save the application. You will be shown a <strong>Client ID</strong> and a
				<strong>Secret Key</strong> — paste both into the fields below.</li>
		</ol>

		<table class="form-table eaf-sso-copy-table">
			<tr>
				<th scope="row">Callback URL</th>
				<td>
					<code id="eaf-callback-url"><?php echo esc_html( $callback_url ); ?></code>
					<button type="button" class="button eaf-copy-callback" data-copy="<?php echo esc_attr( $callback_url ); ?>">
						⧉ Copy
					</button>
				</td>
			</tr>
			<tr>
				<th scope="row">Required Scope</th>
				<td><code>esi-characters.read_standings.v1</code></td>
			</tr>
		</table>
	</div>

	<!-- ── Step 2: Enter credentials ─────────────────────────────────────── -->
	<div class="eaf-sso-step-card">
		<h2><span class="eaf-sso-step-num">2</span> Enter Your Application Credentials</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'eaf_sso_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="eaf_sso_client_id">Client ID</label></th>
					<td>
						<input
							type="text"
							id="eaf_sso_client_id"
							name="eaf_sso_client_id"
							value="<?php echo esc_attr( $client_id_saved ); ?>"
							class="regular-text"
							autocomplete="off"
							placeholder="Paste your Client ID here"
						>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="eaf_sso_client_secret">Secret Key</label></th>
					<td>
						<input
							type="password"
							id="eaf_sso_client_secret"
							name="eaf_sso_client_secret"
							value=""
							class="regular-text"
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( $client_secret_saved ? '(saved — leave blank to keep)' : 'Paste your Secret Key here' ); ?>"
						>
						<p class="description">
							The Secret Key is stored securely and never displayed again after saving.
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save SSO Credentials' ); ?>
		</form>
	</div>

	<!-- ── Step 3: GDPR compliance ───────────────────────────────────────── -->
	<div class="eaf-sso-step-card">
		<h2><span class="eaf-sso-step-num">3</span> GDPR Compliance — Register the Cookie</h2>
		<p style="color:#8ab0cc;margin-bottom:1rem;">
			When EVE SSO is active, this plugin sets a cookie named <code class="eaf-sso-scope">eaf_char_token</code>
			in the visitor's browser when they log in. For GDPR compliance this cookie should be declared in your
			cookie consent tool.
		</p>
		<p style="color:#8ab0cc;margin-bottom:1rem;">
			Cookie scanners cannot detect this cookie automatically because it is only set after a visitor
			completes the EVE Online login flow — it will never appear during a routine page scan.
			<strong style="color:#c8d8e8;">You must add it manually</strong> in your cookie consent plugin's dashboard.
		</p>
		<table class="form-table eaf-sso-copy-table">
			<tr>
				<th scope="row">Cookie name</th>
				<td><code>eaf_char_token</code></td>
			</tr>
			<tr>
				<th scope="row">Category</th>
				<td>Necessary / Functional</td>
			</tr>
			<tr>
				<th scope="row">Duration</th>
				<td>1 day</td>
			</tr>
			<tr>
				<th scope="row">Script URL pattern</th>
				<td><em style="color:#7090aa;">Leave blank</em> — this cookie is set server-side by PHP, not by any script</td>
			</tr>
			<tr>
				<th scope="row">Description</th>
				<td>Set when a visitor authenticates via EVE Online SSO on the EVE Agent Finder. Stores a random session token linking the browser to an authenticated EVE character. Only set when the user explicitly clicks the LOG IN with EVE Online button. Contains no personal data.</td>
			</tr>
		</table>
	</div>
</div>
