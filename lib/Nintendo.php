<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional Nintendo client (Switch, Switch 2 — and older 3DS / Wii U
 * titles where Nintendo has them on record).
 *
 * Nintendo has no public API for this. It uses the play-history endpoint
 * behind the "Play Activity" screen in Nintendo's own Nintendo Store app,
 * signed in the same way that app signs in (the approach used by the
 * ns-play-history and NSPlayTime projects): a Nintendo Account session
 * token, valid for about two years, obtained once via nintendo-link.php
 * and pasted into config.php as nintendo_session_token. Each request swaps
 * it for a 15-minute access token, cached under cache/.
 *
 * What it gives: every game played with total minutes, days played,
 * first/last played and box art, plus the last seven days day by day.
 * What it doesn't: anything live. There's no "now playing" here (Nintendo
 * only exposes presence through the Nintendo Switch Online app, which needs
 * a third-party token service to call), and no achievements, since Nintendo
 * doesn't have them. Nintendo also updates play history with a delay, so
 * a session shows up some time after it ends.
 *
 * Rate limits are undocumented, so history is cached 15 minutes and any
 * failure serves the last good data and pauses requests for 5 minutes,
 * same as the Xbox and PlayStation clients.
 */
class Nintendo
{
    public const CLIENT_ID = '5c38e31cd085304b';
    public const REDIRECT_URI = 'npf5c38e31cd085304b://auth';
    public const SCOPE = 'openid user user.mii user.email user.links[].id';
    public const ACCOUNTS = 'https://accounts.nintendo.com/connect/1.0.0/';
    private const API = 'https://app-api.znej.nintendo.com/api/v2.0/users/me/play_histories';
    private const FAILURE_BACKOFF = 300;

    private const PLATFORMS = [
        'BEE' => 'Switch 2',
        'HAC' => 'Switch',
        'CTR' => '3DS',
        'WUP' => 'Wii U',
    ];

    private string $sessionToken;
    private string $locale;
    private string $cacheDir;

    public function __construct(string $sessionToken, string $locale = 'en-US')
    {
        $this->sessionToken = trim($sessionToken);
        $this->locale = $locale !== '' ? $locale : 'en-US';
        $this->cacheDir = __DIR__ . '/../cache/nintendo_' . md5($this->sessionToken);
    }

    public function enabled(): bool
    {
        return $this->sessionToken !== '';
    }

    // --- Auth ---

    private function errorFile(): string
    {
        return $this->cacheDir . '/auth_error.txt';
    }

    /**
     * Why sign-in last failed, or null if it's working.
     */
    public function authError(): ?string
    {
        return is_file($this->errorFile()) ? trim((string) file_get_contents($this->errorFile())) : null;
    }

    private function accessToken(): ?string
    {
        $file = $this->cacheDir . '/access.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
            return (string) $cached['token'];
        }

        $response = Http::postJson(self::ACCOUNTS . 'api/token', [
            'client_id'     => self::CLIENT_ID,
            'session_token' => $this->sessionToken,
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:jwt-bearer-session-token',
        ], ['User-Agent: XP-Ledger/1.0'], 10);
        $data = $response !== null ? json_decode($response, true) : null;

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        if (!is_array($data) || empty($data['access_token'])) {
            // A network failure isn't a sign-in problem; only blame the token
            // when Nintendo actually answered and refused it.
            if (is_array($data)) {
                @file_put_contents($this->errorFile(), 'Nintendo sign-in failed — your session token has expired or been revoked. Run nintendo-link.php again and update nintendo_session_token in config.php.');
            }

            return null;
        }

        @file_put_contents($file, json_encode(['token' => (string) $data['access_token'], 'expires' => time() + (int) ($data['expires_in'] ?? 900)]));
        @chmod($file, 0600);
        @unlink($this->errorFile());

