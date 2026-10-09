<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional PlayStation Network client (PS4 / PS5).
 *
 * Sony has no public API for this, so it uses the same unofficial mobile-app
 * endpoints as the widely used psn-api library
 * (github.com/achievements-app/psn-api): you sign in to PlayStation in a
 * browser, open https://ca.account.sony.com/api/v1/ssocookie, and copy the
 * 64-character "npsso" value into config.php. That NPSSO is exchanged once
 * for an access token (~1 hour) and a refresh token (~2 months), cached
 * under cache/ and refreshed automatically; when the refresh token finally
 * expires, a still-valid NPSSO is exchanged again. If that fails too, the
 * NPSSO itself has expired (they last about two months) and authError()
 * says so, which the dashboard shows as a banner.
 *
 * Sony doesn't publish rate limits for these endpoints, so everything is
 * cached conservatively — presence for psn_presence_ttl (2 minutes by
 * default), the games list 15 minutes, trophies 30, the profile 6 hours —
 * and, as with Xbox, any failure serves the last good data and pauses all
 * live PSN requests for a few minutes.
 */
class PlayStation
{
    private const AUTH = 'https://ca.account.sony.com/api/authz/v3/oauth/';
    private const API = 'https://m.np.playstation.com/api/';
    private const CLIENT_ID = '09515159-7237-4370-9b40-3806e67c0891';
    // The PlayStation App's public client credentials (base64 "id:secret"),
    // as used by psn-api — not a secret of yours.
    private const CLIENT_BASIC = 'MDk1MTUxNTktNzIzNy00MzcwLTliNDAtMzgwNmU2N2MwODkxOnVjUGprYTV0bnRCMktxc1A=';
    private const REDIRECT_URI = 'com.scee.psxandroid.scecompcall://redirect';
    private const SCOPE = 'psn:mobile.v2.core psn:clientapp';
    private const FAILURE_BACKOFF = 300;

    private string $npsso;
    private int $presenceTtl;
    private string $cacheDir;
    private ?string $accessToken = null;

    public function __construct(string $npsso, int $presenceTtl = 120)
    {
        $this->npsso = trim($npsso);
        $this->presenceTtl = max(60, $presenceTtl);
        $this->cacheDir = __DIR__ . '/../cache/psn_' . md5($this->npsso);
    }

    public function enabled(): bool
    {
        return $this->npsso !== '';
    }

    // --- Auth ---

    private function tokenFile(): string
    {
        return $this->cacheDir . '/tokens.json';
    }

    private function errorFile(): string
    {
        return $this->cacheDir . '/auth_error.txt';
    }

    /**
     * Why sign-in last failed, or null if it's working. Cleared on the next
     * successful sign-in.
     */
    public function authError(): ?string
    {
        return is_file($this->errorFile()) ? trim((string) file_get_contents($this->errorFile())) : null;
    }

    private function accessToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $tokens = is_file($this->tokenFile()) ? json_decode((string) file_get_contents($this->tokenFile()), true) : null;
        $now = time();

        if (is_array($tokens) && ($tokens['access_expires'] ?? 0) > $now + 60) {
            return $this->accessToken = (string) $tokens['access_token'];
        }

        $fresh = null;
        if (is_array($tokens) && ($tokens['refresh_expires'] ?? 0) > $now + 60) {
            $fresh = $this->requestTokens([
                'refresh_token' => (string) $tokens['refresh_token'],
                'grant_type'    => 'refresh_token',
                'token_format'  => 'jwt',
                'scope'         => self::SCOPE,
            ]);
        }

