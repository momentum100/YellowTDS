import { getFlowDist, redistributeWeights, redistributeWeightsAfterDelete } from './weights.js';
import { buildFolderRow, buildRedirectRow, buildFlowSection, buildStepSection, buildStepListRow, renumberSteps, updateStepListInfo, updateAllStepListInfo, updateStepControls } from './templates.js?v=2';
import { openFolderPicker } from './folder-picker.js';

// ── State ──
var flowCounter = 0;

export function initFlowCounter() {
    flowCounter = document.querySelectorAll('.flow-list-row').length;
}

// ═══════════════════════════════════════════
// CHANGE handlers
// ═══════════════════════════════════════════

// ── Toggle step folders/redirects visibility ──
export function handleStepActionChange(e) {
    var sec = e.target.closest('.step-section');
    if (!sec) return;
    var folders = sec.querySelector('.flow-step-folders');
    var redirects = sec.querySelector('.flow-step-redirects');
    if (folders) folders.style.display = e.target.value === 'folder' ? 'block' : 'none';
    if (redirects) redirects.style.display = e.target.value === 'redirect' ? 'block' : 'none';
    updateStepListInfo(sec.dataset.flowIndex, sec.dataset.stepIndex);
    updateStepControls(sec.dataset.flowIndex);
}

// ── Toggle flow-level distribution (thompson opts + weight cols) ──
export function handleDistChange(e) {
    var fi = e.target.dataset.fi;
    var val = e.target.value;
    var opts = document.getElementById('flow-thompson-opts-' + fi);
    if (opts) opts.style.display = val === 'thompson' ? 'block' : 'none';
    // Show weight cols in flow section and all step sections
    var showW = val === 'weighted';
    var sec = document.getElementById('sec-flow-' + fi);
    if (sec) sec.querySelectorAll('.flow-weight-col').forEach(function (col) { col.style.display = showW ? 'block' : 'none'; });
    document.querySelectorAll('.step-section[data-flow-index="' + fi + '"]').forEach(function (stepSec) {
        stepSec.querySelectorAll('.flow-weight-col').forEach(function (col) { col.style.display = showW ? 'block' : 'none'; });
    });
}

// ═══════════════════════════════════════════
// CLICK handlers
// ═══════════════════════════════════════════

// ── Delete step items (folder or redirect row) ──
export function handleRemoveStepItem(e) {
    var removeBtn = e.target.closest('.flow-remove-step-item');
    if (!removeBtn) return;
    var item = removeBtn.closest('.flow-path-item');
    if (!item) return;
    var stepSec = item.closest('.step-section');
    var fi = stepSec ? stepSec.dataset.flowIndex : '';
    var isWeighted = getFlowDist(fi) === 'weighted';
    var removedWeightInp = item.querySelector('input[type="number"]');
    var removedWeight = removedWeightInp ? (parseInt(removedWeightInp.value, 10) || 0) : 0;
    item.remove();
    if (isWeighted && stepSec) {
        var remaining = stepSec.querySelectorAll('.flow-step-weight');
        redistributeWeightsAfterDelete(remaining, removedWeight);
    }
    if (stepSec) updateStepListInfo(stepSec.dataset.flowIndex, stepSec.dataset.stepIndex);
}

// ── Add Existing folder to a step ──
export function handleStepAddExisting(e) {
    var btn = e.target.closest('.flow-step-add-existing');
    if (!btn) return;
    var fi = btn.dataset.fi;
    var stepSec = btn.closest('.step-section');
    if (!stepSec) return;
    var container = stepSec.querySelector('.flow-step-folder-items');
    var showWeight = getFlowDist(fi) === 'weighted';

    btn.disabled = true;
    fetch('listfolders.php').then(function (r) { return r.json(); }).then(function (data) {
        btn.disabled = false;
        if (data.error) { notify(data.result, 'error'); return; }
        if (!data.folders.length) { notify('No folders found. Upload a ZIP first.', 'error'); return; }

        openFolderPicker(data.folders).then(function (choice) {
            if (!choice) return;
            container.appendChild(buildFolderRow(choice, showWeight));
            if (showWeight) {
                redistributeWeights(container.querySelectorAll('.flow-step-weight'));
            }
            updateStepListInfo(fi, stepSec.dataset.stepIndex);
        });
    }).catch(function (err) { btn.disabled = false; notify('Error: ' + err, 'error'); });
}

