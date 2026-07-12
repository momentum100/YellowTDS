var editor = document.querySelector('.campaign-url-editor');

if (editor) {
    var slugInput = document.getElementById('campaign-public-slug');
    var urlInput = document.getElementById('campaign-public-url');
    var hiddenInput = document.querySelector('#campsettings input[name="publicid"]');
    var openLink = document.getElementById('open-campaign-url');
    var copyButton = document.getElementById('copy-campaign-url');
    var error = document.getElementById('campaign-slug-error');
    var aliasesContainer = document.getElementById('campaign-url-aliases');
    var baseUrl = editor.dataset.baseUrl || '';
    var reserved = ['api', 'js', 'caching', 'thankyou', '__dl', 'admin'];

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
        var url = baseUrl + slug + '/';
        urlInput.value = url;
        urlInput.title = url;
        openLink.href = valid ? url : '#';
        hiddenInput.value = slug;
        slugInput.classList.toggle('is-invalid', !valid);
        error.textContent = valid ? '' : 'Use 3-63 lowercase letters, numbers or hyphens.';
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
