import { collectFlowsData } from './collectors.js?v=2';
import { openFolderPicker } from './folder-picker.js';
import {
    initFlowCounter,
    handleStepActionChange,
    handleDistChange,
    handleRemoveStepItem,
    handleStepAddExisting,
    handleStepAddRedirect,
    handleEditFolder,
    handleAddStep,
    handleRemoveStep,
    handleMoveStepUp,
    handleMoveStepDown,
    handleMoveUp,
    handleMoveDown,
    handleDeleteFlow,
    handleAddFlow,
    handleRedirectUrlChange
} from './handlers.js?v=5';
import { initZipDropzones } from './zip-upload.js?v=4';

// ── Window exports for backward compat with inline scripts ──
window.collectFlowsData = collectFlowsData;
window.openFolderPicker = openFolderPicker;
window.reconcileSavedFlows = function (savedFlows) {
    var rows = Array.from(document.querySelectorAll('#flows-list .flow-list-row'));
    savedFlows.forEach(function (flow, index) {
        var row = rows[index];
        if (!row) return;
        row.dataset.streamId = flow.id || '';
        var id = row.querySelector('.flow-short-id');
        if (id) id.textContent = flow.id || 'new';
    });
};

// ── Event dispatch maps ──
var clickSelectors = [
    { sel: '.flow-remove-step-item', fn: handleRemoveStepItem },
    { sel: '.flow-step-add-existing', fn: handleStepAddExisting },
    { sel: '.flow-step-add-redirect', fn: handleStepAddRedirect },
    { sel: '.flow-edit-folder', fn: handleEditFolder },
    { sel: '.flow-add-step', fn: handleAddStep },
    { sel: '.flow-remove-step', fn: handleRemoveStep },
    { sel: '.flow-move-step-up', fn: handleMoveStepUp },
    { sel: '.flow-move-step-down', fn: handleMoveStepDown },
    { sel: '.flow-move-up', fn: handleMoveUp },
    { sel: '.flow-move-down', fn: handleMoveDown },
    { sel: '.flow-delete', fn: handleDeleteFlow }
];

var changeSelectors = [
    { sel: '.flow-step-action', fn: handleStepActionChange },
    { sel: '.flow-dist', fn: handleDistChange }
];

// ── Single delegated click listener ──
document.addEventListener('click', function (e) {
    for (var i = 0; i < clickSelectors.length; i++) {
        if (e.target.closest(clickSelectors[i].sel)) {
            clickSelectors[i].fn(e);
            return;
        }
    }
});

// ── Single delegated change listener ──
document.addEventListener('change', function (e) {
    for (var i = 0; i < changeSelectors.length; i++) {
        if (e.target.matches(changeSelectors[i].sel)) {
            changeSelectors[i].fn(e);
            return;
        }
    }
});

// ── Delegated input listener (for redirect URL slug updates) ──
document.addEventListener('input', function (e) {
    if (e.target.classList.contains('flow-step-redirect')) {
        handleRedirectUrlChange(e);
    }
});

// ── Init ──
initFlowCounter();
initZipDropzones();

// ── Add Flow button ──
var addFlowBtn = document.getElementById('add-flow-btn');
if (addFlowBtn) addFlowBtn.addEventListener('click', handleAddFlow);

var flowsList = document.getElementById('flows-list');
if (flowsList && typeof Sortable !== 'undefined') {
    new Sortable(flowsList, {
        animation: 150,
        handle: '.flow-drag-handle',
        draggable: '.flow-list-row:not(.flow-default-row)',
        onEnd: function () {
            var defaultRow = flowsList.querySelector('.flow-default-row');
            if (defaultRow) flowsList.appendChild(defaultRow);
        }
    });
}
if (flowsList) flowsList.addEventListener('click', function (event) {
    var edit = event.target.closest('.flow-edit-stream');
    if (!edit) return;
    var row = edit.closest('.flow-list-row');
    if (row && window.showSection) window.showSection('sec-flow-' + row.dataset.flowIndex);
});
if (flowsList) flowsList.addEventListener('input', function (event) {
    if (!event.target.classList.contains('flow-name-label')) return;
    var row = event.target.closest('.flow-list-row');
    if (!row) return;
    var fi = row.dataset.flowIndex;
    var name = event.target.value.trim() || 'Flow';
    var navLink = document.querySelector('.flow-nav-item[data-flow-index="' + fi + '"] a');
    if (navLink) navLink.textContent = '\u00a0\u00a0' + name;
    var flowSection = document.getElementById('sec-flow-' + fi);
    var title = flowSection ? flowSection.querySelector('.flow-section-title') : null;
    if (title) title.textContent = name;
    document.querySelectorAll('.step-section[data-flow-index="' + fi + '"]').forEach(function (section, index) {
        var stepTitle = section.querySelector('.flow-section-title');
        if (stepTitle) stepTitle.textContent = name + ' › Step ' + (index + 1);
    });
});

var migrateBtn = document.getElementById('migrate-streams-btn');
if (migrateBtn) migrateBtn.addEventListener('click', async function () {
    if (!(await window.confirmDialog('Upgrade this campaign to unified streams? A Default stream will catch all unmatched traffic.'))) return;
    var campId = new URLSearchParams(window.location.search).get('campId');
    try {
        var response = await fetch('campeditor.php?action=migratestreams&campId=' + encodeURIComponent(campId), {method: 'POST'});
        var data = await response.json();
        if (!response.ok || data.error) throw new Error(data.result || 'Migration failed');
        window.location.reload();
    } catch (error) {
        window.notify(error.message, 'error');
    }
});
