<?php

function is_valid_campaign_public_id(string $publicId): bool
{
    if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $publicId) !== 1) {
        return false;
    }
    return !in_array($publicId, ['api', 'js', 'caching', 'thankyou', '__dl', 'admin'], true);
}

function generate_campaign_public_id(): string
{
    return 'c-' . strtolower(bin2hex(random_bytes(6)));
}

function get_legacy_campaign_public_id(int $campaignId, array $settings): string
{
    $seed = $campaignId . '|' . (string)($settings['apikey'] ?? '');
    return 'c-' . substr(hash('sha256', $seed), 0, 12);
}

function get_campaign_public_id(int $campaignId, array $settings): string
{
    $configured = (string)($settings['publicid'] ?? '');
    if (is_valid_campaign_public_id($configured)) {
        return $configured;
    }

    // Stable legacy fallback until the campaign is saved with a generated ID.
    return get_legacy_campaign_public_id($campaignId, $settings);
}

function get_campaign_public_id_aliases(array $settings, int $campaignId = 0): array
{
    $aliasesWereConfigured = array_key_exists('publicidaliases', $settings);
    $aliases = $settings['publicidaliases'] ?? [];
    if (!is_array($aliases)) {
        return [];
    }
    $aliases = array_map(static fn($alias): string => strtolower(trim((string)$alias)), $aliases);
    $aliases = array_values(array_unique(array_filter($aliases, 'is_valid_campaign_public_id')));
    if (!$aliasesWereConfigured && $campaignId > 0) {
        $currentPublicId = get_campaign_public_id($campaignId, $settings);
        $legacyPublicId = get_legacy_campaign_public_id($campaignId, $settings);
        if ($legacyPublicId !== $currentPublicId) {
            $aliases[] = $legacyPublicId;
        }
    }
    return $aliases;
}

function build_campaign_public_id_aliases(int $campaignId, array $settings, string $nextPublicId): array
{
    $currentPublicId = get_campaign_public_id($campaignId, $settings);
    $aliases = get_campaign_public_id_aliases($settings, $campaignId);
    if ($currentPublicId !== $nextPublicId) {
        $aliases[] = $currentPublicId;
    }
    $aliases = array_values(array_unique($aliases));
    return array_values(array_filter(
        $aliases,
        static fn(string $alias): bool => $alias !== $nextPublicId
    ));
}

function campaign_accepts_public_id(int $campaignId, array $settings, string $publicId): bool
{
    return get_campaign_public_id($campaignId, $settings) === $publicId
        || in_array($publicId, get_campaign_public_id_aliases($settings, $campaignId), true);
}

function get_campaign_request_path(): string
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $scriptDir = dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($path, $scriptDir . '/')) {
        $path = substr($path, strlen($scriptDir));
    }
    return trim($path, '/');
}

/** @return array{public_id:string, relative_path:string}|null */
function get_campaign_request_route(): ?array
{
    $path = get_campaign_request_path();
    if (!preg_match('#^([^/]+)(?:/(.*))?$#', $path, $matches) || !is_valid_campaign_public_id($matches[1])) {
        return null;
    }

    return [
        'public_id' => $matches[1],
        'relative_path' => trim(rawurldecode((string)($matches[2] ?? '')), '/'),
    ];
}

function get_campaign_route_base(string $publicId): string
{
    if (function_exists('get_cloaker_relative_path')) {
        $base = get_cloaker_relative_path();
    } else {
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $base = $scriptDir === '/' || $scriptDir === '.' ? '/' : ('/' . trim($scriptDir, '/') . '/');
    }
    return $base . rawurlencode($publicId) . '/';
}

function get_campaign_route_url(string $publicId, string $relativePath = ''): string
{
    $base = get_campaign_route_base($publicId);
    $relativePath = trim($relativePath, '/');
    if ($relativePath === '') {
        return $base;
    }
    $parts = array_values(array_filter(explode('/', $relativePath), static fn(string $part): bool => $part !== ''));
    return $base . implode('/', array_map('rawurlencode', $parts));
}

function set_campaign_route_context(string $publicId, string $clickid, int $stepIndex, bool $serveRootOnce = false): void
{
    $contexts = session_read('campaign_route_contexts');
    if (!is_array($contexts)) {
        $contexts = [];
    }
    $contexts[$publicId] = [
        'clickid' => $clickid,
        'step' => $stepIndex,
        'root_once' => $serveRootOnce,
    ];
    session_write('campaign_route_contexts', $contexts);
}

function get_campaign_route_context(string $publicId): array
{
    $contexts = session_read('campaign_route_contexts');
    $context = is_array($contexts) ? ($contexts[$publicId] ?? []) : [];
    return is_array($context) ? $context : [];
}

function consume_campaign_route_root(string $publicId): void
{
    $contexts = session_read('campaign_route_contexts');
    if (!is_array($contexts) || !isset($contexts[$publicId]) || !is_array($contexts[$publicId])) {
        return;
    }
    $contexts[$publicId]['root_once'] = false;
    session_write('campaign_route_contexts', $contexts);
}
