<?php

require_once __DIR__ . '/WidgetRegistry.php';

/**
 * Defines and executes the tools exposed over mcp.php — see that file for
 * the transport/auth layer. Read-only: every tool just reads existing
 * Steam / RetroAchievements / ledger data, nothing here modifies anything.
 */
class Mcp
{
    private const WIDGET_TOOLS = [
        'unlock_clock'    => 'When achievements get unlocked, by local hour of day, from exact Steam unlock timestamps.',
        'weekly_rhythm'   => 'Playtime by weekday, a 12-week day-by-day grid, and the current daily play streak, from the playtime ledger.',
        'time_translated' => 'Total lifetime playtime measured in gaming units: Super Mario Bros. speedruns, Minecraft and GTA V in-game days, Portal and Elden Ring playthroughs, Stardew Valley years, Games Done Quick marathons.',
        'loot_tiers'      => 'Most-played games ranked into legendary/epic/rare/uncommon tiers by playtime.',
        'pile_of_shame'   => 'Unplayed and barely-played game counts, the best-known unplayed games, and a rough backlog-clearing estimate.',
        'completionist'   => 'Achievement completion stats, perfect (100%) games, and the games closest to 100%.',
        'rarest'          => 'The rarest achievements unlocked, by Steam global unlock percentage.',
        'hidden_gems'     => 'Most-played games with the fewest Steam reviews.',
        'mainstream'      => 'Median/average Steam review count across the most-played games — how mainstream the taste is.',
        'platform_split'  => 'Lifetime playtime split by operating system (Windows, Linux/SteamOS, macOS, Steam Deck).',
    ];

    private const PERIODS = ['all_time', 'this_year', 'this_month', 'this_week', 'today'];

    private Library $library;
    private array $config;
    private array $handlers;

    public function __construct(Library $library, Widgets $widgets, array $config)
    {
        $this->library = $library;
        $this->config = $config;
        $this->handlers = WidgetRegistry::handlers($library, $widgets, $config);
    }

