<?php

require_once __DIR__ . '/Steam.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Achievements.php';
require_once __DIR__ . '/RetroAchievements.php';

/**
 * Pulls the per-platform clients together into the shapes the dashboard
 * actually shows: now playing, most played per period, recently played,
 * genre breakdown, lifetime stats. Shared by index.php (server render),
 * api.php (polling), widgets.php (AJAX period switches), cron.php and the
 * MCP server, so every surface computes these the same way.
 */
class Library
{
    /**
     * Steam "genres" that are really store labels or content descriptors,
     * not kinds of game — left out of Genre Breakdown.
     */
    private const GENRE_BLOCKLIST = ['Free To Play', 'Free to Play', 'Early Access', 'Nudity', 'Violent', 'Gore', 'Sexual Content'];

    public const PERIOD_LABELS = [
        'all_time'   => 'All Time',
        'this_year'  => 'This Year',
        'this_month' => 'This Month',
        'this_week'  => 'This Week',
        'today'      => 'Today',
    ];

    private Steam $steam;
    private Ledger $ledger;
    private Achievements $achievements;
    private RetroAchievements $retro;
    private array $config;
    private ?array $ownedCache = null;
    private bool $private = false;

    public function __construct(Steam $steam, Ledger $ledger, Achievements $achievements, RetroAchievements $retro, array $config)
    {
        $this->steam = $steam;
        $this->ledger = $ledger;
        $this->achievements = $achievements;
        $this->retro = $retro;
        $this->config = $config;
    }

    /**
     * Builds the whole object graph from config — the one place that
     * knows how the pieces fit together.
     */
    public static function fromConfig(array $config, ?int $cacheTtl = null): self
    {
        $steam = new Steam($config['steam_api_key'] ?? '', $config['steam_id'] ?? '', $cacheTtl ?? (int) ($config['cache_ttl'] ?? 120));
        $retro = new RetroAchievements($config['retroachievements_username'] ?? '', $config['retroachievements_api_key'] ?? '', (int) ($config['cache_ttl'] ?? 120));

        return new self($steam, new Ledger($steam->steamId()), new Achievements($steam), $retro, $config);
    }

    public function steam(): Steam
    {
        return $this->steam;
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }

    public function achievements(): Achievements
    {
        return $this->achievements;
    }

    public function retro(): RetroAchievements
    {
        return $this->retro;
    }

    public function timezone(): DateTimeZone
    {
        return self::resolveTimezone($this->config['timezone'] ?? '');
    }

    // --- Period helpers (same calendar semantics as lastfm-dash) ---

