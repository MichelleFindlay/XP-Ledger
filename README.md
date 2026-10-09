# XP Ledger

A single-page gaming dashboard, in the spirit of
[lastfm-dash](https://github.com/MichelleFindlay/lastfm-dash): what you're
playing right now, your most-played and recently played games, a genre
breakdown, lifetime stats and a set of fun data-driven insight widgets, all
themed live from the current game's art.

It pulls from **Steam**, plus **RetroAchievements** if you want it.

## Features

- **Now Playing**: the game you're in right now (via your Steam profile, or
  RetroAchievements rich presence), or the last one you played. Shows its
  library art, playtime, achievement progress and a session timer, with
  Launch / Store / SteamDB / ProtonDB links and a "previously played" card.
  Polled every 30 seconds.
- **Dynamic theming**: the background and accent colour come from the
  current game's art, using the same contrast-safe accent extraction as
  lastfm-dash.
- **Most Played**, with an All Time / This Year / This Month / This Week /
  Today picker. All Time uses Steam's own lifetime counters. The other
  periods come from the playtime ledger (see below).
- **Recently Played**: last-played time and hours over the last two weeks.
- **Genre Breakdown**: playtime-weighted, from Steam store genres, per period.
- **RetroAchievements panel** (optional): points, rank, mastered/beaten
  counts and recent retro games with progress bars.
- **Lifetime Stats**: games owned, hours played, share of the library
  played, achievements, perfect games, Steam level and account age.
- **Insight widgets**, as click-through popups:
  - **Achievement Clock**: a 24-hour radial chart of when you unlock
    achievements, built from Steam's exact unlock timestamps.
  - **Weekly Rhythm**: playtime by weekday, a 12-week day grid and your
    current play streak, from the ledger.
  - **Time Well Spent**: total hours converted into films, flights, working
    weeks and trips to the Moon.
  - **Loot Tiers**: your most-played games rolled as a
    legendary/epic/rare/uncommon drop.
  - **Pile of Shame**: unplayed and barely played counts, the famous games
    you haven't touched, and a tongue-in-cheek backlog forecast.
  - **Completionist**: perfect games, average completion and the 100%s
    you're closest to.
  - **Rarest Unlocks**: your achievements with the lowest global unlock rates.
  - **Hidden Gems** / **Mainstream Meter**: how widely reviewed your
    favourite games are.
  - **Where You Play**: lifetime hours split between Windows,
    Linux/SteamOS, macOS and Steam Deck.
- **MCP server** (optional): lets an AI client query your library,
  playtime log and achievements directly.
- **Self-update check** in the footer, against the latest GitHub release.

Whenever a number is derived rather than read straight from an API, the
dashboard says so, and it labels partial data as partial.

## Requirements

- PHP 7.4+ (8.x recommended), with `zlib` (bundled almost everywhere). The
  `curl` extension is used when available, with an `allow_url_fopen`
  fallback.
