<?php
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../logging.php';

$passOk = check_password(false);
if (!$passOk)
    return send_camp_result("Error: password check not passed!",true);

$action = $_REQUEST['action'] ?? '';
$name = $_REQUEST['name']??'';
$name = is_string($name) ? trim($name) : '';
$campId = $_REQUEST['campId']??-1;
add_log('trace','CampEditor action: '.$action.', name: '.$name.', campId: '.$campId);
switch ($action) {
    case 'add':
        $campId = $db->add_campaign($name);
        if ($campId===false)
            return send_camp_result("Error adding new campaign!",true);
        break;
    case 'dup':
        if (empty($name)) {
            return send_camp_result("Error: campaign name can not be empty!", true);
        }
        $clonedId = $db->clone_campaign($campId);
        if ($clonedId===false)
            return send_camp_result("Error duplicating campaign!",true);
        if (!empty($name)) {
            $renRes = $db->rename_campaign((int)$clonedId, $name);
            if ($renRes === false) {
                return send_camp_result("Error renaming cloned campaign!", true);
            }
        }
        break;
    case 'del':
        $delRes = $db->delete_campaign($campId);
        if ($delRes===false)
            return send_camp_result("Error deleting campaign!",true);
        break;
    case 'ren':
        $renRes = $db->rename_campaign($campId, $name);
        if ($renRes===false)
            return send_camp_result("Error renaming campaign!",true);
        break;
    case 'save':
        $s = $db->get_campaign_settings($campId);
        $s['publicid'] = get_campaign_public_id((int)$campId, $s);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return send_camp_result("Error: invalid JSON body!", true);
        }
        $publicId = strtolower(trim((string)($input['publicid'] ?? $s['publicid'] ?? get_campaign_public_id((int)$campId, $s))));
        if (!is_valid_campaign_public_id($publicId)) {
            return send_camp_result('Error: campaign slug must be 3-63 lowercase letters, numbers or hyphens', true);
        }
        if ($db->campaign_public_id_exists($publicId, (int)$campId)) {
            return send_camp_result('Error: campaign slug is already in use', true);
        }
        $input['publicid'] = $publicId;
        $input['publicidaliases'] = build_campaign_public_id_aliases((int)$campId, $s, $publicId);
        if (isset($input['black']['flows']) && is_array($input['black']['flows'])) {
            $streamVersion = (int)($input['black']['streamversion'] ?? $s['black']['streamversion'] ?? 1);
            if ($streamVersion >= 2) {
                try {
                    $input['black']['flows'] = normalize_unified_streams($input['black']['flows']);
                } catch (InvalidArgumentException $e) {
                    return send_camp_result('Error: ' . $e->getMessage(), true);
                }
                $input['black']['streamversion'] = 2;
            }
            foreach ($input['black']['flows'] as &$flow) {
                foreach (($flow['steps'] ?? []) as &$step) {
                    normalize_step_weights($step);
                }
                unset($step);
            }
            unset($flow);
        }
        $s = mergeSettingsRecursive($s, $input);
        $saveRes = $db->save_campaign_settings($campId, $s);
        if($saveRes===false)
            return send_camp_result("Error saving campaign!",true);
        $savedFlows = array_map(static fn(array $flow): array => [
            'id' => (string)($flow['id'] ?? ''),
            'name' => (string)($flow['name'] ?? 'Flow'),
            'type' => (string)($flow['type'] ?? 'regular'),
            'enabled' => (bool)($flow['enabled'] ?? true),
        ], $s['black']['flows'] ?? []);
        return send_camp_result("OK", false, [
            'flows' => $savedFlows,
            'publicid' => (string)$s['publicid'],
            'publicidaliases' => get_campaign_public_id_aliases($s, (int)$campId),
        ]);
    case 'migratestreams':
        $s = $db->get_campaign_settings($campId);
        if (empty($s)) return send_camp_result("Error: campaign not found!", true);
        $s = migrate_campaign_to_unified_streams($s);
        if (!$db->save_campaign_settings($campId, $s)) {
            return send_camp_result("Error migrating campaign streams!", true);
        }
        break;
    default:
        return send_camp_result("Error: wrong action!",true);
}
return send_camp_result("OK");

function send_camp_result($msg,$error=false,array $extra=[]): void
{
    $res = array_merge(["result" => $msg], $extra);
    if ($error){
        $res['error']=true;
    }
    header('Content-type: application/json');
    http_response_code(200);
    $json = json_encode($res);
    echo $json;
}

function normalize_step_weights(array &$step): void {
    $weights = $step['weights'] ?? [];
    if (empty($weights)) return;
    $total = array_sum($weights);
    $count = count($weights);
    if ($total <= 0) {
        $base = intdiv(100, $count);
        $remainder = 100 - $base * $count;
        $result = array_fill(0, $count, $base);
        for ($i = 0; $i < $remainder; $i++) $result[$i]++;
        $step['weights'] = $result;
        return;
    }
    if ($total === 100) return;
    $exact = array_map(fn($w) => $w / $total * 100, $weights);
    $floored = array_map('floor', $exact);
    $remainders = [];
    for ($i = 0; $i < $count; $i++) {
        $remainders[$i] = $exact[$i] - $floored[$i];
    }
    $diff = 100 - (int)array_sum($floored);
    arsort($remainders);
    foreach (array_keys($remainders) as $idx) {
        if ($diff <= 0) break;
        $floored[$idx]++;
        $diff--;
    }
    $step['weights'] = array_map('intval', $floored);
}

function generate_stream_id(array &$used): string {
    do {
        $id = 'f_' . rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
    } while (isset($used[$id]));
    $used[$id] = true;
    return $id;
}

