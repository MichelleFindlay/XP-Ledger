# XP Ledger

**v1.0.0** · A self-hosted, single-page gaming dashboard, in the spirit of
[lastfm-dash](https://github.com/MichelleFindlay/lastfm-dash).

It shows what you're playing right now across every platform you connect,
your most-played and recently played games, a genre breakdown, lifetime
stats and a set of fun insight widgets. The whole page is styled as a game
HUD and recolours itself from the current game's art.

## Platforms at a glance

| Platform | Required? | Now playing | Playtime | Achievements | Connects via |
|---|---|---|---|---|---|
| **Steam** | Yes | Live | Lifetime, per-OS, plus the ledger | Yes, with unlock dates | Official Web API key |
| **Xbox / PC Game Pass** | Optional | Live, with rich presence | Per game, where reported | Gamerscore and progress | [OpenXBL](https://xbl.io) (unofficial) |
| **PlayStation** (PS4 / PS5) | Optional | Live | Per game (PS4 / PS5) | Trophies, by tier | NPSSO token (unofficial) |
| **Nintendo** (Switch / Switch 2) | Optional | Last played only | Per game, plus the last 7 days | None (Nintendo has none) | Nintendo Account sign-in (unofficial) |
| **RetroAchievements** | Optional | Live, with rich presence | None | Yes | Official API key |
| **Twitch** | Optional | Live banner when you're streaming | n/a | n/a | Official Helix API |

Steam is the backbone. The playtime ledger, Most Played periods, Genre
Breakdown and most widgets are built from Steam data. The other platforms
add their own panels and join the Now Playing card. See each platform's
section below for setup and limits.

## Features

- **Now Playing**: the game you're in right now on any connected platform,
  or the most recent one you played. Shows its art, playtime, achievement or
  trophy progress, a session timer, and links (Launch / Store / SteamDB /
  ProtonDB for Steam games), plus a "previously played" card. Refreshes
  every 30 seconds.
- **Twitch live banner**: while your channel is live, a "Live on Twitch"
  banner with the stream embedded appears at the top of the page, and
  disappears when the stream ends.
- **Gaming HUD look**: display font, glowing corner brackets, segmented
  XP-style progress bars, and a header player card with your Steam level and
  XP bar. The accent colour is taken live from the current game's art, with
  the same contrast-safe extraction as lastfm-dash.
- **Most Played**, with an All Time / This Year / This Month / This Week /
  Today picker. All Time uses Steam's own lifetime counters; the other
  periods come from the playtime ledger (see below).
- **Recently Played**: last-played time and hours over the last two weeks.
- **Genre Breakdown**: playtime-weighted, from Steam store genres, per period.
- **Hover info cards** on every game image: developer, release date,
  genres, your playtime and OS split, achievements or trophies, and review
  scores.
- **Platform panels** for Xbox, PlayStation, Nintendo and
  RetroAchievements: profile stats plus recent games with playtime and
  progress. Games with no stats are left out.
- **Lifetime Stats**: games owned, hours played, share of the library
  played, achievements, perfect games, Steam level and account age.
- **10 insight widgets**, as click-through popups:
  - **Achievement Clock**: a 24-hour radial chart of when you unlock
    achievements, from Steam's exact unlock timestamps.
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
- **MCP server** (optional): lets an AI client query your library, play
  history, achievements and every connected platform. Read-only.
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
- No database. Everything is stored as files under `cache/`.

## Quick start

1. Copy the sample config:
   ```sh
   cp config.sample.php config.php
   ```
2. Set `steam_api_key` and `steam_id` in `config.php`. `steam_id` accepts
   your 64-bit SteamID, your custom profile name, or your full profile URL.
3. Point a PHP-capable web server at the project folder, or for local
   testing:
   ```sh
   php -S localhost:8000
   ```
4. Schedule `cron.php` every 15 minutes (see
   [Background job](#background-job-cronphp)), then set
   `'cron_enabled' => true`. The dashboard works without it, but period
   totals, achievements and genres fill in far more completely with it.
5. Optionally connect more platforms: [Xbox](#xbox-and-pc-game-pass-optional),
   [PlayStation](#playstation-optional), [Nintendo](#nintendo-optional),
   [RetroAchievements](#retroachievements-optional),
   [Twitch](#twitch-optional) and the [MCP server](#mcp-server-optional).

Any setting you leave out of `config.php` falls back to its default, so an
older `config.php` keeps working when new options are added. Copy the
relevant lines from `config.sample.php` when you want a new feature.

## Configuration reference

All settings live in `config.php`. The comments in `config.sample.php`
explain each one in more detail.

| Setting | Default | What it does |
|---|---|---|
| `steam_api_key`, `steam_id` | none | **Required.** Your Steam Web API key and account |
| `openxbl_api_key` | `''` | Xbox via OpenXBL; blank turns Xbox off |
| `xbox_presence_ttl` | `120` | Seconds between Xbox "now playing" checks (min 60) |
| `psn_npsso` | `''` | PlayStation sign-in token; blank turns PlayStation off |
| `psn_presence_ttl` | `120` | Seconds between PlayStation "now playing" checks (min 60) |
| `nintendo_session_token` | `''` | Nintendo sign-in token from `nintendo-link.php`; blank turns Nintendo off |
| `nintendo_locale` | `'en-US'` | Language for Nintendo game names |
| `retroachievements_username`, `retroachievements_api_key` | `''` | RetroAchievements; blank turns it off |
| `twitch_channel`, `twitch_client_id`, `twitch_client_secret` | `''` | Twitch live banner; any blank turns it off |
| `twitch_embed_player` | `true` | `false` shows a preview image instead of the video player |
| `app_name` | `'XP Ledger'` | Page title and header |
| `poll_interval_ms` | `30000` | How often the page refreshes Now Playing and Twitch |
| `cache_ttl` | `120` | General Steam API cache, in seconds |
| `top_limit`, `recent_limit` | `8` | Rows in Most Played / Recently Played |
| `most_played_default_period`, `genre_default_period` | `'all_time'` | Initial period for each picker |
| `timezone` | server default | IANA timezone, e.g. `'Europe/London'` |
| `ledger_snapshot_interval` | `600` | How often page polling may snapshot playtime, in seconds |
| `achievement_backfill_per_run` | `25` | Games' achievements checked per cron run |
| `store_backfill_per_run` | `40` | Games' store details fetched per cron run |
| `store_live_budget` | `10` | Live store lookups a single page request may make |
| `loot_game_limit`, `review_sample` | `20`, `60` | Widget tuning |
| `cron_enabled`, `cron_secret` | `false`, `''` | Footer note, and a `?token=` required for web-triggered cron runs |
| `mcp_api_key` | `''` | Enables `mcp.php`; blank turns it off |
| `github_repo`, `update_check_ttl` | repo, `3600` | Footer update check; `''` turns it off |

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
- **Steam only.** Xbox, PlayStation and Nintendo playtime appears in their
  own panels but doesn't feed the ledger, Most Played or Genre Breakdown.
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

1. takes a playtime ledger snapshot,
2. refreshes a batch of Steam achievement lists, prioritising games played
   since they were last checked (`achievement_backfill_per_run`), plus their
   global unlock rates,
3. fetches Steam store details (genres, review counts) for a batch of games
   that don't have them yet (`store_backfill_per_run`; the Store API allows
   about 200 requests per 5 minutes),
4. refreshes Xbox, PlayStation and Nintendo data (when configured), keeping
   their sign-ins fresh,
5. pre-warms every widget and period-picker cache.

Steam has no bulk endpoint for achievements or store details, so a large
library fills in over several runs instead of one rate-limited burst. If a
service fails to answer, the run stops that step early rather than waiting
out a timeout per game. The run's one-line summary also reports when a
PlayStation or Nintendo sign-in needs renewing.

Once it's scheduled, set `'cron_enabled' => true` to show a footer note. If
you set `cron_secret`, web-triggered runs need a matching `?token=`.
Command-line runs are always allowed.

## Xbox and PC Game Pass (optional)

Microsoft only opens its Xbox APIs to licensed game developers, so XP
Ledger uses [OpenXBL](https://xbl.io), an unofficial third-party gateway.
Sign in there with your Microsoft account, copy your personal API key from
the dashboard into `openxbl_api_key`, and you get:

- Xbox games in the Now Playing card (from Xbox presence, including rich
  presence like "Racing in Guanajuato"),
- an Xbox panel with gamerscore, games played, hours and recent titles,
- an `xbox_summary` MCP tool.

This covers anything played on an Xbox console and PC games played through
the Xbox app, PC Game Pass or the Microsoft Store, since they share one
Xbox account. Steam games on PC are already covered by the Steam side.

Things to know:

- **OpenXBL holds a token for your Microsoft account.** That's how it
  reaches Xbox Live on your behalf.
- **Rate limit.** The free tier allows 150 requests an hour. XP Ledger
  checks presence at most every `xbox_presence_ttl` seconds, caches the
  title list for 15 minutes and the profile and playtime for an hour
  (playtime for every game is one batched request). That comes to roughly
  40-50 requests an hour with the dashboard open all day.
- **OpenXBL can be slow**, taking 8-10 seconds even to reject a bad key. If a
  request fails (bad key, outage, rate limiting), XP Ledger keeps showing
  the last good data and makes no Xbox requests for 5 minutes, so one slow
  failure can't stall every page load.
- **Playtime is patchy.** Hours come from Xbox's per-game "MinutesPlayed"
  stat, which some older and PC titles don't report.

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

You get PlayStation games in the Now Playing card, a PlayStation panel
(trophy level, platinum/gold/silver/bronze counts, hours, recent games) and
a `playstation_summary` MCP tool. Things to know:

- **Playtime is PS4/PS5 only.** Sony doesn't report it for PS3 or Vita;
  those games still appear through their trophy lists.
- **Games and trophies are matched by name**, since Sony keeps them under
  different IDs.
- **Rate limits are undocumented,** so everything is cached conservatively
  (presence every `psn_presence_ttl` seconds). Failures show the last good
  data and pause PlayStation requests for 5 minutes.

## Nintendo (optional)

Nintendo has no public API, so XP Ledger reads the play history behind the
"Play Activity" screen in Nintendo's own Nintendo Store app, signing in the
same way that app does. To connect:

1. Open `nintendo-link.php` in your browser (or run `php nintendo-link.php`).
2. Click **Sign in to Nintendo** and sign in. Nintendo will say it's linking
   to "Nintendo Store"; that's the app whose sign-in this uses.
3. On the **Select this account** page, don't click the button. Right-click it
   and choose **Copy link address**, then paste that link into the helper.
4. Copy the `nintendo_session_token` line it gives you into `config.php`.

The session token lasts about two years. Treat it like a password: it signs
in as you. The helper stores nothing on the server, so it's safe to leave in
place, but you can delete it once you're connected. If the token stops
working, the dashboard shows a banner and you run the helper again.

You get Nintendo games in the Now Playing card (as **last played**), a
Nintendo panel (hours, games played, a 7-day bar chart, recent games) and a
`nintendo_summary` MCP tool. Things to know:

- **There's no "now playing".** Nintendo only publishes live status through
  the Nintendo Switch Online app, which needs a third-party token service to
  call, so XP Ledger doesn't use it. Play history also updates a while after
  a session ends, not during it.
- **No achievements**, since Nintendo doesn't have them.
- **Games played on both Switch and Switch 2** are merged into one entry.
- **3DS / Wii U games** appear if Nintendo has them on record, usually
  without playtime.
- History is cached 15 minutes. Failures show the last good data and pause
  Nintendo requests for 5 minutes.

## RetroAchievements (optional)

Set `retroachievements_username` and `retroachievements_api_key` (from
[retroachievements.org/settings](https://retroachievements.org/settings),
"Keys" section). This adds the RetroAchievements panel, includes retro games
in the Now Playing card (detected through rich presence), adds your points
to Lifetime Stats, and adds a `retroachievements_summary` MCP tool.
RetroAchievements doesn't track playtime, so it doesn't feed the ledger or
the period totals.

## Twitch (optional)

When your Twitch channel is live, a banner appears at the top of the
dashboard with the stream embedded (muted), its title, game, viewer count
and how long you've been live. It updates on the dashboard's normal
refresh, so it appears and disappears without a reload. When you're not
live, nothing shows.

This uses Twitch's official API. To set it up:

1. Go to [dev.twitch.tv/console/apps](https://dev.twitch.tv/console/apps)
   and sign in. Twitch may ask you to turn on two-factor authentication first.
2. Click **Register Your Application** and fill in:
   - **Name:** anything unique, e.g. `XP Ledger <yourname>`
   - **OAuth Redirect URLs:** `http://localhost` (required by the form, not used)
   - **Category:** Website Integration
   - **Client Type:** Confidential
3. Click **Create**, then **Manage**. Copy the **Client ID**, then click
   **New Secret** and copy the **Client Secret**. Twitch only shows the
   secret once.
4. Add these to `config.php`:
   ```php
   'twitch_channel'       => 'yourchannel',   // or the full twitch.tv URL
   'twitch_client_id'     => 'paste-client-id-here',
   'twitch_client_secret' => 'paste-client-secret-here',
   'twitch_embed_player'  => true,            // false = preview image instead of the player
   ```

Things to know:

- **Live status is checked at most once a minute**, well inside Twitch's
  limits, so the banner can take up to a minute to appear after you go live.
- **Twitch only plays embeds on a domain name.** Open the dashboard as
  `localhost` or your site's domain, not a bare IP address. On an IP the
  banner text still works but the player won't load.
- **Wrong credentials fail quietly.** If the banner never appears while
  you're live, check all three values. After a failure XP Ledger waits
  3 minutes before asking Twitch again.

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
| `get_now_playing` | Current/last played game across platforms, the one before it, and whether you're live on Twitch |
| `list_games` | The Steam library, filterable (played/unplayed) and sortable |
| `top_games` | Most played for a period, with how complete that period's data is |
| `genre_breakdown` | Playtime-weighted genre percentages for a period |
| `game_details` | One game (by appid or name): playtime per OS, store info, unlocked achievements with dates |
| `list_achievements` | Unlocks with exact timestamps, filterable by date |
| `playtime_log` | Raw ledger entries and how far back tracking goes |
| `lifetime_stats` | The Lifetime Stats panel's totals |
| `xbox_summary` | Only when Xbox is configured: profile, now playing, every title with gamerscore and playtime |
| `playstation_summary` | Only when PlayStation is configured: trophy level and counts, now playing, every game with playtime and trophy progress |
| `nintendo_summary` | Only when Nintendo is configured: every game with minutes, days played, first/last played, and the last 7 days |
| `retroachievements_summary` | Only when RetroAchievements is configured |
| `widget_*` | One tool per insight widget |

Client setup (Claude app connectors, OpenAI's Responses API, etc.) is the
same as for lastfm-dash. See its README for step-by-step instructions.

## Security

- **`config.php`** holds your API keys and the PlayStation, Nintendo and
  Twitch credentials. It's gitignored; keep it private.
- **`cache/`** holds access tokens (PlayStation, Nintendo, Twitch) and your
  playtime history. The included `.htaccess` blocks web access to it on
  Apache. On nginx or other servers, block that folder in your server config.
- **`mcp.php`** is off until you set `mcp_api_key`. Anyone with that key can
  read everything the dashboard can, so treat it like a password.
- **`nintendo-link.php`** stores nothing and only shows a token to whoever
  signs in with their own Nintendo account. Delete it after connecting if
  you'd rather it wasn't there.
- Set **`cron_secret`** if `cron.php` is reachable on the web, so random
  visitors can't trigger runs.

## Upgrading

Pull the new version (or copy the files over), keeping your `config.php`
and `cache/` folder. New settings fall back to their defaults until you add
them, so nothing breaks. Check `config.sample.php` for new options. The
footer tells you when a newer release is available.

## Adding another platform

Each platform is its own small client in `lib/`: `Steam.php`, `Xbox.php`,
`PlayStation.php`, `Nintendo.php`, `RetroAchievements.php` and `Twitch.php`.
`Library::nowPlaying()` merges the game platforms into one shared card shape
(see `steamCard()`, `xboxCard()`, `psnCard()` and `retroCards()`), so
another source with a usable API can be added by writing a client and a
`...Cards()` method that returns the same shape.

## License

GPL-3.0. See [LICENSE](LICENSE).
