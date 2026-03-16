=== EVE Agent Finder ===
Contributors: C4813
Tags: eve online, eve, missions, agents, tools
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.2.1
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

= 1.2.1 =
* The eaf_char_token SSO session cookie is now automatically registered with CookieYes (via the cookieyes_cookie_list filter) and Complianz (via the complianz_cookie_list filter), so it appears in cookie declarations as a Strictly Necessary / Functional cookie without requiring a manual entry or scanner run

= 1.2.0 =
* Added EVE SSO authentication — a "LOG IN with EVE Online" button appears to the right of the EVE Agent Finder title in the toolbar when SSO is configured; clicking it authenticates via EVE Online's OAuth2 flow
* When authenticated, the toolbar displays [Authenticated as Character Name] [Change Character] [Log out]
* Added "Available to my character only" toggle in Advanced options — visible only when authenticated; filters out agents whose corporation or faction standing falls below the required threshold for their level (L1: no requirement, L2: 1.0, L3: 3.0, L4: 5.0, L5: 7.0); standings are fetched live from ESI and cached server-side for 30 minutes
* When the standings filter is active and the Share button is used, standings data is embedded directly in the URL hash — recipients see the same filtered results without needing to log in
* Added EVE SSO Settings section to the admin panel: step-by-step instructions for creating an EVE Online Developer Application, required scope (esi-characters.read_standings.v1), one-click copy of the callback URL, and secure credential storage; the Client Secret is never echoed back to the browser after saving
* Reset filters button now appears even when filters produce zero results, and correctly clears the standings filter toggle
* uninstall.php updated to remove all SSO data on deletion: eaf_sso_client_id, eaf_sso_client_secret options, and all eaf_* transients
* Updated "How to use" modal to document EVE SSO login, the "Available to my character only" standings filter, standing thresholds by agent level, and the standings-in-shared-URL behaviour

= 1.1.0 =
* Added locator-only filter toggle in Advanced options, with live agent count hint
* Added Min L4 agents stepper in Advanced options with per-system / per-station scope, aligning with the Min agents stepper
* Added Region filter dropdown below Corporation
* Added system name autocomplete dropdown to the Nearest to… field — suggestions appear after 2 characters and can be clicked to select
* Added Share button — copies the current URL with all active filters encoded in the hash for bookmarking or sharing
* Added Version info button — opens a modal displaying the full changelog
* Added Collapse all button in the results bar — only appears when one or more hub cards are expanded
* Filter state is now saved to localStorage and restored on next visit; URL hash takes priority; Reset filters clears both
* Copy button moved to the left of agent names in hub view and table view
* Copy button added to the left of station names in hub view
* Locator tag now displayed in table view agent column
* Added "Nearest to…" sort option — enter a system name to sort hubs by jump distance from that system; each hub card displays a clickable Dotlan route badge when active
* Renamed sort option "Closest to Lowsec" to "Nearest to Lowsec"
* Gold highlight bar now respects active level and mission type filters — it only appears when multiple agents matching the current filters share the same type and level
* Gold highlight bar tooltip is now filter-aware, naming the specific type and level causing the highlight (e.g. "This system has multiple L4 Distribution agents")
* Renamed "Hub options" to "Advanced options"
* If updating from 1.0.1, re-run BFS in the admin settings

= 1.0.1 =
* Bug fix: selecting Highsec + Lowsec + Nullsec could return fewer results than Highsec + Lowsec alone due to a hardcoded 8000-agent query limit; limit removed as all filtering is client-side

= 1.0.0 =
* Initial Public Release
