<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional Xbox client — covers Xbox consoles and PC games played through
 * the Xbox app / PC Game Pass / Microsoft Store, which share one Xbox
 * account. Microsoft's own Xbox services APIs are only open to licensed
 * game developers, so this goes through OpenXBL (xbl.io), an unofficial
 * third-party gateway: sign in there with your Microsoft account and copy
 * the personal API key from https://xbl.io/dashboard. Entirely skipped
 * when the key is blank.
 *
 * OpenXBL's free tier allows 150 requests an hour, which is the constraint
 * everything here is shaped around:
 * - presence (now playing) is cached for xbox_presence_ttl (2 minutes by
 *   default) even though the dashboard polls Steam every 30 seconds;
 * - the title list is cached 15 minutes, the profile and playtime an hour;
 * - playtime for every game comes from one batched stats request (Xbox's
 *   per-game "MinutesPlayed" counter) rather than one request per game;
 * - on any failure (including 429 rate limiting) the last good response
 *   is served stale rather than showing nothing, and no live request is
 *   made for FAILURE_BACKOFF seconds. OpenXBL can take 8-10 seconds to
 *   answer (even just to reject a bad key), so without the backoff a wrong
 *   key or an outage would stall every page load and poll.
 * That keeps a dashboard left open all day at roughly 40-50 requests/hour.
 */
class Xbox
{
    private const API = 'https://api.xbl.io/v2/';
    private const FAILURE_BACKOFF = 300;

    /**
     * Presence "titles" that are the console/PC shell itself rather than a
     * game — skipped when working out what's being played.
     */
    private const SHELL_TITLES = ['Home', 'Xbox App', 'Xbox', 'Online', 'Xbox Game Bar', 'Microsoft Store'];

    private string $apiKey;
    private int $presenceTtl;
    private string $cacheDir;

    public function __construct(string $apiKey, int $presenceTtl = 120)
    {
        $this->apiKey = trim($apiKey);
        $this->presenceTtl = max(60, $presenceTtl);
        $this->cacheDir = __DIR__ . '/../cache/xbox_' . md5($this->apiKey);
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * GET (or POST, with $body) an OpenXBL endpoint, cached for $ttl
     * seconds, falling back to the last good response on failure.
     */
    private function call(string $endpoint, int $ttl, ?array $body = null): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/' . md5($endpoint . serialize($body)) . '.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && (time() - filemtime($cacheFile)) < $ttl) {
            return $cached;
        }

        // One shared marker: a bad key, an outage or rate limiting hits
        // every endpoint at once, so one failure pauses them all.
        $failFile = $this->cacheDir . '/.failed';
        if (is_file($failFile) && (time() - filemtime($failFile)) < self::FAILURE_BACKOFF) {
            return is_array($cached) ? $cached : null;
        }

        $headers = ['X-Authorization: ' . $this->apiKey, 'Accept: application/json', 'Accept-Language: en-US', 'User-Agent: XP-Ledger/1.0'];
        $response = $body === null
            ? Http::get(self::API . $endpoint, $headers, 10)
            : Http::postJson(self::API . $endpoint, $body, $headers, 10);
        $decoded = $response !== null ? json_decode($response, true) : null;

        // Current responses wrap the payload as {"content": ..., "code": 200};
        // older ones returned it bare. Errors carry a non-200 code or an
        // error/message field.
        if (is_array($decoded) && array_key_exists('code', $decoded) && array_key_exists('content', $decoded)) {
            $decoded = (int) $decoded['code'] === 200 && is_array($decoded['content']) ? $decoded['content'] : null;
        } elseif (is_array($decoded) && (isset($decoded['error']) || (isset($decoded['message']) && count($decoded) <= 3))) {
            $decoded = null;
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        if (!is_array($decoded)) {
            @touch($failFile);

            return is_array($cached) ? $cached : null; // stale beats nothing, especially when rate-limited
        }

        @file_put_contents($cacheFile, json_encode($decoded));
        @unlink($failFile);

        return $decoded;
    }

    /**
     * Gamertag, XUID, gamerscore and gamerpic for the key's own account.
     *
     * @return array{xuid:string, gamertag:string, gamerscore:int, avatar:string}|null
     */
    public function account(): ?array
    {
        $data = $this->call('account', 3600);
        $user = $data['profileUsers'][0] ?? null;
        if (!$user) {
            return null;
        }

        $settings = [];
        foreach ($user['settings'] ?? [] as $s) {
            $settings[(string) ($s['id'] ?? '')] = (string) ($s['value'] ?? '');
        }

        return [
            'xuid'       => (string) ($user['id'] ?? ''),
            'gamertag'   => $settings['Gamertag'] ?? '',
            'gamerscore' => (int) ($settings['Gamerscore'] ?? 0),
            'avatar'     => self::https($settings['GameDisplayPicRaw'] ?? ''),
        ];
    }

