<?php

/**
 * Lightweight JSON endpoint polled by the dashboard to refresh the
 * now-playing card and lifetime stats without reloading the page.
 *
 * Also takes an opportunistic playtime-ledger snapshot (at most every
 * ledger_snapshot_interval seconds), so the ledger keeps growing while the
 * dashboard is open even if cron.php isn't scheduled.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Library.php';

$config = Config::load();
if (Config::needsSetup($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing or incomplete config.php']);
    exit;
}

// Cache the profile summary slightly shorter than the browser's poll
// interval so every poll gets fresh data, while still de-duplicating any
// near-simultaneous requests (several open tabs, say).
$pollSeconds = max(1, (int) ($config['poll_interval_ms'] / 1000));
$library = Library::fromConfig($config);
$nowPlaying = $library->nowPlaying(max(3, $pollSeconds - 2));

try {
    $library->snapshotLedger(max(60, (int) $config['ledger_snapshot_interval']));
} catch (Throwable $e) {
    // never let bookkeeping break the poll
}

if (!$nowPlaying['current']) {
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode([
    'ok'       => true,
    'current'  => $nowPlaying['current'],
    'previous' => $nowPlaying['previous'],
    'stats'    => array_map(fn($s) => $s['value'], $library->lifetimeStats()),
]);
