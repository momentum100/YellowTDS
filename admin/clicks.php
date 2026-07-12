<?php
require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/tablecolumns.php';
require_once __DIR__ . '/dates.php';

global $db;
if (isset($_GET['campId'])) {
    require_once __DIR__ . '/campinit.php';
    global $c, $campId;
    $tz = $c->statistics->timezone;

} else {
    require_once __DIR__ .'/../db/db.php';
    $gs = $db->get_common_settings();
    $tz = $gs['statistics']['timezone'];
    $campId = null;
}

$timeRange = Dates::get_time_range($tz);
$startDate = $timeRange[0];
$endDate = $timeRange[1];

$view = $_GET['view'] ?? 'allowed';
$allowedViews = ['allowed', 'blocked', 'leads', 'trafficback'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'allowed';
}
$unifiedStreams = isset($c) && $c->usesUnifiedStreams();
if ($unifiedStreams && $view !== 'trafficback') {
    $view = 'allowed';
}
$defaults = json_decode(file_get_contents(__DIR__ . '/../db/default.json'), true)['statistics'] ?? [];
switch ($view) {
    case 'trafficback':
        $tableColumns = $gs['statistics']['trafficBack'];
        break;
    case 'leads':
        $tableColumns = $c->statistics->leads;
        if (empty($tableColumns)) $tableColumns = $defaults['leads'] ?? [];
        break;
    case 'blocked':
        $tableColumns = $c->statistics->blocked;
        if (empty($tableColumns)) $tableColumns = $defaults['blocked'] ?? [];
        break;
    default:
        $tableColumns = $c->statistics->allowed;
        if (empty($tableColumns)) $tableColumns = $defaults['allowed'] ?? [];
        break;
}

$tName = empty($view) ? 'allowed' : $view;
$tColumns = Tabulator::get_clicks_columns($campId, $tz, $tableColumns);

// Load saved filters for this table type
$filterKeyMap = ['allowed' => 'allowedFilters', 'blocked' => 'blockedFilters', 'leads' => 'leadsFilters', 'trafficback' => 'trafficBackFilters'];
$filterKey = $filterKeyMap[$view] ?? 'allowedFilters';
if ($view === 'trafficback') {
    $savedFilters = $gs['statistics'][$filterKey] ?? [];
} else {
    $s = $db->get_campaign_settings($campId);
    $savedFilters = $s['statistics'][$filterKey] ?? [];
}
$hasActiveFilters = !empty($savedFilters) && !empty($savedFilters['rules']);
$searchTerm = trim((string)($_GET['search'] ?? ''));
$showSearch = true;
$utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
$utmFilters = [];
foreach ($utmKeys as $utmKey) {
    $utmFilters[$utmKey] = trim((string)($_GET[$utmKey] ?? ''));
}
$hasUtmFilters = count(array_filter($utmFilters, fn($value) => $value !== '')) > 0;

$ajaxParams = ['view' => $view];
if ($campId !== null) $ajaxParams['campId'] = $campId;
if ($searchTerm !== '') $ajaxParams['search'] = $searchTerm;
foreach ($utmFilters as $utmKey => $utmValue) {
    if ($utmValue !== '') $ajaxParams[$utmKey] = $utmValue;
}
if (isset($_GET['startdate'])) $ajaxParams['startdate'] = $_GET['startdate'];
if (isset($_GET['enddate'])) $ajaxParams['enddate'] = $_GET['enddate'];
$ajaxUrl = 'clicksdata.php?' . http_build_query($ajaxParams);
?>