// ── Add redirect to a step ──
export function handleStepAddRedirect(e) {
    var btn = e.target.closest('.flow-step-add-redirect');
    if (!btn) return;
    var fi = btn.dataset.fi;
    var stepSec = btn.closest('.step-section');
    if (!stepSec) return;
    var container = stepSec.querySelector('.flow-step-redirect-items');
    var showWeight = getFlowDist(fi) === 'weighted';
    container.appendChild(buildRedirectRow(showWeight));
    if (showWeight) {
        redistributeWeights(container.querySelectorAll('.flow-step-weight'));
    }
    updateStepListInfo(fi, stepSec.dataset.stepIndex);
}

// ── Update slug when redirect URL changes ──
export function handleRedirectUrlChange(e) {
    if (!e.target.classList.contains('flow-step-redirect')) return;
    var stepSec = e.target.closest('.step-section');
    if (stepSec) updateStepListInfo(stepSec.dataset.flowIndex, stepSec.dataset.stepIndex);
}

// ── Edit folder ──
export function handleEditFolder(e) {
    var btn = e.target.closest('.flow-edit-folder');
    if (!btn) return;
    var item = btn.closest('.flow-path-item');
    if (!item) return;
    var folderInput = item.querySelector('.flow-step-folder');
    if (!folderInput || !folderInput.value.trim()) {
        notify('Please enter a folder name first.', 'error');
        return;
    }
    if (typeof window.openFileEditor === 'function') {
        window.openFileEditor(folderInput.value.trim());
    } else {
        notify('File editor not loaded.', 'error');
    }
}

// ── Helper: get flow name from flow section ──
function getFlowName(fi) {
    var sec = document.getElementById('sec-flow-' + fi);
    if (!sec) return 'Flow';
    var title = sec.querySelector('.flow-section-title');
    return title ? title.textContent : 'Flow';
}

// ── Add step to a flow ──
export function handleAddStep(e) {
    var btn = e.target.closest('.flow-add-step');
    if (!btn) return;
    var fi = btn.dataset.fi;
    var listContainer = document.getElementById('steps-list-' + fi);
    if (!listContainer) return;
    var si = listContainer.querySelectorAll('.step-list-row').length;
    var flowName = getFlowName(fi);

    // 1. Add step list row
    listContainer.appendChild(buildStepListRow(fi, si));

    // 2. Add sidebar nav item (after last step-nav-item for this flow, or after flow-nav-item)
    var navHtml = '<li class="step-nav-item" data-flow-index="' + fi + '" data-step-index="' + si + '"><a href="#sec-step-' + fi + '-' + si + '">&nbsp;&nbsp;&nbsp;&nbsp;Step ' + (si + 1) + '</a></li>';
    var existingStepNavs = document.querySelectorAll('.step-nav-item[data-flow-index="' + fi + '"]');
    if (existingStepNavs.length > 0) {
        existingStepNavs[existingStepNavs.length - 1].insertAdjacentHTML('afterend', navHtml);
    } else {
        var flowNav = document.querySelector('.flow-nav-item[data-flow-index="' + fi + '"]');
        if (flowNav) flowNav.insertAdjacentHTML('afterend', navHtml);
    }

    // 3. Add step section (before sec-scripts or next flow section)
    var sectionFrag = buildStepSection(fi, si, flowName);
    var nextSection = findInsertPointForStep(fi);
    if (nextSection) {
        nextSection.parentNode.insertBefore(sectionFrag, nextSection);
    } else {
        document.querySelector('.camp-content').appendChild(sectionFrag);
    }

    // 4. Renumber and update toggles
    renumberSteps(fi);

    // 5. Navigate to new step
    if (window.showSection) window.showSection('sec-step-' + fi + '-' + si);
}

// ── Find where to insert a new step section for a given flow ──
function findInsertPointForStep(fi) {
    // Find the last step section for this flow
    var stepSecs = document.querySelectorAll('.step-section[data-flow-index="' + fi + '"]');
    if (stepSecs.length > 0) {
        return stepSecs[stepSecs.length - 1].nextElementSibling;
    }
    // Otherwise insert after the flow section itself
    var flowSec = document.getElementById('sec-flow-' + fi);
    if (flowSec) return flowSec.nextElementSibling;
    return document.getElementById('sec-scripts');
}

// ── Remove step from a flow ──
export function handleRemoveStep(e) {
    var btn = e.target.closest('.flow-remove-step');
    if (!btn) return;
    // Could be clicked from step-list-row or from within a step-section
    var listRow = btn.closest('.step-list-row');
    var fi, si;
    if (listRow) {
        fi = listRow.dataset.flowIndex;
        si = listRow.dataset.stepIndex;
    } else {
        return;
    }

    // Remove step section
    var stepSec = document.getElementById('sec-step-' + fi + '-' + si);
    if (stepSec) stepSec.remove();

    // Remove nav item
    var navItem = document.querySelector('.step-nav-item[data-flow-index="' + fi + '"][data-step-index="' + si + '"]');
    if (navItem) navItem.remove();

    // Remove list row
    listRow.remove();

    // Renumber
    renumberSteps(fi);
}

