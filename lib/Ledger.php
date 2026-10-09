<?php

/**
 * The playtime ledger: XP Ledger's equivalent of lastfm-dash's local
 * scrobble library, and the reason the app is called what it is.
 *
 * Last.fm keeps a timestamped history of every play; Steam doesn't. All
 * Steam exposes is each game's *lifetime* playtime counter (plus a rolling
 * "last two weeks" figure). So this class builds the history itself: every
 * time it's handed the owned-games list (cron.php every 15 minutes, plus
 * opportunistically from api.php while the dashboard is open), it diffs
 * each game's counter against the previous snapshot and appends one
 * [timestamp, appid, minutes] entry per game that moved. Over time that
 * gives exact calendar-period totals ("this week", "today"), a time-of-day
 * profile, and a day-by-day log that Steam itself never provides.
 *
 * Honest limits, surfaced to the UI rather than papered over:
 * - History starts the first time this runs. Nothing before that moment
 *   can be reconstructed, so every query reports whether the ledger fully
 *   covers the requested period (see covers()).
 * - Steam only advances the counter while it's online, and may only commit
 *   a session's minutes when the session ends. Each entry's minutes are
 *   therefore placed in the window ending at the snapshot that observed
 *   them ([t - minutes, t]) — exact for totals, approximate (to within one
 *   snapshot interval) for hour-of-day placement.
 */
class Ledger
{
    private string $file;
    private ?array $stateCache = null;

    public function __construct(string $steamId)
    {
        $this->file = __DIR__ . '/../cache/ledger_' . md5($steamId) . '.json.gz';
    }

    private function load(): array
    {
        if ($this->stateCache !== null) {
            return $this->stateCache;
        }

        $data = null;
        if (is_file($this->file)) {
            $raw = @file_get_contents($this->file);
            $json = $raw !== false ? @gzdecode($raw) : false;
            $data = $json !== false ? json_decode($json, true) : null;
        }

        return $this->stateCache = is_array($data) ? ($data + self::emptyState()) : self::emptyState();
    }

    private static function emptyState(): array
    {
        return ['version' => 1, 'started' => null, 'last' => null, 'entries' => []];
    }