        if ($fresh === null) {
            $code = $this->exchangeNpssoForCode();
            if ($code === null) {
                $this->recordAuthError('PlayStation sign-in failed — your NPSSO token has probably expired. Get a new one from https://ca.account.sony.com/api/v1/ssocookie while signed in to PlayStation, and update psn_npsso in config.php.');

                return null;
            }
            $fresh = $this->requestTokens([
                'code'         => $code,
                'redirect_uri' => self::REDIRECT_URI,
                'grant_type'   => 'authorization_code',
                'token_format' => 'jwt',
            ]);
            if ($fresh === null) {
                $this->recordAuthError('PlayStation sign-in failed while exchanging the access code — try again later, or refresh your NPSSO token.');

                return null;
            }
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        // Keep the old refresh token if Sony didn't rotate it this time.
        $fresh['refresh_token'] = $fresh['refresh_token'] ?: (string) ($tokens['refresh_token'] ?? '');
        $fresh['refresh_expires'] = $fresh['refresh_expires'] ?: (int) ($tokens['refresh_expires'] ?? 0);
        @file_put_contents($this->tokenFile(), json_encode($fresh));
        @chmod($this->tokenFile(), 0600);
        @unlink($this->errorFile());

        return $this->accessToken = $fresh['access_token'];
    }

    private function exchangeNpssoForCode(): ?string
    {
        $url = self::AUTH . 'authorize?' . http_build_query([
            'access_type'   => 'offline',
            'client_id'     => self::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
        ]);
        $location = Http::redirectLocation($url, ['Cookie: npsso=' . $this->npsso, 'User-Agent: XP-Ledger/1.0'], 10);

        return ($location !== null && preg_match('/[?&]code=([^&]+)/', $location, $m)) ? urldecode($m[1]) : null;
    }

    /**
     * @return array{access_token:string, access_expires:int, refresh_token:string, refresh_expires:int}|null
     */
    private function requestTokens(array $fields): ?array
    {
        $response = Http::postForm(self::AUTH . 'token', $fields, ['Authorization: Basic ' . self::CLIENT_BASIC, 'User-Agent: XP-Ledger/1.0'], 10);
        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $now = time();

        return [
            'access_token'    => (string) $data['access_token'],
            'access_expires'  => $now + (int) ($data['expires_in'] ?? 3600),
            'refresh_token'   => (string) ($data['refresh_token'] ?? ''),
            'refresh_expires' => isset($data['refresh_token_expires_in']) ? $now + (int) $data['refresh_token_expires_in'] : 0,
        ];
    }

