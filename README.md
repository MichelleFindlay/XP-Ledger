# XP Ledger

A single-page gaming dashboard, in the spirit of
[lastfm-dash](https://github.com/MichelleFindlay/lastfm-dash): what you're
playing right now, your most-played and recently played games, a genre
breakdown, lifetime stats and a set of fun data-driven insight widgets, all
themed live from the current game's art.

It pulls from **Steam**, plus **Xbox** (consoles and PC Game Pass / Xbox
app games), **PlayStation** (PS4 / PS5) and **RetroAchievements** if you
want them.

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
- **Xbox panel** (optional): gamerscore, games played, tracked hours, and
  recent Xbox / PC Game Pass titles with devices, playtime and gamerscore
  progress.
- **PlayStation panel** (optional): trophy level, platinum/gold/silver/bronze
  counts, hours played, and recent games with playtime and trophy progress.
- **RetroAchievements panel** (optional): points, rank, mastered/beaten
  counts and recent retro games with progress bars.
- **Lifetime Stats**: games owned, hours played, share of the library
  played, achievements, perfect games, Steam level and account age.
- **Insight widgets**, as click-through popups:
  - **Achievement Clock**: a 24-hour radial chart of when you unlock
    achievements, built from Steam's exact unlock timestamps.
  - **Weekly Rhythm**: playtime by weekday, a 12-week day grid and your
    current play streak, from the ledger.
  - **Time Well Spent**: total hours measured in Super Mario Bros. speedruns,
    Minecraft and GTA V in-game days, Portal and Elden Ring playthroughs,
    Stardew Valley years and Games Done Quick marathons.
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

## Xbox and PC Game Pass (optional)

Microsoft only opens its Xbox APIs to licensed game developers, so XP
Ledger uses [OpenXBL](https://xbl.io), an unofficial third-party gateway.
Sign in there with your Microsoft account, copy your personal API key from
the dashboard into `openxbl_api_key`, and you get:

- Xbox games in the Now Playing card (from Xbox presence, including rich
  presence like "Racing in Guanajuato"),
- an Xbox panel with gamerscore, games played and recent titles,
- an `xbox_summary` MCP tool.

This covers anything played on an Xbox console and PC games played through
the Xbox app, PC Game Pass or the Microsoft Store, since they share one
Xbox account. Steam games on PC are already covered by the Steam side.

Things to know:

- **OpenXBL holds a token for your Microsoft account.** That's how it
  reaches Xbox Live on your behalf.
- **Rate limit.** The free tier allows 150 requests an hour. XP Ledger
  checks presence at most every `xbox_presence_ttl` seconds (default 120,
  minimum 60), caches the title list for 15 minutes and the profile and
  playtime for an hour (playtime for every game is one batched request),
  which comes to roughly 40-50 requests an hour with the dashboard open all
  day.
- **OpenXBL can be slow**, taking 8-10 seconds even to reject a bad key. If a
  request fails (bad key, outage, rate limiting), XP Ledger keeps showing
  the last good data and makes no Xbox requests for 5 minutes, so one slow
  failure can't stall every page load.
- **Playtime is patchy.** Hours come from Xbox's per-game "MinutesPlayed"
  stat, which some older and PC titles don't report. Xbox playtime doesn't
  feed the playtime ledger, Most Played or Genre Breakdown, which stay
  Steam-only.

## PlayStation (optional)

Sony has no public API, so XP Ledger uses the same unofficial PlayStation
App endpoints as the [psn-api](https://github.com/achievements-app/psn-api)
library. To connect:

1. Sign in at [playstation.com](https://www.playstation.com) in your browser.
2. Open <https://ca.account.sony.com/api/v1/ssocookie> and copy the
   64-character `npsso` value.
3. Paste it into `psn_npsso` in `config.php`.

Treat the NPSSO like a password: it signs in as you. XP Ledger exchanges it
for an access token and refresh token, stored in `cache/` and refreshed
automatically. The NPSSO lasts about two months. When it expires, the
dashboard shows a banner asking for a new one.

You get PlayStation games in the Now Playing card, a PlayStation panel and a
`playstation_summary` MCP tool. Notes:

- **Playtime is PS4/PS5 only.** Sony doesn't report it for PS3 or Vita;
  those games still appear through their trophy lists.
- **Games and trophies are matched by name**, since Sony keeps them under
  different IDs.
- **Rate limits are undocumented,** so everything is cached conservatively
  (presence every `psn_presence_ttl` seconds, default 120). Failures show
  the last good data and pause PSN requests for 5 minutes.
- PlayStation playtime doesn't feed the playtime ledger, Most Played or
  Genre Breakdown, which stay Steam-only.

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
| `xbox_summary` | Only when Xbox is configured: profile, now playing, every title with gamerscore and playtime |
| `playstation_summary` | Only when PlayStation is configured: trophy level and counts, now playing, every game with playtime and trophy progress |
| `retroachievements_summary` | Only when RetroAchievements is configured |
| `widget_*` | One tool per insight widget |

Client setup (Claude app connectors, OpenAI's Responses API, etc.) is
identical to lastfm-dash's. See its README for step-by-step instructions.

## Adding another platform

Each platform is its own small client in `lib/` (`Steam.php`, `Xbox.php`,
`PlayStation.php`, `RetroAchievements.php`). `Library::nowPlaying()` merges them into one
shared card shape (`steamCard()` / `xboxCard()` / `retroCards()`), so
another source with a usable API can be added by writing a client and a
`...Cards()` method that returns the same shape.

## License

GPL-3.0. See [LICENSE](LICENSE).