- A free Steam Web API key from
  [steamcommunity.com/dev/apikey](https://steamcommunity.com/dev/apikey).
- A Steam profile with **My profile** and **Game details** set to
  **Public** (Profile → Edit Profile → Privacy Settings), and "Always keep
  my total playtime private" unticked. Otherwise Steam hides your games
  from the API. The dashboard shows a banner if that's the case.
- No database. All caching is flat-file, under `cache/`.

## Setup

1. Copy the sample config and fill it in:
   ```sh
   cp config.sample.php config.php
   ```
   Set `steam_api_key` and `steam_id`. `steam_id` accepts your 64-bit
   SteamID, your custom profile name, or your full profile URL. Every other
   setting has a sensible default. The comments in `config.sample.php`
   explain each one.

2. Point a PHP-capable web server at the project root, or for local testing:
   ```sh
   php -S localhost:8000
   ```

3. Schedule `cron.php` (next section). The dashboard works without it, but
   period totals, achievements and genres fill in much more completely with it.

## The playtime ledger

Steam doesn't keep a dated play history. It only exposes each game's
lifetime playtime and a rolling "last two weeks" figure. XP Ledger builds
the history itself: each `cron.php` run (and, at most every 10 minutes,
the dashboard's own polling) snapshots every game's playtime counter and
records how much each one moved. That ledger, saved as
`cache/ledger_*.json.gz`, is what makes This Week / Today, Weekly Rhythm
and the MCP `playtime_log` tool possible.

Limits worth knowing, which the UI also states where they apply:

- **History starts when the ledger does.** Before that, period views show
  what the ledger has so far and label it as partial. If it hasn't recorded
  anything yet, they fall back to Steam's last-two-weeks figure, labelled
  as such.
- **Hour-of-day placement is approximate** to within one snapshot interval.
  Steam may only commit a session's minutes when it ends, so each entry is
  placed in the window ending at the snapshot that saw it. Totals are exact.
- **Delete the ledger file and the history is gone.** Back up `cache/` if
  you care about it.

## Background job (`cron.php`)

Run it every 15 minutes:

```sh
# Real system cron (preferred):
0,15,30,45 * * * * php /full/path/to/XP-Ledger/cron.php >/dev/null 2>&1

# URL-based cron on shared hosting:
0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
```

Each run:

1. takes a ledger snapshot,
2. refreshes a batch of per-game achievement lists, prioritising games
   played since they were last checked (`achievement_backfill_per_run`,
   default 25), plus their global unlock rates,
3. fetches Steam store details (genres, review counts) for a batch of games
   that don't have them yet (`store_backfill_per_run`, default 40; the
   Store API allows about 200 requests per 5 minutes),
4. pre-warms every widget and period-picker cache.

Steam has no bulk endpoint for achievements or store details, so a large
library fills in over several runs instead of one rate-limited burst. If
Steam fails to answer, the run stops that step early rather than waiting out
a timeout per game.

Once it's scheduled, set `'cron_enabled' => true` to show a footer note. If
you set `cron_secret`, HTTP-triggered runs need a matching `?token=`. CLI
runs are always allowed.

## RetroAchievements (optional)

Set `retroachievements_username` and `retroachievements_api_key` (from
[retroachievements.org/settings](https://retroachievements.org/settings),
"Keys" section). This adds the RetroAchievements panel, includes retro games
in the Now Playing card (detected through rich presence), adds your points
to Lifetime Stats, and adds a `retroachievements_summary` MCP tool.
RetroAchievements doesn't track playtime, so it doesn't feed the ledger or
the period totals.

## MCP server (optional)

`mcp.php` exposes your data as read-only tools for any MCP client (Claude,
ChatGPT/OpenAI, and others). Set `mcp_api_key` in `config.php` to a long
random secret. It's blank by default, which disables the endpoint. Then
connect with an `Authorization: Bearer <your mcp_api_key>` header, e.g.:

```sh
claude mcp add --transport http xp-ledger https://yourdomain.com/mcp.php \
  --header "Authorization: Bearer YOUR_MCP_API_KEY"
```

| Tool | What it returns |
|---|---|
| `get_now_playing` | Current/last played game and the one before it |
| `list_games` | The library, filterable (played/unplayed) and sortable |
| `top_games` | Most played for a period, with how complete that period's data is |
| `genre_breakdown` | Playtime-weighted genre percentages for a period |
| `game_details` | One game (by appid or name): playtime per OS, store info, unlocked achievements with dates |
| `list_achievements` | Unlocks with exact timestamps, filterable by date |
| `playtime_log` | Raw ledger entries and how far back tracking goes |
| `lifetime_stats` | The Lifetime Stats panel's totals |
| `retroachievements_summary` | Only when RetroAchievements is configured |
| `widget_*` | One tool per insight widget |

Client setup (Claude app connectors, OpenAI's Responses API, etc.) is
identical to lastfm-dash's. See its README for step-by-step instructions.

## Adding another platform

Each platform is its own small client in `lib/` (`Steam.php`,
`RetroAchievements.php`). `Library::nowPlaying()` merges them into one
shared card shape (`steamCard()` / `retroCards()`), so another source with
a usable API can be added by writing a client and a `...Cards()` method
that returns the same shape. PlayStation and Xbox don't offer official
public APIs for this, so they aren't included.

## License

GPL-3.0. See [LICENSE](LICENSE).