// ── Move step up ──
export function handleMoveStepUp(e) {
    var btn = e.target.closest('.flow-move-step-up');
    if (!btn) return;
    var listRow = btn.closest('.step-list-row');
    if (!listRow) return;
    var prev = listRow.previousElementSibling;
    if (!prev || !prev.classList.contains('step-list-row')) return;

    var fi = listRow.dataset.flowIndex;
    var si = parseInt(listRow.dataset.stepIndex, 10);
    var prevSi = parseInt(prev.dataset.stepIndex, 10);

    // Swap list rows
    listRow.parentNode.insertBefore(listRow, prev);

    // Swap step sections
    var secA = document.getElementById('sec-step-' + fi + '-' + si);
    var secB = document.getElementById('sec-step-' + fi + '-' + prevSi);
    if (secA && secB) secA.parentNode.insertBefore(secA, secB);

    // Swap nav items
    var navA = document.querySelector('.step-nav-item[data-flow-index="' + fi + '"][data-step-index="' + si + '"]');
    var navB = document.querySelector('.step-nav-item[data-flow-index="' + fi + '"][data-step-index="' + prevSi + '"]');
    if (navA && navB) navA.parentNode.insertBefore(navA, navB);

    renumberSteps(fi);
}

// ── Move step down ──
export function handleMoveStepDown(e) {
    var btn = e.target.closest('.flow-move-step-down');
    if (!btn) return;
    var listRow = btn.closest('.step-list-row');
    if (!listRow) return;
    var next = listRow.nextElementSibling;
    if (!next || !next.classList.contains('step-list-row')) return;

    var fi = listRow.dataset.flowIndex;
    var si = parseInt(listRow.dataset.stepIndex, 10);
    var nextSi = parseInt(next.dataset.stepIndex, 10);

    // Swap list rows
    listRow.parentNode.insertBefore(next, listRow);

    // Swap step sections
    var secA = document.getElementById('sec-step-' + fi + '-' + si);
    var secB = document.getElementById('sec-step-' + fi + '-' + nextSi);
    if (secA && secB) secB.parentNode.insertBefore(secB, secA);

    // Swap nav items
    var navA = document.querySelector('.step-nav-item[data-flow-index="' + fi + '"][data-step-index="' + si + '"]');
    var navB = document.querySelector('.step-nav-item[data-flow-index="' + fi + '"][data-step-index="' + nextSi + '"]');
    if (navA && navB) navB.parentNode.insertBefore(navB, navA);

    renumberSteps(fi);
}

// ── Flow list: Move Up ──
export function handleMoveUp(e) {
    var btn = e.target.closest('.flow-move-up');
    if (!btn) return;
    var row = btn.closest('.flow-list-row');
    var prev = row.previousElementSibling;
    if (prev && prev.classList.contains('flow-list-row')) {
        row.parentNode.insertBefore(row, prev);
    }
}

// ── Flow list: Move Down ──
export function handleMoveDown(e) {
    var btn = e.target.closest('.flow-move-down');
    if (!btn) return;
    var row = btn.closest('.flow-list-row');
    var next = row.nextElementSibling;
    if (next && next.classList.contains('flow-list-row')) {
        row.parentNode.insertBefore(next, row);
    }
}

// ── Flow list: Delete ──
export async function handleDeleteFlow(e) {
    var btn = e.target.closest('.flow-delete');
    if (!btn) return;
    if (!(await window.confirmDialog('Delete this flow?'))) return;
    var row = btn.closest('.flow-list-row');
    var fi = row.dataset.flowIndex;
    if (window.showSection) window.showSection('sec-flows');
    history.replaceState(null, '', '#sec-flows');
    // Remove all step sections for this flow
    document.querySelectorAll('.step-section[data-flow-index="' + fi + '"]').forEach(function (s) { s.remove(); });
    // Remove all step nav items for this flow
    document.querySelectorAll('.step-nav-item[data-flow-index="' + fi + '"]').forEach(function (s) { s.remove(); });
    // Remove flow section
    var sec = document.getElementById('sec-flow-' + fi);
    if (sec) sec.remove();
    // Remove flow sidebar nav item
    var nav = document.querySelector('.flow-nav-item[data-flow-index="' + fi + '"]');
    if (nav) nav.remove();
    // Remove list row
    row.remove();
}