    private function save(array $state): void
    {
        $this->stateCache = $state;

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Write-then-rename so a reader never sees a half-written file.
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, gzencode(json_encode($state), 6)) !== false) {
            @rename($tmp, $this->file);
        }
    }

    /**
     * Diff the given owned-games list against the previous snapshot and
     * record any playtime that's accumulated since. Skipped (cheaply) if
     * the previous snapshot is younger than $minInterval seconds, so
     * api.php can call this on every poll without bloating the ledger.
     *
     * @param array<int, array{appid:int, playtime:int}> $games
     * @return array{recorded:bool, first:bool, games:int, minutes:int}
     */
    public function snapshot(array $games, int $minInterval = 600): array
    {
        $result = ['recorded' => false, 'first' => false, 'games' => 0, 'minutes' => 0];
        if (empty($games)) {
            return $result; // private profile or failed fetch — never record an empty baseline
        }

        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }

        $this->stateCache = null; // re-read under the lock
        $state = $this->load();
        $now = time();

        $current = [];
        foreach ($games as $g) {
            $current[(string) $g['appid']] = (int) $g['playtime'];
        }

        if ($state['last'] === null) {
            $state['started'] = $now;
            $state['last'] = ['t' => $now, 'playtime' => $current];
            $this->save($state);
            $result['recorded'] = $result['first'] = true;
        } elseif ($now - (int) $state['last']['t'] >= $minInterval) {
            $intervalMinutes = ($now - (int) $state['last']['t']) / 60;
            $previous = $state['last']['playtime'];

            foreach ($current as $appid => $minutes) {
                if (isset($previous[$appid])) {
                    $delta = $minutes - (int) $previous[$appid];
                } else {
                    // A game that wasn't in the last snapshot at all — newly
                    // bought, or a licence that reappeared. Only count its
                    // playtime if it could plausibly have happened since the
                    // last snapshot; otherwise it's years-old history arriving
                    // all at once, which would wildly inflate "today".
                    $delta = $minutes <= $intervalMinutes + 60 ? $minutes : 0;
                }

                if ($delta > 0) {
                    $state['entries'][] = [$now, (int) $appid, $delta];
                    $result['games']++;
                    $result['minutes'] += $delta;
                }
            }

            $state['last'] = ['t' => $now, 'playtime' => $current];
            $this->save($state);
            $result['recorded'] = true;
        }

        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    public function started(): ?int
    {
        $started = $this->load()['started'];

        return $started === null ? null : (int) $started;
    }

    /**
     * Whether the ledger has been running since at least $sinceUnix, i.e.
     * whether totals for a period starting then are complete.
     */
    public function covers(int $sinceUnix): bool
    {
        $started = $this->started();

        return $started !== null && $sinceUnix > 0 && $sinceUnix >= $started;
    }

    public function hasEntries(): bool
    {
        return !empty($this->load()['entries']);
    }

    /**
     * The time window an entry's minutes are attributed to — see the
     * class docblock for why it ends at the snapshot rather than starting
     * at the previous one.
     *
     * @return array{0:int, 1:int}
     */
    private static function window(array $entry): array
    {
        return [(int) $entry[0] - (int) $entry[2] * 60, (int) $entry[0]];
    }

    /**
     * Minutes played per game within [$since, $until), counting only the
     * overlapping part of each entry's window so calendar boundaries
     * (midnight, Monday, the 1st) split sessions correctly.
     *
     * @return array<int, float> appid => minutes, heaviest first
     */
    public function minutesByGame(int $sinceUnix, ?int $untilUnix = null): array
    {
        $until = $untilUnix ?? PHP_INT_MAX;
        $totals = [];

        foreach ($this->load()['entries'] as $entry) {
            [$start, $end] = self::window($entry);
            $overlap = min($end, $until) - max($start, $sinceUnix);
            if ($overlap > 0) {
                $appid = (int) $entry[1];
                $totals[$appid] = ($totals[$appid] ?? 0) + $overlap / 60;
            }
        }

        arsort($totals);

        return $totals;
    }

    /**
     * Calls $fn($localDateTime, $minutes) for each hour-aligned slice of
     * every entry's window, in the given timezone.
     */
    private function eachHourSlice(DateTimeZone $tz, callable $fn, int $sinceUnix = 0): void
    {
        foreach ($this->load()['entries'] as $entry) {
            [$start, $end] = self::window($entry);
            $cursor = max($start, $sinceUnix);

            while ($cursor < $end) {
                $local = (new DateTime('@' . $cursor))->setTimezone($tz);
                $intoHour = (int) $local->format('i') * 60 + (int) $local->format('s');
                $sliceEnd = min($end, $cursor + 3600 - $intoHour);
                $fn($local, ($sliceEnd - $cursor) / 60);
                $cursor = $sliceEnd;
            }
        }
    }

    /**
     * @return float[] 24 entries, minutes played in each local hour of the day
     */
    public function hourly(DateTimeZone $tz): array
    {
        $hours = array_fill(0, 24, 0.0);
        $this->eachHourSlice($tz, function (DateTime $local, float $minutes) use (&$hours) {
            $hours[(int) $local->format('G')] += $minutes;
        });

        return $hours;
    }

    /**
     * @return float[] 7 entries (Mon..Sun), minutes played on each weekday
     */
    public function weekday(DateTimeZone $tz): array
    {
        $days = array_fill(0, 7, 0.0);
        $this->eachHourSlice($tz, function (DateTime $local, float $minutes) use (&$days) {
            $days[(int) $local->format('N') - 1] += $minutes;
        });

        return $days;
    }

    /**
     * @return array<string, float> 'Y-m-d' => minutes, for days since $sinceUnix
     */
    public function daily(DateTimeZone $tz, int $sinceUnix): array
    {
        $days = [];
        $this->eachHourSlice($tz, function (DateTime $local, float $minutes) use (&$days) {
            $key = $local->format('Y-m-d');
            $days[$key] = ($days[$key] ?? 0) + $minutes;
        }, $sinceUnix);
        ksort($days);

        return $days;
    }

    /**
     * Raw entries for the MCP server's playtime_log tool, newest first.
     *
     * @return array<int, array{appid:int, minutes:int, observed_at:int}>
     */
    public function entries(int $sinceUnix, ?int $untilUnix, int $limit): array
    {
        $out = [];
        foreach (array_reverse($this->load()['entries']) as $entry) {
            $t = (int) $entry[0];
            if ($t < $sinceUnix || ($untilUnix !== null && $t > $untilUnix)) {
                continue;
            }
            $out[] = ['appid' => (int) $entry[1], 'minutes' => (int) $entry[2], 'observed_at' => $t];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function coverage(): array
    {
        $state = $this->load();

        return [
            'tracking_since' => $state['started'] !== null ? date('c', (int) $state['started']) : null,
            'last_snapshot'  => $state['last'] !== null ? date('c', (int) $state['last']['t']) : null,
            'entries'        => count($state['entries']),
            'tracked_hours'  => round(array_sum(array_map(fn($e) => (int) $e[2], $state['entries'])) / 60, 1),
        ];
    }
}
