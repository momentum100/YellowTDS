import { getFlowDist, redistributeWeights } from './weights.js';
import { buildFolderRow } from './templates.js';

function setDropzoneState(dropzone, state, message) {
    dropzone.dataset.state = state || '';
    dropzone.classList.toggle('is-uploading', state === 'uploading');
    var label = dropzone.querySelector('.flow-zip-dropzone-label');
    if (label) label.textContent = message || 'Drop ZIP here or click to choose';
}

async function parseUploadResponse(response) {
    var body = await response.text();
    var data;

    try {
        data = JSON.parse(body);
    } catch (error) {
        if (response.status === 413) {
            throw new Error('ZIP is larger than the server upload limit.');
        }
        throw new Error('Server returned an invalid response (HTTP ' + response.status + ').');
    }

    if (!response.ok || data.error) {
        throw new Error(data.result || ('Upload failed (HTTP ' + response.status + ').'));
    }
    return data;
}

async function uploadZip(dropzone, file) {
    if (!file || dropzone.dataset.state === 'uploading') return;
    if (!/\.zip$/i.test(file.name)) {
        notify('Please choose a ZIP archive.', 'error');
        return;
    }

    var fi = dropzone.dataset.fi;
    var stepSec = dropzone.closest('.step-section');
    var container = stepSec ? stepSec.querySelector('.flow-step-folder-items') : null;
    if (!fi || !container) {
        notify('Could not find the destination step for this ZIP.', 'error');
        return;
    }

    var suggestedName = file.name.replace(/\.zip$/i, '');
    var folderName = await window.promptDialog('Enter folder name for uploaded files:', suggestedName);
    if (!folderName || !folderName.trim()) return;

    folderName = folderName.trim();
    if (!/^[a-zA-Z0-9_\-\.]+$/.test(folderName)) {
        notify('Invalid folder name. Use only letters, numbers, hyphens, underscores, dots.', 'error');
        return;
    }

    var fd = new FormData();
    fd.append('zipfile', file);
    fd.append('folder', folderName);

    setDropzoneState(dropzone, 'uploading', 'Uploading ' + file.name + '...');
    try {
        var data = await fetch('zipupload.php', { method: 'POST', body: fd }).then(parseUploadResponse);
        var showWeight = getFlowDist(fi) === 'weighted';
        container.appendChild(buildFolderRow(data.folder, showWeight));
        if (showWeight) redistributeWeights(container.querySelectorAll('.flow-step-weight'));
        notify('ZIP uploaded to folder "' + data.folder + '".', 'success');
    } catch (error) {
        notify('Upload failed: ' + error.message, 'error');
    } finally {
        var input = dropzone.querySelector('.flow-zip-input');
        if (input) input.value = '';
        setDropzoneState(dropzone, '', 'Drop ZIP here or click to choose');
    }
}

export function initZipDropzones() {
    document.addEventListener('change', function (event) {
        if (!event.target.classList.contains('flow-zip-input')) return;
        var dropzone = event.target.closest('.flow-zip-dropzone');
        if (dropzone && event.target.files.length) uploadZip(dropzone, event.target.files[0]);
    });

    document.addEventListener('dragover', function (event) {
        var dropzone = event.target.closest('.flow-zip-dropzone');
        if (!dropzone) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        dropzone.classList.add('is-dragover');
    });

    document.addEventListener('dragleave', function (event) {
        var dropzone = event.target.closest('.flow-zip-dropzone');
        if (!dropzone || dropzone.contains(event.relatedTarget)) return;
        dropzone.classList.remove('is-dragover');
    });

    document.addEventListener('drop', function (event) {
        var dropzone = event.target.closest('.flow-zip-dropzone');
        if (!dropzone) return;
        event.preventDefault();
        dropzone.classList.remove('is-dragover');
        if (event.dataTransfer.files.length) uploadZip(dropzone, event.dataTransfer.files[0]);
    });
}
