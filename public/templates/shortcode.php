<?php
/**
 * Template: shortcode app shell.
 * Included by EAF_Public::render_shortcode(). No direct access.
 *
 * Available variables:
 *   $cfg  (string) — JSON-encoded shortcode config for data-config attribute
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="eaf-app" data-config="<?php echo esc_attr( $cfg ); ?>">

	<!-- ── Loading overlay ──────────────────────────────────────── -->
	<div class="eaf-loader" id="eaf-loader">
		<div class="eaf-loader-inner">
			<div class="eaf-spinner-large"></div>
			<p>Loading agent data…</p>
		</div>
	</div>

	<!-- ── Top toolbar ──────────────────────────────────────────── -->
	<div class="eaf-toolbar">
		<div class="eaf-toolbar-left">
			<h2 class="eaf-app-title">EVE Agent Finder</h2>
			<?php if ( EAF_SSO::is_configured() ) : ?>
			<!-- SSO auth zone — initial state rendered server-side; JS updates dynamically -->
			<div class="eaf-sso-zone" id="eaf-sso-zone">
				<?php $auth = EAF_SSO::get_current_auth(); ?>
				<?php if ( $auth ) :
					$_change_url = EAF_SSO::build_auth_url(
						( is_ssl() ? 'https://' : 'http://' )
						. sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
						. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) )
					);
				?>
					<span class="eaf-sso-authed-label">[Authenticated as <strong><?php echo esc_html( $auth['character_name'] ); ?></strong>]</span>
					<a href="<?php echo esc_url( $_change_url ); ?>" class="eaf-sso-change-btn">[Change Character]</a>
					<button type="button" id="eaf-sso-logout-btn" class="eaf-sso-logout-link">[Log out]</button>
				<?php else :
					$return_url = ( is_ssl() ? 'https://' : 'http://' )
						. sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) )
						. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
				?>
					<a href="<?php echo esc_url( EAF_SSO::build_auth_url( $return_url ) ); ?>" class="eaf-sso-login-btn" id="eaf-sso-login-btn">
						<img src="<?php echo esc_url( EAF_URL . 'public/img/eve-sso.png' ); ?>" alt="LOG IN with EVE Online">
					</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<div class="eaf-toolbar-right">
			<button class="eaf-btn eaf-btn-sm eaf-btn-info" id="eaf-info-btn" title="How to use this tool">ℹ How to use</button>
			<button class="eaf-btn eaf-btn-sm eaf-btn-share" id="eaf-share-btn" title="Copy shareable link to clipboard">⎘ Share</button>
			<button class="eaf-btn eaf-btn-sm eaf-btn-version" id="eaf-version-btn" title="Version history &amp; changelog">📋 Version info</button>
			<div class="eaf-view-toggle">
				<button class="eaf-view-btn active" data-view="station" title="Hub view — grouped by system">Hubs</button>
				<button class="eaf-view-btn"        data-view="table"   title="Flat agent table">Table</button>
			</div>
		</div>
	</div>

	<!-- ── Info modal ───────────────────────────────────────────── -->
	<?php require __DIR__ . '/info-modal.php'; ?>

	<!-- ── Changelog modal ──────────────────────────────────────── -->
	<div class="eaf-modal" id="eaf-version-modal" style="display:none">
		<div class="eaf-modal-box eaf-modal-box-wide">
			<button class="eaf-modal-close" id="eaf-version-close" aria-label="Close">✕</button>
			<h3 class="eaf-modal-title" style="display:flex;align-items:center;gap:.75rem;">Version info <a href="https://github.com/C4813/EVE-Agent-Finder" target="_blank" rel="noopener" style="margin-left:auto;font-size:.95rem;font-weight:500;color:#7ac4ff;text-decoration:none;border:1px solid #2a5080;border-radius:4px;padding:2px 12px;white-space:nowrap;">⎋ GitHub</a></h3>
			<div id="eaf-version-content" class="eaf-changelog">
				<div class="eaf-changelog-loading">Loading changelog…</div>
			</div>
		</div>
	</div>

	<!-- ── Filter panel ─────────────────────────────────────────── -->
	<div class="eaf-filter-panel" id="eaf-filter-panel">
		<div class="eaf-filter-row">

			<!-- Search + hub options -->
			<div class="eaf-filter-group eaf-filter-search-col">
				<div class="eaf-filter-group">
					<label>Search</label>
					<input type="search" id="eaf-search"
					       placeholder="agent · station · system · corp · faction"
					       autocomplete="off">
				</div>
				<div class="eaf-filter-group eaf-hub-inline" id="eaf-hub-options">
					<label>Advanced options</label>
					<!-- Row 1: Min agents stepper + scope -->
					<div class="eaf-hub-options-row">
						<!-- Min agents stepper -->
						<div class="eaf-min-agents-pill">
							<span class="eaf-min-agents-label">Min agents</span>
							<button class="eaf-min-agents-btn" id="eaf-min-agents-dec" type="button" aria-label="decrease">−</button>
							<span class="eaf-min-agents-val" id="eaf-min-agents-display">1</span>
							<input type="hidden" id="eaf-min-agents" value="1">
							<button class="eaf-min-agents-btn" id="eaf-min-agents-inc" type="button" aria-label="increase">+</button>
						</div>
						<!-- Per-system / per-station scope -->
						<div class="eaf-scope-pills" id="eaf-min-agents-scope" data-scope="system">
							<span class="eaf-scope-pill eaf-scope-pill-active" data-val="system">per system</span>
							<span class="eaf-scope-pill" data-val="station">per station</span>
						</div>
					</div>
					<!-- Row 2: Min L4 stepper + scope -->
					<div class="eaf-hub-options-row">
						<div class="eaf-min-agents-pill">
							<span class="eaf-min-agents-label">Min L4</span>
							<button class="eaf-min-agents-btn" id="eaf-min-l4-dec" type="button" aria-label="decrease L4">−</button>
							<span class="eaf-min-agents-val" id="eaf-min-l4-display">0</span>
							<input type="hidden" id="eaf-min-l4-agents" value="0">
							<button class="eaf-min-agents-btn" id="eaf-min-l4-inc" type="button" aria-label="increase L4">+</button>
						</div>
						<!-- Per-system / per-station scope for L4 -->
						<div class="eaf-scope-pills" id="eaf-min-l4-scope" data-scope="system">
							<span class="eaf-scope-pill eaf-scope-pill-active" data-val="system">per system</span>
							<span class="eaf-scope-pill" data-val="station">per station</span>
						</div>
					</div>
					<!-- Row 3: Toggles -->
					<div class="eaf-hub-options-row">
						<!-- Storyline toggle -->
						<label class="eaf-check-pill">
							<input type="checkbox" id="eaf-storyline-only"> Storyline in system
						</label>
						<!-- Locator toggle -->
						<label class="eaf-check-pill">
							<input type="checkbox" id="eaf-locator-only"> <span class="eaf-locator-tag">locator</span> agents only
						</label>
					</div>
					<?php if ( EAF_SSO::is_configured() ) : ?>
					<!-- Row 4: Standings filter — only shown when a character is authenticated -->
					<div class="eaf-hub-options-row eaf-standings-row" id="eaf-standings-row" style="display:none">
						<label class="eaf-check-pill eaf-standings-pill">
							<input type="checkbox" id="eaf-standings-filter">
							<span class="eaf-standings-label">Available to my character only</span>
						</label>
						<span class="eaf-standings-status" id="eaf-standings-status"></span>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Location / security class -->
			<div class="eaf-filter-group eaf-sec-block">
				<label>Location</label>
				<div class="eaf-sec-cards" id="eaf-sec-class">
					<div class="eaf-sec-card eaf-sec-card-highsec">
						<label class="eaf-sec-card-label">
							<input type="checkbox" name="sec_class" value="highsec" checked>
							<span class="eaf-sec-name sec-hi">Highsec</span>
						</label>
						<div class="eaf-highsec-only eaf-jumps-row">
							<span class="eaf-jumps-row-label">Min jumps from Lowsec</span>
							<input type="range" id="eaf-min-jumps-range" min="0" max="30" value="0" step="1">
							<span class="eaf-range-val" id="eaf-min-jumps-val">0</span>
						</div>
					</div>
					<div class="eaf-sec-card eaf-sec-card-lowsec">
						<label class="eaf-sec-card-label">
							<input type="checkbox" name="sec_class" value="lowsec" checked>
							<span class="eaf-sec-name sec-lo">Lowsec</span>
						</label>
					</div>
					<div class="eaf-sec-card eaf-sec-card-nullsec">
						<label class="eaf-sec-card-label">
							<input type="checkbox" name="sec_class" value="nullsec" checked>
							<span class="eaf-sec-name sec-null">Nullsec</span>
						</label>
					</div>
				</div>
			</div>

			<!-- Agent level -->
			<div class="eaf-filter-group">
				<label>Agent level</label>
				<div class="eaf-check-group eaf-check-vertical" id="eaf-level-filter">
					<label class="eaf-check-pill eaf-level-1"><input type="checkbox" name="level" value="1"> L1</label>
					<label class="eaf-check-pill eaf-level-2"><input type="checkbox" name="level" value="2"> L2</label>
					<label class="eaf-check-pill eaf-level-3"><input type="checkbox" name="level" value="3"> L3</label>
					<label class="eaf-check-pill eaf-level-4"><input type="checkbox" name="level" value="4"> L4</label>
					<label class="eaf-check-pill eaf-level-5"><input type="checkbox" name="level" value="5"> L5</label>
				</div>
			</div>

			<!-- Mission type -->
			<div class="eaf-filter-group">
				<label>Mission type</label>
				<div class="eaf-check-group eaf-check-vertical" id="eaf-mission-type">
					<label class="eaf-check-pill eaf-mt-dist" ><input type="checkbox" name="mission_type" value="Distribution"> Distribution</label>
					<label class="eaf-check-pill eaf-mt-sec"  ><input type="checkbox" name="mission_type" value="Security"> Security</label>
					<label class="eaf-check-pill eaf-mt-mine" ><input type="checkbox" name="mission_type" value="Mining"> Mining</label>
					<label class="eaf-check-pill eaf-mt-rd"   ><input type="checkbox" name="mission_type" value="R&D"> R&amp;D</label>
				</div>
			</div>

			<!-- Hidden division select (used internally by JS) -->
			<div class="eaf-hidden">
				<select id="eaf-division" multiple><option value="">— All —</option></select>
			</div>

			<!-- Dropdowns: agent type, faction, corporation, sort -->
			<div class="eaf-filter-group eaf-filter-selects-col">
				<div class="eaf-select-row">
					<label>Agent type</label>
					<select id="eaf-agent-type"><option value="">— All types —</option></select>
				</div>
				<div class="eaf-select-row">
					<label>Faction</label>
					<select id="eaf-faction"><option value="">— All factions —</option></select>
				</div>
				<div class="eaf-select-row">
					<label>Corporation</label>
					<select id="eaf-corporation"><option value="">— All corps —</option></select>
				</div>
				<div class="eaf-select-row">
					<label>Region</label>
					<select id="eaf-region"><option value="">— All regions —</option></select>
				</div>
				<div class="eaf-select-row">
					<label>Sort by</label>
					<select id="eaf-sort-by">
						<option value="agents_desc">Most agents</option>
						<option value="jumps_desc">Furthest from Lowsec</option>
						<option value="jumps_asc">Nearest to Lowsec</option>
						<option value="system_asc">System name A→Z</option>
						<option value="score_desc">Hub score</option>
						<option value="nearest_to">Nearest to…</option>
					</select>
				</div>
				<div class="eaf-select-row eaf-nearest-row" id="eaf-nearest-row" style="display:none">
					<label>System name</label>
					<div class="eaf-nearest-input-wrap">
						<div class="eaf-autocomplete-wrap">
							<input type="search" id="eaf-nearest-system"
							       placeholder="e.g. Jita" autocomplete="off">
							<div class="eaf-autocomplete-dropdown" id="eaf-nearest-autocomplete"></div>
						</div>
						<span id="eaf-nearest-status"></span>
					</div>
				</div>
			</div>

		</div>
	</div><!-- /.eaf-filter-panel -->

	<!-- ── Results area ─────────────────────────────────────────── -->
	<div class="eaf-results-area" id="eaf-results-area">
		<!-- Populated by JS -->
	</div>

</div><!-- /.eaf-app -->
