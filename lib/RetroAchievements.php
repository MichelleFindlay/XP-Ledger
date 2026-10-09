<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional RetroAchievements (retroachievements.org) client — emulated
 * retro games with achievement sets, which Steam knows nothing about.
 * Needs the account's username plus its free Web API key (from
 * https://retroachievements.org/settings, "Keys" section). Entirely
 * skipped when either is blank.
 *
 * RetroAchievements doesn't track playtime, so it contributes to the
 * now-playing hero (via rich presence), its own panel, and lifetime
 * stats — not to the playtime ledger or period totals.
 */
class RetroAchievements
{
    private const API = 'https://retroachievements.org/API/';
    private const MEDIA = 'https://media.retroachievements.org';

    private string $username;
    private string $apiKey;
    private int $cacheTtl;
    private string $cacheDir;

    public function __construct(string $username, string $apiKey, int $cacheTtl = 60)
    {
        $this->username = trim($username);
        $this->apiKey = trim($apiKey);
        $this->cacheTtl = $cacheTtl;
        $this->cacheDir = __DIR__ . '/../cache/retro';
    }

    public function enabled(): bool
    {
        return $this->username !== '' && $this->apiKey !== '';
    }

    public function username(): string
    {
        return $this->username;
    }

    private function call(string $endpoint, array $params, int $ttl): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/' . md5($endpoint . serialize($params) . $this->username) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = self::API . $endpoint . '.php?' . http_build_query(['u' => $this->username, 'y' => $this->apiKey] + $params);
        $response = Http::get($url, ['User-Agent: XP-Ledger/1.0'], 10);
        $decoded = $response !== null ? json_decode($response, true) : null;

        // Errors come back as {"message": ...} or {"error": ...} objects.
        if (!is_array($decoded) || isset($decoded['message']) || isset($decoded['error'])) {
            return null;
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($decoded));

        return $decoded;
    }

    /**
     * Profile, points, rank, online status and rich presence ("what are
     * they doing right now"), plus the two most recently played games.
     */
    public function summary(?int $ttl = null): ?array
    {
        return $this->call('API_GetUserSummary', ['g' => 2, 'a' => 0], $ttl ?? $this->cacheTtl);
    }

    public function recentlyPlayed(int $limit = 10): array
    {
        return $this->call('API_GetUserRecentlyPlayedGames', ['c' => max(1, min(50, $limit))], $this->cacheTtl) ?? [];
    }

    /**
     * Every game with progress, and its highest award (mastered,
     * completed, beaten). Cached an hour — it's a large response.
     */
    public function completionProgress(): array
    {
        $data = $this->call('API_GetUserCompletionProgress', ['c' => 500], 3600);

        return $data['Results'] ?? [];
    }

    public static function media(?string $path): string
    {
        return $path ? self::MEDIA . $path : '';
    }

    public static function gameUrl(int $gameId): string
    {
        return 'https://retroachievements.org/game/' . $gameId;
    }

    public function profileUrl(): string
    {
        return 'https://retroachievements.org/user/' . rawurlencode($this->username);
    }

    /**
     * RetroAchievements reports times as UTC "Y-m-d H:i:s" strings.
     */
    public static function parseTime(?string $value): int
    {
        if (!$value) {
            return 0;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $dt ? $dt->getTimestamp() : 0;
    }
}
