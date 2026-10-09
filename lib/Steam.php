<?php

require_once __DIR__ . '/Http.php';

/**
 * Minimal Steam Web API + Steam Store client with simple file-based
 * response caching (same idea as lastfm-dash's LastFm client).
 *
 * Steam's Web API (api.steampowered.com) needs a free key from
 * https://steamcommunity.com/dev/apikey and covers the account itself:
 * profile/now-playing, owned games with lifetime playtime, recently played,
 * level/XP, and per-game achievements. The Store API
 * (store.steampowered.com/api/appdetails) needs no key and fills in what
 * the Web API doesn't know — genres, review counts, release dates — at the
 * cost of a stricter rate limit (~200 requests per 5 minutes), which is why
 * appDetails() caches for a month and supports a cache-only mode for page
 * requests (cron.php does the actual fetching in paced batches).
 */
class Steam
{
    private const API = 'https://api.steampowered.com/';
    private const STORE_API = 'https://store.steampowered.com/api/appdetails';
    private const CDN = 'https://cdn.cloudflare.steamstatic.com/steam/apps/';

    private string $apiKey;
    private string $steamIdInput;
    private ?string $resolvedSteamId = null;
    private int $cacheTtl;
    private string $cacheDir;

    public function __construct(string $apiKey, string $steamId, int $cacheTtl = 60)
    {
        $this->apiKey = $apiKey;
        $this->steamIdInput = trim($steamId);
        $this->cacheTtl = $cacheTtl;
        $this->cacheDir = __DIR__ . '/../cache/steam';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Call a Web API endpoint (e.g. "IPlayerService/GetOwnedGames/v1"),
     * transparently caching the decoded JSON. Same knobs as lastfm-dash's
     * LastFm::call(): $ttlOverride for long-lived lookups, $cacheOnly to
     * answer "do we already know this" without ever making a live request,
     * and $isCacheable to refuse to persist a well-formed but unhelpful
     * response (Steam returns an empty `{"response":{}}` rather than an
     * error for a private profile, for instance).
     */
    public function call(string $endpoint, array $params = [], ?int $ttlOverride = null, bool $cacheOnly = false, ?callable $isCacheable = null): ?array
    {
        $ttl = $ttlOverride ?? $this->cacheTtl;
        $cacheFile = $this->cacheDir . '/' . md5($endpoint . serialize($params)) . '.json';

        $cached = $this->readCache($cacheFile, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        if ($cacheOnly) {
            return null;
        }

        $url = self::API . $endpoint . '/?' . http_build_query(['key' => $this->apiKey, 'format' => 'json'] + $params);
        $response = Http::get($url, ['User-Agent: XP-Ledger/1.0'], 12);
        $decoded = $response !== null ? json_decode($response, true) : null;

        if (!is_array($decoded)) {
            return null;
        }

        if ($ttl > 0 && ($isCacheable === null || $isCacheable($decoded))) {
            $this->writeCache($cacheFile, $decoded);
        }

        return $decoded;
    }

    private function readCache(string $file, int $ttl): ?array
    {
        if ($ttl <= 0 || !is_file($file) || (time() - filemtime($file)) >= $ttl) {
            return null;
        }
        $cached = json_decode((string) file_get_contents($file), true);

        return is_array($cached) ? $cached : null;
    }

    private function writeCache(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data));
    }

    /**
     * The configured account as a 64-bit SteamID. Accepts the ID itself,
     * a custom profile name ("vanity URL"), or a full profile URL in either
     * form — whichever the user happened to paste into config.php. Vanity
     * names are resolved once and cached for a month.
     */
    public function steamId(): string
    {
        if ($this->resolvedSteamId !== null) {
            return $this->resolvedSteamId;
        }

        $input = rtrim($this->steamIdInput, '/');
        if (preg_match('~/profiles/(7656\d{13})$~', $input, $m) || preg_match('~^(7656\d{13})$~', $input, $m)) {
            return $this->resolvedSteamId = $m[1];
        }

        $vanity = preg_match('~/id/([^/]+)$~', $input, $m) ? $m[1] : $input;
        if ($vanity === '') {
            return $this->resolvedSteamId = '';
        }

        $data = $this->call('ISteamUser/ResolveVanityURL/v1', ['vanityurl' => $vanity], 30 * 86400, false, function ($d) {
            return (int) ($d['response']['success'] ?? 0) === 1;
        });

        return $this->resolvedSteamId = (string) ($data['response']['steamid'] ?? '');
    }

    /**
     * Profile summary, including `gameid`/`gameextrainfo` while the user is
     * in a game — the closest thing Steam has to Last.fm's "now playing".
     */
    public function playerSummary(?int $ttl = null): ?array
    {
        $data = $this->call('ISteamUser/GetPlayerSummaries/v2', ['steamids' => $this->steamId()], $ttl);

        return $data['response']['players'][0] ?? null;
    }

    /**
     * Every game on the account with lifetime playtime (minutes), per-OS
     * playtime splits, and last-played timestamps. Returns
     * ['private' => true] when Steam hides the list — it does that silently
     * (an empty response, not an error) when the profile's "Game details"
     * privacy setting isn't Public.
     */
    public function ownedGames(): ?array
    {
        $data = $this->call('IPlayerService/GetOwnedGames/v1', [
            'steamid'                   => $this->steamId(),
            'include_appinfo'           => 1,
            'include_played_free_games' => 1,
        ], null, false, function ($d) {
            return isset($d['response']['game_count']);
        });

        if ($data === null) {
            return null;
        }
        if (!isset($data['response']['game_count'])) {
            return ['private' => true, 'games' => []];
        }

        return ['private' => false, 'games' => $data['response']['games'] ?? []];
    }