// ── Add Flow ──
export async function handleAddFlow() {
    var flowName = await window.promptDialog('Enter stream name:');
    if (!flowName || !flowName.trim()) return;
    flowName = flowName.trim();

    // Validate uniqueness
    var existing = document.querySelectorAll('.flow-name-label');
    for (var i = 0; i < existing.length; i++) {
        if (existing[i].value === flowName) {
            notify('Flow name "' + flowName + '" already exists. Choose a different name.', 'error');
            return;
        }
    }

    var fi = 'new_' + flowCounter;
    flowCounter++;

    // 1. Add list row
    var rowHtml = '<div class="flow-list-row" data-flow-index="' + fi + '" data-stream-id="">' +
        '<span class="flow-drag-handle" title="Drag to reorder">☰</span>' +
        '<label class="flow-enabled-toggle"><input type="checkbox" class="flow-enabled" checked> On</label>' +
        '<select class="form-select flow-type"><option value="forced">Forced</option><option value="regular" selected>Regular</option></select>' +
        '<input type="text" class="form-control flow-name-label" value="' + flowName.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;') + '" /> ' +
        '<span class="flow-row-stats">0 / 0 / 0</span>' +
        '<code class="flow-short-id">new</code>' +
        '<button type="button" class="btn btn-primary btn-sm flow-edit-stream">Edit</button>' +
        '<a href="javascript:void(0)" class="btn btn-danger btn-sm flow-delete" title="Delete"><i class="bi bi-trash"></i></a>' +
        '</div>';
    var flowsList = document.getElementById('flows-list');
    var defaultRow = flowsList.querySelector('.flow-default-row');
    if (defaultRow) defaultRow.insertAdjacentHTML('beforebegin', rowHtml);
    else flowsList.insertAdjacentHTML('beforeend', rowHtml);

    // 2. Add sidebar nav item (after last step-nav-item or flow-nav-item, or after sec-flows)
    var navHtml = '<li class="flow-nav-item" data-flow-index="' + fi + '"><a href="#sec-flow-' + fi + '">&nbsp;&nbsp;' + flowName + '</a></li>';
    var defaultNav = defaultRow ? document.querySelector('.flow-nav-item[data-flow-index="' + defaultRow.dataset.flowIndex + '"]') : null;
    var allFlowNavs = document.querySelectorAll('.flow-nav-item');
    if (defaultNav) {
        defaultNav.insertAdjacentHTML('beforebegin', navHtml);
    } else if (allFlowNavs.length > 0) {
        var lastFlowNav = allFlowNavs[allFlowNavs.length - 1];
        var lastStepNavs = document.querySelectorAll('.step-nav-item[data-flow-index="' + lastFlowNav.dataset.flowIndex + '"]');
        var insertAfter = lastStepNavs.length ? lastStepNavs[lastStepNavs.length - 1] : lastFlowNav;
        insertAfter.insertAdjacentHTML('afterend', navHtml);
    } else {
        var flowsNavLink = document.querySelector('a[href="#sec-flows"]');
        if (flowsNavLink) flowsNavLink.closest('li').insertAdjacentHTML('afterend', navHtml);
    }

    // 3. Add flow section from template
    var sectionFrag = buildFlowSection(fi, flowName);

    // Insert section before sec-scripts (or at end of camp-content)
    var defaultSection = defaultRow ? document.getElementById('sec-flow-' + defaultRow.dataset.flowIndex) : null;
    var scriptsSection = document.getElementById('sec-scripts');
    if (defaultSection) {
        defaultSection.parentNode.insertBefore(sectionFrag, defaultSection);
    } else if (scriptsSection) {
        scriptsSection.parentNode.insertBefore(sectionFrag, scriptsSection);
    } else {
        document.querySelector('.camp-content').appendChild(sectionFrag);
    }

    // 4. Init QueryBuilder for the new flow's filters
    if (typeof $ !== 'undefined' && typeof $.fn.queryBuilder !== 'undefined') {
        try {
            $('#flow-filters-' + fi).queryBuilder({
                operators: $.fn.queryBuilder.constructor.DEFAULTS.operators.concat(typeof paramOperators !== 'undefined' ? paramOperators : []),
                filters: typeof tdsFilters !== 'undefined' ? tdsFilters : []
            });
        } catch (e) { console.warn('Could not init QueryBuilder for flow ' + fi, e); }
    }

    // 5. Navigate to the new flow section
    if (window.showSection) window.showSection('sec-flow-' + fi);
    history.replaceState(null, '', '#sec-flow-' + fi);
}