function normalize_unified_streams(array $flows): array {
    $used = [];
    $normalized = [];
    $default = null;
    foreach ($flows as $flow) {
        if (!is_array($flow)) continue;
        $id = (string)($flow['id'] ?? '');
        if (!preg_match('/^f_[A-Za-z0-9_-]{6,16}$/', $id) || isset($used[$id])) {
            $id = generate_stream_id($used);
        } else {
            $used[$id] = true;
        }
        $flow['id'] = $id;
        $type = (string)($flow['type'] ?? 'regular');
        if (!in_array($type, ['forced', 'regular', 'default'], true)) $type = 'regular';
        $flow['type'] = $type;
        $flow['enabled'] = $type === 'default' ? true : (bool)($flow['enabled'] ?? true);
        if ($type === 'default') {
            $flow['filters'] = [];
            $default = $flow;
        } else {
            $normalized[] = $flow;
        }
    }
    if ($default === null) {
        throw new InvalidArgumentException('Unified streams require one default stream');
    }
    $normalized[] = $default;
    return $normalized;
}

function white_destination_step(array $white): array {
    $action = (string)($white['action'] ?? 'folder');
    if ($action === 'redirect') {
        $urls = array_map(fn($url) => ['url' => (string)$url, 'label' => StepSettings::generateRedirectLabel((string)$url)], $white['redirect']['urls'] ?? []);
        return ['action' => 'redirect', 'folders' => [], 'redirect' => ['urls' => $urls, 'type' => $white['redirect']['type'] ?? 302], 'weights' => [], 'folderloadtypes' => []];
    }
    if ($action === 'error') {
        return ['action' => 'http404', 'folders' => [], 'redirect' => ['urls' => [], 'type' => 302], 'weights' => [], 'folderloadtypes' => []];
    }
    return ['action' => 'folder', 'folders' => $white['folders'] ?? [], 'redirect' => ['urls' => [], 'type' => 302], 'weights' => [], 'folderloadtypes' => $white['loadmode'] ?? []];
}

function migrate_campaign_to_unified_streams(array $settings): array {
    if ((int)($settings['black']['streamversion'] ?? 1) >= 2) return $settings;
    $used = [];
    $safeStep = white_destination_step($settings['white'] ?? []);
    $flows = [];
    $whiteFilters = $settings['white']['filters'] ?? [];
    if (!empty($whiteFilters['rules'])) {
        $flows[] = ['id' => generate_stream_id($used), 'name' => 'Safe traffic', 'type' => 'forced', 'enabled' => true, 'filters' => $whiteFilters, 'distribution' => 'equal', 'optimize_for' => 'Lead', 'optimize_mode' => 'funnels', 'steps' => [$safeStep]];
    }
    $flows[] = ['id' => generate_stream_id($used), 'name' => 'JS check failed', 'type' => 'forced', 'enabled' => true, 'filters' => ['condition' => 'AND', 'rules' => [['id' => 'reason', 'field' => 'reason', 'type' => 'string', 'input' => 'text', 'operator' => 'in', 'value' => ['timezone', 'audiocontext', 'timeout', 'jscheck_scam_timeout']]]], 'distribution' => 'equal', 'optimize_for' => 'Lead', 'optimize_mode' => 'funnels', 'steps' => [$safeStep]];

    $legacy = $settings['black']['flows'] ?? [];
    foreach ($legacy as $i => $flow) {
        $flow['id'] = generate_stream_id($used);
        $flow['enabled'] = true;
        $flow['type'] = 'regular';
        $flows[] = $flow;
    }
    if (empty($legacy)) {
        $flows[] = ['id' => generate_stream_id($used), 'name' => 'Default', 'type' => 'default', 'enabled' => true, 'filters' => [], 'distribution' => 'equal', 'optimize_for' => 'Lead', 'optimize_mode' => 'funnels', 'steps' => [['action' => 'nothing', 'folders' => [], 'redirect' => ['urls' => [], 'type' => 302], 'weights' => [], 'folderloadtypes' => []]]];
    } else {
        $last = array_pop($flows);
        $last['type'] = 'default';
        $last['filters'] = [];
        $last['enabled'] = true;
        $last['name'] = ($last['name'] ?? 'Flow') . ' (Default)';
        $flows[] = $last;
    }
    $settings['black']['flows'] = $flows;
    $settings['black']['streamversion'] = 2;
    return $settings;
}

function mergeSettingsRecursive($current, $incoming) {
    if (!is_array($incoming)) {
        if ($incoming === 'false' || $incoming === 'true') {
            return filter_var($incoming, FILTER_VALIDATE_BOOLEAN);
        }
        return $incoming;
    }

    // A list on the incoming side replaces wholesale (normalized).
    if (array_is_list($incoming)) {
        return compactListRecursive($incoming);
    }

    // Incoming is an associative array: deep-merge by key, preserving keys.
    // If the current value can't hold string keys (null / scalar / list, e.g.
    // a brand-new key or previously corrupted shape), start from an empty
    // associative base so incoming's keys survive instead of being flattened
    // into a positional list by compactListRecursive.
    if (!is_array($current) || array_is_list($current)) {
        $current = [];
    }

    foreach ($incoming as $key => $value) {
        $current[$key] = mergeSettingsRecursive($current[$key] ?? null, $value);
    }

    return $current;
}

function compactListRecursive(array $list): array {
    $result = [];
    foreach ($list as $value) {
        if (is_array($value)) {
            $result[] = array_is_list($value)
                ? compactListRecursive($value)
                : mergeSettingsRecursive([], $value);
            continue;
        }

        if ($value === 'false' || $value === 'true') {
            $result[] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            continue;
        }

        $result[] = $value;
    }

    return $result;
}