    private function recordAuthError(string $message): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($this->errorFile(), $message);
        @touch($this->cacheDir . '/.failed');
    }

    // --- API ---

    /**
     * GET an endpoint under m.np.playstation.com/api/, cached for $ttl
     * seconds, serving the last good response (and pausing live requests)
     * on any failure.
     */
    private function call(string $path, int $ttl): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/' . md5($path) . '.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && (time() - filemtime($cacheFile)) < $ttl) {
            return $cached;
        }

        $failFile = $this->cacheDir . '/.failed';
        if (is_file($failFile) && (time() - filemtime($failFile)) < self::FAILURE_BACKOFF) {
            return is_array($cached) ? $cached : null;
        }

        $token = $this->accessToken();
        $decoded = null;
        if ($token !== null) {
            $response = Http::get(self::API . $path, ['Authorization: Bearer ' . $token, 'Accept-Language: en-US', 'User-Agent: XP-Ledger/1.0'], 10);
            $decoded = $response !== null ? json_decode($response, true) : null;
            if (is_array($decoded) && isset($decoded['error'])) {
                $decoded = null;
            }
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        if (!is_array($decoded)) {
            @touch($failFile);

            return is_array($cached) ? $cached : null;
        }

        @file_put_contents($cacheFile, json_encode($decoded));
        @unlink($failFile);

        return $decoded;
    }

    /**
     * Trophy level and totals for the signed-in account — also the source
     * of the numeric accountId that presence and profile lookups need.
     *
     * @return array{account_id:string, level:int, level_progress:int, tier:int, trophies:array{bronze:int, silver:int, gold:int, platinum:int}}|null
     */
    public function trophySummary(): ?array
    {
        $data = $this->call('trophy/v1/users/me/trophySummary', 1800);
        if (!isset($data['accountId'])) {
            return null;
        }

        return [
            'account_id'     => (string) $data['accountId'],
            'level'          => (int) ($data['trophyLevel'] ?? 0),
            'level_progress' => (int) ($data['progress'] ?? 0),
            'tier'           => (int) ($data['tier'] ?? 0),
            'trophies'       => self::trophyCounts($data['earnedTrophies'] ?? []),
        ];
    }

    /**
     * @return array{online_id:string, avatar:string, plus:bool}|null
     */
    public function profile(): ?array
    {
        $summary = $this->trophySummary();
        if (!$summary) {
            return null;
        }
        $data = $this->call('userProfile/v1/internal/users/' . rawurlencode($summary['account_id']) . '/profiles', 6 * 3600);
        if (!isset($data['onlineId'])) {
            return null;
        }

        // Largest avatar on offer.
        $avatars = $data['avatars'] ?? [];
        usort($avatars, fn($a, $b) => (int) preg_replace('/\D/', '', (string) ($b['size'] ?? '')) <=> (int) preg_replace('/\D/', '', (string) ($a['size'] ?? '')));

        return [
            'online_id' => (string) $data['onlineId'],
            'avatar'    => (string) ($avatars[0]['url'] ?? ''),
            'plus'      => !empty($data['isPlus']),
        ];
    }

    /**
     * Every PS4/PS5 game played, with real playtime, merged with its trophy
     * progress, most recently played first. Sony keeps games and trophies
     * under different IDs, so they're matched by normalized name; trophy
     * titles with no matching game (e.g. PS3/Vita, which the games list
     * doesn't cover) are included on their own.
     *
     * @return array<string, array{id:string, name:string, image:string, platform:string, minutes:int, play_count:int, first_played:int, last_played:int, trophies:?array}>
     */
    public function games(): array
    {
        $games = [];
        for ($offset = 0, $page = 0; $page < 10; $page++) {
            $data = $this->call('gamelist/v2/users/me/titles?' . http_build_query(['categories' => 'ps4_game,ps5_native_game', 'limit' => 200, 'offset' => $offset]), 900);
            foreach ($data['titles'] ?? [] as $t) {
                $name = (string) ($t['localizedName'] ?? $t['name'] ?? '');
                $key = self::normalize($name);
                if ($key === '' || isset($games[$key])) {
                    continue;
                }
                $games[$key] = [
                    'id'           => (string) ($t['titleId'] ?? ''),
                    'name'         => $name,
                    'image'        => (string) ($t['localizedImageUrl'] ?? $t['imageUrl'] ?? ''),
                    'platform'     => ($t['category'] ?? '') === 'ps5_native_game' ? 'PS5' : 'PS4',
                    'minutes'      => self::durationMinutes((string) ($t['playDuration'] ?? '')),
                    'play_count'   => (int) ($t['playCount'] ?? 0),
                    'first_played' => self::parseTime($t['firstPlayedDateTime'] ?? null),
                    'last_played'  => self::parseTime($t['lastPlayedDateTime'] ?? null),
                    'trophies'     => null,
                ];
            }
            $next = $data['nextOffset'] ?? null;
            if ($next === null || (int) $next <= $offset || empty($data['titles'])) {
                break;
            }
            $offset = (int) $next;
        }

        foreach ($this->trophyTitles() as $key => $trophy) {
            if (isset($games[$key])) {
                $games[$key]['trophies'] = $trophy;
            } else {
                $games[$key] = [
                    'id'           => $trophy['id'],
                    'name'         => $trophy['name'],
                    'image'        => $trophy['image'],
                    'platform'     => $trophy['platform'],
                    'minutes'      => 0,
                    'play_count'   => 0,
                    'first_played' => 0,
                    'last_played'  => $trophy['updated'],
                    'trophies'     => $trophy,
                ];
            }
        }

        uasort($games, fn($a, $b) => $b['last_played'] <=> $a['last_played']);

        return $games;
    }

    /**
     * @return array<string, array{id:string, name:string, image:string, platform:string, progress:int, earned:array, defined:array, earned_total:int, defined_total:int, updated:int}>
     */
    private function trophyTitles(): array
    {
        $data = $this->call('trophy/v1/users/me/trophyTitles?limit=800', 1800);

        $titles = [];
        foreach ($data['trophyTitles'] ?? [] as $t) {
            $name = (string) ($t['trophyTitleName'] ?? '');
            $key = self::normalize($name);
            if ($key === '' || isset($titles[$key])) {
                continue;
            }
            $earned = self::trophyCounts($t['earnedTrophies'] ?? []);
            $defined = self::trophyCounts($t['definedTrophies'] ?? []);
            $titles[$key] = [
                'id'            => (string) ($t['npCommunicationId'] ?? ''),
                'name'          => $name,
                'image'         => (string) ($t['trophyTitleIconUrl'] ?? ''),
                'platform'      => str_replace(',', ' / ', (string) ($t['trophyTitlePlatform'] ?? '')),
                'progress'      => (int) ($t['progress'] ?? 0),
                'earned'        => $earned,
                'defined'       => $defined,
                'earned_total'  => array_sum($earned),
                'defined_total' => array_sum($defined),
                'updated'       => self::parseTime($t['lastUpdatedDateTime'] ?? null),
            ];
        }

        return $titles;
    }

    /**
     * What's being played right now, if anything.
     *
     * @return array{id:string, name:string, platform:string, image:string}|null
     */
    public function nowPlaying(): ?array
    {
        $summary = $this->trophySummary();
        if (!$summary) {
            return null;
        }
        $data = $this->call('userProfile/v1/internal/users/' . rawurlencode($summary['account_id']) . '/basicPresences?type=primary', $this->presenceTtl);
        $presence = $data['basicPresence'] ?? null;
        $status = $presence['primaryPlatformInfo']['onlineStatus'] ?? ($presence['onlineStatus'] ?? 'offline');
        $game = $presence['gameTitleInfoList'][0] ?? null;

        if ($status !== 'online' || !$game || empty($game['titleName'])) {
            return null;
        }

        return [
            'id'       => (string) ($game['npTitleId'] ?? ''),
            'name'     => (string) $game['titleName'],
            'platform' => strtoupper((string) ($game['format'] ?? $game['launchPlatform'] ?? '')),
            'image'    => (string) ($game['conceptIconUrl'] ?? $game['npTitleIconUrl'] ?? ''),
        ];
    }

    // --- Helpers ---

    /**
     * Matching key for a game name: case-insensitive, ignoring trademark
     * symbols, punctuation and spacing, so "Marvel's Spider-Man 2" from the
     * games list matches "Marvel’s Spider-Man 2" from trophies.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(str_replace(['™', '®', '©'], '', $name));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
    }

    /**
     * Sony reports playtime as an ISO 8601 duration, e.g. "PT228H56M33S".
     */
    public static function durationMinutes(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $iso, $m)) {
            return 0;
        }

        // Unmatched optional groups come back as '' (or not at all), so
        // cast each part before doing arithmetic on it.
        [$days, $hours, $minutes, $seconds] = [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (float) ($m[4] ?? 0)];

        return $days * 1440 + $hours * 60 + $minutes + (int) round($seconds / 60);
    }

    public static function parseTime(?string $value): int
    {
        if (!$value) {
            return 0;
        }
        $ts = strtotime($value);

        return $ts !== false ? $ts : 0;
    }

    private static function trophyCounts(array $counts): array
    {
        return [
            'platinum' => (int) ($counts['platinum'] ?? 0),
            'gold'     => (int) ($counts['gold'] ?? 0),
            'silver'   => (int) ($counts['silver'] ?? 0),
            'bronze'   => (int) ($counts['bronze'] ?? 0),
        ];
    }

    public static function storeSearchUrl(string $name): string
    {
        return 'https://store.playstation.com/en-us/search/' . rawurlencode($name);
    }

    public static function profileUrl(string $onlineId): string
    {
        return 'https://profile.playstation.com/' . rawurlencode($onlineId);
    }
}
