/*
 * Abilities Hub: enable/disable a single row over AJAX so toggling never
 * reloads the page and the open sections stay open. Progressive enhancement:
 * if the script never runs, the row's plain <form> POST still works.
 *
 * On a failed request (network error, or a nonce that expired after hours on
 * the page) we reload instead of retrying the form — the rendered form carries
 * the same stale nonce, so only a fresh page yields a usable one. This is rare;
 * the cost is losing the open sections, which beats a dead "link expired" wall.
 */
(function () {
    'use strict';

    var hub = document.querySelector('.wppilot-hub');
    if (!hub || typeof window.ajaxurl !== 'string') {
        return;
    }

    hub.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.wppilot-hub-actions form')) {
            return;
        }

        var actionInput = form.querySelector('input[name="wppilot_ability_hub_action"]');
        if (!actionInput || actionInput.value !== 'toggle_disabled') {
            return;
        }

        var row = form.closest('.wppilot-hub-row');
        var button = form.querySelector('button');
        var nonce = form.querySelector('input[name="_wpnonce"]');
        var abilityName = form.querySelector('input[name="ability_name"]');
        if (!row || !button || !nonce || !abilityName) {
            return; // Let the native submit handle it.
        }

        event.preventDefault();

        var body = new URLSearchParams();
        body.set('action', 'wppilot_toggle_ability');
        body.set('_wpnonce', nonce.value);
        body.set('ability_name', abilityName.value);

        button.disabled = true;
        row.classList.add('is-busy');

        fetch(window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (payload) {
                if (!payload || payload.success !== true || !payload.data) {
                    throw new Error('request refused');
                }
                applyToggle(row, button, payload.data);
                button.disabled = false;
                row.classList.remove('is-busy');
            })
            .catch(function () {
                // See the file header: reload to recover with a fresh nonce.
                window.location.reload();
            });
    });

    // "Select all" in a provider/category header toggles every row checkbox
    // within that section. Kept separate from the <details> open/close: clicking
    // the checkbox must not expand or collapse the section.
    hub.addEventListener('click', function (event) {
        if (event.target instanceof HTMLElement && event.target.closest('.wppilot-hub-select-all')) {
            event.stopPropagation();
        }
    });

    hub.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        // A header "select all" sets every row checkbox in its section...
        if (input.classList.contains('wppilot-hub-select-all-input')) {
            var section = input.closest('.wppilot-hub-section, .wppilot-hub-subsection');
            if (section) {
                section.querySelectorAll('.wppilot-hub-row input[type="checkbox"]').forEach(function (box) {
                    box.checked = input.checked;
                });
            }
            syncSelectAllStates();
            return;
        }

        // ...and a single row checkbox feeds back into its headers.
        if (input.closest('.wppilot-hub-row')) {
            syncSelectAllStates();
        }
    });

    // Reflect each section's selection in its header checkbox: checked when all
    // rows are selected, indeterminate when only some, unchecked when none.
    function syncSelectAllStates() {
        hub.querySelectorAll('.wppilot-hub-select-all-input').forEach(function (selectAll) {
            var section = selectAll.closest('.wppilot-hub-section, .wppilot-hub-subsection');
            if (!section) {
                return;
            }
            var boxes = section.querySelectorAll('.wppilot-hub-row input[type="checkbox"]');
            var checked = 0;
            boxes.forEach(function (box) {
                if (box.checked) {
                    checked++;
                }
            });
            selectAll.checked = checked > 0 && checked === boxes.length;
            selectAll.indeterminate = checked > 0 && checked < boxes.length;
        });
    }

    // One confirmation before a bulk *disable*, however many rows are selected
    // (one group or all of them) — the bulk form submits once, so this is a
    // single prompt, never one per row. Enabling needs no confirmation.
    var bulkForm = document.getElementById('wppilot-abilities-bulk');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
            if (selectedBulkAction() !== 'disable') {
                return;
            }
            var count = hub.querySelectorAll('.wppilot-hub-row input[type="checkbox"]:checked').length;
            if (count === 0) {
                return;
            }
            var template = hub.getAttribute('data-confirm-disable') || 'Disable the %d selected abilities?';
            if (!window.confirm(template.replace('%d', String(count)))) {
                event.preventDefault();
            }
        });
    }

    // Mirror the server's choice between the top and bottom bulk selectors.
    function selectedBulkAction() {
        var top = document.getElementById('wppilot-bulk-action-selector-top');
        var bottom = document.getElementById('wppilot-bulk-action-selector-bottom');
        var topValue = top ? top.value : '-1';
        if (topValue !== '-1' && topValue !== '') {
            return topValue;
        }
        return bottom ? bottom.value : '-1';
    }

    function applyToggle(row, button, data) {
        var disabled = data.disabled === true;
        row.classList.toggle('is-off', disabled);
        row.classList.toggle('is-on', !disabled);

        var pill = row.querySelector('.pill.status');
        if (pill && typeof data.status === 'string') {
            pill.classList.toggle('is-disabled', disabled);
            pill.classList.toggle('is-enabled', !disabled);
            pill.textContent = data.status;
        }
        if (typeof data.button === 'string') {
            button.textContent = data.button;
        }

        // Keep the enclosing category and provider headers in sync.
        var subsection = row.closest('.wppilot-hub-subsection');
        if (subsection) {
            refreshSectionMeta(subsection);
        }
        var section = row.closest('.wppilot-hub-section');
        if (section) {
            refreshSectionMeta(section);
        }
    }

    // Recompute a section header's `enabled / total` count and toggle its
    // "All disabled" pill, mirroring wppilot_render_ability_header_meta() in PHP.
    function refreshSectionMeta(section) {
        var summary = section.querySelector(':scope > summary');
        if (!summary) {
            return;
        }
        var rows = section.querySelectorAll('.wppilot-hub-row');
        var total = rows.length;
        var enabled = 0;
        rows.forEach(function (r) {
            if (!r.classList.contains('is-off')) {
                enabled++;
            }
        });

        var count = summary.querySelector('.count');
        if (count) {
            count.textContent = enabled === total ? String(total) : enabled + ' / ' + total;
        }

        var heading = summary.querySelector('h2, h3');
        var pill = summary.querySelector('.wppilot-hub-alloff');
        if (enabled === 0 && total > 0) {
            if (!pill && heading) {
                pill = document.createElement('span');
                pill.className = 'pill status is-disabled wppilot-hub-alloff';
                pill.textContent = hub.getAttribute('data-alloff-label') || 'All disabled';
                heading.appendChild(pill);
            }
        } else if (pill) {
            pill.remove();
        }
    }
})();