    public static function resolveTimezone(string $tzName): DateTimeZone
    {
        if ($tzName !== '') {
            try {
                return new DateTimeZone($tzName);
            } catch (Exception $e) {
                // fall through to the server default
            }
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    public static function validUiPeriod(string $value, string $default = 'all_time'): string
    {
        return isset(self::PERIOD_LABELS[$value]) ? $value : $default;
    }

    /**
     * Exact calendar start of a UI period: real midnight, real Monday,
     * real 1st of the month, real Jan 1.
     */
    public static function periodStart(string $uiPeriod, DateTimeZone $tz): int
    {
        $today = new DateTime('today', $tz);

        switch ($uiPeriod) {
            case 'today':
                return $today->getTimestamp();
            case 'this_week':
                return (clone $today)->modify('-' . ((int) $today->format('N') - 1) . ' days')->getTimestamp();
            case 'this_month':
                return (clone $today)->modify('first day of this month')->getTimestamp();
            case 'this_year':
                return (new DateTime($today->format('Y') . '-01-01', $tz))->getTimestamp();
            default:
                return 0;
        }
    }

    // --- Formatting ---

    public static function formatMinutes(float $minutes): string
    {
        if ($minutes < 60) {
            return max(1, (int) round($minutes)) . ' min';
        }
        $hours = $minutes / 60;

        return ($hours < 100 ? number_format($hours, 1) : number_format($hours)) . ' h';
    }

    // --- Steam library ---

    /**
     * Owned games normalized to a flat shape, keyed by appid.
     *
     * @return array<int, array{appid:int, name:string, playtime:int, playtime_2weeks:int, last_played:int, icon:string, windows:int, mac:int, linux:int, deck:int}>
     */
    public function ownedGames(): array
    {
        if ($this->ownedCache !== null) {
            return $this->ownedCache;
        }

        $owned = $this->steam->ownedGames();
        $this->private = !empty($owned['private']);

        $games = [];
        foreach ($owned['games'] ?? [] as $g) {
            $appid = (int) ($g['appid'] ?? 0);
            if ($appid <= 0) {
                continue;
            }
            $games[$appid] = [
                'appid'           => $appid,
                'name'            => (string) ($g['name'] ?? ('App ' . $appid)),
                'playtime'        => (int) ($g['playtime_forever'] ?? 0),
                'playtime_2weeks' => (int) ($g['playtime_2weeks'] ?? 0),
                'last_played'     => (int) ($g['rtime_last_played'] ?? 0),
                'icon'            => Steam::iconUrl($appid, (string) ($g['img_icon_url'] ?? '')),
                'windows'         => (int) ($g['playtime_windows_forever'] ?? 0),
                'mac'             => (int) ($g['playtime_mac_forever'] ?? 0),
                'linux'           => (int) ($g['playtime_linux_forever'] ?? 0),
                'deck'            => (int) ($g['playtime_deck_forever'] ?? 0),
            ];
        }

        return $this->ownedCache = $games;
    }

    /**
     * True when Steam hid the games list because the profile's "Game
     * details" privacy setting isn't Public.
     */
    public function isPrivate(): bool
    {
        $this->ownedGames();

        return $this->private;
    }

    public function snapshotLedger(int $minInterval): array
    {
        return $this->ledger->snapshot(array_values($this->ownedGames()), $minInterval);
    }

    /**
     * One list row's worth of data for a game.
     */
    private function gameRow(int $appid, float $minutes, float $maxMinutes, int $rank): array
    {
        $owned = $this->ownedGames();
        $g = $owned[$appid] ?? null;
        $record = $this->achievements->get($appid);

        return [
            'rank'         => $rank,
            'appid'        => $appid,
            'name'         => $g['name'] ?? ('App ' . $appid),
            'minutes'      => round($minutes),
            'label'        => self::formatMinutes($minutes),
            'total_label'  => $g ? self::formatMinutes($g['playtime']) . ' total' : '',
            'pct'          => max(4, (int) round($minutes / max(1, $maxMinutes) * 100)),
            'art'          => Steam::headerArt($appid),
            'last_played'  => $g['last_played'] ?? 0,
            'achievements' => ($record && $record['has_stats']) ? ['unlocked' => $record['unlocked'], 'total' => $record['total']] : null,
            'url'          => Steam::storeUrl($appid),
        ];
    }

    /**
     * @param array<int, float> $minutesByGame
     */
    private function rowsFromMinutes(array $minutesByGame, int $limit): array
    {
        arsort($minutesByGame);
        $top = array_slice($minutesByGame, 0, $limit, true);
        $max = $top ? max($top) : 1;

        $rows = [];
        $rank = 1;
        foreach ($top as $appid => $minutes) {
            if ($minutes <= 0) {
                continue;
            }
            $rows[] = $this->gameRow((int) $appid, (float) $minutes, (float) $max, $rank++);
        }

        return $rows;
    }

    /**
     * Minutes per game for a UI period, plus where the numbers came from.
     *
     * All Time is Steam's own lifetime counter, so it's always exact. Every
     * other period comes from the ledger: exact once the ledger has been
     * running since before the period began, partial (and labelled as
     * such) before that. With nothing in the ledger yet at all, This
     * Week/Month/Year fall back to Steam's rolling last-two-weeks figure —
     * clearly labelled, since it's a different window.
     *
     * @return array{minutes: array<int, float>, source: string, note: ?string}
     */
    public function minutesForPeriod(string $period, DateTimeZone $tz): array
    {
        $owned = $this->ownedGames();

        if ($period === 'all_time') {
            return ['minutes' => array_map(fn($g) => (float) $g['playtime'], $owned), 'source' => 'steam', 'note' => null];
        }

        $since = self::periodStart($period, $tz);
        if ($this->ledger->covers($since)) {
            return ['minutes' => $this->ledger->minutesByGame($since), 'source' => 'ledger', 'note' => null];
        }

        $started = $this->ledger->started();
        $label = strtolower(self::PERIOD_LABELS[$period]);
        $partial = $started !== null ? $this->ledger->minutesByGame($since) : [];

        if (!empty($partial) || $period === 'today') {
            return [
                'minutes' => $partial,
                'source'  => 'ledger_partial',
                'note'    => $started !== null
                    ? 'Tracking since ' . (new DateTime('@' . $started))->setTimezone($tz)->format('j M, H:i') . ' — earlier play ' . $label . " isn't included."
                    : 'Playtime tracking starts the first time cron.php (or this page) takes a snapshot.',
            ];
        }

        return [
            'minutes' => array_map(fn($g) => (float) $g['playtime_2weeks'], $owned),
            'source'  => 'steam_2weeks',
            'note'    => "Showing Steam's last-two-weeks playtime — exact " . $label . ' totals fill in as XP Ledger tracks your sessions.',
        ];
    }

    public function gamesForPeriod(string $period, int $limit, DateTimeZone $tz): array
    {
        $result = $this->minutesForPeriod($period, $tz);

        return [
            'period' => $period,
            'games'  => $this->rowsFromMinutes($result['minutes'], $limit),
            'source' => $result['source'],
            'note'   => $result['note'],
        ];
    }

    public function recentlyPlayed(int $limit): array
    {
        $games = array_filter($this->ownedGames(), fn($g) => $g['last_played'] > 0);
        usort($games, fn($a, $b) => $b['last_played'] <=> $a['last_played']);
        $games = array_slice($games, 0, $limit);
        $max = max(1, ...array_map(fn($g) => $g['playtime_2weeks'], $games ?: [['playtime_2weeks' => 1]]));

        $rows = [];
        foreach ($games as $i => $g) {
            $row = $this->gameRow($g['appid'], (float) $g['playtime_2weeks'], (float) $max, $i + 1);
            $row['label'] = $g['playtime_2weeks'] > 0 ? self::formatMinutes($g['playtime_2weeks']) . ' / 2 wks' : self::formatMinutes($g['playtime']) . ' total';
            $row['pct'] = $g['playtime_2weeks'] > 0 ? $row['pct'] : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Store metadata for a set of games: cache-only, plus up to $liveBudget
     * live Store API lookups for the heaviest-weighted games still missing
     * (cron.php fills in the rest in paced batches).
     *
     * @param array<int, float> $weights appid => weight, used to prioritize live lookups
     * @return array<int, array> appid => details (only games we have details for)
     */
    public function storeDetails(array $weights, int $liveBudget): array
    {
        arsort($weights);
        $details = [];
        foreach (array_keys($weights) as $appid) {
            $d = $this->steam->appDetails((int) $appid, true);
            if ($d === null && $liveBudget > 0) {
                $liveBudget--;
                $d = $this->steam->appDetails((int) $appid);
            }
            if ($d !== null && empty($d['unavailable'])) {
                $details[(int) $appid] = $d;
            }
        }

        return $details;
    }

    /**
     * Playtime-weighted genre breakdown for a period: each game's minutes
     * are split evenly across its Steam store genres.
     */
    public function genresForPeriod(string $period, DateTimeZone $tz): array
    {
        $result = $this->minutesForPeriod($period, $tz);
        $minutes = array_filter($result['minutes'], fn($m) => $m > 0);
        $details = $this->storeDetails($minutes, (int) ($this->config['store_live_budget'] ?? 10));

        $scores = [];
        $covered = 0.0;
        foreach ($minutes as $appid => $m) {
            $genres = array_values(array_diff($details[$appid]['genres'] ?? [], self::GENRE_BLOCKLIST));
            if (!$genres) {
                continue;
            }
            $covered += $m;
            foreach ($genres as $genre) {
                $scores[$genre] = ($scores[$genre] ?? 0) + $m / count($genres);
            }
        }

        arsort($scores);
        $total = array_sum($scores);
        $genres = [];
        foreach ($scores as $name => $score) {
            $genres[] = ['name' => $name, 'pct' => round($score / max(1, $total) * 100, 1), 'hours' => round($score / 60, 1)];
        }

        $totalMinutes = array_sum($minutes);

        return [
            'period'   => $period,
            'genres'   => $genres,
            'coverage' => $totalMinutes > 0 ? (int) round($covered / $totalMinutes * 100) : 0,
            'source'   => $result['source'],
            'note'     => $result['note'],
        ];
    }

    // --- Now playing ---

    /**
     * The hero card: what's being played right now across platforms (or
     * the most recently played game), plus the one played before it.
     */
    public function nowPlaying(?int $summaryTtl = null): array
    {
        $candidates = [];
        $owned = $this->ownedGames();

        $summary = $this->steam->playerSummary($summaryTtl);
        $steamPlayingId = (int) ($summary['gameid'] ?? 0);
        $steamPlayingName = (string) ($summary['gameextrainfo'] ?? '');

        if ($steamPlayingName !== '') {
            // Non-Steam games added as library shortcuts report a synthetic
            // 64-bit gameid that doesn't exist on the store — name only.
            $isRealApp = $steamPlayingId > 0 && $steamPlayingId < 0xFFFFFFFF;
            $candidates[] = $this->steamCard($isRealApp ? $steamPlayingId : 0, $steamPlayingName, true, time());
        }

        $byRecent = $owned;
        usort($byRecent, fn($a, $b) => $b['last_played'] <=> $a['last_played']);
        foreach (array_slice($byRecent, 0, 3) as $g) {
            if ($g['last_played'] > 0 && $g['appid'] !== $steamPlayingId) {
                $candidates[] = $this->steamCard($g['appid'], $g['name'], false, $g['last_played']);
            }
        }

        if ($this->retro->enabled()) {
            foreach ($this->retroCards($summaryTtl) as $card) {
                $candidates[] = $card;
            }
        }

        // Playing beats not playing; otherwise most recent first.
        usort($candidates, function ($a, $b) {
            return [$b['playing'], $b['last_played']] <=> [$a['playing'], $a['last_played']];
        });

        $current = $candidates[0] ?? null;
        $previous = null;
        foreach (array_slice($candidates, 1) as $c) {
            if ($current && $c['key'] !== $current['key']) {
                $previous = $c;
                break;
            }
        }

        if ($current && $current['playing']) {
            $current['session_started'] = $this->trackSession($current['key']);
        }

        return [
            'current'  => $current,
            'previous' => $previous,
            'persona'  => $summary ? [
                'name'   => (string) ($summary['personaname'] ?? ''),
                'avatar' => (string) ($summary['avatarfull'] ?? ''),
                'url'    => (string) ($summary['profileurl'] ?? ''),
                'state'  => (int) ($summary['personastate'] ?? 0),
            ] : null,
        ];
    }

    private function steamCard(int $appid, string $name, bool $playing, int $lastPlayed): array
    {
        $owned = $this->ownedGames();
        $g = $owned[$appid] ?? null;

        $facts = [];
        if ($g) {
            $facts[] = self::formatMinutes($g['playtime']) . ' played';
            if ($g['playtime_2weeks'] > 0) {
                $facts[] = self::formatMinutes($g['playtime_2weeks']) . ' in the last 2 weeks';
            }
        }

        $achievements = null;
        if ($appid > 0 && $g) {
            $record = $playing ? $this->achievements->forGame($appid, $g['playtime']) : $this->achievements->get($appid);
            if ($record && $record['has_stats']) {
                $achievements = [
                    'unlocked' => $record['unlocked'],
                    'total'    => $record['total'],
                    'pct'      => (int) floor($record['unlocked'] / max(1, $record['total']) * 100),
                ];
            }
        }

        $details = $appid > 0 ? $this->steam->appDetails($appid, true) : null;
        $subtitle = $details && empty($details['unavailable']) ? implode(' · ', array_slice(array_diff($details['genres'], self::GENRE_BLOCKLIST), 0, 3)) : '';

        $links = [];
        if ($appid > 0) {
            $links = [
                ['kind' => 'launch', 'label' => 'Launch', 'url' => 'steam://run/' . $appid],
                ['kind' => 'store', 'label' => 'Store', 'url' => Steam::storeUrl($appid)],
                ['kind' => 'steamdb', 'label' => 'SteamDB', 'url' => 'https://steamdb.info/app/' . $appid . '/'],
                ['kind' => 'protondb', 'label' => 'ProtonDB', 'url' => 'https://www.protondb.com/app/' . $appid],
            ];
        }

        return [
            'key'             => 'steam:' . ($appid ?: md5($name)),
            'platform'        => 'steam',
            'platform_label'  => $appid > 0 ? 'Steam' : 'Steam · non-Steam game',
            'id'              => $appid,
            'name'            => $name,
            'subtitle'        => $subtitle,
            'art'             => $appid > 0 ? Steam::portraitArt($appid) : '',
            'art_fallback'    => $appid > 0 ? Steam::headerArt($appid) : '',
            'background'      => $appid > 0 ? Steam::heroArt($appid) : '',
            'playing'         => $playing,
            'last_played'     => $lastPlayed,
            'session_started' => null,
            'facts'           => $facts,
            'achievements'    => $achievements,
            'presence'        => null,
            'links'           => $links,
        ];
    }

    private function retroCards(?int $ttl): array
    {
        $summary = $this->retro->summary($ttl);
        if (!$summary) {
            return [];
        }

        $presenceAt = RetroAchievements::parseTime($summary['RichPresenceMsgDate'] ?? null);
        $online = ($summary['Status'] ?? '') === 'Online' || ($presenceAt > 0 && time() - $presenceAt < 300);
        $awarded = $summary['Awarded'] ?? [];

        $cards = [];
        foreach (array_slice($summary['RecentlyPlayed'] ?? [], 0, 2) as $i => $game) {
            $gameId = (int) ($game['GameID'] ?? 0);
            $playing = $i === 0 && $online && $gameId === (int) ($summary['LastGameID'] ?? 0);
            $progress = $awarded[(string) $gameId] ?? null;
            $total = (int) ($progress['NumPossibleAchievements'] ?? $game['AchievementsTotal'] ?? 0);
            $unlocked = (int) ($progress['NumAchievedHardcore'] ?? 0) ?: (int) ($progress['NumAchieved'] ?? 0);

            $cards[] = [
                'key'             => 'ra:' . $gameId,
                'platform'        => 'retroachievements',
                'platform_label'  => 'RetroAchievements · ' . ($game['ConsoleName'] ?? ''),
                'id'              => $gameId,
                'name'            => (string) ($game['Title'] ?? ''),
                'subtitle'        => (string) ($summary['LastGame']['Genre'] ?? '') !== '' && $i === 0 ? (string) $summary['LastGame']['Genre'] : (string) ($game['ConsoleName'] ?? ''),
                'art'             => RetroAchievements::media($game['ImageBoxArt'] ?? null),
                'art_fallback'    => RetroAchievements::media($game['ImageTitle'] ?? null),
                'background'      => RetroAchievements::media($game['ImageIngame'] ?? null),
                'playing'         => $playing,
                'last_played'     => $playing ? time() : RetroAchievements::parseTime($game['LastPlayed'] ?? null),
                'session_started' => null,
                'facts'           => $total > 0 ? [number_format((int) ($progress['ScoreAchieved'] ?? 0)) . ' points earned'] : [],
                'achievements'    => $total > 0 ? ['unlocked' => $unlocked, 'total' => $total, 'pct' => (int) floor($unlocked / $total * 100)] : null,
                'presence'        => $playing ? (string) ($summary['RichPresenceMsg'] ?? '') : null,
                'links'           => [['kind' => 'store', 'label' => 'Game page', 'url' => RetroAchievements::gameUrl($gameId)]],
            ];
        }

        return $cards;
    }

    /**
     * When the current play session started, as first observed by this
     * app — every poll/cron run that sees the same game keeps the session
     * alive; a gap of 30+ minutes starts a new one.
     */
    private function trackSession(string $key): int
    {
        $file = __DIR__ . '/../cache/session.json';
        $now = time();
        $session = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($session) || ($session['key'] ?? '') !== $key || $now - (int) ($session['last_seen'] ?? 0) > 1800) {
            $session = ['key' => $key, 'started' => $now];
        }
        $session['last_seen'] = $now;
        @file_put_contents($file, json_encode($session));

        return (int) $session['started'];
    }

    // --- Lifetime stats ---

    /**
     * @return array<string, array{icon:string, label:string, value:string, title?:string}>
     */
    public function lifetimeStats(): array
    {
        $owned = $this->ownedGames();
        $stats = [];

        if ($owned) {
            $totalMinutes = array_sum(array_column($owned, 'playtime'));
            $played = count(array_filter($owned, fn($g) => $g['playtime'] > 0));

            $stats['owned'] = ['icon' => 'library', 'label' => 'Games Owned', 'value' => number_format(count($owned))];
            $stats['hours'] = ['icon' => 'clock', 'label' => 'Hours Played', 'value' => number_format($totalMinutes / 60)];
            $stats['played_pct'] = ['icon' => 'gamepad', 'label' => 'Library Played', 'value' => (int) round($played / count($owned) * 100) . '%'];

            $ach = $this->achievements->summary($played);
            if ($ach['checked_games'] > 0) {
                $coverageNote = $ach['coverage_pct'] < 100 ? 'From ' . $ach['coverage_pct'] . '% of played games checked so far — cron.php fills in the rest' : null;
                $stats['achievements'] = ['icon' => 'trophy', 'label' => 'Achievements', 'value' => number_format($ach['unlocked']), 'title' => $coverageNote];
                $stats['perfect'] = ['icon' => 'medal', 'label' => 'Perfect Games', 'value' => number_format($ach['perfect_games']), 'title' => $coverageNote];
            }
        }

        $badges = $this->steam->badges();
        if ($badges) {
            $stats['level'] = [
                'icon'  => 'zap',
                'label' => 'Steam Level',
                'value' => number_format((int) $badges['player_level']),
                'title' => number_format((int) ($badges['player_xp'] ?? 0)) . ' XP · ' . number_format((int) ($badges['player_xp_needed_to_level_up'] ?? 0)) . ' to next level',
            ];
        }

        $summary = $this->steam->playerSummary();
        if (!empty($summary['timecreated'])) {
            $stats['member_since'] = ['icon' => 'calendar-days', 'label' => 'On Steam Since', 'value' => date('Y', (int) $summary['timecreated'])];
        }

        if ($this->retro->enabled()) {
            $ra = $this->retro->summary();
            if ($ra) {
                $stats['ra_points'] = [
                    'icon'  => 'joystick',
                    'label' => 'RetroAchievements Points',
                    'value' => number_format((int) ($ra['TotalPoints'] ?? 0)),
                    'title' => !empty($ra['Rank']) ? 'Rank #' . number_format((int) $ra['Rank']) . ' of ' . number_format((int) ($ra['TotalRanked'] ?? 0)) : null,
                ];
            }
        }

        return $stats;
    }
}
