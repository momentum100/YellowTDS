<?php

require_once __DIR__ . '/debug.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/htmlprocessing.php';
require_once __DIR__ . '/db/db.php';
require_once __DIR__ . '/redirect.php';
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/campaign.php';

$clickid = (string)($_GET['clickid'] ?? '');
$stepRaw = (string)($_GET['step'] ?? '');

if ($clickid === '' || $stepRaw === '' || !preg_match('/^\d+$/', $stepRaw)) {
    die('INVALID NEXT CONTEXT!');
}

$currentStep = (int)$stepRaw;

global $db;
$click = $db->get_click_by_clickid($clickid);
if (empty($click)) {
    die('CLICK NOT FOUND!');
}

set_clickid($clickid);

$campId = (int)$click['campaign_id'];
$settings = $db->get_campaign_settings($campId);
$c = new Campaign($campId, $settings);

$flowName = (string)($click['flow'] ?? '');
$flowId = (string)($click['flow_id'] ?? '');
$flow = null;
foreach ($c->black->flows as $f) {
    if (($flowId !== '' && $f->id === $flowId) || ($flowId === '' && $f->name === $flowName)) {
        $flow = $f;
        break;
    }
}
if ($flow === null) {
    die('FLOW NOT FOUND!');
}

$steps = $flow->steps;
$maxReachedStep = (int)($click['step'] ?? 0);
if ($currentStep > $maxReachedStep) {
    die('INVALID STEP CONTEXT!');
}

$nextStep = $currentStep + 1;
if ($nextStep >= count($steps)) {
    die('NO MORE STEPS IN FUNNEL!');
}

$plannedPath = $click['path'] ?? [];
if (!is_array($plannedPath) || empty($plannedPath)) {
    die('EMPTY PLANNED PATH!');
}

if (!isset($plannedPath[$nextStep])) {
    die('NO VARIANT PLANNED FOR STEP ' . $nextStep);
}

$chosenVariant = $plannedPath[$nextStep];
$stepSettings = $steps[$nextStep];
$validItems = $stepSettings->getItems();
if (!in_array($chosenVariant, $validItems, true)) {
    if (empty($validItems)) {
        die('NO ITEMS AVAILABLE FOR STEP ' . $nextStep);
    }
    $chosenVariant = $validItems[0];
    $plannedPath[$nextStep] = $chosenVariant;
    $db->update_click_path($clickid, $plannedPath);
}

if ($maxReachedStep < $nextStep) {
    if (!$db->add_click_step($clickid, $nextStep, $chosenVariant)) {
        die('FAILED TO RECORD STEP ENTRY!');
    }
}

if ($stepSettings->isRedirect()) {
    $url = $stepSettings->getRedirectUrlByLabel($chosenVariant);
    $mp = new MacrosProcessor($c, null, $clickid, $click['userid'] ?? null);
    $url = $mp->replace_url_macros($url);
    redirect($url, $stepSettings->redirectType, false);
    return;
}

if ($stepSettings->isDirectLoad($chosenVariant)) {
    set_campaign_route_context($c->publicId, $clickid, $nextStep, true);
    redirect(get_campaign_route_url($c->publicId), 302, false);
    return;
}

echo load_step($c, $flow, $nextStep, $chosenVariant, $clickid, false);
