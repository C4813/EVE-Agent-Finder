/* eve-agent-finder / admin/js/eaf-admin.js
 * Single SDE zip upload + BFS trigger.
 */
jQuery(function ($) {
    'use strict';

    const { ajax_url, nonce } = EAF_Admin;

    // ── ZIP file selection ────────────────────────────────────────────────────

    const $input    = $('#eaf-sde-zip-input');
    const $filename = $('#eaf-zip-filename');
    const $importBtn = $('#eaf-zip-import-btn');
    const $area     = $('#eaf-zip-area');

    $input.on('change', function () {
        const file = this.files[0];
        if (!file) { $filename.text(''); $importBtn.prop('disabled', true); return; }
        $filename.text(file.name);
        $importBtn.prop('disabled', false);
    });

    // Drag and drop
    $area.on('dragover', function(e) {
        e.preventDefault();
        $area.addClass('eaf-drag-over');
    }).on('dragleave drop', function(e) {
        e.preventDefault();
        $area.removeClass('eaf-drag-over');
        if (e.type === 'drop') {
            const file = e.originalEvent.dataTransfer.files[0];
            if (file && file.name.endsWith('.zip')) {
                $filename.text(file.name);
                $importBtn.prop('disabled', false);
                // Store file for upload
                $input.data('drag-file', file);
            }
        }
    });

    // ── Import ────────────────────────────────────────────────────────────────

    $importBtn.on('click', function () {
        const file = $input.data('drag-file') || ($input[0].files && $input[0].files[0]);
        if (!file) return;

        const fd = new FormData();
        fd.append('action',   'eaf_sde_zip_import');
        fd.append('nonce',    nonce);
        fd.append('sde_zip',  file);

        $importBtn.prop('disabled', true).text('Importing…');
        globalStatus('Importing SDE zip…', 'info');

        $.ajax({
            url:         ajax_url,
            type:        'POST',
            data:        fd,
            processData: false,
            contentType: false,
            timeout:     300000,
        })
        .done(function (resp) {
            if (resp.success) {
                showResults(resp.data.results);
                globalStatus('✅ Import complete! Run BFS to update distances.', 'success');
                resetBfsCard();
                // Refresh status cards
                resp.data.results.forEach(function(r) {
                    updateStepCard(r.key, r.success, r.message, r.rows);
                });
            } else {
                globalStatus('❌ ' + (resp.data?.message || 'Import failed.'), 'error');
            }
        })
        .fail(function (xhr) {
            globalStatus('❌ HTTP ' + xhr.status, 'error');
        })
        .always(function () {
            $importBtn.prop('disabled', false).text('⬆ Import SDE');
            $input.data('drag-file', null);
        });
    });

    // ── Results table ─────────────────────────────────────────────────────────

    function showResults(results) {
        const $tbody = $('#eaf-results-tbody').empty();
        results.forEach(function(r) {
            const icon = r.success ? '✅' : '❌';
            $tbody.append(
                '<tr class="' + (r.success ? 'eaf-row-ok' : 'eaf-row-err') + '">' +
                '<td>' + icon + ' ' + escHtml(r.label) + '</td>' +
                '<td>' + escHtml(r.message) + '</td>' +
                '<td>' + (r.rows > 0 ? r.rows.toLocaleString() : '—') + '</td>' +
                '</tr>'
            );
        });
        $('#eaf-import-results').show();
    }

    function updateStepCard(key, success, message, rows) {
        const $card = $('[data-step="' + key + '"]');
        if (!$card.length) return;
        $card.removeClass('pending done error').addClass(success ? 'done' : 'error');
        $card.find('.eaf-step-status').text(success ? '✅' : '❌');
        if (success && rows > 0) {
            $card.find('.eaf-step-meta small').text(rows.toLocaleString() + ' rows · just now');
        }
        $card.find('.eaf-step-log').text(message);
    }

    // ── Drop individual table ─────────────────────────────────────────────────

    $(document).on('click', '.eaf-drop-btn', function () {
        const step  = $(this).data('step');
        const $card = $('[data-step="' + step + '"]');
        if ( ! confirm('Drop the "' + step + '" table? This cannot be undone.') ) return;
        $.post(ajax_url, { action: 'eaf_drop_step', nonce, step })
        .done(function(resp) {
            if (resp.success) {
                $card.removeClass('done error').addClass('pending');
                $card.find('.eaf-step-status').text('⏳');
                $card.find('.eaf-step-meta small').text('Not imported');
                $card.find('.eaf-step-log').text('Table dropped.');
                resetBfsCard();
            } else {
                $card.find('.eaf-step-log').text('⚠ ' + (resp.data?.message || 'Failed.'));
            }
        })
        .fail(function(xhr) {
            $card.find('.eaf-step-log').text('⚠ HTTP ' + xhr.status);
        });
    });

    // ── BFS ───────────────────────────────────────────────────────────────────

    $('#eaf-run-bfs').on('click', function () {
        const $btn  = $(this);
        const $card = $('[data-step="bfs"]');

        $btn.prop('disabled', true);
        setStatus($card, 'Running multi-source BFS…', 'running');
        globalStatus('Running BFS…', 'info');

        $.post(ajax_url, { action: 'eaf_import_bfs', nonce })
        .done(function (resp) {
            if (resp.success) {
                setStatus($card, resp.data.message, 'done');
                globalStatus('✅ BFS complete! Hub finder is ready.', 'success');
            } else {
                setStatus($card, '⚠ ' + (resp.data?.message || ''), 'error');
                globalStatus('❌ BFS failed.', 'error');
            }
        })
        .fail(function (xhr) {
            setStatus($card, '⚠ HTTP ' + xhr.status, 'error');
            globalStatus('❌ BFS failed (HTTP error).', 'error');
        })
        .always(function () { $btn.prop('disabled', false); });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function resetBfsCard() {
        const $bfs = $('[data-step="bfs"]');
        $bfs.removeClass('done error running').addClass('pending');
        $bfs.find('.eaf-step-status').text('⏳');
        $bfs.find('.eaf-step-log').text('Import complete — BFS must be re-run.');
    }

    function setStatus($card, msg, cls) {
        $card.find('.eaf-step-log').text(msg);
        $card.removeClass('pending done error running').addClass(cls || 'running');
        const icon = { done: '✅', error: '❌', running: '⏳' };
        if (icon[cls]) $card.find('.eaf-step-status').text(icon[cls]);
    }

    function globalStatus(msg, cls) {
        $('#eaf-global-status')
            .text(msg)
            .removeClass('success error info')
            .addClass(cls || 'info');
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── SSO settings: copy callback URL button ────────────────────────────────
    $(document).on('click', '.eaf-copy-callback', function() {
        const url = $(this).data('copy');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url);
        } else {
            const ta = document.createElement('textarea');
            ta.value = url;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        const $btn = $(this);
        const orig = $btn.text();
        $btn.text('\u2713 Copied!');
        setTimeout(function() { $btn.text(orig); }, 1800);
    });
});
