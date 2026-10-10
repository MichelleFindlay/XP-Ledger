<?php

require_once __DIR__ . '/Steam.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/Achievements.php';
require_once __DIR__ . '/RetroAchievements.php';
require_once __DIR__ . '/Xbox.php';
require_once __DIR__ . '/PlayStation.php';
require_once __DIR__ . '/Nintendo.php';

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
    private Xbox $xbox;
    private PlayStation $psn;
    private Nintendo $nintendo;
    private array $config;
    private ?array $ownedCache = null;
    private bool $private = false;

    public function __construct(Steam $steam, Ledger $ledger, Achievements $achievements, RetroAchievements $retro, Xbox $xbox, PlayStation $psn, Nintendo $nintendo, array $config)
    {
        $this->steam = $steam;
        $this->ledger = $ledger;
        $this->achievements = $achievements;
        $this->retro = $retro;
        $this->xbox = $xbox;
        $this->psn = $psn;
        $this->nintendo = $nintendo;
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

        $xbox = new Xbox($config['openxbl_api_key'] ?? '', (int) ($config['xbox_presence_ttl'] ?? 120));

        $psn = new PlayStation($config['psn_npsso'] ?? '', (int) ($config['psn_presence_ttl'] ?? 120));

        $nintendo = new Nintendo($config['nintendo_session_token'] ?? '', $config['nintendo_locale'] ?? 'en-US');

        return new self($steam, new Ledger($steam->steamId()), new Achievements($steam), $retro, $xbox, $psn, $nintendo, $config);
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

    public function xbox(): Xbox
    {
        return $this->xbox;
    }

    public function psn(): PlayStation
    {
        return $this->psn;
    }

    public function nintendo(): Nintendo
    {
        return $this->nintendo;
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

    public static function timeAgo(int $ts): string
    {
        if ($ts <= 0) {
            return 'never';
        }
        $secs = max(0, time() - $ts);
        foreach ([[86400 * 365, 'year'], [86400 * 30, 'month'], [86400 * 7, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $label]) {
            if ($secs >= 120 && $secs >= $unit) {
                $n = intdiv($secs, $unit);

                return $n . ' ' . $label . ($n === 1 ? '' : 's') . ' ago';
            }
        }

        return 'just now';
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
            'info'         => $this->steamInfo($appid, $g['name'] ?? ('App ' . $appid), $record && $record['has_stats'] ? $record : null),
        ];
    }

    /**
     * The hover card shown over a game's art (hero, previously played,
     * and every list row): who made it and when, your own numbers, and how
     * the wider world rates it. Store details are read cache-only, so a
     * tooltip never costs a live Store API call; a game whose details
     * haven't been fetched yet (cron.php fills them in) just shows fewer
     * lines.
     *
     * Shape: ['title' => string, 'groups' => [['lines' => string[], 'accent' => bool], ...]],
     * rendered by renderTooltip() in index.php and fillTooltip() in
     * assets/app.js.
     */
    private function steamInfo(int $appid, string $name, ?array $achievements): array
    {
        $g = $this->ownedGames()[$appid] ?? null;
        $d = $appid > 0 ? $this->steam->appDetails($appid, true) : null;
        if ($d !== null && !empty($d['unavailable'])) {
            $d = null;
        }

        $groups = [];

        $about = [];
        if ($d) {
            $byline = array_filter([
                implode(', ', array_slice($d['developers'], 0, 2)),
                $d['release_date'] !== '' ? 'Released ' . $d['release_date'] : '',
            ]);
            if ($byline) {
                $about[] = implode(' · ', $byline);
            }
            $genres = array_slice(array_values(array_diff($d['genres'], self::GENRE_BLOCKLIST)), 0, 4);
            if ($genres) {
                $about[] = implode(' · ', $genres);
            }
        } elseif ($appid === 0) {
            $about[] = 'Non-Steam game added to your library';
        }
        if ($about) {
            $groups[] = ['lines' => $about, 'accent' => false];
        }

        $you = [];
        if ($g) {
            $you[] = $g['playtime'] > 0
                ? self::formatMinutes($g['playtime']) . ' played' . ($g['playtime_2weeks'] > 0 ? ' · ' . self::formatMinutes($g['playtime_2weeks']) . ' in the last 2 weeks' : '')
                : 'Never played';
            if ($g['last_played'] > 0) {
                $you[] = 'Last played ' . self::timeAgo($g['last_played']);
            }

            // Steam only splits playtime by OS from around 2018, so these
            // are shares of the attributed hours, not of the lifetime total.
            $os = array_filter(['Windows' => $g['windows'], 'Linux' => $g['linux'], 'macOS' => $g['mac']]);
            $osTotal = array_sum($os);
            if ($osTotal > 0 && count($os) > 1) {
                arsort($os);
                $you[] = 'On ' . implode(' · ', array_map(fn($k, $v) => $k . ' ' . round($v / $osTotal * 100) . '%', array_keys($os), $os));
            } elseif ($osTotal > 0) {
                $you[] = 'Played on ' . array_key_first($os);
            }
            if ($g['deck'] > 0) {
                $you[] = self::formatMinutes($g['deck']) . ' on Steam Deck';
            }
        }
        if ($achievements && $achievements['total'] > 0) {
            $pct = (int) floor($achievements['unlocked'] / $achievements['total'] * 100);
            $you[] = $achievements['unlocked'] >= $achievements['total']
                ? 'All ' . $achievements['total'] . ' achievements — perfect game'
                : $achievements['unlocked'] . ' / ' . $achievements['total'] . ' achievements (' . $pct . '%)';
        }
        if ($you) {
            $groups[] = ['lines' => $you, 'accent' => true];
        }

        if ($d) {
            $world = array_filter([
                $d['recommendations'] > 0 ? number_format($d['recommendations']) . ' Steam reviews' : '',
                $d['metacritic'] !== null ? 'Metacritic ' . $d['metacritic'] : '',
                $d['is_free'] ? 'Free to play' : '',
            ]);
            if ($world) {
                $groups[] = ['lines' => [implode(' · ', $world)], 'accent' => false];
            }
        }

        return ['title' => $name, 'groups' => $groups];
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

        if ($this->xbox->enabled()) {
            foreach ($this->xboxCards() as $card) {
                $candidates[] = $card;
            }
        }

        if ($this->psn->enabled()) {
            foreach ($this->psnCards() as $card) {
                $candidates[] = $card;
            }
        }

        if ($this->nintendo->enabled()) {
            foreach ($this->nintendoCards() as $card) {
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
            'info'            => $this->steamInfo($appid, $name, $achievements),
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

            // RetroAchievements only includes developer/release/genre
            // details for the single most recent game (LastGame).
            $about = [(string) ($game['ConsoleName'] ?? '')];
            $last = $summary['LastGame'] ?? null;
            if ($i === 0 && $last && (int) ($last['ID'] ?? 0) === $gameId) {
                $about[] = implode(' · ', array_filter([(string) ($last['Developer'] ?? ''), !empty($last['Released']) ? 'Released ' . substr((string) $last['Released'], 0, 4) : '']));
                $about[] = (string) ($last['Genre'] ?? '');
            }
            $you = [];
            if ($total > 0) {
                $you[] = $unlocked . ' / ' . $total . ' achievements (' . (int) floor($unlocked / $total * 100) . '%)';
                $you[] = number_format((int) ($progress['ScoreAchieved'] ?? 0)) . ' of ' . number_format((int) ($progress['PossibleScore'] ?? 0)) . ' points';
            }
            $lastPlayed = RetroAchievements::parseTime($game['LastPlayed'] ?? null);
            if ($lastPlayed > 0) {
                $you[] = 'Last played ' . self::timeAgo($lastPlayed);
            }
            $info = ['title' => (string) ($game['Title'] ?? ''), 'groups' => array_values(array_filter([
                ['lines' => array_values(array_filter($about)), 'accent' => false],
                ['lines' => $you, 'accent' => true],
            ], fn($grp) => !empty($grp['lines'])))];

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
                'info'            => $info,
            ];
        }

        return $cards;
    }

    /**
     * Hero cards for Xbox: the game being played right now (from
     * presence), then the two most recently played titles.
     */
    private function xboxCards(): array
    {
        $titles = $this->xbox->titles();
        $playing = $this->xbox->nowPlaying();

        $cards = [];
        if ($playing) {
            $cards[] = $this->xboxCard($titles[$playing['title_id']] ?? null, $playing['name'], true, $playing['device'], $playing['presence']);
        }
        foreach (array_slice($titles, 0, 3, true) as $id => $t) {
            if (count($cards) >= 3) {
                break;
            }
            if (!$playing || (string) $id !== $playing['title_id']) {
                $cards[] = $this->xboxCard($t, $t['name'], false, null, null);
            }
        }

        return $cards;
    }

    private function xboxCard(?array $t, string $name, bool $playing, ?string $device, ?string $presence): array
    {
        $devices = $t ? array_values(array_unique(array_map([Xbox::class, 'deviceLabel'], $t['devices']))) : [];
        $platformLabel = 'Xbox' . ($device ? ' · ' . $device : ($devices ? ' · ' . implode(' / ', $devices) : ''));

        $facts = [];
        if ($t && $t['minutes'] > 0) {
            $facts[] = self::formatMinutes($t['minutes']) . ' played';
        }
        if ($t && $t['gamerscore_total'] > 0) {
            $facts[] = number_format($t['gamerscore']) . ' / ' . number_format($t['gamerscore_total']) . ' G';
        }

        // Xbox's title list doesn't always report an achievement total, so
        // gamerscore progress is the more reliable completion figure.
        $achievements = null;
        if ($t && $t['achievements_total'] > 0) {
            $achievements = ['unlocked' => $t['achievements'], 'total' => $t['achievements_total'], 'pct' => (int) floor($t['achievements'] / $t['achievements_total'] * 100)];
        }

        return [
            'key'             => 'xbox:' . ($t['title_id'] ?? md5($name)),
            'platform'        => 'xbox',
            'platform_label'  => $platformLabel,
            'id'              => $t['title_id'] ?? '',
            'name'            => $name,
            'subtitle'        => $t && $t['game_pass'] ? 'Game Pass' : '',
            'art'             => $t['image'] ?? '',
            'art_fallback'    => '',
            'background'      => $t['image'] ?? '',
            'playing'         => $playing,
            'last_played'     => $playing ? time() : (int) ($t['last_played'] ?? 0),
            'session_started' => null,
            'facts'           => $facts,
            'achievements'    => $achievements,
            'presence'        => $presence,
            'links'           => [['kind' => 'store', 'label' => 'Xbox Store', 'url' => Xbox::storeSearchUrl($name)]],
            'info'            => $this->xboxInfo($t, $name, $playing),
        ];
    }

    /**
     * The hover card for an Xbox title (same shape as steamInfo()), shared
     * by the now-playing card and the Xbox panel. Xbox's title list has no
     * developer/genre/review data, so it's devices, Game Pass, and your
     * own numbers.
     */
    public function xboxInfo(?array $t, string $name, bool $playing = false): array
    {
        $about = [];
        $you = [];
        if ($t) {
            $devices = array_values(array_unique(array_map([Xbox::class, 'deviceLabel'], $t['devices'])));
            $about[] = implode(' · ', array_filter([implode(' / ', $devices), $t['game_pass'] ? 'Game Pass' : '']));

            if ($t['minutes'] > 0) {
                $you[] = self::formatMinutes($t['minutes']) . ' played';
            }
            if ($t['gamerscore_total'] > 0) {
                $you[] = number_format($t['gamerscore']) . ' of ' . number_format($t['gamerscore_total']) . ' gamerscore (' . $t['progress_pct'] . '%)';
            }
            if ($t['achievements'] > 0) {
                $you[] = $t['achievements'] . ($t['achievements_total'] > 0 ? ' / ' . $t['achievements_total'] : '') . ' achievements';
            }
            if ($t['last_played'] > 0 && !$playing) {
                $you[] = 'Last played ' . self::timeAgo($t['last_played']);
            }
        }

        return ['title' => $name, 'groups' => array_values(array_filter([
            ['lines' => array_values(array_filter($about)), 'accent' => false],
            ['lines' => $you, 'accent' => true],
        ], fn($g) => !empty($g['lines'])))];
    }

    /**
     * Hero cards for PlayStation: the game being played right now (from
     * presence), then the most recently played games.
     */
    private function psnCards(): array
    {
        $games = $this->psn->games();
        $playing = $this->psn->nowPlaying();

        $cards = [];
        $playingKey = null;
        if ($playing) {
            $playingKey = PlayStation::normalize($playing['name']);
            $g = $games[$playingKey] ?? null;
            if ($g === null) {
                // Presence knows the name, platform and icon even for a game
                // that hasn't reached the played-games list yet.
                $g = ['id' => $playing['id'], 'name' => $playing['name'], 'image' => $playing['image'], 'platform' => $playing['platform'],
                      'minutes' => 0, 'play_count' => 0, 'first_played' => 0, 'last_played' => time(), 'trophies' => null];
            }
            $cards[] = $this->psnCard($g, true, $playing['platform']);
        }
        foreach ($games as $key => $g) {
            if (count($cards) >= 3) {
                break;
            }
            if ($key !== $playingKey) {
                $cards[] = $this->psnCard($g, false, null);
            }
        }

        return $cards;
    }

    private function psnCard(array $g, bool $playing, ?string $platform): array
    {
        $t = $g['trophies'];
        $facts = [];
        if ($g['minutes'] > 0) {
            $facts[] = self::formatMinutes($g['minutes']) . ' played';
        }
        if ($t && $t['defined_total'] > 0) {
            $facts[] = $t['earned_total'] . ' / ' . $t['defined_total'] . ' trophies' . ($t['earned']['platinum'] > 0 ? ' · Platinum' : '');
        }

        return [
            'key'             => 'psn:' . PlayStation::normalize($g['name']),
            'platform'        => 'playstation',
            'platform_label'  => 'PlayStation · ' . ($platform ?: $g['platform']),
            'id'              => $g['id'],
            'name'            => $g['name'],
            'subtitle'        => '',
            'art'             => $g['image'],
            'art_fallback'    => '',
            'background'      => $g['image'],
            'playing'         => $playing,
            'last_played'     => $playing ? time() : $g['last_played'],
            'session_started' => null,
            'facts'           => $facts,
            // Trophies stand in for achievements in the hero's progress bar,
            // using Sony's own weighted progress percentage.
            'achievements'    => $t && $t['defined_total'] > 0 ? ['unlocked' => $t['earned_total'], 'total' => $t['defined_total'], 'pct' => $t['progress'], 'noun' => 'trophies'] : null,
            'presence'        => null,
            'links'           => [['kind' => 'store', 'label' => 'PS Store', 'url' => PlayStation::storeSearchUrl($g['name'])]],
            'info'            => $this->psnInfo($g, $playing),
        ];
    }

    /**
     * The hover card for a PlayStation game (same shape as steamInfo()),
     * shared by the now-playing card and the PlayStation panel.
     */
    public function psnInfo(array $g, bool $playing = false): array
    {
        $about = [$g['platform']];
        $you = [];
        if ($g['minutes'] > 0) {
            $you[] = self::formatMinutes($g['minutes']) . ' played' . ($g['play_count'] > 1 ? ' over ' . number_format($g['play_count']) . ' sessions' : '');
        }
        if ($g['first_played'] > 0) {
            $you[] = 'First played ' . date('j M Y', $g['first_played']);
        }
        if ($g['last_played'] > 0 && !$playing) {
            $you[] = 'Last played ' . self::timeAgo($g['last_played']);
        }

        $trophyLines = [];
        $t = $g['trophies'];
        if ($t && $t['defined_total'] > 0) {
            $trophyLines[] = $t['earned_total'] . ' / ' . $t['defined_total'] . ' trophies (' . $t['progress'] . '%)';
            $parts = [];
            foreach (['platinum', 'gold', 'silver', 'bronze'] as $tier) {
                if ($t['defined'][$tier] > 0) {
                    $parts[] = ucfirst($tier) . ' ' . $t['earned'][$tier] . '/' . $t['defined'][$tier];
                }
            }
            $trophyLines[] = implode(' · ', $parts);
        }

        return ['title' => $g['name'], 'groups' => array_values(array_filter([
            ['lines' => array_values(array_filter($about)), 'accent' => false],
            ['lines' => $you, 'accent' => true],
            ['lines' => $trophyLines, 'accent' => false],
        ], fn($grp) => !empty($grp['lines'])))];
    }

    /**
     * Hero cards for Nintendo: the two most recently played games. Never
     * "playing" — Nintendo's play history has no live presence, and it's
     * updated after a session ends, not during it.
     */
    private function nintendoCards(): array
    {
        $cards = [];
        foreach (array_slice($this->nintendo->games(), 0, 2) as $g) {
            $cards[] = [
                'key'             => 'nintendo:' . ($g['id'] ?: md5($g['name'])),
                'platform'        => 'nintendo',
                'platform_label'  => 'Nintendo · ' . implode(' / ', $g['platforms']),
                'id'              => $g['id'],
                'name'            => $g['name'],
                'subtitle'        => '',
                'art'             => $g['image'],
                'art_fallback'    => '',
                'background'      => $g['image'],
                'playing'         => false,
                'last_played'     => $g['last_played'],
                'session_started' => null,
                'facts'           => $g['minutes'] > 0 ? [self::formatMinutes($g['minutes']) . ' played over ' . number_format($g['days']) . ' day' . ($g['days'] === 1 ? '' : 's')] : [],
                'achievements'    => null,
                'presence'        => null,
                'links'           => [['kind' => 'store', 'label' => 'Nintendo eShop', 'url' => Nintendo::storeSearchUrl($g['name'])]],
                'info'            => $this->nintendoInfo($g),
            ];
        }

        return $cards;
    }

    /**
     * The hover card for a Nintendo game (same shape as steamInfo()).
     */
    public function nintendoInfo(array $g): array
    {
        $you = [];
        if ($g['minutes'] > 0) {
            $you[] = self::formatMinutes($g['minutes']) . ' played';
            if ($g['days'] > 0) {
                $you[] = 'Played on ' . number_format($g['days']) . ' day' . ($g['days'] === 1 ? '' : 's') . ' · ' . self::formatMinutes($g['minutes'] / $g['days']) . ' a day on average';
            }
        }
        if ($g['first_played'] > 0) {
            $you[] = 'First played ' . date('j M Y', $g['first_played']);
        }
        if ($g['last_played'] > 0) {
            $you[] = 'Last played ' . self::timeAgo($g['last_played']);
        }

        return ['title' => $g['name'], 'groups' => array_values(array_filter([
            ['lines' => [implode(' / ', $g['platforms'])], 'accent' => false],
            ['lines' => $you, 'accent' => true],
        ], fn($grp) => !empty(array_filter($grp['lines']))))];
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
