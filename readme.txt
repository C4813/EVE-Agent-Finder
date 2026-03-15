=== EVE Agent Finder ===
Contributors: C4813
Tags: eve online, eve, missions, agents, tools
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find optimal EVE Online mission hubs using CCP Static Data Export (SDE) data.

== Description ==

EVE Agent Finder lets you import agent, system, faction, and region data from the CCP Static Data Export and then use the `[eve_agent_finder]` shortcode to filter and browse mission hubs across New Eden.

Features include:

* Filter by security class (Highsec, Lowsec, Nullsec), agent level, mission type, agent type, faction, and corporation
* Min agents stepper controls minimum qualifying agents per system or per station before a hub appears
* Hub view groups agents by system showing station count, agent count, storyline proximity, and jumps from Lowsec
* Security status colours match the in-game Photon UI palette
* Clickable system, constellation, and region names open directly in Dotlan EveMaps
* Lowsec distance badge links to a Dotlan route to the nearest Lowsec entry point
* Storyline distance badge links to the nearest storyline agent system in Dotlan
* Hub score ranking based on agent count, L4 density, corporation diversity, station count, and safety
* Highlighted hub borders indicate systems with multiple agents of the same mission type and level
* Expand any hub card to see individual stations and agents with corporation, faction, division, level, and type
* Copy-to-clipboard buttons on system names and agent names
* Table view for flat agent browsing with sortable columns
* Single SDE zip upload — drop the full CCP SDE zip and all required files are imported automatically in the correct order
* Per-table drop buttons allow individual data tables to be cleared and re-imported independently
* BFS (breadth-first search) calculates jump distances from Lowsec and nearest storyline agent system for every system in New Eden
* Admin panel shows import status, row counts, and timestamps for each data table

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin via the WordPress admin Plugins menu
3. Go to **EVE Agent Finder** in the admin menu
4. Download the latest SDE zip from the link on the admin page
5. Upload the SDE zip — all required YAML files are extracted and imported automatically
6. Click **Run BFS** to calculate jump distances
7. Add `[eve_agent_finder]` to any page or post (recommended on a full-width page without a sidebar)

== Changelog ==

= 1.0.0 =
Initial Public Release

