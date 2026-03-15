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

			<h4>Filters</h4>
			<p><strong>Location</strong> — choose Highsec, Lowsec, or Nullsec. The <em>Min jumps from Lowsec</em> slider (highsec only) lets you find systems deep inside empire space.</p>
			<p><strong>Agent Level</strong> — L1–L5.</p>
			<p><strong>Mission Type</strong> — Distribution (courier), Security (combat), Mining, or R&amp;D.</p>
			<p><strong>Agent Type</strong> — Basic is the standard type. Storyline agents are triggered after 16 missions. COSMOS, Epic Arc, and Faction Warfare agents are specialised.</p>

			<h4>Hub options</h4>
			<p><strong>Min agents</strong> — minimum number of qualifying agents before a system appears in results. Switch between <em>per system</em> (total across all stations) and <em>per station</em> (requires that many at a single station).</p>
			<p><strong>Storyline in system</strong> — filter out all systems which do not have a Storyline Agent within the system.</p>

			<h4>Reading the results</h4>
			<p>Each card is a <strong>system</strong>. The header shows the system name (click to open in Dotlan), its security status, constellation and region breadcrumb (also clickable), station and agent count, storyline distance, and the jump distance from Lowsec. On Highsec systems, the jumps-to-Lowsec badge links to a Dotlan route to the nearest Lowsec entry point.</p>
			<p>The second line breaks down agents by mission type and level — for example <em>Security L3×4 L4×6</em> means four L3 and six L4 Security agents. Factions present in the system are shown on the right.</p>
			<p>A <strong>coloured left border</strong> on a card means the system has multiple agents of the same mission type and level.</p>
			<p><strong>Score</strong> is a composite of total agents, L4 count, number of unique corporations and stations, storyline proximity, and distance from Lowsec. Use it as a guide rather than a strict ranking.</p>
			<p>Click any card to expand it and see the individual stations and agents, including corporation, faction, and agent type.</p>

		</div>
	</div>
</div>
