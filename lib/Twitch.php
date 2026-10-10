<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional Twitch live status, via Twitch's official Helix API.
 *
 * Needs a free app registration at https://dev.twitch.tv/console/apps
 * (any name; OAuth redirect http://localhost; category "Website
 * Integration") for a Client ID and Client Secret, plus the channel name
 * to watch. Uses an app access token (client-credentials flow, no user
 * sign-in), which lasts about two months and is cached under cache/;
 * refreshed automatically when it expires or Twitch rejects it.
 *
 * Status is cached 60 seconds, so the dashboard's polling costs at most
 * one Helix request a minute — far inside Twitch's 800-requests-a-minute
 * limit. A failure serves the last known status and pauses requests for
 * a few minutes, like the other platform clients.
 */
class Twitch
{
    private const HELIX = 'https://api.twitch.tv/helix/';
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const FAILURE_BACKOFF = 180;

    private string $channel;
    private string $clientId;
    private string $clientSecret;
    private string $cacheDir;

    public function __construct(string $channel, string $clientId, string $clientSecret)
    {
        // Accept a bare name, an @name, or a full twitch.tv URL.
        $channel = trim($channel);
        if (preg_match('~twitch\.tv/([A-Za-z0-9_]+)~', $channel, $m)) {
            $channel = $m[1];
        }
        $this->channel = strtolower(ltrim($channel, '@'));
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
        $this->cacheDir = __DIR__ . '/../cache/twitch_' . md5($this->clientId);
    }

    public function enabled(): bool
    {
        return $this->channel !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function channelUrl(): string
    {
        return 'https://www.twitch.tv/' . rawurlencode($this->channel);
    }

    private function appToken(bool $forceNew = false): ?string
    {
        $file = $this->cacheDir . '/token.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!$forceNew && is_array($cached) && ($cached['expires'] ?? 0) > time() + 300) {
            return (string) $cached['token'];
        }

        $response = Http::postForm(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ], ['User-Agent: XP-Ledger/1.0'], 8);
        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($file, json_encode(['token' => (string) $data['access_token'], 'expires' => time() + (int) ($data['expires_in'] ?? 3600)]));
        @chmod($file, 0600);

        return (string) $data['access_token'];
    }

    /**
     * GET a Helix endpoint, retrying once with a fresh token if Twitch
     * says the current one is invalid (they can be revoked early).
     */
    private function helix(string $path): ?array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->appToken($attempt > 0);
            if ($token === null) {
                return null;
            }
            $response = Http::get(self::HELIX . $path, [
                'Client-Id: ' . $this->clientId,
                'Authorization: Bearer ' . $token,
                'User-Agent: XP-Ledger/1.0',
            ], 8);
            $data = $response !== null ? json_decode($response, true) : null;
            if (is_array($data) && isset($data['data'])) {
                return $data;
            }
            if (!is_array($data) || (int) ($data['status'] ?? 0) !== 401) {
                return null;
            }
        }

        return null;
    }

    /**
     * The live stream, or null when offline (or Twitch is unreachable and
     * nothing is cached).
     *
     * @return array{channel:string, display_name:string, title:string, game:string, viewers:int, started_at:int, thumbnail:string, url:string}|null
     */
    public function liveStream(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/status_' . md5($this->channel) . '.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
        $hasCache = is_array($cached) && array_key_exists('live', $cached);
        if ($hasCache && (time() - filemtime($cacheFile)) < 60) {
            return $cached['live'];
        }

        $failFile = $this->cacheDir . '/.failed';
        if (is_file($failFile) && (time() - filemtime($failFile)) < self::FAILURE_BACKOFF) {
            return $hasCache ? $cached['live'] : null;
        }

        $data = $this->helix('streams?' . http_build_query(['user_login' => $this->channel]));

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        if ($data === null) {
            @touch($failFile);

            return $hasCache ? $cached['live'] : null;
        }

        $s = $data['data'][0] ?? null;
        $live = ($s && ($s['type'] ?? 'live') === 'live') ? [
            'channel'      => (string) ($s['user_login'] ?? $this->channel),
            'display_name' => (string) ($s['user_name'] ?? $this->channel),
            'title'        => (string) ($s['title'] ?? ''),
            'game'         => (string) ($s['game_name'] ?? ''),
            'viewers'      => (int) ($s['viewer_count'] ?? 0),
            'started_at'   => (int) (strtotime((string) ($s['started_at'] ?? '')) ?: time()),
            'thumbnail'    => str_replace(['{width}', '{height}'], ['640', '360'], (string) ($s['thumbnail_url'] ?? '')),
            'url'          => $this->channelUrl(),
        ] : null;

        @file_put_contents($cacheFile, json_encode(['live' => $live]));
        @unlink($failFile);

        return $live;
    }
}
