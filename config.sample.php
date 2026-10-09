<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php is gitignored so your API keys never get committed.
 */
return [
    // Required — a free Steam Web API key from
    // https://steamcommunity.com/dev/apikey, and the account to show.
    // steam_id accepts your 64-bit SteamID (7656119...), your custom
    // profile name, or just paste your full profile URL.
    //
    // Your Steam profile's privacy settings need "My profile" and "Game
    // details" set to Public, or Steam hides your games list and
    // achievements from the API (the dashboard tells you if that's the case).
    'steam_api_key' => 'YOUR_STEAM_API_KEY',
    'steam_id'      => 'YOUR_STEAM_ID_OR_PROFILE_URL',

    // Optional — RetroAchievements (retroachievements.org). Your username
    // and the Web API key from https://retroachievements.org/settings
    // (the "Keys" section). Leave either blank to hide RetroAchievements
    // entirely.
    'retroachievements_username' => '',
    'retroachievements_api_key'  => '',

    // Dashboard branding
    'app_name' => 'XP Ledger',

    // How often the browser polls for now-playing updates, in milliseconds.
    // Steam allows 100,000 Web API calls a day; the default 30s poll uses
    // a small fraction of that even with the dashboard open all day.
    'poll_interval_ms' => 30000,

    // How long general server-side API responses are cached, in seconds
    'cache_ttl' => 120,

    // List sizes
    'top_limit'    => 8,    // Most Played
    'recent_limit' => 8,    // Recently Played

    // Which timeframe each period-picker panel shows on page load.
    // Valid values: all_time | this_year | this_month | this_week | today
    // All Time is Steam's own lifetime counter. Every other period comes
    // from the playtime ledger XP Ledger builds as it runs (Steam doesn't
    // keep a dated play history itself) — see "The playtime ledger" in
    // README.md.
    'most_played_default_period' => 'all_time',
    'genre_default_period'       => 'all_time',

    'timezone' => '',   // IANA tz e.g. 'Europe/London' — leave blank to use the server's default

    // How often api.php may take a playtime-ledger snapshot while the
    // dashboard is open, in seconds. cron.php always takes one per run.
    'ledger_snapshot_interval' => 600,

    // Background backfill pacing (per cron.php run). Steam has no "all my
    // achievements" or "all my games' genres" endpoint — it's one request
    // per game — so these fill in gradually instead of in one burst.
    'achievement_backfill_per_run' => 25,   // games' achievement lists refreshed per run (played-since-last-check first)
    'store_backfill_per_run'       => 40,   // games' store details (genres, review counts) fetched per run; the Store API allows ~200 per 5 minutes

    // How many missing store-detail lookups a single page/widget request
    // may make live before falling back to whatever's cached.
    'store_live_budget' => 10,

    // Widget tuning
    'loot_game_limit' => 20,   // games in the Loot Tiers drop
    'review_sample'   => 60,   // most-played games sampled for Hidden Gems / Mainstream Meter

    // Set cron_enabled to true once you've actually scheduled cron.php
    // (shown as a small footer note); cron_secret, if set, is required as
    // a ?token= query param for HTTP-triggered runs of cron.php.
    'cron_enabled' => false,
    'cron_secret'  => '',

    // MCP (Model Context Protocol) server at mcp.php — lets an AI client
    // (Claude, ChatGPT/OpenAI, or anything else speaking MCP) query your
    // library, playtime log and achievements as tools. Read-only, but it's
    // still your real data behind a single static key, so keep it secret.
    // Leave blank (the default) to disable the endpoint entirely.
    'mcp_api_key' => '',

    // Footer "update available" check against the latest GitHub release.
    // Set github_repo to '' to disable the check entirely.
    'github_repo'      => 'MichelleFindlay/XP-Ledger',
    'update_check_ttl' => 3600,
];
