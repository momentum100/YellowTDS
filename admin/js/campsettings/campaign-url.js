var editor = document.querySelector('.campaign-url-editor');

if (editor) {
    var slugInput = document.getElementById('campaign-public-slug');
    var urlInput = document.getElementById('campaign-public-url');
    var hiddenInput = document.querySelector('#campsettings input[name="publicid"]');
    var openLink = document.getElementById('open-campaign-url');
    var copyButton = document.getElementById('copy-campaign-url');
    var saveButton = document.getElementById('save-campaign-slug');
    var error = document.getElementById('campaign-slug-error');
    var aliasesContainer = document.getElementById('campaign-url-aliases');
    var baseUrl = editor.dataset.baseUrl || '';
    var reserved = ['api', 'js', 'caching', 'thankyou', '__dl', 'admin'];
    var savedSlug = slugInput.value.trim();
    var isSaving = false;

    function normalizeSlug(value) {
        return value.toLowerCase()
            .trim()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '')
            .slice(0, 63)
            .replace(/-$/g, '');
    }

    function isValidSlug(value) {
        return /^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/.test(value) && reserved.indexOf(value) === -1;
    }

    function syncCampaignUrl() {
        var slug = slugInput.value.trim();
        var valid = isValidSlug(slug);
        var dirty = valid && slug !== savedSlug;
        var url = baseUrl + slug + '/';
        urlInput.value = url;
        urlInput.title = url;
        openLink.href = valid && !dirty ? url : '#';
        openLink.classList.toggle('disabled', !valid || dirty || isSaving);
        openLink.setAttribute('aria-disabled', (!valid || dirty || isSaving) ? 'true' : 'false');
        copyButton.disabled = !valid || dirty || isSaving;
        saveButton.disabled = !valid || !dirty || isSaving;
        hiddenInput.value = slug;
        slugInput.classList.toggle('is-invalid', !valid);
        error.classList.toggle('is-dirty', dirty);
        error.textContent = valid
            ? (dirty ? 'Unsaved campaign link.' : '')
            : 'Use 3-63 lowercase letters, numbers or hyphens.';
        return valid;
    }

    function renderAliases(aliases) {
        aliasesContainer.innerHTML = '';
        if (!Array.isArray(aliases) || aliases.length === 0) return;
        var title = document.createElement('div');
        title.className = 'campaign-aliases-title';
        title.textContent = 'Previous links';
        aliasesContainer.appendChild(title);
        aliases.forEach(function (alias) {
            var link = document.createElement('a');
            link.href = baseUrl + alias + '/';
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = '/' + alias + '/';
            link.title = link.href;
            aliasesContainer.appendChild(link);
        });
    }

    slugInput.addEventListener('input', syncCampaignUrl);
    slugInput.addEventListener('blur', function () {
        slugInput.value = normalizeSlug(slugInput.value);
        syncCampaignUrl();
    });
    slugInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        event.stopPropagation();
        saveCampaignSlug();
    });

    async function saveCampaignSlug() {
        if (isSaving) return;
        slugInput.value = normalizeSlug(slugInput.value);
        if (!syncCampaignUrl()) {
            slugInput.focus();
            notify('Enter a valid campaign URL slug.', 'error');
            return;
        }
        if (slugInput.value.trim() === savedSlug) return;

        var campId = new URLSearchParams(window.location.search).get('campId');
        if (!campId) {
            notify('Campaign ID is missing.', 'error');
            return;
        }

        isSaving = true;
        saveButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving';
        syncCampaignUrl();
        try {
            var response = await fetch('campeditor.php?action=save&campId=' + encodeURIComponent(campId), {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({publicid: slugInput.value.trim()})
            });
            var result = await response.json();
            if (!response.ok || result.error) {
                throw new Error(result.result || 'Could not save campaign link.');
            }
            window.reconcileCampaignRoute(result.publicid, result.publicidaliases || []);
            notify('Campaign link saved.', 'success');
        } catch (saveError) {
            notify(saveError.message || 'Could not save campaign link.', 'error');
        } finally {
            isSaving = false;
            saveButton.innerHTML = '<i class="bi bi-check-lg"></i> Save';
            syncCampaignUrl();
        }
    }

    saveButton.addEventListener('click', saveCampaignSlug);

    openLink.addEventListener('click', function (event) {
        if (openLink.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
        }
    });

    copyButton.addEventListener('click', async function () {
        if (!syncCampaignUrl()) {
            slugInput.focus();
            return;
        }
        try {
            await navigator.clipboard.writeText(urlInput.value);
            notify('Campaign URL copied.', 'success');
        } catch (copyError) {
            urlInput.select();
            document.execCommand('copy');
            notify('Campaign URL copied.', 'success');
        }
    });

    document.getElementById('campsettings').addEventListener('submit', function (event) {
        slugInput.value = normalizeSlug(slugInput.value);
        if (!syncCampaignUrl()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            slugInput.focus();
            notify('Enter a valid campaign URL slug.', 'error');
        }
    }, true);

    window.reconcileCampaignRoute = function (publicId, aliases) {
        if (typeof publicId === 'string' && publicId !== '') {
            savedSlug = publicId;
            slugInput.value = publicId;
            syncCampaignUrl();
        }
        renderAliases(aliases);
    };

    syncCampaignUrl();
    try {
        renderAliases(JSON.parse(editor.dataset.aliases || '[]'));
    } catch (parseError) {
        renderAliases([]);
    }
}