        return (string) $data['access_token'];
    }

    /**
     * Exchange the link copied from Nintendo's "Select this account" page
     * for a long-lived session token. Used by nintendo-link.php.
     *
     * @return array{session_token:?string, error:?string}
     */
    public static function exchangeLink(string $link, string $verifier, string $expectedState): array
    {
        $link = trim($link);
        if (strpos($link, self::REDIRECT_URI . '#') !== 0) {
            return ['session_token' => null, 'error' => 'That isn\'t the sign-in link. It should start with ' . self::REDIRECT_URI . '# — right-click the "Select this account" button and copy the link address (not the address bar).'];
        }

        parse_str(substr($link, strpos($link, '#') + 1), $fragment);
        if (($fragment['state'] ?? '') !== $expectedState) {
            return ['session_token' => null, 'error' => 'That link is from a different sign-in attempt. Start again and use the newest link.'];
        }
        if (empty($fragment['session_token_code'])) {
            return ['session_token' => null, 'error' => 'The link has no session_token_code in it. Copy it again from the "Select this account" button.'];
        }

        $response = Http::postForm(self::ACCOUNTS . 'api/session_token', [
            'client_id'                   => self::CLIENT_ID,
            'session_token_code'          => (string) $fragment['session_token_code'],
            'session_token_code_verifier' => $verifier,
        ], ['User-Agent: XP-Ledger/1.0'], 15);
        $data = $response !== null ? json_decode($response, true) : null;

        if (!is_array($data) || empty($data['session_token'])) {
            return ['session_token' => null, 'error' => 'Nintendo didn\'t accept that link. Links only work once and expire after a few minutes, so start again.'];
        }

        return ['session_token' => (string) $data['session_token'], 'error' => null];
    }

    /**
     * A fresh sign-in URL plus the PKCE verifier and state needed to finish it.
     *
     * @return array{url:string, verifier:string, state:string}
     */
    public static function startLink(): array
    {
        $b64url = fn(string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $verifier = $b64url(random_bytes(32));
        $state = $b64url(random_bytes(36));

        return [
            'url'      => self::ACCOUNTS . 'authorize?' . http_build_query([
                'state'                               => $state,
                'redirect_uri'                        => self::REDIRECT_URI,
                'client_id'                           => self::CLIENT_ID,
                'scope'                               => self::SCOPE,
                'response_type'                       => 'session_token_code',
                'session_token_code_challenge'        => $b64url(hash('sha256', $verifier, true)),
                'session_token_code_challenge_method' => 'S256',
            ], '', '&', PHP_QUERY_RFC3986),
            'verifier' => $verifier,
            'state'    => $state,
        ];
    }

    // --- API ---

    /**
     * The raw play-history response, cached 15 minutes, with the last good
     * copy served (and requests paused) on failure.
     */
    private function history(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/history_' . md5($this->locale) . '.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && (time() - filemtime($cacheFile)) < 900) {
            return $cached;
        }

        $failFile = $this->cacheDir . '/.failed';
        if (is_file($failFile) && (time() - filemtime($failFile)) < self::FAILURE_BACKOFF) {
            return is_array($cached) ? $cached : null;
        }

        $token = $this->accessToken();
        $decoded = null;
        if ($token !== null) {
            $response = Http::get(self::API, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'gentry-locale: ' . $this->locale,
                'User-Agent: XP-Ledger/1.0',
            ], 15);
            $decoded = $response !== null ? json_decode($response, true) : null;
            if (!is_array($decoded) || !isset($decoded['playHistories'])) {
                $decoded = null;
            }
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        if ($decoded === null) {
            @touch($failFile);

            return is_array($cached) ? $cached : null;
        }

        @file_put_contents($cacheFile, json_encode($decoded));
        @unlink($failFile);

        return $decoded;
    }

    /**
     * Every game played, most recently played first. A game played on two
     * consoles (e.g. Switch and Switch 2) comes back as two entries, which
     * are merged here: minutes and days added, the earliest first-played
     * and latest last-played kept, and both platforms listed.
     *
     * @return array<string, array{id:string, name:string, image:string, platforms:string[], minutes:int, days:int, first_played:int, last_played:int}>
     */
    public function games(): array
    {
        $games = [];
        foreach ($this->history()['playHistories'] ?? [] as $h) {
            $id = (string) ($h['titleId'] ?? '');
            $name = (string) ($h['titleName'] ?? '');
            $key = $id !== '' ? $id : md5($name);
            if ($name === '') {
                continue;
            }

            $platform = self::platformLabel((string) ($h['platform'] ?? $h['deviceType'] ?? ''));
            $first = self::parseTime($h['firstPlayedAt'] ?? null);
            $last = self::parseTime($h['lastPlayedAt'] ?? null);

            if (!isset($games[$key])) {
                $games[$key] = [
                    'id'           => $id,
                    'name'         => $name,
                    'image'        => (string) ($h['imageUrl'] ?? ''),
                    'platforms'    => [],
                    'minutes'      => 0,
                    'days'         => 0,
                    'first_played' => $first,
                    'last_played'  => $last,
                ];
            }
            $g = &$games[$key];
            if ($platform !== '' && !in_array($platform, $g['platforms'], true)) {
                $g['platforms'][] = $platform;
            }
            $g['minutes'] += (int) ($h['totalPlayedMinutes'] ?? 0);
            $g['days'] += (int) ($h['totalPlayedDays'] ?? 0);
            if ($first > 0 && ($g['first_played'] === 0 || $first < $g['first_played'])) {
                $g['first_played'] = $first;
            }
            $g['last_played'] = max($g['last_played'], $last);
            if ($g['image'] === '' && !empty($h['imageUrl'])) {
                $g['image'] = (string) $h['imageUrl'];
            }
            unset($g);
        }

        uasort($games, fn($a, $b) => $b['last_played'] <=> $a['last_played']);

        return $games;
    }

    /**
     * The last seven days, oldest first: each day's total minutes and the
     * games played that day.
     *
     * @return array<int, array{date:string, minutes:int, games:array<int, array{name:string, minutes:int}>}>
     */
    public function recentDays(): array
    {
        $days = [];
        foreach ($this->history()['recentPlayHistories'] ?? [] as $day) {
            $ts = self::parseTime($day['playedDate'] ?? null);
            $games = [];
            foreach ($day['dailyPlayHistories'] ?? [] as $d) {
                $games[] = ['name' => (string) ($d['titleName'] ?? ''), 'minutes' => (int) ($d['totalPlayedMinutes'] ?? 0)];
            }
            usort($games, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
            $days[] = [
                'date'    => $ts > 0 ? date('Y-m-d', $ts) : (string) ($day['playedDate'] ?? ''),
                'minutes' => array_sum(array_column($games, 'minutes')),
                'games'   => $games,
            ];
        }
        usort($days, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $days;
    }

    // --- Helpers ---

    public static function platformLabel(string $code): string
    {
        return self::PLATFORMS[strtoupper($code)] ?? $code;
    }

    public static function parseTime(?string $value): int
    {
        if (!$value) {
            return 0;
        }
        $ts = strtotime($value);

        return $ts !== false ? $ts : 0;
    }

    public static function storeSearchUrl(string $name): string
    {
        return 'https://www.nintendo.com/us/search/#q=' . rawurlencode($name);
    }
}
