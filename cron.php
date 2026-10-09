<?php

/**
 * The background job that keeps XP Ledger's books. Each run:
 *
 * 1. Takes a playtime-ledger snapshot (see lib/Ledger.php) — the only way
 *    to get dated play history out of Steam, which only reports lifetime
 *    totals. The more regularly this runs, the more precisely sessions
 *    land in the right hour/day; every 15 minutes is plenty.
 * 2. Refreshes a bounded batch of per-game achievement lists (games played
 *    since they were last checked first), plus their global unlock rates.
 * 3. Fetches store details (genres, review counts) for a bounded batch of
 *    games that don't have them yet, heaviest-played first.
 * 4. Pre-warms every widget and period-picker cache, so visitors never
 *    trigger a slow cold computation on their own page load.
 *
 * Steps 2 and 3 are paced on purpose: Steam has no bulk endpoint for
 * either, so a large library fills in over several runs rather than in
 * one rate-limit-risking burst.
 *
 * Two ways to schedule it (crontab syntax: minute 0,15,30,45 of every hour,
 * written out longhand so it doesn't get parsed as the end of this comment):
 *
 *   Real system cron (preferred, if you have shell access):
 *     0,15,30,45 * * * * php /full/path/to/XP-Ledger/cron.php >/dev/null 2>&1
 *
 *   URL-based "cron" (common on shared hosting control panels):
 *     0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
 *
 * Once scheduled, set 'cron_enabled' => true in config.php (shown as a
 * small footer note). If you set 'cron_secret', HTTP requests must include
 * a matching ?token= to run this; CLI runs are always allowed.
 */

if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Library.php';
require __DIR__ . '/lib/Widgets.php';
require __DIR__ . '/lib/WidgetCache.php';
require __DIR__ . '/lib/WidgetRegistry.php';

$isCli = PHP_SAPI === 'cli';

function respond(string $message, int $httpStatus = 200): void
{
    global $isCli;

    if ($isCli) {
        fwrite(STDOUT, $message . "\n");
        return;
    }

    http_response_code($httpStatus);
    header('Content-Type: text/plain');
    echo $message . "\n";
}

$config = Config::load();
if (Config::needsSetup($config)) {
    respond('cron.php: missing or incomplete config.php', 500);
    exit(1);
}

if (!$isCli) {
    $secret = (string) $config['cron_secret'];
    if ($secret !== '' && !hash_equals($secret, (string) ($_GET['token'] ?? ''))) {
        respond('cron.php: invalid or missing token', 403);
        exit(1);
    }
}

$library = Library::fromConfig($config);
$handlers = WidgetRegistry::handlers($library, new Widgets($library, $config), $config);

$refreshed = [];
$failed = [];
$notes = [];

$games = array_values($library->ownedGames());
if ($library->isPrivate()) {
    $notes[] = 'Steam is hiding this profile\'s games — set "Game details" to Public in Steam\'s privacy settings';
}

// 1. Ledger snapshot. A minimum interval just under the 15-minute schedule
// lets every scheduled run record, while a stray double-trigger doesn't
// produce a near-empty extra entry.
try {
    $snap = $library->snapshotLedger(240);
    $refreshed[] = 'ledger';
    if ($snap['first']) {
        $notes[] = 'ledger started — period totals build up from now';
    } elseif ($snap['recorded']) {
        $notes[] = $snap['minutes'] > 0
            ? sprintf('ledger: +%s across %d game(s)', Library::formatMinutes($snap['minutes']), $snap['games'])
            : 'ledger: no new playtime';
    }
} catch (Throwable $e) {
    $failed[] = 'ledger';
}

// Xbox title list + playtime, so page loads never wait on OpenXBL. Cached
// 15-60 minutes inside the client, so this costs a few requests an hour.
if ($library->xbox()->enabled()) {
    try {
        $notes[] = sprintf('xbox: %d title(s)', count($library->xbox()->titles()));
        $refreshed[] = 'xbox';
    } catch (Throwable $e) {
        $failed[] = 'xbox';
    }
}

// PlayStation games + trophies, for the same reason. Also keeps the PSN
// access token refreshed between dashboard visits.
if ($library->psn()->enabled()) {
    try {
        $notes[] = sprintf('playstation: %d game(s)', count($library->psn()->games()));
        if ($library->psn()->authError()) {
            $notes[] = 'playstation sign-in failing — update psn_npsso';
        }
        $refreshed[] = 'playstation';
    } catch (Throwable $e) {
        $failed[] = 'playstation';
    }
}

// Keeps the now-playing session timer alive between dashboard visits.
try {
    $library->nowPlaying();
} catch (Throwable $e) {
    $failed[] = 'now_playing';
}

// 2. Achievements.
try {
    $ach = $library->achievements()->backfill($games, max(1, (int) $config['achievement_backfill_per_run']));
    $refreshed[] = 'achievements';
    $notes[] = sprintf('achievements: %d game(s) checked, %d queued', $ach['checked'], $ach['remaining']);
} catch (Throwable $e) {
    $failed[] = 'achievements';
}

// 3. Store details, played games first (heaviest first), then the rest of
// the library (Pile of Shame wants to know which unplayed games are famous).
try {
    $budget = max(1, (int) $config['store_backfill_per_run']);
    usort($games, fn($a, $b) => $b['playtime'] <=> $a['playtime']);
    $fetched = 0;
    $missing = 0;
    foreach ($games as $g) {
        if ($library->steam()->appDetails($g['appid'], true) !== null) {
            continue;
        }
        if ($fetched >= $budget) {
            $missing++;
            continue;
        }
        if ($library->steam()->appDetails($g['appid']) === null) {
            $missing++;
            break; // most likely rate-limited — stop and let the next run carry on
        }
        $fetched++;
    }
    $refreshed[] = 'store';
    $notes[] = sprintf('store details: +%d, %d still to fetch', $fetched, $missing);
} catch (Throwable $e) {
    $failed[] = 'store';
}

// 4. Widget caches. Period-parameterized handlers read $_GET (matching how
// widgets.php's on-demand requests are shaped), so it's faked here for
// each combination; the cache key matches what widgets.php looks up.
foreach (WidgetRegistry::SIMPLE_IDS as $id) {
    try {
        WidgetCache::remember($id, ['id' => $id], 900, $handlers[$id]);
        $refreshed[] = $id;
    } catch (Throwable $e) {
        $failed[] = $id;
    }
}

foreach (WidgetRegistry::PERIOD_IDS as $id) {
    foreach (array_keys(Library::PERIOD_LABELS) as $period) {
        $params = ['id' => $id, 'period' => $period];
        try {
            $_GET = $params;
            WidgetCache::remember($id, $params, 900, $handlers[$id]);
            $refreshed[] = $id . '_' . $period;
        } catch (Throwable $e) {
            $failed[] = $id . '_' . $period;
        }
    }
}

$summary = sprintf(
    '[%s] XP-Ledger cron: refreshed %d/%d (%s)',
    date('c'),
    count($refreshed),
    count($refreshed) + count($failed),
    $failed ? 'failed: ' . implode(', ', $failed) : 'all ok'
);
if ($notes) {
    $summary .= ' — ' . implode(' — ', $notes);
}

respond($summary, $failed ? 500 : 200);