    public function initialize(): array
    {
        $versionFile = __DIR__ . '/../VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';

        return [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'xp-ledger', 'version' => $version],
        ];
    }

    public function listTools(): array
    {
        $noArgs = ['type' => 'object', 'properties' => new stdClass()];
        $range = [
            'since' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime, e.g. "2026-01-01". Omit for no lower bound.'],
            'until' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime. Omit for now.'],
            'limit' => ['type' => 'integer', 'description' => 'Max entries to return (default 200, max 2000).'],
        ];

        $tools = [
            [
                'name'        => 'get_now_playing',
                'description' => 'The game being played right now (Steam, plus RetroAchievements, Xbox and PlayStation if configured) or the most recently played one, plus the game before it.',
                'inputSchema' => $noArgs,
            ],
            [
                'name'        => 'list_games',
                'description' => 'The Steam library: every owned game with lifetime playtime, last-two-weeks playtime, last-played date, and achievement progress where known.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'filter' => ['type' => 'string', 'enum' => ['all', 'played', 'unplayed'], 'description' => 'Defaults to all.'],
                        'sort'   => ['type' => 'string', 'enum' => ['playtime', 'last_played', 'name'], 'description' => 'Defaults to playtime.'],
                        'limit'  => ['type' => 'integer', 'description' => 'Max games to return (default 50, max 5000).'],
                    ],
                ],
            ],
            [
                'name'        => 'top_games',
                'description' => 'Most-played games for a period. all_time is Steam\'s own lifetime counter; other periods come from the playtime ledger, and the response says whether that period is fully covered.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'period' => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'Defaults to all_time.'],
                        'limit'  => ['type' => 'integer', 'description' => 'Max games to return (default 20, max 200).'],
                    ],
                ],
            ],
            [
                'name'        => 'genre_breakdown',
                'description' => 'Playtime-weighted genre percentages for a period, from Steam store genres.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['period' => ['type' => 'string', 'enum' => self::PERIODS, 'description' => 'Defaults to all_time.']],
                ],
            ],
            [
                'name'        => 'game_details',
                'description' => 'Everything known about one owned game: playtime (total, per OS, last two weeks), last played, store details, and its unlocked achievements with dates.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'appid' => ['type' => 'integer', 'description' => 'Steam app id.'],
                        'name'  => ['type' => 'string', 'description' => 'Game name (case-insensitive; exact match preferred, else first partial match). Used when appid is omitted.'],
                    ],
                ],
            ],
            [
                'name'        => 'list_achievements',
                'description' => 'Unlocked Steam achievements with exact unlock date/time and game, newest first, across every game checked so far.',
                'inputSchema' => ['type' => 'object', 'properties' => $range],
            ],
            [
                'name'        => 'playtime_log',
                'description' => 'Raw playtime-ledger entries (game, minutes, when observed) — dated play history Steam itself doesn\'t keep. Includes how far back tracking goes.',
                'inputSchema' => ['type' => 'object', 'properties' => $range],
            ],
            [
                'name'        => 'lifetime_stats',
                'description' => 'Lifetime totals: games owned, hours played, share of library played, achievements, perfect games, Steam level, RetroAchievements points.',
                'inputSchema' => $noArgs,
            ],
        ];

        if ($this->library->retro()->enabled()) {
            $tools[] = [
                'name'        => 'retroachievements_summary',
                'description' => 'RetroAchievements profile: points, rank, rich presence, recently played retro games with achievement progress, and mastered/beaten counts.',
                'inputSchema' => $noArgs,
            ];
        }

        if ($this->library->xbox()->enabled()) {
            $tools[] = [
                'name'        => 'xbox_summary',
                'description' => 'Xbox profile (gamertag, gamerscore), what is being played right now, and every Xbox / PC Game Pass title played with devices, last played date, gamerscore and achievement progress, and lifetime minutes where Xbox reports them.',
                'inputSchema' => $noArgs,
            ];
        }

        if ($this->library->psn()->enabled()) {
            $tools[] = [
                'name'        => 'playstation_summary',
                'description' => 'PlayStation profile, trophy level and trophy counts, what is being played right now, and every PS4/PS5 game played with playtime, sessions, first/last played dates and trophy progress (plus PS3/Vita trophy lists).',
                'inputSchema' => $noArgs,
            ];
        }

        foreach (self::WIDGET_TOOLS as $id => $description) {
            $tools[] = ['name' => 'widget_' . $id, 'description' => $description, 'inputSchema' => $noArgs];
        }

        return $tools;
    }

    /**
     * @return array{content: array<int, array{type:string, text:string}>, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        try {
            $data = $this->dispatch($name, $arguments);

            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT)]], 'isError' => false];
        } catch (Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    private function dispatch(string $name, array $args): array
    {
        if (strpos($name, 'widget_') === 0 && isset(self::WIDGET_TOOLS[substr($name, 7)])) {
            $id = substr($name, 7);

            return WidgetCache::remember($id, ['id' => $id], 900, $this->handlers[$id]);
        }

        switch ($name) {
            case 'get_now_playing':
                $np = $this->library->nowPlaying();

                return ['available' => $np['current'] !== null, 'current' => $np['current'], 'previous' => $np['previous']];
            case 'list_games':
                return $this->listGames($args);
            case 'top_games':
                $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));

                return $this->library->gamesForPeriod($this->period($args), $limit, $this->library->timezone());
            case 'genre_breakdown':
                return $this->library->genresForPeriod($this->period($args), $this->library->timezone());
            case 'game_details':
                return $this->gameDetails($args);
            case 'list_achievements':
                return $this->listAchievements($args);
            case 'playtime_log':
                return $this->playtimeLog($args);
            case 'lifetime_stats':
                return array_map(fn($s) => ['label' => $s['label'], 'value' => $s['value']], $this->library->lifetimeStats());
            case 'retroachievements_summary':
                return $this->retroSummary();
            case 'xbox_summary':
                return $this->xboxSummary();
            case 'playstation_summary':
                return $this->psnSummary();
            default:
                throw new InvalidArgumentException('Unknown tool: ' . $name);
        }
    }

    private function period(array $args): string
    {
        return Library::validUiPeriod((string) ($args['period'] ?? 'all_time'), 'all_time');
    }

    private function parseWhen($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);

        return $ts !== false ? $ts : null;
    }

    private function listGames(array $args): array
    {
        $games = array_values($this->library->ownedGames());
        $filter = $args['filter'] ?? 'all';
        if ($filter === 'played') {
            $games = array_filter($games, fn($g) => $g['playtime'] > 0);
        } elseif ($filter === 'unplayed') {
            $games = array_filter($games, fn($g) => $g['playtime'] === 0);
        }

        $sort = $args['sort'] ?? 'playtime';
        usort($games, function ($a, $b) use ($sort) {
            if ($sort === 'name') {
                return strcasecmp($a['name'], $b['name']);
            }
            $key = $sort === 'last_played' ? 'last_played' : 'playtime';

            return $b[$key] <=> $a[$key];
        });

        $limit = max(1, min(5000, (int) ($args['limit'] ?? 50)));
        $out = [];
        foreach (array_slice($games, 0, $limit) as $g) {
            $record = $this->library->achievements()->get($g['appid']);
            $out[] = [
                'appid'                 => $g['appid'],
                'name'                  => $g['name'],
                'playtime_hours'        => round($g['playtime'] / 60, 1),
                'playtime_2weeks_hours' => round($g['playtime_2weeks'] / 60, 1),
                'last_played'           => $g['last_played'] > 0 ? date('c', $g['last_played']) : null,
                'achievements'          => $record && $record['has_stats'] ? $record['unlocked'] . '/' . $record['total'] : null,
            ];
        }

        return ['private_profile' => $this->library->isPrivate(), 'total_matching' => count($games), 'games' => $out];
    }

    private function gameDetails(array $args): array
    {
        $owned = $this->library->ownedGames();
        $appid = (int) ($args['appid'] ?? 0);

        if ($appid === 0 && !empty($args['name'])) {
            $needle = mb_strtolower(trim((string) $args['name']));
            foreach ($owned as $g) {
                if (mb_strtolower($g['name']) === $needle) {
                    $appid = $g['appid'];
                    break;
                }
            }
            if ($appid === 0) {
                foreach ($owned as $g) {
                    if (mb_strpos(mb_strtolower($g['name']), $needle) !== false) {
                        $appid = $g['appid'];
                        break;
                    }
                }
            }
        }

        if (!isset($owned[$appid])) {
            return ['found' => false, 'message' => 'No owned game matches that appid/name.'];
        }

        $g = $owned[$appid];
        $record = $this->library->achievements()->get($appid);
        $store = $this->library->steam()->appDetails($appid);

        return [
            'found'                 => true,
            'appid'                 => $appid,
            'name'                  => $g['name'],
            'playtime_hours'        => round($g['playtime'] / 60, 1),
            'playtime_2weeks_hours' => round($g['playtime_2weeks'] / 60, 1),
            'playtime_by_os_hours'  => [
                'windows'    => round($g['windows'] / 60, 1),
                'linux'      => round($g['linux'] / 60, 1),
                'mac'        => round($g['mac'] / 60, 1),
                'steam_deck' => round($g['deck'] / 60, 1),
            ],
            'last_played'           => $g['last_played'] > 0 ? date('c', $g['last_played']) : null,
            'store'                 => $store && empty($store['unavailable']) ? $store : null,
            'store_url'             => Steam::storeUrl($appid),
            'achievements'          => $record ? [
                'has_achievements' => $record['has_stats'],
                'unlocked'         => $record['unlocked'],
                'total'            => $record['total'],
                'checked_at'       => date('c', (int) $record['checked_at']),
                'unlocks'          => array_map(fn($u) => [
                    'name'        => $u['name'],
                    'description' => $u['desc'],
                    'unlocked_at' => $u['time'] > 0 ? date('c', $u['time']) : null,
                ], $record['unlocks']),
            ] : null,
        ];
    }

    private function listAchievements(array $args): array
    {
        $since = $this->parseWhen($args['since'] ?? null) ?? 0;
        $until = $this->parseWhen($args['until'] ?? null) ?? PHP_INT_MAX;
        $limit = max(1, min(2000, (int) ($args['limit'] ?? 200)));
        $owned = $this->library->ownedGames();

        $out = [];
        foreach ($this->library->achievements()->unlocks() as $u) {
            if ($u['time'] < $since || $u['time'] > $until) {
                continue;
            }
            $out[] = [
                'game'        => $owned[$u['appid']]['name'] ?? ('App ' . $u['appid']),
                'appid'       => $u['appid'],
                'achievement' => $u['name'],
                'description' => $u['desc'],
                'unlocked_at' => date('c', $u['time']),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        $summary = $this->library->achievements()->summary(count(array_filter($owned, fn($g) => $g['playtime'] > 0)));

        return [
            'coverage_note' => 'Covers ' . $summary['checked_games'] . ' checked games (' . $summary['coverage_pct'] . '% of played games); cron.php checks more each run.',
            'count'         => count($out),
            'achievements'  => $out,
        ];
    }

    private function playtimeLog(array $args): array
    {
        $since = $this->parseWhen($args['since'] ?? null) ?? 0;
        $until = $this->parseWhen($args['until'] ?? null);
        $limit = max(1, min(2000, (int) ($args['limit'] ?? 200)));
        $owned = $this->library->ownedGames();
        $ledger = $this->library->ledger();

        $entries = array_map(fn($e) => [
            'game'        => $owned[$e['appid']]['name'] ?? ('App ' . $e['appid']),
            'appid'       => $e['appid'],
            'minutes'     => $e['minutes'],
            'observed_at' => date('c', $e['observed_at']),
        ], $ledger->entries($since, $until, $limit));

        return [
            'coverage' => $ledger->coverage(),
            'note'     => 'Each entry is playtime Steam\'s counter advanced by between two snapshots; observed_at is when the snapshot saw it (the session ended at or shortly before then). Nothing before tracking_since is recorded.',
            'count'    => count($entries),
            'entries'  => $entries,
        ];
    }

    private function xboxSummary(): array
    {
        $xbox = $this->library->xbox();
        $account = $xbox->account();
        if (!$account) {
            return ['available' => false];
        }

        return [
            'available'   => true,
            'gamertag'    => $account['gamertag'],
            'gamerscore'  => $account['gamerscore'],
            'now_playing' => $xbox->nowPlaying(),
            'note'        => 'minutes_played is Xbox\'s per-game MinutesPlayed stat; null means the game doesn\'t report it.',
            'titles'      => array_values(array_map(fn($t) => [
                'title_id'       => $t['title_id'],
                'name'           => $t['name'],
                'devices'        => array_values(array_unique(array_map([Xbox::class, 'deviceLabel'], $t['devices']))),
                'game_pass'      => $t['game_pass'],
                'last_played'    => $t['last_played'] > 0 ? date('c', $t['last_played']) : null,
                'gamerscore'     => $t['gamerscore'] . '/' . $t['gamerscore_total'],
                'achievements'   => $t['achievements'] . ($t['achievements_total'] > 0 ? '/' . $t['achievements_total'] : ''),
                'progress_pct'   => $t['progress_pct'],
                'minutes_played' => $t['minutes'] > 0 ? $t['minutes'] : null,
            ], $xbox->titles())),
        ];
    }

    private function psnSummary(): array
    {
        $psn = $this->library->psn();
        $summary = $psn->trophySummary();
        if (!$summary) {
            return ['available' => false, 'auth_error' => $psn->authError()];
        }
        $profile = $psn->profile();

        return [
            'available'   => true,
            'online_id'   => $profile['online_id'] ?? null,
            'trophy_level' => $summary['level'],
            'trophies'    => $summary['trophies'],
            'now_playing' => $psn->nowPlaying(),
            'note'        => 'minutes_played covers PS4/PS5 only; PS3/Vita games appear via their trophy lists without playtime.',
            'games'       => array_values(array_map(fn($g) => [
                'name'           => $g['name'],
                'platform'       => $g['platform'],
                'minutes_played' => $g['minutes'] > 0 ? $g['minutes'] : null,
                'sessions'       => $g['play_count'] ?: null,
                'first_played'   => $g['first_played'] > 0 ? date('c', $g['first_played']) : null,
                'last_played'    => $g['last_played'] > 0 ? date('c', $g['last_played']) : null,
                'trophies'       => $g['trophies'] ? [
                    'progress_pct' => $g['trophies']['progress'],
                    'earned'       => $g['trophies']['earned'],
                    'defined'      => $g['trophies']['defined'],
                ] : null,
            ], $psn->games())),
        ];
    }

    private function retroSummary(): array
    {
        $retro = $this->library->retro();
        $summary = $retro->summary();
        if (!$summary) {
            return ['available' => false];
        }

        $awards = array_count_values(array_filter(array_map(fn($r) => $r['HighestAwardKind'] ?? null, $retro->completionProgress())));

        return [
            'available'       => true,
            'user'            => $summary['User'] ?? $retro->username(),
            'points'          => (int) ($summary['TotalPoints'] ?? 0),
            'true_points'     => (int) ($summary['TotalTruePoints'] ?? 0),
            'rank'            => $summary['Rank'] ?? null,
            'status'          => $summary['Status'] ?? null,
            'rich_presence'   => $summary['RichPresenceMsg'] ?? null,
            'member_since'    => $summary['MemberSince'] ?? null,
            'awards'          => $awards,
            'recently_played' => array_map(fn($g) => [
                'title'       => $g['Title'] ?? '',
                'console'     => $g['ConsoleName'] ?? '',
                'last_played' => $g['LastPlayed'] ?? null,
                'achieved'    => (int) ($g['NumAchieved'] ?? 0),
                'possible'    => (int) ($g['NumPossibleAchievements'] ?? 0),
            ], $retro->recentlyPlayed(10)),
        ];
    }
}