    /**
     * Every title played on the account, normalized and keyed by titleId,
     * most recently played first. Apps (type "App", e.g. Netflix) are left out.
     *
     * @return array<string, array{title_id:string, name:string, image:string, devices:string[], game_pass:bool, last_played:int, achievements:int, achievements_total:int, gamerscore:int, gamerscore_total:int, progress_pct:int, minutes:int}>
     */
    public function titles(): array
    {
        $data = $this->call('titles', 900);

        $titles = [];
        foreach ($data['titles'] ?? [] as $t) {
            if (($t['type'] ?? 'Game') !== 'Game') {
                continue;
            }
            $id = (string) ($t['titleId'] ?? '');
            if ($id === '') {
                continue;
            }
            $a = $t['achievement'] ?? [];
            $titles[$id] = [
                'title_id'           => $id,
                'name'               => (string) ($t['name'] ?? ''),
                'image'              => self::https((string) ($t['displayImage'] ?? '')),
                'devices'            => array_values(array_map('strval', $t['devices'] ?? [])),
                'game_pass'          => !empty($t['gamePass']['isGamePass']),
                'last_played'        => self::parseTime($t['titleHistory']['lastTimePlayed'] ?? null),
                'achievements'       => (int) ($a['currentAchievements'] ?? 0),
                'achievements_total' => (int) ($a['totalAchievements'] ?? 0),
                'gamerscore'         => (int) ($a['currentGamerscore'] ?? 0),
                'gamerscore_total'   => (int) ($a['totalGamerscore'] ?? 0),
                'progress_pct'       => (int) ($a['progressPercentage'] ?? 0),
                'minutes'            => 0,
            ];
        }

        uasort($titles, fn($a, $b) => $b['last_played'] <=> $a['last_played']);

        $minutes = $this->minutesPlayed(array_keys($titles));
        foreach ($minutes as $id => $m) {
            if (isset($titles[$id])) {
                $titles[$id]['minutes'] = $m;
            }
        }

        return $titles;
    }

    /**
     * Lifetime minutes per title from Xbox's "MinutesPlayed" stat, batched
     * 100 titles per request and cached an hour. Not every game reports
     * it (older and some PC titles don't), so missing titles are simply
     * absent rather than zero.
     *
     * @param string[] $titleIds
     * @return array<string, int>
     */
    public function minutesPlayed(array $titleIds): array
    {
        $account = $this->account();
        if (!$account || $account['xuid'] === '' || !$titleIds) {
            return [];
        }

        $minutes = [];
        foreach (array_chunk($titleIds, 100) as $chunk) {
            $data = $this->call('player/stats', 3600, [
                'xuids' => [$account['xuid']],
                'stats' => array_map(fn($id) => ['name' => 'MinutesPlayed', 'titleId' => (string) $id], $chunk),
            ]);
            self::collectMinutes($data ?? [], $minutes);
        }

        return $minutes;
    }

    /**
     * The stats response nests entries a few levels deep
     * (statlistscollection -> stats -> {name, titleid, value}); walk it
     * rather than hard-coding the path, since OpenXBL passes Microsoft's
     * shape through as-is.
     */
    private static function collectMinutes(array $node, array &$out): void
    {
        if (($node['name'] ?? null) === 'MinutesPlayed' && isset($node['value'])) {
            $id = (string) ($node['titleid'] ?? $node['titleId'] ?? '');
            if ($id !== '' && $node['value'] !== '') {
                $out[$id] = (int) $node['value'];
            }
            return;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                self::collectMinutes($child, $out);
            }
        }
    }

    /**
     * What's being played right now, if anything.
     *
     * @return array{title_id:string, name:string, device:string, presence:?string}|null
     */
    public function nowPlaying(): ?array
    {
        $data = $this->call('presence', $this->presenceTtl);
        if (!$data || ($data['state'] ?? '') !== 'Online') {
            return null;
        }

        foreach ($data['devices'] ?? [] as $device) {
            foreach ($device['titles'] ?? [] as $title) {
                $name = (string) ($title['name'] ?? '');
                if ($name === '' || in_array($name, self::SHELL_TITLES, true) || ($title['state'] ?? 'Active') !== 'Active') {
                    continue;
                }

                return [
                    'title_id' => (string) ($title['id'] ?? ''),
                    'name'     => $name,
                    'device'   => self::deviceLabel((string) ($device['type'] ?? '')),
                    'presence' => isset($title['activity']['richPresence']) ? (string) $title['activity']['richPresence'] : null,
                ];
            }
        }

        return null;
    }

    public static function deviceLabel(string $type): string
    {
        $labels = [
            'XboxSeries' => 'Xbox Series X|S', 'XboxSeriesX' => 'Xbox Series X|S', 'XboxSeriesS' => 'Xbox Series X|S', 'Scarlett' => 'Xbox Series X|S',
            'XboxOne' => 'Xbox One', 'Durango' => 'Xbox One', 'Xbox360' => 'Xbox 360',
            'PC' => 'PC', 'WindowsOneCore' => 'PC', 'Win32' => 'PC',
            'iOS' => 'Mobile', 'Android' => 'Mobile', 'Web' => 'Cloud',
        ];

        return $labels[$type] ?? $type;
    }

    public static function storeSearchUrl(string $name): string
    {
        return 'https://www.xbox.com/en-US/search?q=' . rawurlencode($name);
    }

    public static function profileUrl(string $gamertag): string
    {
        return 'https://www.xbox.com/en-US/play/user/' . rawurlencode($gamertag);
    }

    /**
     * Xbox timestamps carry seven fractional-second digits, which not every
     * PHP version's parser accepts — trim to microseconds first.
     */
    public static function parseTime(?string $value): int
    {
        if (!$value) {
            return 0;
        }
        $ts = strtotime((string) preg_replace('/(\.\d{6})\d+/', '$1', $value));

        return $ts !== false ? $ts : 0;
    }

    /**
     * Some Microsoft image URLs come back as plain http://, which a page
     * served over https would block as mixed content.
     */
    private static function https(string $url): string
    {
        return preg_replace('~^http://~i', 'https://', $url) ?? $url;
    }
}