<!doctype html>
<html lang="en">
<?php include "head.php" ?>
<body>
    <?php include "header.php" ?>
    <div class="all-content-wrapper">
        <div class="buttons-block" style="display: flex; justify-content: space-between; align-items: center;">
            <div style="display:flex; align-items:flex-end; gap: 12px;">
            <?php if ($view !== 'trafficback' && !$unifiedStreams):?>
                <span style="display: inline-block;">
                    <label for="viewSelector" style="color: white; font-weight: bold;">View:</label>
                    <select id="viewSelector" class="form-select" style="width: 140px; display: inline-block; margin-left: 10px;">
                        <option value="allowed"<?= $view == 'allowed' ? ' selected' : '' ?>>Allowed</option>
                        <option value="blocked"<?= $view == 'blocked' ? ' selected' : '' ?>>Blocked</option>
                        <option value="leads"<?= $view == 'leads' ? ' selected' : '' ?>>Leads</option>
                    </select>
                </span>
            <?php endif; ?>
            <?php if ($showSearch): ?>
                <form id="idSearchForm" method="get" action="clicks.php" style="display:inline-flex; align-items:flex-end; gap:8px; margin-left: 8px;">
                    <?php if ($campId !== null): ?>
                        <input type="hidden" name="campId" value="<?= (int)$campId ?>">
                    <?php endif; ?>
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
                    <?php if (isset($_GET['startdate'])): ?>
                        <input type="hidden" name="startdate" value="<?= htmlspecialchars((string)$_GET['startdate'], ENT_QUOTES) ?>">
                    <?php endif; ?>
                    <?php if (isset($_GET['enddate'])): ?>
                        <input type="hidden" name="enddate" value="<?= htmlspecialchars((string)$_GET['enddate'], ENT_QUOTES) ?>">
                    <?php endif; ?>
                    <span style="display:inline-flex; align-items:center; gap:6px;">
                        <label for="idSearchInput" style="color: white; font-weight: bold; margin-bottom:0;">Search:</label>
                        <input
                            id="idSearchInput"
                            name="search"
                            type="text"
                            class="form-control"
                            value="<?= htmlspecialchars($searchTerm, ENT_QUOTES) ?>"
                            placeholder="IP / Click ID / URL parameter"
                            style="width: 290px;">
                    </span>
                </form>
            <?php endif; ?>
            </div>
            <div>
                <button id="clickFiltersOpen" type="button" class="btn <?=$hasActiveFilters ? 'btn-primary' : 'btn-info'?>">
                    <i class="bi bi-funnel-fill"></i> Filters<?=$hasActiveFilters ? ' · ' . count($savedFilters['rules']) : ''?>
                </button>
                <button id="resetFilters" title="Reset all filters" class="btn btn-outline-danger" style="margin-left: 8px;<?= $hasActiveFilters ? '' : ' display:none;' ?>"><i
                        class="bi bi-funnel"></i> Reset Filters</button>
                <button id="columnsSelect" title="Select and order columns" class="btn btn-info" style="margin-left: 8px;"><i
                        class="bi bi-layout-three-columns"></i></button>
                <button id="downloadCsv" title="Download table as XLSX" class="btn btn-success" style="margin-left: 8px;"><i
                        class="bi bi-download"></i></button>
            </div>
        </div>
        <div class="tabulator-scroll-wrapper">
        <div id="t<?=$tName?>" style="clear: both;"></div>
        </div>
        <script>
            $('#viewSelector').change(function() {
                let newUrl = new URL(window.location.href);
                newUrl.searchParams.set('view', $(this).val());
                window.location.href = newUrl.href;
            });

            const idSearchInput = document.getElementById('idSearchInput');
            if (idSearchInput) {
                let debounceTimer = null;
                let lastValue = idSearchInput.value.trim();
                idSearchInput.addEventListener('input', function () {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const currentValue = idSearchInput.value.trim();
                        if (currentValue === lastValue) return;
                        lastValue = currentValue;
                        const newUrl = new URL(window.location.href);
                        if (currentValue === '') {
                            newUrl.searchParams.delete('search');
                        } else {
                            newUrl.searchParams.set('search', currentValue);
                        }
                        window.location.href = newUrl.toString();
                    }, 500);
                });
            }

            $('#resetFilters').click(async function() {
                try {
                    await fetch("clmnseditor.php?action=savecolumns&table=<?=$view?><?=is_null($campId)?'':'&campid='.$campId?>", {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ columns: <?= json_encode(array_map(fn($c) => is_array($c) ? $c['field'] : $c, $tableColumns)) ?>, filters: {} })
                    });
                    window.location.reload();
                } catch(e) { notify('Error resetting filters: ' + e.message, 'error'); }
            });
            
            let t<?=$tName?>Columns = <?=$tColumns?>;
            let t<?=$tName?>Table = new Tabulator('#t<?=$tName?>', {
                layout: "fitData",
                columns: t<?=$tName?>Columns,
                nestedFieldSeparator: false,
                columnCalcs: "both",
                pagination: true,
                paginationMode: "remote",
                sortMode: "remote",
                paginationSize: 100,
                paginationSizeSelector: [25, 50, 100, 200, 500, 1000, 2000, 5000],
                paginationCounter: "rows",
                ajaxURL: "<?=$ajaxUrl?>",
                ajaxURLGenerator: function(url, config, params){
                    let u = new URL(url, window.location.href);
                    if(params.page) u.searchParams.set("page", params.page);
                    if(params.size) u.searchParams.set("size", params.size);
                    if(params.sort && params.sort.length){
                        u.searchParams.set("sort", params.sort[0].field);
                        u.searchParams.set("dir", params.sort[0].dir);
                    }
                    return u.toString();
                },
                columnDefaults:{
                    tooltip:true,
                },
                dependencies:{
                    XLSX:XLSX,
                }
            });

            <?php if (in_array($view, ['allowed', 'leads'], true)): ?>
            t<?=$tName?>Table.on("rowClick", function (event, row) {
                const clickId = row.getData().clickid;
                if (clickId) openClickDetail(clickId);
            });
            <?php endif; ?>

            t<?=$tName?>Table.on("columnResized", async function (column) {
                let updatedColumn = { field: column.getField(), width: column.getWidth() };
                await fetch("clmnseditor.php?action=width&table=<?=$view?><?=is_null($campId)?'':'&campid='.$campId?>", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify(updatedColumn),
                });
            });
        </script>
        <div id="clickDetailBackdrop" class="click-detail-backdrop" aria-hidden="true">
            <aside class="click-detail-panel" role="dialog" aria-modal="true" aria-labelledby="clickDetailTitle">
                <div class="click-detail-header">
                    <div>
                        <h2 id="clickDetailTitle">Click details</h2>
                        <div id="clickDetailId" class="click-detail-id"></div>
                    </div>
                    <button id="clickDetailClose" class="click-detail-close" type="button" aria-label="Close">&times;</button>
                </div>
                <div id="clickDetailContent"></div>
            </aside>
        </div>
        <script>
        (() => {
            const backdrop = document.getElementById('clickDetailBackdrop');
            const content = document.getElementById('clickDetailContent');
            const clickIdLabel = document.getElementById('clickDetailId');
            const closeButton = document.getElementById('clickDetailClose');
            const groups = [
                ['Overview', ['id', 'campaign_id', 'userid', 'time', 'ip', 'country', 'lang']],
                ['Device & network', ['device', 'brand', 'model', 'os', 'osver', 'client', 'clientver', 'isp', 'ua']],
                ['Journey', ['flow', 'path', 'step']],
                ['Attribution parameters', ['params']],
                ['Events', ['events']],
                ['Conversion', ['status', 'cost', 'payout', 'leaddata']],
            ];

            function formatValue(key, value) {
                if (key === 'time' && Number.isFinite(Number(value))) {
                    return luxon.DateTime.fromSeconds(Number(value)).setZone(<?=json_encode($tz)?>).toFormat('yyyy-LL-dd HH:mm:ss ZZZZ');
                }
                if (key === 'leaddata' && typeof value === 'string' && value.trim().startsWith('{')) {
                    try { value = JSON.parse(value); } catch (_) {}
                }
                if (value && typeof value === 'object') return JSON.stringify(value, null, 2);
                if (value === null || value === undefined || value === '') return '—';
                return String(value);
            }

            function renderSection(title, fields, click) {
                const section = document.createElement('section');
                section.className = 'click-detail-section';
                const heading = document.createElement('h3');
                heading.textContent = title;
                const grid = document.createElement('div');
                grid.className = 'click-detail-grid';
                fields.forEach(key => {
                    const label = document.createElement('div');
                    label.className = 'click-detail-key';
                    label.textContent = key;
                    const value = document.createElement('div');
                    value.className = 'click-detail-value';
                    const formatted = formatValue(key, click[key]);
                    value.textContent = formatted;
                    if (formatted === '—') value.classList.add('click-detail-empty');
                    grid.append(label, value);
                });
                section.append(heading, grid);
                return section;
            }

            function closeClickDetail() {
                backdrop.classList.remove('is-open');
                backdrop.setAttribute('aria-hidden', 'true');
            }

            window.openClickDetail = async function (clickId) {
                clickIdLabel.textContent = clickId;
                content.textContent = 'Loading…';
                backdrop.classList.add('is-open');
                backdrop.setAttribute('aria-hidden', 'false');
                closeButton.focus();
                try {
                    const response = await fetch('clickdetail.php?clickid=' + encodeURIComponent(clickId));
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.error || 'Unable to load click');
                    content.replaceChildren(...groups.map(group => renderSection(group[0], group[1], payload.click)));
                } catch (error) {
                    content.textContent = '';
                    notify(error.message, 'error');
                    closeClickDetail();
                }
            };

            closeButton.addEventListener('click', closeClickDetail);
            backdrop.addEventListener('click', event => {
                if (event.target === backdrop) closeClickDetail();
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && backdrop.classList.contains('is-open')) closeClickDetail();
            });
        })();
        </script>
        <?php include __DIR__."/clmnspopup.html" ?>
        <?php include __DIR__."/clickfilters.html" ?>
        <script>
        document.addEventListener("DOMContentLoaded", function () {
            document.getElementById("downloadCsv").onclick = () => {
                t<?=$tName?>Table.download("xlsx", `<?=$tName?>${getDateSuffix()}.xlsx`);
            };

            document.getElementById("columnsSelect").onclick = async () => {
                let availableClmns = <?= json_encode(AvailableColumns::get_columns_for_type($view)) ?>;
                let selectedClmns = <?= json_encode($tableColumns) ?>;
                let existingFilters = <?= json_encode($savedFilters) ?>;
                const commonParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'];
                const observedParams = t<?=$tName?>Table.getData().flatMap(row => Object.keys(row.params || {}));
                const availableParams = [...new Set([...commonParams, ...observedParams])].sort();
                addColumnsToList(selectedClmns, availableClmns, existingFilters, '<?= $view ?>', {availableParams});
                setSaveButtonHandler("clmnseditor.php?action=savecolumns&table=<?= $view ?><?= is_null($campId) ? '' : '&campid=' . $campId ?>");
                $('#columnModal').modal({
                    modalClass: 'ywbmodal',
                    fadeDuration: 250,
                    fadeDelay: 0.80,
                    showClose: false
                });
            }

            const filtersSaveUrl = "clmnseditor.php?action=savefilters&table=<?=$view?><?=is_null($campId)?'':'&campid='.$campId?>";
            const saveClickFilters = async (filters) => {
                const response = await fetch(filtersSaveUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({filters})
                });
                const data = await response.json();
                if (!response.ok || data.error) throw new Error(data.result || 'Unable to save filters');
                window.location.reload();
            };

            document.getElementById('clickFiltersOpen').onclick = () => {
                initializeFilters(<?=json_encode($savedFilters)?>, <?=$view === 'blocked' ? "['reason']" : '[]'?>);
                if (!document.querySelector('#filterRows .filter-row')) addFilterRowToDOM('', '=', '');
                $('#clickFiltersModal').modal({modalClass: 'ywbmodal', fadeDuration: 0, showClose: false});
            };
            document.getElementById('closeClickFilters').onclick = () => $.modal.close();
            document.getElementById('saveClickFilters').onclick = async () => {
                try { await saveClickFilters(collectFilters()); }
                catch (error) { notify(error.message, 'error'); }
            };
            document.getElementById('clearClickFilters').onclick = async () => {
                try { await saveClickFilters({}); }
                catch (error) { notify(error.message, 'error'); }
            };
        });
        </script>
        <br/>
        <br/>
    </div>
</body>
</html>
