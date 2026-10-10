<?php

/**
 * Loads config.php merged over the defaults, so every entry point (page,
 * poll endpoint, widgets, cron, MCP) agrees on what an unset option means.
 */
class Config
{
    public const DEFAULTS = [
        'steam_api_key'                => '',
        'steam_id'                     => '',
        'retroachievements_username'   => '',
        'retroachievements_api_key'    => '',
        'openxbl_api_key'              => '',
        'xbox_presence_ttl'            => 120,
        'psn_npsso'                    => '',
        'psn_presence_ttl'             => 120,
        'nintendo_session_token'       => '',
        'nintendo_locale'              => 'en-US',
        'twitch_channel'               => '',
        'twitch_client_id'             => '',
        'twitch_client_secret'         => '',
        'twitch_embed_player'          => true,
        'app_name'                     => 'XP Ledger',
        'poll_interval_ms'             => 30000,
        'cache_ttl'                    => 120,
        'top_limit'                    => 8,
        'recent_limit'                 => 8,
        'most_played_default_period'   => 'all_time',
        'genre_default_period'         => 'all_time',
        'timezone'                     => '',
        'ledger_snapshot_interval'     => 600,
        'achievement_backfill_per_run' => 25,
        'store_backfill_per_run'       => 40,
        'store_live_budget'            => 10,
        'loot_game_limit'              => 20,
        'review_sample'                => 60,
        'cron_enabled'                 => false,
        'cron_secret'                  => '',
        'mcp_api_key'                  => '',
        'github_repo'                  => '',
        'update_check_ttl'             => 3600,
    ];

    /**
     * @return array|null null when config.php doesn't exist yet
     */
    public static function load(): ?array
    {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            return null;
        }
        $config = require $file;

        return (is_array($config) ? $config : []) + self::DEFAULTS;
    }

    /**
     * True until the two required settings have real values.
     */
    public static function needsSetup(?array $config): bool
    {
        return $config === null
            || in_array($config['steam_api_key'], ['', 'YOUR_STEAM_API_KEY'], true)
            || in_array($config['steam_id'], ['', 'YOUR_STEAM_ID_OR_PROFILE_URL'], true);
    }
}
