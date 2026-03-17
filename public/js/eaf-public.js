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

    // ── Nearest-to state ──────────────────────────────────────────────────────
    let nearestDistances  = null;  // null = not loaded; object = system_id(str) → jumps
    let nearestOriginName = '';    // the resolved system name confirmed by the server

    // Cache all corp data for faction→corp filtering
    let allCorps = [];
    // Cache all region names for region select filtering
    let allRegions = [];

    // ── SSO / standings state ─────────────────────────────────────────────────
    // standings: array of { from_id, from_type, standing } from ESI, or null = not loaded
    let standingsData     = null;
    let standingsLoading  = false;
    let standingsCharName = EAF.sso_char_name || '';
    // Mutable auth flag — updated on logout so renderSSOZone re-renders correctly
    // without needing a page reload.
    let ssoAuthed = EAF.sso_authed === '1';

    // ── URL hash & localStorage ───────────────────────────────────────────────
    const LS_KEY = 'eaf_filters_v2';

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
            const saved = loadSavedState();
            if (saved) { applyState(saved); }
            updateSliderMax(allAgents);
            bindEvents();

            // SSO: initialise toolbar zone and show standings row if already authed
            if (EAF.sso_configured === '1') {
                if (ssoAuthed) {
                    $('#eaf-standings-row').show();
                }
            }

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
            return cfg.default_sec_class || ['highsec', 'lowsec', 'nullsec'];
        } catch(_) { return ['highsec', 'lowsec', 'nullsec']; }
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

        // Regions
        allRegions = filterOptions.regions || [];
        const $reg = $('#eaf-region');
        $reg.empty().append('<option value="">— All regions —</option>');
        allRegions.forEach(function(r) {
            $reg.append('<option value="' + esc(r) + '">' + esc(r) + '</option>');
        });

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
            // Hub-specific options make sense in hub + constellation views
            $('#eaf-hub-options').toggle(currentView === 'station' || currentView === 'constellation');
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

        // Version info modal
        $('#eaf-version-btn').on('click', function() {
            const $modal = $('#eaf-version-modal');
            const $content = $('#eaf-version-content');
            $modal.fadeIn(180);
            if ($content.find('.eaf-changelog-loading').length) {
                ajaxPost({ action: 'eaf_changelog' })
                    .then(function(resp) {
                        $content.html(renderChangelog(resp.data.changelog));
                    })
                    .catch(function() {
                        $content.html('<p class="eaf-changelog-error">Could not load changelog.</p>');
                    });
            }
        });
        $('#eaf-version-close').on('click', function() {
            $('#eaf-version-modal').fadeOut(150);
        });
        $('#eaf-version-modal').on('click', function(e) {
            if ($(e.target).is('#eaf-version-modal')) $(this).fadeOut(150);
        });

        // Realtime filters — non-search controls (instant render)
        $app.on('change', '#eaf-level-filter input, '
            + '#eaf-mission-type input, #eaf-division, #eaf-agent-type, #eaf-faction, #eaf-corporation, '
            + '#eaf-region, #eaf-storyline-only, #eaf-locator-only, #eaf-compact-view', debounce(render, 180));

        // Faction → filter corp list (region is independent — no cascade needed)
        $('#eaf-faction').on('change', function() {
            populateCorpSelect($(this).val());
            render();
        });

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

        // Min-L4 scope toggle
        $app.on('click', '#eaf-min-l4-scope .eaf-scope-pill', function() {
            const val = $(this).data('val');
            $('#eaf-min-l4-scope').data('scope', val);
            $('#eaf-min-l4-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
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
            const isNearest = sortBy === 'nearest_to';
            $('#eaf-nearest-row').toggle(isNearest);
            if (!isNearest) {
                render();
            } else if (nearestDistances) {
                render(); // distances already loaded from a previous lookup
            }
            // if nearest_to selected but no distances yet, wait for the user to type
        });

        // Nearest-to system input — fire AJAX after the user stops typing
        $app.on('input search', '#eaf-nearest-system', debounce(function() {
            const name = $(this).val().trim();
            if (!name) return;
            fetchNearestDistances(name);
        }, 500));

        // Min L4 stepper
        $app.on('click', '#eaf-min-l4-dec', function() {
            const $inp = $('#eaf-min-l4-agents');
            const v = Math.max(0, parseInt($inp.val(), 10) - 1);
            $inp.val(v);
            $('#eaf-min-l4-display').text(v);
            render();
        });
        $app.on('click', '#eaf-min-l4-inc', function() {
            const $inp = $('#eaf-min-l4-agents');
            const v = Math.min(20, parseInt($inp.val(), 10) + 1);
            $inp.val(v);
            $('#eaf-min-l4-display').text(v);
            render();
        });

        // Share button — copy current URL (with hash) to clipboard
        $app.on('click', '#eaf-share-btn', function() {
            saveState();
            const url = window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url);
            } else {
                const ta = document.createElement('textarea');
                ta.value = url; document.body.appendChild(ta);
                ta.select(); document.execCommand('copy');
                document.body.removeChild(ta);
            }
            const $btn = $(this);
            const orig = $btn.text();
            $btn.text('✓ Copied!');
            setTimeout(function() { $btn.text(orig); }, 1800);
        });

        // Collapse all button — shown when any card is expanded
        $results.on('click', '#eaf-collapse-all', function() {
            $results.find('.eaf-hub-card.expanded').each(function() {
                $(this).removeClass('expanded').find('.eaf-hub-body').slideUp(200);
            });
            $(this).hide();
        });

        // Show/hide collapse-all when a card is expanded/collapsed
        $results.on('click', '.eaf-hub-header', function(e) {
            if ($(e.target).closest('button, a, input, select').length) return;
            const $card = $(this).closest('.eaf-hub-card');
            $card.toggleClass('expanded');
            $card.find('.eaf-hub-body').stop(true).slideToggle(200);
            const anyExpanded = $results.find('.eaf-hub-card.expanded').length > 0;
            $results.find('#eaf-collapse-all').toggle(anyExpanded);
        });

        // Autocomplete for nearest-to system input
        $app.on('input', '#eaf-nearest-system', debounce(function() {
            const q = $(this).val().trim();
            const $drop = $('#eaf-nearest-autocomplete');
            if (q.length < 2) { $drop.hide().empty(); return; }
            ajaxPost({ action: 'eaf_suggest', q: q }).then(function(resp) {
                const names = resp.data || [];
                $drop.empty();
                if (!names.length) { $drop.hide(); return; }
                names.forEach(function(name) {
                    $drop.append('<div class="eaf-ac-item" data-name="' + esc(name) + '">' + esc(name) + '</div>');
                });
                $drop.show();
            }).catch(function() { $drop.hide().empty(); });
        }, 250));

        // Select autocomplete item
        $app.on('click', '.eaf-ac-item', function() {
            const name = $(this).data('name');
            $('#eaf-nearest-system').val(name);
            $('#eaf-nearest-autocomplete').hide().empty();
            fetchNearestDistances(name);
        });

        // Hide autocomplete on outside click
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.eaf-autocomplete-wrap').length) {
                $('#eaf-nearest-autocomplete').hide().empty();
            }
        });

        // Expand / collapse hub cards
        // (handled above with collapse-all toggle — remove old duplicate handler)

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

        // ── SSO / standings events ────────────────────────────────────────────

        // Standings filter toggle — lazy-fetch standings on first check
        $app.on('change', '#eaf-standings-filter', function() {
            if ($(this).is(':checked')) {
                loadStandings();
            } else {
                render();
            }
        });

        // Logout button (rendered dynamically by renderSSOZone)
        $app.on('click', '#eaf-sso-logout-btn', function(e) {
            e.preventDefault();
            ajaxPost({ action: 'eaf_sso_logout' }).then(function() {
                standingsData    = null;
                standingsLoading = false;
                standingsCharName = '';
                ssoAuthed = false;
                $('#eaf-standings-filter').prop('checked', false);
                $('#eaf-standings-status').text('');
                $('#eaf-standings-row').hide();
                renderSSOZone();
                render();
            });
        });
    }

    // ── SSO toolbar renderer ──────────────────────────────────────────────────

    /**
     * Renders the SSO zone in the toolbar based on EAF.sso_* config and
     * current standingsCharName. The server already renders the initial state
     * in PHP; this function handles updates after logout / re-auth prompt.
     */
    function renderSSOZone() {
        const $zone = $('#eaf-sso-zone');
        if (!$zone.length || EAF.sso_configured !== '1') return;

        if (ssoAuthed && standingsCharName) {
            // Authenticated state
            $zone.html(
                '<span class="eaf-sso-authed-label">[Authenticated as <strong>' + esc(standingsCharName) + '</strong>]</span>' +
                '<a href="' + esc(EAF.sso_auth_url || '#') + '" class="eaf-sso-change-btn">[Change Character]</a>' +
                '<button type="button" id="eaf-sso-logout-btn" class="eaf-sso-logout-link">[Log out]</button>'
            );
            $('#eaf-standings-row').show();
        } else {
            // Logged-out state
            const btnHtml = EAF.sso_img_url
                ? '<img src="' + esc(EAF.sso_img_url) + '" alt="LOG IN with EVE Online">'
                : '<span class="eaf-sso-text-btn">LOG IN with EVE Online</span>';
            $zone.html(
                '<a href="' + esc(EAF.sso_auth_url) + '" class="eaf-sso-login-btn" id="eaf-sso-login-btn">' + btnHtml + '</a>'
            );
            $('#eaf-standings-row').hide();
        }
    }

    // ── Standings loader ──────────────────────────────────────────────────────

    /**
     * Fetches standings from the server (which proxies ESI with caching).
     * Shows a spinner in the standings status area while loading.
     * On success: stores standingsData and triggers a re-render.
     * On need_reauth: unchecks the toggle and shows a re-auth prompt.
     */
    function loadStandings() {
        if (standingsLoading) return;
        if (standingsData) { render(); return; }

        standingsLoading = true;
        $('#eaf-standings-status').html('<span class="eaf-standings-spinner">⟳</span> Loading standings…');

        ajaxPost({ action: 'eaf_standings' })
            .then(function(resp) {
                standingsData     = resp.data.standings;
                standingsCharName = resp.data.character_name || standingsCharName;
                $('#eaf-standings-status').text('');
                render();
            })
            .catch(function() {
                standingsLoading = false;
                $('#eaf-standings-filter').prop('checked', false);
                $('#eaf-standings-status').html(
                    '<span class="eaf-standings-warn">Could not load standings — re-auth your character by clicking <strong>[Change Character]</strong> above.</span>'
                );
            })
            .finally(function() {
                standingsLoading = false;
            });
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
        const regionName = $('#eaf-region').val() || '';
        const minJumps   = parseInt($('#eaf-min-jumps-range').val(), 10);
        const locatorOnly      = $('#eaf-locator-only').is(':checked');
        const standingsFilter  = $('#eaf-standings-filter').is(':checked');

        return { q, secClasses, levels, missionTypes,
                 divIds: divIds.map(Number).filter(Boolean),
                 atIds, factionId, corpId, regionName, minJumps, locatorOnly, standingsFilter };
    }

    // ── Standings: can a character access this agent? ─────────────────────────
    // ESI standings: NPC corps/factions use from_type 'npc_corp' or 'faction'.
    // An agent is available when the standing toward their corporation is ≥ 1
    // for L1–L2, ≥ 3 for L3–L4, ≥ 5 for L5  (CCP's threshold formula).
    // We also pass if the character has sufficient faction standing as a fallback
    // (faction standing can unlock access to corp agents).
    // Threshold reference: https://support.eveonline.com/hc/en-us/articles/203219961
    const LEVEL_THRESHOLDS = { 1: -10, 2: 1, 3: 3, 4: 5, 5: 7 };

    function standingFor(fromId) {
        if (!standingsData) return null;
        for (let i = 0; i < standingsData.length; i++) {
            if (standingsData[i].from_id === fromId) return standingsData[i].standing;
        }
        return null;
    }

    function isAgentAccessible(agent) {
        if (!standingsData) return true;
        const threshold = LEVEL_THRESHOLDS[agent.level] !== undefined ? LEVEL_THRESHOLDS[agent.level] : 1;
        const corpStanding    = standingFor(agent.corporation_id);
        const factionStanding = standingFor(agent.faction_id);
        const effective = Math.max(
            corpStanding    !== null ? corpStanding    : -10,
            factionStanding !== null ? factionStanding : -10
        );
        return effective >= threshold;
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
            if (f.regionName && a.region_name !== f.regionName) return false;

            if (f.locatorOnly && !a.is_locator) return false;

            // Standings filter — hide agents the character cannot access
            if (f.standingsFilter && standingsData) {
                if (!isAgentAccessible(a)) return false;
            }

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
        saveState();
        if (currentView === 'station') {
            renderHubView(filtered);
        } else if (currentView === 'constellation') {
            renderConstellationView(filtered);
        } else {
            renderTableView(filtered);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // HUB VIEW  –  grouped by SYSTEM (stations shown inside each system card)
    // ────────────────────────────────────────────────────────────────────────

    function renderHubView(filtered) {
        const minAgents    = parseInt($('#eaf-min-agents').val(), 10) || 1;
        const minL4        = parseInt($('#eaf-min-l4-agents').val(), 10) || 0;
        const minScope     = $('#eaf-min-agents-scope').data('scope') || 'system';
        const minL4Scope   = $('#eaf-min-l4-scope').data('scope') || 'system';
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

        // ── Min L4 agents filter ──────────────────────────────────────────
        if (minL4 > 0) {
            systems = systems.filter(function(sys) {
                if (minL4Scope === 'station') {
                    return Object.values(sys.stations).some(function(sta) {
                        return sta.agents.filter(function(a) { return a.level === 4; }).length >= minL4;
                    });
                }
                return sys.agents.filter(function(a) { return a.level === 4; }).length >= minL4;
            });
        }

        // ── Score and sort ────────────────────────────────────────────────
        systems.forEach(function(sys) { sys.hub_score = computeSystemHubScore(sys); });
        systems = sortSystems(systems);

        // Update storyline-in-system label with count
        const slInSysCount = systems.filter(function(s) { return s.storyline_distance === 0; }).length;
        const $slLabel = $('#eaf-storyline-only').closest('label');
        $slLabel.find('.eaf-sl-count').remove();
        $slLabel.append('<span class="eaf-sl-count eaf-count-hint"> (' + slInSysCount + ')</span>');

        // Update locator-only label with agent count (before station/system grouping)
        const locatorAgentCount = filtered.filter(function(a) { return a.is_locator; }).length;
        const $locLabel = $('#eaf-locator-only').closest('label');
        $locLabel.find('.eaf-locator-count').remove();
        $locLabel.append('<span class="eaf-locator-count eaf-count-hint"> (' + locatorAgentCount + ')</span>');

        if (systems.length === 0) {
            $results.html(
                '<div class="eaf-hub-count">'
                + '<span>No systems match your filters</span>'
                + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button>'
                + '</div>'
                + '<div class="eaf-empty">Try relaxing the filters.</div>'
            );
            return;
        }

        const agentCount = filtered.length;
        let html = '<div class="eaf-hub-count">'
            + '<span>Showing ' + agentCount.toLocaleString() + ' agent' + (agentCount !== 1 ? 's' : '') + ' in ' + systems.length.toLocaleString() + ' system' + (systems.length !== 1 ? 's' : '') + '</span>'
            + '<button class="eaf-btn eaf-btn-collapse eaf-btn-sm eaf-collapse-all-btn" id="eaf-collapse-all" style="display:none">↑ Collapse all</button>'
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
                case 'nearest_to': {
                    const da = nearestDistances ? (nearestDistances[String(a.system_id)] ?? 99999) : 99999;
                    const db = nearestDistances ? (nearestDistances[String(b.system_id)] ?? 99999) : 99999;
                    return da - db;
                }
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
        // When level/mission-type filters are active, only count agents matching those filters
        // so the gold bar reflects the filtered view and the tooltip stays unambiguous.
        const f = getFilters();
        const filtersActive = f.levels.length > 0 || f.missionTypes.length > 0;
        const highlightAgents = sys.agents.filter(function(a) {
            if (f.levels.length && !f.levels.includes(a.level)) return false;
            if (f.missionTypes.length) {
                const mt = getMissionType(a.division_id, a.agent_type_name, a.division_name);
                if (!mt || !f.missionTypes.includes(mt)) return false;
            }
            return true;
        });
        const typeLevelCounts = {};
        highlightAgents.forEach(function(a) {
            const mt = getMissionType(a.division_id, a.agent_type_name, a.division_name) || 'Other';
            const key = mt + '|' + a.level;
            typeLevelCounts[key] = (typeLevelCounts[key] || 0) + 1;
        });

        // All type|level combos that have more than one agent
        const qualifyingKeys = Object.keys(typeLevelCounts).filter(function(k) { return typeLevelCounts[k] > 1; });
        const multiSameDiv   = qualifyingKeys.length > 0;
        const highlightClass = multiSameDiv ? 'eaf-hub-highlight' : '';

        // Tooltip: generic when no filters are influencing the result;
        // specific when level/mission-type filters are active.
        let highlightTitle = 'This system has multiple agents of the same mission type and level';
        if (multiSameDiv && filtersActive) {
            // Group qualifying keys by mission type → { 'Security': [3,4], … }
            const byType = {};
            qualifyingKeys.forEach(function(k) {
                const p  = k.split('|');
                const mt = p[0], lv = parseInt(p[1], 10);
                if (!byType[mt]) byType[mt] = [];
                byType[mt].push(lv);
            });
            Object.values(byType).forEach(function(lvs) { lvs.sort(function(a,b){return a-b;}); });

            // Build one natural-language clause per type
            function joinLevels(lvs) {
                const tags = lvs.map(function(l) { return 'L' + l; });
                if (tags.length === 1) return tags[0];
                return tags.slice(0, -1).join(', ') + ' and ' + tags[tags.length - 1];
            }

            const typeEntries = Object.entries(byType);
            if (typeEntries.length === 1) {
                const mt  = typeEntries[0][0];
                const lvs = typeEntries[0][1];
                if (lvs.length === 1) {
                    highlightTitle = 'This system has multiple L' + lvs[0] + ' ' + mt + ' agents';
                } else {
                    highlightTitle = 'This system has multiple ' + joinLevels(lvs) + ' ' + mt + ' agents';
                }
            } else {
                // Multiple types qualify — list each clause
                const clauses = typeEntries.map(function(e) {
                    return joinLevels(e[1]) + ' ' + e[0];
                });
                highlightTitle = 'This system has multiple agents: ' + clauses.join(', ');
            }
        }

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

        const stationCount   = Object.keys(sys.stations).length;

        let h = '<div class="eaf-hub-card ' + highlightClass + '">';
        if (multiSameDiv) {
            h += '<span class="eaf-highlight-bar" title="' + esc(highlightTitle) + '"></span>';
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
        h += '  <span class="eaf-sec ' + secColor + '">' + fmtSecurity(sys.security, sys.sec_class) + '</span>';

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
        if (sortBy === 'nearest_to' && nearestDistances) {
            const nd = nearestDistances[String(sys.system_id)];
            const ndTxt = nd !== undefined ? nd + ' jump' + (nd !== 1 ? 's' : '') + ' from ' + esc(nearestOriginName) : 'unreachable';
            const ndBadge = '<span class="eaf-badge eaf-badge-nearest">' + ndTxt + '</span>';
            if (nd !== undefined) {
                h += '<a class="eaf-dotlan-link" href="https://evemaps.dotlan.net/route/' + encodeURIComponent(sys.system_name) + ':' + encodeURIComponent(nearestOriginName) + '" target="_blank" rel="noopener">' + ndBadge + '</a>';
            } else {
                h += ndBadge;
            }
        }
        h += '  <span class="eaf-score" title="Composite score: total agents ×2, L4 agents ×3, unique corporations ×1.5, stations ×1, storyline proximity bonus, distance from Lowsec ×0.3">Score ' + sys.hub_score.toFixed(1) + '</span>';
        h += '  <div class="eaf-toggle-arrow">▼</div>';
        h += '</div>';

        // ── Line 2: composition + factions ────────────────────────────────
        if (!$('#eaf-compact-view').is(':checked')) {
            h += '<div class="eaf-hub-row2">';
            h += typeClusters;
            h += '<span class="eaf-hub-factions">' + esc(facNames) + '</span>';
            h += '</div>';
        }

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
            h += '  <div class="eaf-hub-station-label"><button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(sta.station_name || '') + '" title="Copy station name">⧉</button> ' + esc(sta.station_name || 'Station #' + sta.station_id) + '</div>';
            h += '  <table class="eaf-table"><thead><tr>';
            h += '    <th>Agent</th><th>Level</th><th>Division</th><th>Type</th><th>Corporation</th><th>Faction</th>';
            h += '  </tr></thead><tbody>';

            sta.agents.forEach(function(a) {
                h += '<tr class="eaf-agent-row">';
                h += '<td><button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(a.agent_name) + '" title="Copy agent name">⧉</button> <strong>' + esc(a.agent_name) + '</strong>' + (a.is_locator ? ' <span class="eaf-locator-tag">locator</span>' : '') + '</td>';
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
    // CONSTELLATION VIEW  –  systems grouped by constellation
    // ────────────────────────────────────────────────────────────────────────

    function renderConstellationView(filtered) {
        const minAgents  = parseInt($('#eaf-min-agents').val(), 10) || 1;
        const minL4      = parseInt($('#eaf-min-l4-agents').val(), 10) || 0;
        const minScope   = $('#eaf-min-agents-scope').data('scope') || 'system';
        const minL4Scope = $('#eaf-min-l4-scope').data('scope') || 'system';
        const storylineOnly = $('#eaf-storyline-only').is(':checked');

        // ── Build system map (same as hub view) ───────────────────────────
        const systemMap = {};
        filtered.forEach(function(a) {
            const sid = a.system_id;
            if (!systemMap[sid]) {
                systemMap[sid] = {
                    system_id:           a.system_id,
                    system_name:         a.system_name,
                    security:            a.security,
                    sec_class:           a.sec_class,
                    lowsec_distance:     a.lowsec_distance,
                    storyline_distance:  a.storyline_distance,
                    storyline_lowsec:    a.storyline_lowsec,
                    constellation_name:  a.constellation_name || '',
                    constellation_id:    a.constellation_id   || '',
                    region_name:         a.region_name        || '',
                    stations:            {},
                    agents:              [],
                    factions:            new Set(),
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

        // ── Apply hub-view filters ─────────────────────────────────────────
        systems = systems.filter(function(sys) {
            if (minScope === 'station') {
                return Object.values(sys.stations).some(function(sta) { return sta.agents.length >= minAgents; });
            }
            return sys.agents.length >= minAgents;
        });
        if (storylineOnly) {
            systems = systems.filter(function(sys) { return sys.storyline_distance === 0; });
        }
        if (minL4 > 0) {
            systems = systems.filter(function(sys) {
                if (minL4Scope === 'station') {
                    return Object.values(sys.stations).some(function(sta) {
                        return sta.agents.filter(function(a) { return a.level === 4; }).length >= minL4;
                    });
                }
                return sys.agents.filter(function(a) { return a.level === 4; }).length >= minL4;
            });
        }

        // ── Score systems ─────────────────────────────────────────────────
        systems.forEach(function(sys) { sys.hub_score = computeSystemHubScore(sys); });
        systems = sortSystems(systems);

        // ── Group into constellations ─────────────────────────────────────
        const constMap = {};
        systems.forEach(function(sys) {
            const key = sys.constellation_name || '(Unknown Constellation)';
            if (!constMap[key]) {
                constMap[key] = {
                    constellation_name: key,
                    region_name:        sys.region_name,
                    systems:            [],
                    agents:             [],
                };
            }
            constMap[key].systems.push(sys);
            constMap[key].agents = constMap[key].agents.concat(sys.agents);
        });

        let constellations = Object.values(constMap);

        // Sort constellations: by total agents desc, then name asc
        constellations.sort(function(a, b) {
            if (b.agents.length !== a.agents.length) return b.agents.length - a.agents.length;
            return a.constellation_name.localeCompare(b.constellation_name);
        });

        const totalSystems = systems.length;
        const totalAgents  = filtered.length;

        if (constellations.length === 0) {
            $results.html(
                '<div class="eaf-hub-count"><span>No systems match your filters</span>'
                + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button></div>'
                + '<div class="eaf-empty">Try relaxing the filters.</div>'
            );
            return;
        }

        let html = '<div class="eaf-hub-count">'
            + '<span>Showing ' + totalAgents.toLocaleString() + ' agent' + (totalAgents !== 1 ? 's' : '')
            + ' in ' + totalSystems.toLocaleString() + ' system' + (totalSystems !== 1 ? 's' : '')
            + ' across ' + constellations.length.toLocaleString() + ' constellation' + (constellations.length !== 1 ? 's' : '') + '</span>'
            + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button>'
            + '</div>';

        html += '<div class="eaf-const-list">';
        constellations.forEach(function(con) {
            const dotlanRegion = con.region_name.replace(/ /g, '_');
            const dotlanConst  = con.constellation_name.replace(/ /g, '_');
            const constUrl     = dotlanRegion
                ? 'https://evemaps.dotlan.net/map/' + dotlanRegion + '/' + encodeURIComponent(dotlanConst)
                : 'https://evemaps.dotlan.net/search/' + encodeURIComponent(con.constellation_name);
            const regUrl       = dotlanRegion
                ? 'https://evemaps.dotlan.net/map/' + dotlanRegion
                : '';
            const l4Count      = con.agents.filter(function(a) { return a.level === 4; }).length;

            html += '<div class="eaf-const-card">';
            html += '<div class="eaf-const-header">';
            html += '<div class="eaf-const-row1">';
            html += '<span class="eaf-const-arrow">▶</span>';
            html += '<a class="eaf-dotlan-link eaf-const-name-link" href="' + constUrl + '" target="_blank" rel="noopener">'
                  + '<span class="eaf-const-name">' + esc(con.constellation_name) + '</span></a>';
            if (con.region_name) {
                const regLink = regUrl
                    ? '<a class="eaf-dotlan-link eaf-breadcrumb-link" href="' + regUrl + '" target="_blank" rel="noopener">' + esc(con.region_name) + '</a>'
                    : esc(con.region_name);
                html += '<span class="eaf-hub-breadcrumb">&lsaquo; ' + regLink + '</span>';
            }
            html += '<span class="eaf-hub-subinfo"> · '
                  + con.systems.length + ' system' + (con.systems.length !== 1 ? 's' : '')
                  + ' · ' + con.agents.length + ' agent' + (con.agents.length !== 1 ? 's' : '');
            if (l4Count > 0) html += ' · <span class="eaf-lbadge eaf-level-4">L4×' + l4Count + '</span>';
            html += '</span>';
            html += '<span class="eaf-score" style="margin-left:auto">Score '
                  + con.systems.reduce(function(s, sys) { return s + sys.hub_score; }, 0).toFixed(1) + '</span>';
            html += '</div>'; // const-row1
            html += '</div>'; // const-header

            html += '<div class="eaf-const-body" style="display:none"><div class="eaf-hub-list">';
            con.systems.forEach(function(sys, i) {
                html += renderSystemCard(sys, i);
            });
            html += '</div></div>'; // const-body
            html += '</div>'; // const-card
        });
        html += '</div>';

        $results.html(html);

        // Expand / collapse constellation cards
        $results.off('click.const').on('click.const', '.eaf-const-header', function(e) {
            if ($(e.target).closest('button, a, input, select').length) return;
            const $card = $(this).closest('.eaf-const-card');
            $card.toggleClass('eaf-const-expanded');
            $card.find('.eaf-const-arrow').text($card.hasClass('eaf-const-expanded') ? '▼' : '▶');
            $card.find('> .eaf-const-body').stop(true).slideToggle(200);
        });
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
            { key: 'agent_name',       label: 'Agent',       cls: 'col-agent'    },
            { key: 'level',            label: 'Lvl',         cls: 'col-lvl'      },
            { key: 'division_name',    label: 'Division',    cls: 'col-division' },
            { key: 'agent_type_name',  label: 'Type',        cls: 'col-type'     },
            { key: 'corporation_name', label: 'Corporation', cls: 'col-corp'     },
            { key: 'faction_name',     label: 'Faction',     cls: 'col-faction'  },
            { key: 'station_name',     label: 'Station',     cls: 'col-station'  },
            { key: 'system_name',      label: 'System',      cls: 'col-system'   },
            { key: 'security',         label: 'Sec',         cls: 'col-sec'      },
            { key: 'lowsec_distance',  label: 'Jumps→LS',    cls: 'col-jumps'    },
        ];

        const tCount = tableData.length;
        let h = '<div class="eaf-hub-count">'
            + '<span>Showing ' + tCount.toLocaleString() + ' agent' + (tCount !== 1 ? 's' : '') + '</span>'
            + '<button class="eaf-btn eaf-btn-reset eaf-btn-sm" id="eaf-reset-filters">↺ Reset filters</button>'
            + '</div>';
        h += '<div class="eaf-table-wrap"><table class="eaf-table eaf-table-flat">';
        h += '<colgroup>';
        cols.forEach(function(c) { h += '<col class="' + c.cls + '">'; });
        h += '</colgroup>';
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
            h += '<td class="eaf-td-agent" title="' + esc(a.agent_name) + '"><button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(a.agent_name) + '" title="Copy agent name">⧉</button> ' + esc(a.agent_name) + (a.is_locator ? ' <span class="eaf-locator-tag">locator</span>' : '') + '</td>';
            h += '<td><span class="eaf-lbadge eaf-level-' + a.level + '">L' + a.level + '</span></td>';
            h += '<td>' + esc(getMissionType(a.division_id, a.agent_type_name, a.division_name) || a.division_name) + '</td>';
            h += '<td><span class="eaf-type-pill">' + esc(fmtAgentType(a.agent_type_name)) + '</span></td>';
            h += '<td class="eaf-td-corp" title="' + esc(a.corporation_name) + '">' + esc(a.corporation_name) + '</td>';
            h += '<td class="eaf-td-faction" title="' + esc(a.faction_name) + '">' + esc(a.faction_name) + '</td>';
            h += '<td class="eaf-td-station" title="' + esc(a.station_name) + '"><button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(a.station_name) + '" title="Copy station name">⧉</button> ' + esc(a.station_name) + '</td>';
            const dotlanSysUrl = 'https://evemaps.dotlan.net/system/' + encodeURIComponent(a.system_name.replace(/ /g,'_'));
            const distBadge = dist >= 0 ? '<span class="eaf-badge eaf-badge-dist eaf-badge-dist-sm ' + distClass.replace('eaf-dist-','dist-') + '">' + distTxt + '</span>' : '<span class="eaf-dist-na">—</span>';
            const distLinked = dist >= 0 && a.lowsec_gateway ? '<a class="eaf-dotlan-link" href="https://evemaps.dotlan.net/route/' + encodeURIComponent(a.system_name) + ':' + encodeURIComponent(a.lowsec_gateway) + '" target="_blank" rel="noopener">' + distBadge + '</a>' : distBadge;
            h += '<td><button class="eaf-copy-btn eaf-copy-inline" data-copy="' + esc(a.system_name) + '" title="Copy system name">⧉</button> <a class="eaf-dotlan-link eaf-table-syslink" href="' + dotlanSysUrl + '" target="_blank" rel="noopener"><span class="eaf-hub-sysname eaf-table-sysname ' + secCssClass(a.security, a.sec_class) + '">' + esc(a.system_name) + '</span></a></td>';
            h += '<td><span class="eaf-sec ' + secCssClass(a.security, a.sec_class) + '">' + fmtSecurity(a.security, a.sec_class) + '</span></td>';
            h += '<td>' + distLinked + '</td>';
            h += '</tr>';
        });

        if (sorted.length > 2000) {
            h += '<tr><td colspan="10" class="eaf-truncate-notice">Showing 2 000 of ' + sorted.length.toLocaleString() + '. Add filters to narrow.</td></tr>';
        }

        h += '</tbody></table></div>';
        $results.html(h);
    }

    // ── URL hash & localStorage ───────────────────────────────────────────────

    function serialiseState() {
        const f = getFilters();
        const state = {
            q:          f.q || undefined,
            sec:        $('#eaf-sec-class input:checked').map(function(){ return this.value; }).get(),
            lv:         f.levels.length    ? f.levels    : undefined,
            mt:         f.missionTypes.length ? f.missionTypes : undefined,
            at:         $('#eaf-agent-type').val() || undefined,
            fac:        f.factionId        || undefined,
            corp:       f.corpId           || undefined,
            region:     f.regionName       || undefined,
            jumps:      f.minJumps         || undefined,
            minA:       parseInt($('#eaf-min-agents').val(), 10) !== 1 ? parseInt($('#eaf-min-agents').val(), 10) : undefined,
            minL4:      parseInt($('#eaf-min-l4-agents').val(), 10) || undefined,
            scope:      $('#eaf-min-agents-scope').data('scope') !== 'system' ? $('#eaf-min-agents-scope').data('scope') : undefined,
            l4scope:    $('#eaf-min-l4-scope').data('scope') !== 'system' ? $('#eaf-min-l4-scope').data('scope') : undefined,
            sl:         $('#eaf-storyline-only').is(':checked') || undefined,
            loc:        $('#eaf-locator-only').is(':checked')   || undefined,
            cmp:        $('#eaf-compact-view').is(':checked')    || undefined,
            std:        $('#eaf-standings-filter').is(':checked') || undefined,
            sort:       sortBy !== 'agents_desc' ? sortBy : undefined,
            nearest:    nearestOriginName || undefined,
            view:       currentView !== 'station' ? currentView : undefined,
        };
        // Strip undefined keys
        Object.keys(state).forEach(function(k) { if (state[k] === undefined) delete state[k]; });
        return state;
    }

    function applyState(state) {
        if (!state) return;
        if (state.q)      { $('#eaf-search').val(state.q); lastSearch = state.q; }
        if (state.sec)    {
            $('#eaf-sec-class input').prop('checked', false);
            state.sec.forEach(function(v) { $('#eaf-sec-class input[value="' + v + '"]').prop('checked', true); });
            const highsecOn = $('#eaf-sec-class input[value=highsec]').is(':checked');
            $('.eaf-highsec-only').toggleClass('eaf-filter-disabled', !highsecOn);
        }
        if (state.lv)     { state.lv.forEach(function(v) { $('#eaf-level-filter input[value="' + v + '"]').prop('checked', true); }); }
        if (state.mt)     { state.mt.forEach(function(v) { $('#eaf-mission-type input[value="' + v + '"]').prop('checked', true); }); }
        if (state.at)     { $('#eaf-agent-type').val(state.at); }
        if (state.fac)    { $('#eaf-faction').val(state.fac); populateCorpSelect(state.fac); }
        if (state.corp)   { $('#eaf-corporation').val(state.corp); }
        if (state.region) { $('#eaf-region').val(state.region); }
        if (state.jumps)  { $('#eaf-min-jumps-range').val(state.jumps); updateRangeLabel('eaf-min-jumps-range', 'eaf-min-jumps-val', false); }
        if (state.minA)   { $('#eaf-min-agents').val(state.minA); $('#eaf-min-agents-display').text(state.minA); }
        if (state.minL4)  { $('#eaf-min-l4-agents').val(state.minL4); $('#eaf-min-l4-display').text(state.minL4); }
        if (state.scope && state.scope !== 'system') {
            $('#eaf-min-agents-scope').data('scope', state.scope);
            $('#eaf-min-agents-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
            $('#eaf-min-agents-scope .eaf-scope-pill[data-val="' + state.scope + '"]').addClass('eaf-scope-pill-active');
        }
        if (state.l4scope && state.l4scope !== 'system') {
            $('#eaf-min-l4-scope').data('scope', state.l4scope);
            $('#eaf-min-l4-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
            $('#eaf-min-l4-scope .eaf-scope-pill[data-val="' + state.l4scope + '"]').addClass('eaf-scope-pill-active');
        }
        if (state.sl)     { $('#eaf-storyline-only').prop('checked', true); }
        if (state.loc)    { $('#eaf-locator-only').prop('checked', true); }
        if (state.cmp)    { $('#eaf-compact-view').prop('checked', true); }
        if (state.std) {
            // Standings filter was active when this link was shared.
            $('#eaf-standings-row').show();
            $('#eaf-standings-filter').prop('checked', true);

            if (state.std_data && state.std_data.length) {
                // Standings were embedded in the URL — restore them directly.
                // This works for anyone with the link, logged in or not.
                standingsData = state.std_data;
                // No status message needed — filter is live immediately.
            } else if (ssoAuthed) {
                // No embedded data but user is authenticated — fetch from ESI.
                loadStandings();
            } else if (EAF.sso_configured === '1') {
                // No data and not logged in — show a prompt.
                $('#eaf-standings-status').html(
                    '<span class="eaf-standings-warn">Log in to apply standings filter. ' +
                    '<a href="' + esc(EAF.sso_auth_url || '#') + '">Authenticate</a></span>'
                );
            }
        }
        if (state.sort)   {
            sortBy = state.sort;
            $('#eaf-sort-by').val(state.sort);
            if (state.sort === 'nearest_to') { $('#eaf-nearest-row').show(); }
        }
        if (state.nearest && sortBy === 'nearest_to') {
            $('#eaf-nearest-system').val(state.nearest);
            fetchNearestDistances(state.nearest);
        }
        if (state.view && state.view !== 'station') {
            currentView = state.view;
            $('.eaf-view-btn').removeClass('active');
            $('.eaf-view-btn[data-view="' + state.view + '"]').addClass('active');
            $('#eaf-hub-options').toggle(state.view === 'station' || state.view === 'constellation');
        }
    }

    function saveState() {
        const state  = serialiseState();
        const parts  = [];

        // Scalar values
        const scalars = ['q', 'at', 'fac', 'corp', 'region', 'jumps', 'minA', 'minL4',
                         'scope', 'l4scope', 'sort', 'nearest', 'view'];
        scalars.forEach(function(k) {
            if (state[k] !== undefined) parts.push(k + '=' + encodeURIComponent(state[k]));
        });

        // Booleans
        if (state.sl)  parts.push('sl=1');
        if (state.loc) parts.push('loc=1');
        if (state.cmp) parts.push('cmp=1');
        if (state.std) {
            parts.push('std=1');
            // Encode loaded standings into the URL so shared links work for anyone,
            // authenticated or not. Format: std_data=id:standing,id:standing,...
            // Only the from_id and standing are needed; from_type is not used in filtering.
            if (standingsData && standingsData.length) {
                const encoded = standingsData
                    .map(function(s) { return s.from_id + ':' + s.standing; })
                    .join(',');
                parts.push('std_data=' + encodeURIComponent(encoded));
            }
        }

        // Arrays — commas are legal in hash fragments; no encoding needed
        if (state.sec && !(state.sec.length === 1 && state.sec[0] === 'highsec')) {
            parts.push('sec=' + state.sec.join(','));
        }
        if (state.lv)  parts.push('lv='  + state.lv.join(','));
        if (state.mt)  parts.push('mt='  + state.mt.join(','));

        const qs   = parts.join('&');
        const hash = qs ? '#eaf?' + qs : '';
        try { history.replaceState(null, '', window.location.pathname + window.location.search + hash); } catch(_) {}
        try { localStorage.setItem(LS_KEY, qs || ''); } catch(_) {}
    }

    function loadSavedState() {
        let qs = '';
        const hash = window.location.hash;
        if (hash.startsWith('#eaf?')) {
            qs = hash.slice(5);
        } else {
            try { qs = localStorage.getItem(LS_KEY) || ''; } catch(_) {}
        }
        if (!qs) return null;

        const params = new URLSearchParams(qs);
        const state  = {};

        // Scalars
        ['q', 'at', 'fac', 'corp', 'region', 'sort', 'nearest', 'view', 'scope', 'l4scope'].forEach(function(k) {
            if (params.has(k)) state[k] = params.get(k);
        });
        ['jumps', 'minA', 'minL4', 'fac', 'corp'].forEach(function(k) {
            if (params.has(k)) state[k] = parseInt(params.get(k), 10) || undefined;
        });

        // Booleans
        if (params.get('sl')  === '1') state.sl  = true;
        if (params.get('loc') === '1') state.loc = true;
        if (params.get('cmp') === '1') state.cmp = true;
        if (params.get('std') === '1') state.std = true;

        // Standings data embedded by sharer — array of {from_id, standing}
        if (params.has('std_data')) {
            try {
                state.std_data = decodeURIComponent(params.get('std_data'))
                    .split(',')
                    .map(function(pair) {
                        const parts = pair.split(':');
                        return { from_id: parseInt(parts[0], 10), standing: parseFloat(parts[1]) };
                    })
                    .filter(function(s) { return !isNaN(s.from_id) && !isNaN(s.standing); });
            } catch(_) {}
        }

        // Arrays
        if (params.has('sec')) state.sec = params.get('sec').split(',');
        if (params.has('lv'))  state.lv  = params.get('lv').split(',').map(Number);
        if (params.has('mt'))  state.mt  = params.get('mt').split(',');

        return Object.keys(state).length ? state : null;
    }

    // ── Nearest-to AJAX ───────────────────────────────────────────────────────

    function fetchNearestDistances(systemName) {
        nearestDistances  = null;
        nearestOriginName = '';
        $('#eaf-nearest-status').text('Calculating…').removeClass('eaf-nearest-error eaf-nearest-ok');
        ajaxPost({ action: 'eaf_nearest', system_name: systemName })
            .then(function(resp) {
                nearestDistances  = resp.data.distances;
                nearestOriginName = systemName;
                $('#eaf-nearest-status').text('✓ ' + systemName).addClass('eaf-nearest-ok').removeClass('eaf-nearest-error');
                render();
            })
            .catch(function() {
                nearestDistances  = null;
                nearestOriginName = '';
                $('#eaf-nearest-status').text('System not found').addClass('eaf-nearest-error').removeClass('eaf-nearest-ok');
            });
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    function resetFilters() {
        $('#eaf-search').val('');
        $('#eaf-sec-class input').prop('checked', true);
        $('.eaf-highsec-only').removeClass('eaf-filter-disabled');
        $('#eaf-level-filter input').prop('checked', false);
        $('#eaf-mission-type input').prop('checked', false);
        $('#eaf-division option').prop('selected', false);
        $('#eaf-agent-type').val('');
        $('#eaf-faction, #eaf-corporation').val('');
        $('#eaf-region').val('');
        $('#eaf-min-jumps-range').val(0);
        $('#eaf-min-jumps-val').text('0');
        $('#eaf-min-agents').val(1);
        $('#eaf-min-agents-display').text(1);
        $('#eaf-min-l4-agents').val(0);
        $('#eaf-min-l4-display').text(0);
        $('#eaf-min-l4-scope').data('scope', 'system');
        $('#eaf-min-l4-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
        $('#eaf-min-l4-scope .eaf-scope-pill[data-val="system"]').addClass('eaf-scope-pill-active');
        $('#eaf-min-agents-scope').data('scope', 'system');
        $('#eaf-min-agents-scope .eaf-scope-pill').removeClass('eaf-scope-pill-active');
        $('#eaf-min-agents-scope .eaf-scope-pill[data-val="system"]').addClass('eaf-scope-pill-active');
        $('#eaf-storyline-only').prop('checked', false);
        $('#eaf-locator-only').prop('checked', false);
        $('#eaf-compact-view').prop('checked', false);
        $('#eaf-standings-filter').prop('checked', false);
        $('#eaf-standings-status').text('');
        $('#eaf-sort-by').val('agents_desc');
        sortBy = 'agents_desc';
        nearestDistances  = null;
        nearestOriginName = '';
        $('#eaf-nearest-row').hide();
        $('#eaf-nearest-system').val('');
        $('#eaf-nearest-status').text('').removeClass('eaf-nearest-error eaf-nearest-ok');
        lastSearch = '';
        populateCorpSelect('');
        try { localStorage.removeItem(LS_KEY); } catch(_) {}
        try { history.replaceState(null, '', window.location.pathname + window.location.search); } catch(_) {}
        // Reload from server in case sec_class changed (e.g. was lowsec, now highsec)
        reloadAgents();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    // EVE Online displays security rounded up to the nearest 0.1 for positive values,
    // so a system with true_sec=0.049 shows as 0.1 (lowsec), not 0.0 (nullsec).
    function eveSecRound(sec) {
        if (sec <= 0) return 0;
        return Math.ceil(sec * 10) / 10;
    }

    function fmtSecurity(sec, secClass) {
        if (secClass === 'wormhole') return '—';
        return eveSecRound(sec).toFixed(1);
    }

    function secCssClass(sec, secClass) {
        if (secClass === 'wormhole') return 'sec-wh';
        const r = eveSecRound(sec);
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

    // Converts raw readme.txt changelog text into styled HTML
    function renderChangelog(text) {
        if (!text) return '<p>No changelog available.</p>';
        let html = '';
        let inList = false;
        text.split('\n').forEach(function(line) {
            line = line.trimEnd();
            if (/^= .+ =$/.test(line)) {
                // Version heading: = 1.0.9 =
                if (inList) { html += '</ul>'; inList = false; }
                const ver = line.replace(/^= | =$/g, '');
                html += '<h4 class="eaf-cl-version">' + esc(ver) + '</h4><ul class="eaf-cl-list">';
                inList = true;
            } else if (/^\* /.test(line)) {
                html += '<li>' + esc(line.slice(2)) + '</li>';
            } else if (line.trim() && inList) {
                // Plain text line inside a version block (e.g. "Initial Public Release")
                html += '<li>' + esc(line.trim()) + '</li>';
            }
        });
        if (inList) html += '</ul>';
        return html || '<p>No changelog entries found.</p>';
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
