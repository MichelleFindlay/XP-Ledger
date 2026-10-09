<?php

/**
 * JSON endpoint for the insight widget popups and the period pickers.
 * Each widget is computed lazily on first click (rather than on every
 * page load), then cached for 15 minutes (see WidgetCache::remember()) —
 * or pre-warmed ahead of time by cron.php, if you've set that up.
 */

header('Content-Type: application/json');

// A cold widget can make a handful of live Store API lookups (capped by
// store_live_budget), so the default 30s limit some hosts set isn't always
// enough. Silently no-ops on hosts where set_time_limit is disabled.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Library.php';
require __DIR__ . '/lib/Widgets.php';
require __DIR__ . '/lib/WidgetCache.php';
require __DIR__ . '/lib/WidgetRegistry.php';

$config = Config::load();
if (Config::needsSetup($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing or incomplete config.php']);
    exit;
}

$id = $_GET['id'] ?? '';
$library = Library::fromConfig($config);
$handlers = WidgetRegistry::handlers($library, new Widgets($library, $config), $config);

if (!isset($handlers[$id])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown widget']);
    exit;
}

// Only the params a widget actually reads go into its cache key, so stray
// query-string junk can't mint unlimited cache files.
$params = ['id' => $id];
if (in_array($id, WidgetRegistry::PERIOD_IDS, true)) {
    $params['period'] = Library::validUiPeriod($_GET['period'] ?? '');
    $_GET['period'] = $params['period'];
}

$data = WidgetCache::remember($id, $params, 900, $handlers[$id]);

echo json_encode(['ok' => true, 'id' => $id, 'data' => $data]);
