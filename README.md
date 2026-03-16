# EVE Agent Finder

A WordPress plugin for finding optimal EVE Online mission hubs using data from the [CCP Static Data Export (SDE)](https://developers.eveonline.com/static-data).

Import the full SDE zip, run the BFS jump-distance calculation, then drop `[eve_agent_finder]` on any page to give your players a live, filterable mission hub browser.

---

## Features

### Frontend
- **Hub view** — systems grouped by hub, showing station count, agent count, security status, constellation/region breadcrumb, storyline proximity, and jumps from Lowsec
- **Security colours** matching the in-game Photon UI palette
- **Clickable Dotlan links** on system names, constellations, regions, Lowsec distance badges, and storyline distance badges
- **Expand any hub card** to see individual stations and agents with level, division, type, corporation, and faction
- **Collapse all** button appears in the results bar when one or more cards are expanded
- **Hub score** — composite ranking based on agent count, L4 density, corporation diversity, station count, and safety
- **Gold highlight border** on hubs with multiple agents of the same mission type and level — respects active filters, with a tooltip naming the specific type and level (e.g. _This system has multiple L4 Security agents_)
- **Copy buttons** (`⧉`) on system names, station names, and agent names for quick in-game pasting
- **Table view** — flat sortable agent list as an alternative to hub view
- **Locator agent tag** shown on agents in both hub and table view

### Filters
- Security class (Highsec / Lowsec / Nullsec)
- Agent level (L1–L5)
- Mission type (Distribution, Security, Mining, R&D)
- Agent type (Basic, Storyline, COSMOS, Epic Arc, R&D, Career, Faction Warfare, etc.)
- Faction and corporation dropdowns
- Region dropdown
- Min jumps from Lowsec slider (Highsec only)

### Advanced options
- **Min agents** per system or per station stepper
- **Min L4 agents** stepper with its own per-system / per-station scope
- **Storyline in system** toggle with live count
- **Locator agents only** toggle with live count
- **Available to my character only** — visible when logged in via EVE SSO; hides agents your character cannot access based on current standings (see [EVE SSO](#eve-sso) below)

### Sort options
- Most agents, Furthest from Lowsec, Nearest to Lowsec, System name A→Z, Hub score
- **Nearest to…** — enter any system name to sort hubs by jump distance; autocomplete suggestions appear after 2 characters; each hub card shows a clickable jump-count badge linking to a Dotlan route

### Sharing & bookmarking
- **Share button** — copies the current URL with all active filters encoded in a clean, readable hash (e.g. `#eaf?region=Everyshore&lv=4&mt=Security`)
- When the **Available to my character only** standings filter is active, standings data is embedded directly in the shared URL — recipients see the same filtered results without needing to log in
- Filter state is saved to **localStorage** and restored automatically on next visit
- URL hash takes priority over localStorage; Reset filters clears both

### Admin
- **Single SDE zip upload** — drop the full CCP SDE zip and all 11 required YAML files are extracted and imported automatically in the correct dependency order
- **Per-table drop buttons** — clear and re-import any individual table independently
- **BFS engine** — multi-source breadth-first search calculates Lowsec distance, nearest storyline agent system, and on-demand single-source BFS for the Nearest to… sort feature, for every system in New Eden (~8,500 systems)
- Import status cards show row counts and timestamps for each data table
- Unnamed agents notice if any agents lack names after import
- **EVE SSO Settings** — configure a developer application Client ID and Secret Key to enable front-end character authentication (see [EVE SSO](#eve-sso) below)

---

## EVE SSO

When an EVE Online Developer Application is configured in the admin settings, a **LOG IN with EVE Online** button appears in the toolbar next to the page title. Authenticating grants read-only access to the character's standings — no in-game actions, wallet, or assets are accessible.

Once authenticated, the toolbar displays:

> [Authenticated as Character Name] [Change Character] [Log out]

The **Available to my character only** toggle then becomes available in Advanced options. Enabling it hides any agent whose corporation or faction standing falls below the threshold required for that agent's level:

| Agent Level | Required Standing |
|---|---|
| L1 | No requirement |
| L2 | 1.0 |
| L3 | 3.0 |
| L4 | 5.0 |
| L5 | 7.0 |

Standings are fetched live from ESI and cached server-side for 30 minutes. The session cookie persists for 24 hours; logging out clears it immediately without a page refresh.

### Setting up EVE SSO

1. Go to the **EVE Agent Finder** admin panel and open the **EVE SSO Settings** section
2. Follow the step-by-step instructions to create an application at [developers.eveonline.com/applications](https://developers.eveonline.com/applications)
3. Set **Connection Type** to **Authentication & API Access**
4. Add the scope `esi-characters.read_standings.v1`
5. Copy the **Callback URL** shown in the settings panel and paste it into your application
6. Save the application, then paste the **Client ID** and **Secret Key** into the settings form

The Secret Key is stored securely and never displayed again after saving.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| PHP extension | `ZipArchive` (for SDE zip upload) |
| MySQL | 5.7+ / MariaDB 10.3+ |

---

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **EVE Agent Finder** in the admin menu
5. Download the latest SDE yaml zip from [developers.eveonline.com/static-data](https://developers.eveonline.com/static-data)
6. Upload the SDE zip — all required files are imported automatically
7. Click **Run BFS** to calculate jump distances
8. Add `[eve_agent_finder]` to any page or post

> Recommended: use a full-width page template without a sidebar for best layout.

---

## Shortcode
```
[eve_agent_finder]
```

Optional attributes:

| Attribute | Default | Description |
|---|---|---|
| `sec_class` | `highsec` | Comma-separated default security filter: `highsec`, `lowsec`, `nullsec` |
| `min_jumps` | `0` | Default minimum jumps from Lowsec |

Example:
```
[eve_agent_finder sec_class="highsec" min_jumps="5"]
```

---

## SDE Files Used

The plugin extracts and imports these files from the SDE zip automatically:

| File | Data |
|---|---|
| `factions.yaml` | NPC factions |
| `npcCorporations.yaml` | NPC corporations |
| `agentTypes.yaml` | Agent type definitions |
| `npcCorporationDivisions.yaml` | Division names |
| `mapConstellations.yaml` | Constellation names |
| `mapRegions.yaml` | Region names |
| `mapSolarSystems.yaml` | Solar systems + security status |
| `mapStargates.yaml` | Stargate connections (for BFS) |
| `stationOperations.yaml` | Station operation types |
| `npcStations.yaml` | NPC station locations |
| `npcCharacters.yaml` | Agents with names and attributes |

---

## Database Tables

All tables are prefixed with `{wp_prefix}eaf_`:

`factions` · `corporations` · `agent_types` · `divisions` · `constellations` · `regions` · `systems` · `system_jumps` · `station_operations` · `stations` · `agents` · `import_log`

All tables and plugin options are removed cleanly on plugin deletion, including all SSO credentials and cached transients.

---

## Data Source

All game data is sourced from the [CCP Static Data Export](https://developers.eveonline.com/static-data), provided by CCP Games under the [EVE Online Developer License Agreement](https://developers.eveonline.com/license-agreement).

EVE Online and all related marks are the property of CCP hf.

---

## License

GPL-2.0+ — see [LICENSE](LICENSE)
