/**
 * EVE Agent Finder – public/js/eaf-public.js
 *
 * Architecture
 * ────────────
 * 1. On load → fetch filter options (populates dropdowns)
 * 2. On load → fetch all agents matching sec-class defaults (AJAX, server pre-filter)
 * 3. All further filtering is done client-side (instant, no round-trips)
 *
 * Two display views
 * ─────────────────
 * "Hubs" (station view)
 *   Groups agents by station. Each station card shows:
 *   - Station name, system, security, jumps from low-sec, faction
 *   - Every qualifying agent at that station in a compact table
 *   - Highlight when multiple agents of same level/division are present
 *   - Hub score badge
 *
 * "Table" (flat agent list)
 *   Every agent as a row, sortable columns, direct output.
 */

/* global EAF, jQuery */
jQuery(function ($) {
    'use strict';

    const { ajax_url, nonce } = EAF;

    // ── State ─────────────────────────────────────────────────────────────────
    let allAgents      = [];   // raw payload from server
    let filterOptions  = {};   // factions, corps, divisions, agent_types, levels
    let currentView    = 'station';
    let sortBy         = 'agents_desc';
    let lastSearch     = '';   // tracks last search value to avoid spurious re-renders

    // Cache all corp data for faction→corp filtering
    let allCorps = [];

    // ── Selectors ─────────────────────────────────────────────────────────────
    const $app         = $('.eaf-app');
    const $loader      = $('#eaf-loader');
    const $results     = $('#eaf-results-area');
    const $filterPanel = $('#eaf-filter-panel');

    // ── Roman numeral parser (for station name sorting) ──────────────────────
    const ROMAN = { I:1, V:5, X:10, L:50, C:100, D:500, M:1000 };
    function romanToInt(s) {
        if (!s) return 0;
        let total = 0, prev = 0;
        for (let i = s.length - 1; i >= 0; i--) {
            const cur = ROMAN[s[i]] || 0;
            total += cur < prev ? -cur : cur;
            prev = cur;
        }
        return total;
    }
    // Returns [planetNum, moonNum] for sorting
    // e.g. "Airkio IX - Moon 7 - Lai Dai…" → [9, 7]
    // e.g. "Nonni III - Poksu…"             → [3, 0]
    function stationSortKey(name) {
        const planet = name.match(/^[^\s]+\s+([IVXLCDM]+)(?:\s|$|-)/);
        const moon   = name.match(/-\s*Moon\s+(\d+)/i);
        return [
            planet ? romanToInt(planet[1]) : 0,
            moon   ? parseInt(moon[1], 10) : 0,
        ];
    }

    // ── Division → mission type mapping ──────────────────────────────────────
    // Derived from division_name (string) so it works regardless of which IDs
    // CCP assigns in any SDE version.  Four player-facing mission types:
    //   Distribution  Security  Mining  R&D
    const DIVISION_SECURITY = new Set([
        'security', 'intelligence', 'internal security', 'surveillance', 'command',
        'enforcer', 'soldier of fortune',
    ]);
    const DIVISION_MINING = new Set([
        'mining', 'astrosurveying', 'industrialist - producer', 'industrialist - entrepreneur',
        'industrialist',
    ]);
    const DIVISION_RD = new Set([
        'r&d', 'research', 'engineering', 'manufacturing', 'production',
        'explorer',
    ]);
    // Everything else that has agents = Distribution (couriers)

    function getMissionType(divisionId, agentTypeName, divisionName) {
        if (divisionName) {
            const d = divisionName.toLowerCase();
            if (DIVISION_SECURITY.has(d)) return 'Security';
            if (DIVISION_MINING.has(d))   return 'Mining';
            if (DIVISION_RD.has(d))       return 'R&D';
            // Catch partial matches for future division name variants
            if (d.includes('security') || d.includes('intelligence') || d.includes('surveillance')) return 'Security';
            if (d.includes('mining') || d.includes('astro'))                                        return 'Mining';
            if (d.includes('r&d') || d.includes('research') || d.includes('engineer'))             return 'R&D';
        }
        // Last resort: infer from agent type name
        if (agentTypeName) {
            const t = agentTypeName.toLowerCase();
            if (t.includes('mining'))       return 'Mining';
            if (t.includes('research'))     return 'R&D';
            if (t.includes('security'))     return 'Security';
            if (t.includes('distribution')) return 'Distribution';
        }
        return 'Distribution'; // default: courier
    }

    // ── Agent type display name mapping ──────────────────────────────────────
    const AGENT_TYPE_LABELS = {
        'basicagent':                    'Basic',
        'cosmosagent':                   'COSMOS',
        'epicarcagent':                  'Epic Arc',
        'eventmissionagent':             'Event',
        'factionwarfareagent':           'Faction Warfare',
        'factionalwarfareagent':         'Faction Warfare',
        'researchagent':                 'R&D',
        'storylinemissionagent':         'Storyline',
        'genericstorylinemissionagent':  'Storyline',
        'storylineagent':                'Storyline',
        'tutorialagent':                 'Tutorial',
        'auraagent':                     'Aura',
        'careeragent':                   'Career',
        'concordagent':                  'CONCORD',
        'heraldryagent':                 'Paragon',
    };
    function fmtAgentType(name) {
        if (!name) return name;
        return AGENT_TYPE_LABELS[name.toLowerCase()] || name;
    }

    // Set the slider max to the furthest highsec system in the loaded data
    function updateSliderMax(agents) {
        let maxDist = 1;
        agents.forEach(function(a) {
            if (a.sec_class === 'highsec' && a.lowsec_distance > maxDist) {
                maxDist = a.lowsec_distance;
            }
        });
        $('#eaf-min-jumps-range').attr('max', maxDist);
    }

    init();

    async function init() {
        showLoader(true);
        try {
            const [opts, agentData] = await Promise.all([
                ajaxPost({ action: 'eaf_filters' }),
                ajaxPost({ action: 'eaf_agents', sec_class: getDefaultSecClass(), min_jumps: getDefaultMinJumps() }),
            ]);

            filterOptions = opts.data;
            allAgents     = agentData.data.agents;
            allCorps      = filterOptions.corporations || [];

            // Warn about division names that don't resolve to a known mission type
            const unknownDivs = {};
            allAgents.forEach(function(a) {
                if (!a.division_name) return;
                const d = a.division_name.toLowerCase();
                const known = DIVISION_SECURITY.has(d) || DIVISION_MINING.has(d) || DIVISION_RD.has(d)
                    || d.includes('security') || d.includes('intelligence') || d.includes('surveillance')
                    || d.includes('mining') || d.includes('astro')
                    || d.includes('r&d') || d.includes('research') || d.includes('engineer')
                    || d.includes('distribution') || d.includes('command') || d.includes('finance')
                    || d.includes('accounting') || d.includes('legal') || d.includes('marketing')
                    || d.includes('personnel') || d.includes('storage') || d.includes('docking');
                if (!known) unknownDivs[a.division_name] = true;
            });
            const unknownList = Object.keys(unknownDivs);
            if (unknownList.length) {
                console.warn('[EAF] Unknown division names (falling back to Distribution):', unknownList);
            }

            populateFilterOptions();
            applyDefaultFilters();
            updateSliderMax(allAgents);
            bindEvents();
            render();
        } catch (e) {
            $results.html('<div class="eaf-error">Failed to load agent data. ' + esc(e.message || '') + '</div>');
        } finally {
            showLoader(false);
        }
    }

    function getDefaultSecClass() {
        try {
            const cfg = JSON.parse($app.attr('data-config') || '{}');
            return cfg.default_sec_class || ['highsec'];
        } catch(_) { return ['highsec']; }
    }

    function getDefaultMinJumps() {
        try {
            const cfg = JSON.parse($app.attr('data-config') || '{}');
            return cfg.default_min_jumps || 0;
        } catch(_) { return 0; }
    }

    // ── Populate dropdowns ────────────────────────────────────────────────────

    function populateFilterOptions() {
        const f = filterOptions;

        // Factions
        const $fac = $('#eaf-faction');
        (f.factions || []).forEach(function(x) {
            if (!x.faction_id) return;
            $fac.append('<option value="' + parseInt(x.faction_id, 10) + '">' + esc(x.faction_name) + '</option>');
        });

        // Corporations (full list, will be filtered on faction change)
        populateCorpSelect('');

        // Divisions (multi-select)
        const $div = $('#eaf-division');
        $div.empty().append('<option value="">— All —</option>');
        (f.divisions || []).forEach(function(x) {
            $div.append('<option value="' + parseInt(x.division_id, 10) + '">' + esc(x.division_name) + '</option>');
        });

        // Agent types — deduplicate by display label (e.g. both storyline types → one 'Storyline' entry)
        const $at = $('#eaf-agent-type');
        $at.empty().append('<option value="">— All —</option>');
        const atSeen = {};
        (f.agent_types || []).forEach(function(x) {
            const label = fmtAgentType(x.agent_type_name);
            if (atSeen[label] === undefined) {
                atSeen[label] = [parseInt(x.agent_type_id, 10)];
            } else {
                atSeen[label].push(parseInt(x.agent_type_id, 10));
            }
        });
        Object.keys(atSeen).sort().forEach(function(label) {
            $at.append('<option value="' + esc(atSeen[label].join(',')) + '">' + esc(label) + '</option>');
        });

        // Security class counts as label hints
        const sc = f.sec_counts || {};
        $('#eaf-sec-class input').each(function() {
            const v    = $(this).val();
            const cnt  = sc[v] || 0;
            const $lbl = $(this).closest('label');
            $lbl.append(' <span class="eaf-count-hint">(' + cnt.toLocaleString() + ')</span>');
        });
    }

    function populateCorpSelect(factionId) {
        const $corp = $('#eaf-corporation');
        const prev  = $corp.val();
        $corp.empty().append('<option value="">— All corps —</option>');
        const filtered = factionId
            ? allCorps.filter(function(c) { return String(c.faction_id) === String(factionId); })
            : allCorps;
        filtered.forEach(function(c) {
            $corp.append('<option value="' + parseInt(c.corporation_id, 10) + '">' + esc(c.corporation_name) + '</option>');
        });
        if (prev) $corp.val(prev);
    }

    // ── Apply defaults from shortcode attr ───────────────────────────────────

    function applyDefaultFilters() {
        const cfg = JSON.parse($app.attr('data-config') || '{}');

        // Sec class checkboxes
        if (cfg.default_sec_class) {
            $('#eaf-sec-class input[type=checkbox]').prop('checked', false);
            cfg.default_sec_class.forEach(function(sc) {
                $('#eaf-sec-class input[value="' + sc + '"]').prop('checked', true);
            });
        }

        // Min jumps slider
        if (cfg.default_min_jumps) {
            $('#eaf-min-jumps-range').val(cfg.default_min_jumps);
            updateRangeLabel('eaf-min-jumps-range', 'eaf-min-jumps-val', false);
        }
    }

    // ── Events ────────────────────────────────────────────────────────────────

    function bindEvents() {
        // View toggle
        $('.eaf-view-btn').on('click', function() {
            currentView = $(this).data('view');
            $('.eaf-view-btn').removeClass('active');
            $(this).addClass('active');
            // Hub-specific options only make sense in hub view
            $('#eaf-hub-options').toggle(currentView === 'station');
            render();
        });

        // Info modal
        $('#eaf-info-btn').on('click', function() {
            $('#eaf-info-modal').fadeIn(180);
        });
        $('#eaf-info-close').on('click', function() {
            $('#eaf-info-modal').fadeOut(150);
        });
        $('#eaf-info-modal').on('click', function(e) {
            if ($(e.target).is('#eaf-info-modal')) $(this).fadeOut(150);
        });

        // Realtime filters — non-search controls (instant render)
        $app.on('change', '#eaf-level-filter input, '
            + '#eaf-mission-type input, #eaf-division, #eaf-agent-type, #eaf-faction, #eaf-corporation, '
            + '#eaf-storyline-only', debounce(render, 180));

        // Search box — only re-render when the value actually changes
        // Using input + lastSearch guard prevents re-render on focus/click events
        $app.on('input search', '#eaf-search', debounce(function() {
            const val = $(this).val();
            if (val === lastSearch) return;
            lastSearch = val;
            render();
        }, 220));

        // Min-agents stepper
        $app.on('click', '#eaf-min-agents-dec', function() {
            const $inp = $('#eaf-min-agents');
            const v = Math.max(1, parseInt($inp.val(), 10) - 1);
            $inp.val(v);
            $('#eaf-min-agents-display').text(v);
            render();
        });
        $app.on('click', '#eaf-min-agents-inc', function() {
            const $inp = $('#eaf-min-agents');
            const v = Math.min(20, parseInt($inp.val(), 10) + 1);
            $inp.val(v);
            $('#eaf-min-agents-display').text(v);
            render();
        });

        // Min-agents scope toggle (per system / per station pills)
        $app.on('click', '#eaf-min-agents-scope .eaf-scope-pill', function() {
            const val = $(this).data('val');
            $('#eaf-min-agents-scope').data('scope', val);
            $('#eaf-min-agents-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
            $(this).addClass('eaf-scope-pill-active');
            render();
        });

        // Security class change → reload agents from server (different sec classes = different data)
        $app.on('change', '#eaf-sec-class input', debounce(function() {
            const highsecOn = $('#eaf-sec-class input[value=highsec]').is(':checked');
            $('.eaf-highsec-only').toggleClass('eaf-filter-disabled', !highsecOn);
            reloadAgents();
        }, 300));

        // Range sliders
        $('#eaf-min-jumps-range').on('input', function() {
            updateRangeLabel('eaf-min-jumps-range', 'eaf-min-jumps-val', false);
            render();
        });

        // Sort
        $('#eaf-sort-by').on('change', function() {
            sortBy = $(this).val();
            render();
        });

        // Faction → filter corp list
        $('#eaf-faction').on('change', function() {
            populateCorpSelect($(this).val());
            render();
        });

        // Expand / collapse hub cards
        $results.on('click', '.eaf-hub-header', function(e) {
            // Ignore clicks on interactive children (buttons, links, inputs)
            if ($(e.target).closest('button, a, input, select').length) return;
            const $card = $(this).closest('.eaf-hub-card');
            $card.toggleClass('expanded');
            $card.find('.eaf-hub-body').stop(true).slideToggle(200);
        });

        // Table sort column headers
        $results.on('click', '.eaf-th-sort', function() {
            const col = $(this).data('col');
            const asc = !$(this).hasClass('sort-asc');
            renderTableSorted(col, asc);
        });

        // Copy buttons
        $app.on('click', '.eaf-copy-btn', function(e) {
            e.stopPropagation();
            const text = $(this).data('copy');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta);
                ta.select(); document.execCommand('copy');
                document.body.removeChild(ta);
            }
            const $btn = $(this);
            $btn.text('✓').addClass('eaf-copy-btn-done');
            setTimeout(function() { $btn.text('⧉').removeClass('eaf-copy-btn-done'); }, 1200);
        });

        // Reset
        $results.on('click', '#eaf-reset-filters', resetFilters);
    }

    function updateRangeLabel(rangeId, valId, isMax) {
        const v = parseInt($('#' + rangeId).val(), 10);
        $('#' + valId).text(isMax && v === 0 ? 'Any' : v);
    }

    // ── Filter engine (client-side) ───────────────────────────────────────────

    function getFilters() {
        const q          = $('#eaf-search').val().trim().toLowerCase();
        const secClasses = $('#eaf-sec-class input:checked').map(function() { return this.value; }).get();
        const levels     = $('#eaf-level-filter input:checked').map(function() { return parseInt(this.value, 10); }).get();
        const missionTypes = $('#eaf-mission-type input:checked').map(function() { return this.value; }).get();
        const divIds     = $('#eaf-division').val() || [];
        const atIds      = ($('#eaf-agent-type').val() || '').split(',').map(Number).filter(Boolean);
        const factionId  = parseInt($('#eaf-faction').val() || '0', 10);
        const corpId     = parseInt($('#eaf-corporation').val() || '0', 10);
        const minJumps   = parseInt($('#eaf-min-jumps-range').val(), 10);

        return { q, secClasses, levels, missionTypes,
                 divIds: divIds.map(Number).filter(Boolean),
                 atIds, factionId, corpId, minJumps };
    }

    function applyFilters(agents) {
        const f = getFilters();
        return agents.filter(function(a) {
            if (f.secClasses.length && !f.secClasses.includes(a.sec_class)) return false;

            // Jump distance only meaningful for highsec — skip filter for lowsec/nullsec
            if (a.sec_class === 'highsec' && f.minJumps > 0) {
                if (a.lowsec_distance >= 0 && a.lowsec_distance < f.minJumps) return false;
            }

            if (f.levels.length && !f.levels.includes(a.level)) return false;

            // Mission type filter
            if (f.missionTypes.length) {
                const mt = getMissionType(a.division_id, a.agent_type_name, a.division_name);
                if (!mt || !f.missionTypes.includes(mt)) return false;
            }

            if (f.divIds.length && !f.divIds.includes(a.division_id)) return false;
            if (f.atIds.length && !f.atIds.includes(a.agent_type_id)) return false;
            if (f.factionId && a.faction_id !== f.factionId) return false;
            if (f.corpId && a.corporation_id !== f.corpId) return false;

            if (f.q) {
                const haystack = [
                    a.agent_name, a.station_name, a.system_name,
                    a.corporation_name, a.faction_name, a.division_name, a.agent_type_name
                ].join(' ').toLowerCase();
                if (!haystack.includes(f.q)) return false;
            }

            return true;
        });
    }

    // ── Render dispatcher ─────────────────────────────────────────────────────

    // ── Reload agents from server when sec-class selection changes ────────────

    function reloadAgents() {
        const secClasses = $('#eaf-sec-class input:checked').map(function() { return this.value; }).get();
        if (!secClasses.length) {
            allAgents = [];
            render();
            return;
        }
        showLoader(true);
        ajaxPost({ action: 'eaf_agents', sec_class: secClasses, min_jumps: 0 })
            .then(function(resp) {
                allAgents = resp.data.agents;
                updateSliderMax(allAgents);
                render();
            })
            .catch(function(e) {
                $results.html('<div class="eaf-error">Failed to reload agent data. ' + esc(e.message || '') + '</div>');
            })
            .finally(function() {
                showLoader(false);
            });
    }

    function render() {
        const filtered = applyFilters(allAgents);

        if (currentView === 'station') {
            renderHubView(filtered);
        } else {
            renderTableView(filtered);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // HUB VIEW  –  grouped by SYSTEM (stations shown inside each system card)
    // ────────────────────────────────────────────────────────────────────────

    function renderHubView(filtered) {
        const minAgents    = parseInt($('#eaf-min-agents').val(), 10) || 1;
        const minScope     = $('#eaf-min-agents-scope').data('scope') || 'system';
        const storylineOnly= $('#eaf-storyline-only').is(':checked');

        // ── Group filtered agents by system → station ─────────────────────
        const systemMap = {};
        filtered.forEach(function(a) {
            const sid = a.system_id;
            if (!systemMap[sid]) {
                systemMap[sid] = {
                    system_id:          a.system_id,
                    system_name:        a.system_name,
                    security:           a.security,
                    sec_class:          a.sec_class,
                    lowsec_distance:    a.lowsec_distance,
                    storyline_distance: a.storyline_distance,
                    storyline_lowsec:   a.storyline_lowsec, // lowsec dist of the nearest storyline system
                    stations:           {},
                    agents:             [],
                    factions:           new Set(),
                };
            }
            const sys = systemMap[sid];
            sys.agents.push(a);
            sys.factions.add(a.faction_id);

            const stid = a.station_id;
            if (!sys.stations[stid]) {
                sys.stations[stid] = { station_id: a.station_id, station_name: a.station_name, agents: [] };
            }
            sys.stations[stid].agents.push(a);
        });

        let systems = Object.values(systemMap);

        // ── Min agents filter (per system or per station) ─────────────────
        systems = systems.filter(function(sys) {
            if (minScope === 'station') {
                // Keep system only if at least one station has >= minAgents
                return Object.values(sys.stations).some(function(sta) {
                    return sta.agents.length >= minAgents;
                });
            }
            return sys.agents.length >= minAgents;
        });

        // ── Storyline-only filter ─────────────────────────────────────────
        if (storylineOnly) {
            systems = systems.filter(function(sys) { return sys.storyline_distance === 0; });
        }

        // ── Score and sort ────────────────────────────────────────────────
        systems.forEach(function(sys) { sys.hub_score = computeSystemHubScore(sys); });
        systems = sortSystems(systems);

        // Update storyline-in-system label with count
        const slInSysCount = systems.filter(function(s) { return s.storyline_distance === 0; }).length;
        const $slLabel = $('#eaf-storyline-only').closest('label');
        $slLabel.find('.eaf-sl-count').remove();
        $slLabel.append('<span class="eaf-sl-count eaf-count-hint"> (' + slInSysCount + ')</span>');

        if (systems.length === 0) {
            $results.html('<div class="eaf-empty">No systems match your filters. Try relaxing the constraints — e.g. reduce "min agents in system" to 1.</div>');
            return;
        }

        const agentCount = filtered.length;
        let html = '<div class="eaf-hub-count">'
            + '<span>Showing ' + agentCount.toLocaleString() + ' agent' + (agentCount !== 1 ? 's' : '') + ' in ' + systems.length.toLocaleString() + ' system' + (systems.length !== 1 ? 's' : '') + '</span>'
            + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button>'
            + '</div>';
        html += '<div class="eaf-hub-list">';
        systems.slice(0, 200).forEach(function(sys, i) { html += renderSystemCard(sys, i); });
        if (systems.length > 200) {
            html += '<div class="eaf-truncate-notice">⚠ Showing first 200 of ' + systems.length.toLocaleString() + ' systems. Add more filters to narrow results.</div>';
        }
        html += '</div>';
        $results.html(html);
    }

    function computeSystemHubScore(sys) {
        const agents      = sys.agents;
        const lvl4        = agents.filter(function(a) { return a.level === 4; }).length;
        const uniqueCorps = new Set(agents.map(function(a) { return a.corporation_id; })).size;
        const uniqueSta   = Object.keys(sys.stations).length;
        const dist        = sys.lowsec_distance >= 0 ? sys.lowsec_distance : 0;
        // Bonus for having a storyline agent: full bonus in-system, partial if nearby
        const slDist      = sys.storyline_distance;
        const slBonus     = slDist === 0 ? 4 : slDist > 0 && slDist <= 3 ? 2 : slDist > 0 && slDist <= 7 ? 1 : 0;

        return (agents.length * 2)
             + (lvl4 * 3)
             + (uniqueCorps * 1.5)
             + (uniqueSta * 1)
             + slBonus
             + (dist * 0.3);
    }

    function sortSystems(systems) {
        return systems.sort(function(a, b) {
            switch (sortBy) {
                case 'agents_desc': return b.agents.length - a.agents.length;
                case 'jumps_desc':  return (b.lowsec_distance === -1 ? -999 : b.lowsec_distance) - (a.lowsec_distance === -1 ? -999 : a.lowsec_distance);
                case 'jumps_asc':   return (a.lowsec_distance === -1 ? 9999 : a.lowsec_distance) - (b.lowsec_distance === -1 ? 9999 : b.lowsec_distance);
                case 'system_asc':  return a.system_name.localeCompare(b.system_name);
                case 'score_desc':  return b.hub_score - a.hub_score;
                default:            return b.agents.length - a.agents.length;
            }
        });
    }

    function renderSystemCard(sys, idx) {
        const dist        = sys.lowsec_distance;
        const distDisplay = dist >= 0 ? dist + ' jump' + (dist !== 1 ? 's' : '') : 'N/A';
        const distClass   = dist < 0 ? 'dist-na' : dist >= 10 ? 'dist-very-safe' : dist >= 7 ? 'dist-safe' : dist >= 4 ? 'dist-medium' : 'dist-close';
        const secColor    = secCssClass(sys.security, sys.sec_class);
        const facNames    = [...sys.factions].map(function(fid) {
            const a = sys.agents.find(function(a) { return a.faction_id === fid; });
            return a ? a.faction_name : '';
        }).filter(Boolean).join(', ');

        // Aggregate agents by mission-type → level
        // typeLevels: { 'Distribution': { 1: N, 2: N, … }, 'Security': {…}, … }
        const TYPE_ORDER = ['Distribution', 'Security', 'Mining', 'R&D'];
        const typeLevels = {};
        sys.agents.forEach(function(a) {
            const mt = getMissionType(a.division_id, a.agent_type_name, a.division_name) || 'Other';
            if (!typeLevels[mt]) typeLevels[mt] = {};
            typeLevels[mt][a.level] = (typeLevels[mt][a.level] || 0) + 1;
        });

        // Build one cluster per mission type: "Dist. L1×5 L2×3"
        const typeClusters = Object.entries(typeLevels)
            .sort(function([a],[b]) {
                const ai = TYPE_ORDER.indexOf(a), bi = TYPE_ORDER.indexOf(b);
                if (ai === -1 && bi === -1) return a.localeCompare(b);
                if (ai === -1) return 1; if (bi === -1) return -1;
                return ai - bi;
            })
            .map(function([mt, levels]) {
                const lvlBadges = Object.entries(levels)
                    .sort(function([a],[b]) { return a - b; })
                    .map(function([l,c]) {
                        return '<span class="eaf-lbadge eaf-level-' + l + '">L' + l + '×' + c + '</span>';
                    }).join('');
                return '<span class="eaf-type-cluster">'
                    + '<span class="eaf-dbadge eaf-dbadge-label">' + esc(mt) + '</span>'
                    + lvlBadges
                    + '</span>';
            }).join('');

        // Highlight logic: same mission type AND same level
        const typeLevelCounts = {};
        sys.agents.forEach(function(a) {
            const mt = getMissionType(a.division_id, a.agent_type_name, a.division_name) || 'Other';
            const key = mt + '|' + a.level;
            typeLevelCounts[key] = (typeLevelCounts[key] || 0) + 1;
        });

        const stationCount   = Object.keys(sys.stations).length;
        const multiSameDiv   = Object.values(typeLevelCounts).some(function(c) { return c > 1; });
        const highlightClass = multiSameDiv ? 'eaf-hub-highlight' : '';

        const slDist   = sys.storyline_distance;
        const slSystem = sys.agents[0] && sys.agents[0].storyline_system;
        let slHtml = '';
        if (slDist === 0) {
            slHtml = '<span class="eaf-storyline-inline">Storyline in system</span>';
        } else if (slDist > 0) {
            let slLabel = 'Storyline ' + slDist + ' jump' + (slDist !== 1 ? 's' : '') + ' away';
            if (sys.sec_class === 'highsec' && sys.storyline_lowsec >= 0) {
                slLabel += ' (' + sys.storyline_lowsec + ' jump' + (sys.storyline_lowsec !== 1 ? 's' : '') + ' from Lowsec)';
            }
            const slSpan = '<span class="eaf-storyline-inline eaf-storyline-near">' + slLabel + '</span>';
            if (slSystem) {
                const slRegion = (sys.agents[0].storyline_region || '').replace(/ /g, '_');
                const slUrl = slRegion
                    ? 'https://evemaps.dotlan.net/map/' + slRegion + '/' + encodeURIComponent(slSystem.replace(/ /g, '_'))
                    : 'https://evemaps.dotlan.net/search/' + encodeURIComponent(slSystem);
                slHtml = '<a class="eaf-dotlan-link eaf-storyline-link" href="' + slUrl + '" target="_blank" rel="noopener">' + slSpan + '</a>';
            } else {
                slHtml = slSpan;
            }
        }

        let h = '<div class="eaf-hub-card ' + highlightClass + '">';
        if (multiSameDiv) {
            h += '<span class="eaf-highlight-bar" title="This system has multiple agents of the same mission type and level"></span>';
        }
        h += '<div class="eaf-hub-header">';

        // ── Line 1: identity + key stats ──────────────────────────────────
        h += '<div class="eaf-hub-row1">';
        h += '  <span class="eaf-hub-rank">#' + (idx + 1) + '</span>';
        const dotlanRegion = (sys.agents[0] && sys.agents[0].region_name || '').replace(/ /g, '_');
        const dotlanConst  = (sys.agents[0] && sys.agents[0].constellation_name || '').replace(/ /g, '_');
        const dotlanSys    = sys.system_name.replace(/ /g, '_');
        const dotlanUrl    = dotlanRegion
            ? 'https://evemaps.dotlan.net/map/' + dotlanRegion + '/' + encodeURIComponent(dotlanSys)
            : 'https://evemaps.dotlan.net/search/' + encodeURIComponent(sys.system_name);
        const sysLabel = '<span class="eaf-hub-sysname ' + secColor + '">' + esc(sys.system_name) + '</span>';
        h += '  <button class="eaf-copy-btn" data-copy="' + esc(sys.system_name) + '" title="Copy system name">⧉</button>';
        h += '  <a class="eaf-dotlan-link eaf-sysname-link" href="' + dotlanUrl + '" target="_blank" rel="noopener">' + sysLabel + '</a>';
        h += '  <span class="eaf-sec ' + secColor + '">' + sys.security.toFixed(1) + '</span>';

        // Constellation and region breadcrumb
        if (dotlanConst || dotlanRegion) {
            h += '  <span class="eaf-hub-breadcrumb">';
            if (dotlanConst && dotlanRegion) {
                const constUrl = 'https://evemaps.dotlan.net/map/' + dotlanRegion + '/' + encodeURIComponent(dotlanConst);
                h += '&lsaquo; <a class="eaf-dotlan-link eaf-breadcrumb-link" href="' + constUrl + '" target="_blank" rel="noopener">' + esc(sys.agents[0].constellation_name) + '</a>';
            }
            if (dotlanRegion) {
                const regUrl = 'https://evemaps.dotlan.net/map/' + dotlanRegion;
                h += ' &lsaquo; <a class="eaf-dotlan-link eaf-breadcrumb-link" href="' + regUrl + '" target="_blank" rel="noopener">' + esc(sys.agents[0].region_name) + '</a>';
            }
            h += '  </span>';
        }
        h += '  <span class="eaf-hub-subinfo">'
            + ' · ' + stationCount + ' station' + (stationCount !== 1 ? 's' : '')
            + ' · ' + sys.agents.length + ' agent' + (sys.agents.length !== 1 ? 's' : '')
            + '</span>';
        if (slHtml) h += slHtml;
        if (dist >= 0 && sys.security >= 0.5) {
            const gateway = sys.agents[0] && sys.agents[0].lowsec_gateway;
            const distBadge = '<span class="eaf-badge eaf-badge-dist ' + distClass + '">' + distDisplay + ' from Lowsec</span>';
            if (gateway) {
                h += '<a class="eaf-dotlan-link" href="https://evemaps.dotlan.net/route/' + encodeURIComponent(sys.system_name) + ':' + encodeURIComponent(gateway) + '" target="_blank" rel="noopener">' + distBadge + '</a>';
            } else {
                h += distBadge;
            }
        }
        h += '  <span class="eaf-score" title="Composite score: total agents ×2, L4 agents ×3, unique corporations ×1.5, stations ×1, storyline proximity bonus, distance from Lowsec ×0.3">Score ' + sys.hub_score.toFixed(1) + '</span>';
        h += '  <div class="eaf-toggle-arrow">▼</div>';
        h += '</div>';

        // ── Line 2: composition + factions ────────────────────────────────
        h += '<div class="eaf-hub-row2">';
        h += typeClusters;
        h += '<span class="eaf-hub-factions">' + esc(facNames) + '</span>';
        h += '</div>';

        h += '</div>'; // eaf-hub-header

        // Body — one sub-section per station
        h += '<div class="eaf-hub-body" style="display:none">';

        const sortedStations = Object.values(sys.stations).slice().sort(function(a, b) {
            const ka = stationSortKey(a.station_name);
            const kb = stationSortKey(b.station_name);
            if (ka[0] !== kb[0]) return ka[0] - kb[0];  // planet number
            if (ka[1] !== kb[1]) return ka[1] - kb[1];  // moon number
            return (a.station_name || '').localeCompare(b.station_name || '');
        });
        sortedStations.forEach(function(sta) {
            h += '<div class="eaf-hub-station-section">';
            h += '  <div class="eaf-hub-station-label">' + esc(sta.station_name || 'Station #' + sta.station_id) + '</div>';
            h += '  <table class="eaf-table"><thead><tr>';
            h += '    <th>Agent</th><th>Level</th><th>Division</th><th>Type</th><th>Corporation</th><th>Faction</th>';
            h += '  </tr></thead><tbody>';

            sta.agents.forEach(function(a) {
                h += '<tr class="eaf-agent-row">';
                h += '<td><strong>' + esc(a.agent_name) + '</strong>' + (a.is_locator ? ' <span class="eaf-locator-tag">locator</span>' : '') + ' <button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(a.agent_name) + '" title="Copy agent name">⧉</button></td>';
                h += '<td><span class="eaf-lbadge eaf-level-' + a.level + '">L' + a.level + '</span></td>';
                h += '<td>' + esc(getMissionType(a.division_id, a.agent_type_name, a.division_name) || a.division_name) + '</td>';
                h += '<td><span class="eaf-type-pill">' + esc(fmtAgentType(a.agent_type_name)) + '</span></td>';
                h += '<td>' + esc(a.corporation_name) + '</td>';
                h += '<td>' + esc(a.faction_name) + '</td>';
                h += '</tr>';
            });

            h += '  </tbody></table>';
            h += '</div>';
        });

        h += '</div>'; // hub-body
        h += '</div>'; // hub-card
        return h;
    }

    // ────────────────────────────────────────────────────────────────────────
    // TABLE VIEW
    // ────────────────────────────────────────────────────────────────────────

    let tableData     = [];
    let tableSortCol  = 'system_name';
    let tableSortAsc  = true;

    function renderTableView(filtered) {
        tableData = filtered;
        const agentCount = filtered.length;
        // Re-bind reset since the button is rendered dynamically
        $results.off('click', '#eaf-reset-filters').on('click', '#eaf-reset-filters', resetFilters);
        renderTableSorted(tableSortCol, tableSortAsc);
    }

    function renderTableSorted(col, asc) {
        tableSortCol = col;
        tableSortAsc = asc;

        const sorted = [...tableData].sort(function(a, b) {
            const va = a[col] || '';
            const vb = b[col] || '';
            if (typeof va === 'number') return asc ? va - vb : vb - va;
            return asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
        });

        const cols = [
            { key: 'agent_name',       label: 'Agent'        },
            { key: 'level',            label: 'Lvl'          },
            { key: 'division_name',    label: 'Division'     },
            { key: 'agent_type_name',  label: 'Type'         },
            { key: 'corporation_name', label: 'Corporation'  },
            { key: 'faction_name',     label: 'Faction'      },
            { key: 'station_name',     label: 'Station'      },
            { key: 'system_name',      label: 'System'       },
            { key: 'security',         label: 'Sec'          },
            { key: 'sec_class',        label: 'Class'        },
            { key: 'lowsec_distance',  label: 'Jumps→LS'     },
        ];

        const tCount = tableData.length;
        let h = '<div class="eaf-hub-count">'
            + '<span>Showing ' + tCount.toLocaleString() + ' agent' + (tCount !== 1 ? 's' : '') + '</span>'
            + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button>'
            + '</div>';
        h += '<div class="eaf-table-wrap"><table class="eaf-table eaf-table-flat">';
        h += '<thead><tr>';
        cols.forEach(function(c) {
            const isSort  = c.key === tableSortCol;
            const dirCls  = isSort ? (tableSortAsc ? 'sort-asc' : 'sort-desc') : '';
            h += '<th class="eaf-th-sort ' + dirCls + '" data-col="' + c.key + '">';
            h += esc(c.label);
            if (isSort) h += tableSortAsc ? ' ▲' : ' ▼';
            h += '</th>';
        });
        h += '</tr></thead><tbody>';

        sorted.slice(0, 2000).forEach(function(a) {
            const dist       = a.lowsec_distance;
            const distTxt    = dist >= 0 ? String(dist) : '—';
            const distClass  = dist < 0 ? 'eaf-dist-na' : dist >= 10 ? 'eaf-dist-very-safe' : dist >= 7 ? 'eaf-dist-safe' : dist >= 4 ? 'eaf-dist-medium' : 'eaf-dist-close';
            h += '<tr class="eaf-agent-row">';
            h += '<td>' + esc(a.agent_name) + '</td>';
            h += '<td><span class="eaf-lbadge eaf-level-' + a.level + '">L' + a.level + '</span></td>';
            h += '<td>' + esc(getMissionType(a.division_id, a.agent_type_name, a.division_name) || a.division_name) + '</td>';
            h += '<td><span class="eaf-type-pill">' + esc(fmtAgentType(a.agent_type_name)) + '</span></td>';
            h += '<td>' + esc(a.corporation_name) + '</td>';
            h += '<td>' + esc(a.faction_name) + '</td>';
            h += '<td class="eaf-td-station">' + esc(a.station_name) + '</td>';
            h += '<td>' + esc(a.system_name) + '</td>';
            h += '<td><span class="eaf-sec ' + secCssClass(a.security, a.sec_class) + '">' + a.security.toFixed(1) + '</span></td>';
            h += '<td><span class="eaf-secclass-' + a.sec_class + '">' + a.sec_class + '</span></td>';
            h += '<td class="' + distClass + '">' + distTxt + '</td>';
            h += '</tr>';
        });

        if (sorted.length > 2000) {
            h += '<tr><td colspan="11" class="eaf-truncate-notice">Showing 2 000 of ' + sorted.length.toLocaleString() + '. Add filters to narrow.</td></tr>';
        }

        h += '</tbody></table></div>';
        $results.html(h);
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    function resetFilters() {
        $('#eaf-search').val('');
        $('#eaf-sec-class input[value=highsec]').prop('checked', true);
        $('#eaf-sec-class input:not([value=highsec])').prop('checked', false);
        $('#eaf-level-filter input').prop('checked', false);
        $('#eaf-mission-type input').prop('checked', false);
        $('#eaf-division option').prop('selected', false);
        $('#eaf-agent-type').val('');
        $('#eaf-faction, #eaf-corporation').val('');
        $('#eaf-min-jumps-range').val(0);
        $('#eaf-min-jumps-val').text('0');
        $('#eaf-min-agents').val(1);
        $('#eaf-min-agents-display').text(1);
        $('#eaf-min-agents-scope').data('scope', 'system');
        $('#eaf-min-agents-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
        $('#eaf-min-agents-scope .eaf-scope-pill[data-val="system"]').addClass('eaf-scope-pill-active');
        $('#eaf-storyline-only').prop('checked', false);
        lastSearch = '';
        populateCorpSelect('');
        // Reload from server in case sec_class changed (e.g. was lowsec, now highsec)
        reloadAgents();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function secCssClass(sec, secClass) {
        if (secClass === 'wormhole') return 'sec-wh';
        const r = Math.round(sec * 10) / 10;
        if (r >= 1.0) return 'sec-10';
        if (r >= 0.9) return 'sec-09';
        if (r >= 0.8) return 'sec-08';
        if (r >= 0.7) return 'sec-07';
        if (r >= 0.6) return 'sec-06';
        if (r >= 0.5) return 'sec-05';
        if (r >= 0.4) return 'sec-04';
        if (r >= 0.3) return 'sec-03';
        if (r >= 0.2) return 'sec-02';
        if (r >= 0.1) return 'sec-01';
        return 'sec-00';
    }

    function showLoader(on) {
        $loader.toggle(on);
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function debounce(fn, ms) {
        let t;
        return function() {
            const ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, ms);
        };
    }

    function ajaxPost(data) {
        return new Promise(function(resolve, reject) {
            $.post(ajax_url, Object.assign({ nonce }, data))
                .done(function(r) { r.success ? resolve(r) : reject(new Error(r.data?.message || 'Error')); })
                .fail(function(xhr) { reject(new Error('HTTP ' + xhr.status)); });
        });
    }
});
