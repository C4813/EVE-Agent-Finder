<?php
/**
 * Template partial: info / how-to modal.
 * Included by shortcode.php. No direct access.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="eaf-info-modal" id="eaf-info-modal" style="display:none">
	<div class="eaf-info-modal-inner">
		<button class="eaf-info-close" id="eaf-info-close">&times;</button>
		<h3>How to use EVE Agent Finder</h3>
		<div class="eaf-info-body">

			<h4>Views</h4>
			<p><strong>Hubs</strong> — systems grouped by hub. Each card shows security status, constellation, region, station count, agent count, storyline proximity, and jumps from Lowsec. Click any card to expand it and see individual stations and agents.</p>
			<p><strong>Constellations</strong> — systems grouped by constellation. Each constellation card shows the name, region, total system count, agent count, and L4 count. Click to expand and browse the full hub cards for every system inside it.</p>
			<p><strong>Table</strong> — a flat, sortable list of every agent matching your filters. Click any column header to sort. System names link to Dotlan; the Jumps→LS column shows a colour-coded distance badge linking to a Dotlan route.</p>

			<h4>Filters</h4>
			<p><strong>Location</strong> — choose Highsec, Lowsec, or Nullsec (all three are selected by default). The <em>Min jumps from Lowsec</em> slider (Highsec only) lets you find systems deep inside empire space.</p>
			<p><strong>Agent level</strong> — L1–L5. Select multiple to show all matching levels simultaneously.</p>
			<p><strong>Mission type</strong> — Distribution (courier), Security (combat), Mining, or R&amp;D.</p>
			<p><strong>Agent type</strong> — Basic is the standard type. Storyline agents are triggered after every 16 missions. COSMOS, Epic Arc, and Faction Warfare agents are specialised.</p>
			<p><strong>Faction &amp; Corporation</strong> — narrow results to a specific faction or corporation. Selecting a faction filters the corporation list automatically.</p>
			<p><strong>Region</strong> — restrict results to a single region of space.</p>

			<h4>Advanced options</h4>
			<p><strong>Min agents</strong> — minimum number of qualifying agents before a system appears. Switch between <em>per system</em> (total across all stations) and <em>per station</em> (requires that many at a single station).</p>
			<p><strong>Min L4</strong> — minimum number of L4 agents required. Has its own <em>per system</em> / <em>per station</em> scope toggle.</p>
			<p><strong>Storyline in system</strong> — shows only systems that have a Storyline agent in the same system.</p>
			<p><strong><span class="eaf-locator-tag">locator</span> agents only</strong> — shows only systems containing at least one locator agent. The count in brackets shows how many locator agents match the current filters.</p>
			<p><strong>Compact view</strong> — hides the mission type and level breakdown row on each hub card for a denser display. Useful when browsing large result sets.</p>
			<p><strong>Available to my character only</strong> — visible when you are logged in via EVE SSO. Hides agents your character cannot access based on your current standings toward their corporation or faction. The required standing threshold is: L2 ≥ 1.0, L3 ≥ 3.0, L4 ≥ 5.0, L5 ≥ 7.0. Standings are fetched from ESI when the toggle is enabled and cached for 30 minutes; they are silently refreshed in the background when they expire. If re-authentication is needed, a prompt will direct you to click <strong>[Change Character]</strong> in the toolbar.</p>

			<h4>Sort options</h4>
			<p>Results can be sorted by <em>Most agents</em>, <em>Furthest from Lowsec</em>, <em>Nearest to Lowsec</em>, <em>System name A→Z</em>, or <em>Hub score</em>.</p>
			<p><strong>Nearest to…</strong> — enter any system name to sort hubs by jump distance from that system. An autocomplete dropdown appears after two characters. Each hub card shows a clickable badge with the jump count, linking to a Dotlan route.</p>

			<h4>Reading the results</h4>
			<p>Each hub card represents a <strong>system</strong>. The header shows the system name (links to Dotlan), its security status, constellation and region breadcrumb (also Dotlan links), station and agent count, storyline distance, and jumps from Lowsec. The Lowsec distance badge links to a Dotlan route to the nearest entry point.</p>
			<p>The second line breaks down agents by mission type and level — for example <em>Security L3×4 L4×6</em> means four L3 and six L4 Security agents. Factions present are shown on the right. This row can be hidden using the <strong>Compact view</strong> toggle.</p>
			<p>A <strong>gold left border</strong> on a card means the system has multiple agents of the same mission type and level. When level or mission type filters are active, the border reflects those filters — hover over it for a specific description such as <em>This system has multiple L4 Security agents</em>.</p>
			<p><strong>Hub score</strong> is a composite of total agents, L4 count, unique corporations and stations, storyline proximity, and distance from Lowsec. Use it as a guide rather than a strict ranking.</p>
			<p>Click any card to expand it and see individual stations and agents. Use the <strong>Collapse all</strong> button (appears when any card is open) to close all cards at once.</p>
			<p>The <strong>⧉</strong> buttons next to system names, station names, and agent names copy that name to your clipboard.</p>

			<h4>EVE SSO login</h4>
			<p>If the site administrator has configured EVE SSO, a <strong>LOG IN with EVE Online</strong> button appears in the toolbar. Clicking it takes you to the EVE Online login page where you authorise read-only access to your character standings — no in-game actions or wallet access are requested.</p>
			<p>Once authenticated, the toolbar shows <em>[Authenticated as Character Name]</em> and the <strong>Available to my character only</strong> option becomes available in Advanced options. Use <em>[Change Character]</em> to switch to a different character, or <em>[Log out]</em> to clear the session without leaving the page.</p>

			<h4>Sharing &amp; bookmarking</h4>
			<p>The <strong>Share</strong> button copies the current page URL with all active filters encoded in the hash (e.g. <em>#eaf?region=Everyshore&amp;lv=4&amp;mt=Security</em>). Paste it anywhere to share an exact filtered view. Your filters are also saved automatically and restored when you return to the page.</p>
			<p>When the <strong>Available to my character only</strong> filter is active, your standings data is embedded in the shared URL — recipients will see exactly the same filtered results without needing to log in themselves.</p>

		</div>
	</div>
</div>
