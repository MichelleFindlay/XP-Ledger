<?php

/**
 * Per-game Steam achievement records, kept under cache/achievements/.
 *
 * Steam has no "all my achievements" endpoint — it's one request per
 * game — so a library of a few hundred games can't be read live on a page
 * load. Instead cron.php calls backfill() each run to refresh a bounded
 * batch: games whose playtime has moved since they were last checked come
 * first (that's where new unlocks can be), then never-checked games,
 * heaviest-played first. Everything else here reads only what's already
 * on disk, and reports how much of the library that covers.
 *
 * Unlock timestamps are real (Steam records the exact second), which makes
 * this the one source of genuinely historical, dated activity Steam
 * offers — the Achievement Clock widget is built on it.
 */
class Achievements
{
    private Steam $steam;
    private string $dir;
    private ?array $allCache = null;

    public function __construct(Steam $steam)
    {
        $this->steam = $steam;
        $this->dir = __DIR__ . '/../cache/achievements_' . md5($steam->steamId());
    }

    public function get(int $appid): ?array
    {
        $file = $this->dir . '/' . $appid . '.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Fetch and store one game's achievements. Returns the new record, or
     * the previous one (if any) when Steam couldn't be reached.
     */
    public function refresh(int $appid, int $playtime, int $ttl = 300): ?array
    {
        $result = $this->steam->playerAchievements($appid, $ttl);
        if ($result === null) {
            return $this->get($appid);
        }

        $record = [
            'appid'             => $appid,
            'checked_at'        => time(),
            'playtime_at_check' => $playtime,
            'has_stats'         => false,
            'total'             => 0,
            'unlocked'          => 0,
            'unlocks'           => [],
        ];

        if ($result['success']) {
            $record['has_stats'] = !empty($result['achievements']);
            $record['total'] = count($result['achievements']);
            foreach ($result['achievements'] as $a) {
                if (!empty($a['achieved'])) {
                    $record['unlocks'][] = [
                        'api'  => (string) ($a['apiname'] ?? ''),
                        'name' => (string) ($a['name'] ?? $a['apiname'] ?? ''),
                        'desc' => mb_substr((string) ($a['description'] ?? ''), 0, 160),
                        'time' => (int) ($a['unlocktime'] ?? 0),
                    ];
                }
            }
            $record['unlocked'] = count($record['unlocks']);
        } elseif (!$result['no_stats']) {
            return $this->get($appid); // profile hides achievements, or a transient error — keep what we had
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        @file_put_contents($this->dir . '/' . $appid . '.json', json_encode($record));
        $this->allCache = null;

        return $record;
    }

    /**
     * The record for one game, refreshed live first if it's missing or the
     * game has been played since it was last checked. Used for the
     * now-playing hero only — a single request, cached 5 minutes.
     */
    public function forGame(int $appid, int $playtime): ?array
    {
        $record = $this->get($appid);
        $stale = $record === null
            || ((int) $record['playtime_at_check'] !== $playtime && time() - (int) $record['checked_at'] > 300);

        return $stale ? $this->refresh($appid, $playtime) : $record;
    }

    /**
     * Refresh up to $max games' records. Also fetches global unlock rates
     * (for the Rarest Unlocks widget) for games with at least one unlock,
     * counted against the same budget.
     *
     * @param array<int, array{appid:int, playtime:int}> $games
     * @return array{checked:int, remaining:int}
     */
    public function backfill(array $games, int $max): array
    {
        $changed = [];
        $unchecked = [];
        $now = time();

        foreach ($games as $g) {
            if ($g['playtime'] <= 0) {
                continue; // can't have unlocked anything in a game never launched
            }
            $record = $this->get($g['appid']);
            if ($record === null) {
                $unchecked[] = $g;
            } elseif ((int) $record['playtime_at_check'] !== $g['playtime']) {
                $changed[] = $g;
            } elseif (!$record['has_stats'] && $now - (int) $record['checked_at'] > 90 * 86400) {
                $unchecked[] = $g; // games sometimes add achievements in later updates
            }
        }

        usort($unchecked, fn($a, $b) => $b['playtime'] <=> $a['playtime']);
        $queue = array_merge($changed, $unchecked);

        $budget = $max;
        $checked = 0;
        foreach ($queue as $g) {
            if ($budget <= 0) {
                break;
            }
            $record = $this->refresh($g['appid'], $g['playtime'], 0);
            $budget--;

            // refresh() hands back the old record (or null) when Steam
            // couldn't answer. That's almost never game-specific — a bad
            // key, an outage, rate limiting, achievements set to private —
            // so stop here rather than wait out a timeout per queued game.
            if ($record === null || (int) $record['checked_at'] < $now) {
                break;
            }
            $checked++;

            if ($record && $record['unlocked'] > 0 && $budget > 0
                && $this->steam->globalAchievementPercentages($g['appid'], true) === null) {
                $this->steam->globalAchievementPercentages($g['appid']);
                $budget--;
            }
        }

        return ['checked' => $checked, 'remaining' => max(0, count($queue) - $checked)];
    }

    /**
     * @return array<int, array> appid => record, for every game checked so far
     */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $records = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['appid'])) {
                $records[(int) $data['appid']] = $data;
            }
        }

        return $this->allCache = $records;
    }

    /**
     * Library-wide totals across every game checked so far.
     *
     * @param int $playedGames how many owned games have any playtime, to report coverage against
     */
    public function summary(int $playedGames): array
    {
        $records = $this->all();
        $unlocked = 0;
        $perfect = 0;
        $withStats = 0;
        $started = 0;
        $completionSum = 0.0;

        foreach ($records as $r) {
            if (!$r['has_stats']) {
                continue;
            }
            $withStats++;
            $unlocked += $r['unlocked'];
            if ($r['unlocked'] > 0) {
                $started++;
                $completionSum += $r['unlocked'] / max(1, $r['total']);
            }
            if ($r['total'] > 0 && $r['unlocked'] >= $r['total']) {
                $perfect++;
            }
        }

        return [
            'unlocked'           => $unlocked,
            'perfect_games'      => $perfect,
            'games_with_stats'   => $withStats,
            'games_started'      => $started,
            'avg_completion_pct' => $started > 0 ? round($completionSum / $started * 100, 1) : null,
            'checked_games'      => count($records),
            'coverage_pct'       => $playedGames > 0 ? min(100, (int) round(count($records) / $playedGames * 100)) : 0,
        ];
    }

    /**
     * Every unlock with its game, newest first.
     *
     * @return array<int, array{appid:int, api:string, name:string, desc:string, time:int}>
     */
    public function unlocks(): array
    {
        $all = [];
        foreach ($this->all() as $appid => $r) {
            foreach ($r['unlocks'] as $u) {
                if ($u['time'] > 0) {
                    $all[] = ['appid' => $appid] + $u;
                }
            }
        }
        usort($all, fn($a, $b) => $b['time'] <=> $a['time']);

        return $all;
    }
}