    public function recentlyPlayed(): array
    {
        $data = $this->call('IPlayerService/GetRecentlyPlayedGames/v1', ['steamid' => $this->steamId()]);

        return $data['response']['games'] ?? [];
    }

    /**
     * Steam level and XP — fittingly, the account's literal XP ledger.
     */
    public function badges(): ?array
    {
        $data = $this->call('IPlayerService/GetBadges/v1', ['steamid' => $this->steamId()], 3600, false, function ($d) {
            return isset($d['response']['player_level']);
        });

        return isset($data['response']['player_level']) ? $data['response'] : null;
    }

    /**
     * The account's achievement list for one game (with display names, via
     * l=english). Returns null on a network failure, or
     * ['success' => false, 'no_stats' => bool] when Steam says the game
     * has no achievements (an HTTP 400 with an explanatory body) or the
     * profile hides them.
     */
    public function playerAchievements(int $appid, int $ttl): ?array
    {
        $data = $this->call('ISteamUserStats/GetPlayerAchievements/v1', [
            'steamid' => $this->steamId(),
            'appid'   => $appid,
            'l'       => 'english',
        ], $ttl);

        if (!isset($data['playerstats'])) {
            return null;
        }

        $stats = $data['playerstats'];
        if (empty($stats['success'])) {
            return ['success' => false, 'no_stats' => stripos((string) ($stats['error'] ?? ''), 'no stats') !== false];
        }

        return ['success' => true, 'achievements' => $stats['achievements'] ?? []];
    }

    /**
     * Global unlock rate per achievement (apiname => percent of all
     * owners), cached for a month — used to find the user's rarest unlocks.
     *
     * @return array<string, float>|null
     */
    public function globalAchievementPercentages(int $appid, bool $cacheOnly = false): ?array
    {
        $data = $this->call('ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2', ['gameid' => $appid], 30 * 86400, $cacheOnly, function ($d) {
            return isset($d['achievementpercentages']['achievements']);
        });

        if (!isset($data['achievementpercentages']['achievements'])) {
            return null;
        }

        $map = [];
        foreach ($data['achievementpercentages']['achievements'] as $a) {
            $map[(string) ($a['name'] ?? '')] = (float) ($a['percent'] ?? 0);
        }

        return $map;
    }

    /**
     * Store-page metadata for one game, trimmed down to the handful of
     * fields this app uses (the raw response is tens of KB of HTML
     * descriptions) and cached for a month. Delisted/region-locked games
     * cache an ['unavailable' => true] marker so they aren't retried on
     * every run.
     */
    public function appDetails(int $appid, bool $cacheOnly = false): ?array
    {
        $cacheFile = $this->cacheDir . '/store_' . $appid . '.json';
        $cached = $this->readCache($cacheFile, 30 * 86400);
        if ($cached !== null) {
            return $cached;
        }
        if ($cacheOnly) {
            return null;
        }

        $response = Http::get(self::STORE_API . '?' . http_build_query(['appids' => $appid, 'cc' => 'us', 'l' => 'english']), ['User-Agent: XP-Ledger/1.0'], 12);
        $decoded = $response !== null ? json_decode($response, true) : null;
        if (!is_array($decoded) || !isset($decoded[(string) $appid])) {
            return null; // rate-limited (Steam answers with a bare `null`) or unreachable — try again next time
        }

        $entry = $decoded[(string) $appid];
        if (empty($entry['success']) || !isset($entry['data'])) {
            $trimmed = ['unavailable' => true];
        } else {
            $d = $entry['data'];
            $trimmed = [
                'name'            => (string) ($d['name'] ?? ''),
                'type'            => (string) ($d['type'] ?? ''),
                'genres'          => array_values(array_filter(array_map(fn($g) => (string) ($g['description'] ?? ''), $d['genres'] ?? []))),
                'categories'      => array_values(array_filter(array_map(fn($c) => (string) ($c['description'] ?? ''), $d['categories'] ?? []))),
                'developers'      => array_values($d['developers'] ?? []),
                'release_date'    => (string) ($d['release_date']['date'] ?? ''),
                'recommendations' => (int) ($d['recommendations']['total'] ?? 0),
                'metacritic'      => isset($d['metacritic']['score']) ? (int) $d['metacritic']['score'] : null,
                'is_free'         => !empty($d['is_free']),
            ];
        }

        $this->writeCache($cacheFile, $trimmed);

        return $trimmed;
    }

    // --- Artwork & links. The legacy steam/apps/{appid}/ CDN paths still
    // resolve for every app (including brand-new releases, whose store
    // pages use hashed asset paths instead), and send
    // Access-Control-Allow-Origin: *, so the browser can sample them for
    // the accent colour without a proxy. ---

    public static function headerArt(int $appid): string
    {
        return self::CDN . $appid . '/header.jpg';
    }

    public static function portraitArt(int $appid): string
    {
        return self::CDN . $appid . '/library_600x900.jpg';
    }

    public static function heroArt(int $appid): string
    {
        return self::CDN . $appid . '/library_hero.jpg';
    }

    public static function iconUrl(int $appid, string $hash): string
    {
        return $hash === '' ? '' : 'https://media.steampowered.com/steamcommunity/public/images/apps/' . $appid . '/' . $hash . '.jpg';
    }

    public static function storeUrl(int $appid): string
    {
        return 'https://store.steampowered.com/app/' . $appid . '/';
    }
}
