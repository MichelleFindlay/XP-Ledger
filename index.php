<?php

// A cold first load can make a handful of live Store API lookups for Genre
// Breakdown (capped by store_live_budget), so the default 30s execution
// limit some hosts set isn't always enough. Silently no-ops on hosts where
// set_time_limit is disabled.
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Library.php';
require __DIR__ . '/lib/VersionCheck.php';

Security::sendHeaders('html');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The app's own version, tracked in git via a plain VERSION file rather
 * than config.php (which is gitignored and user-managed).
 */
function appVersion(): string
{
    $versionFile = __DIR__ . '/VERSION';

    return is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';
}

/**
 * Inner markup (no wrapping <svg>) for a small set of Lucide icons
 * (ISC-licensed, lucide.dev), used to give each widget card and lifetime
 * stat a quick visual identifier. Kept as plain strings rather than fetched
 * at request time so the page has no runtime dependency on an icon CDN.
 */
const ICONS = [
    'alarm-clock'    => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/>',
    'calendar-range' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M17 14h-6"/><path d="M13 18H7"/><path d="M7 14h.01"/><path d="M17 18h.01"/>',
    'hourglass'      => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
    'swords'         => '<polyline points="14.5 17.5 3 6 3 3 6 3 17.5 14.5"/><line x1="13" x2="19" y1="19" y2="13"/><line x1="16" x2="20" y1="16" y2="20"/><line x1="19" x2="21" y1="21" y2="19"/><polyline points="14.5 6.5 18 3 21 3 21 6 17.5 9.5"/><line x1="5" x2="9" y1="14" y2="18"/><line x1="7" x2="4" y1="17" y2="20"/><line x1="3" x2="5" y1="19" y2="21"/>',
    'archive'        => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
    'trophy'         => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    'gem'            => '<path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/>',
    'telescope'      => '<path d="m10.065 12.493-6.18 1.318a.934.934 0 0 1-1.108-.702l-.537-2.15a1.07 1.07 0 0 1 .691-1.265l13.504-4.44"/><path d="m13.56 11.747 4.332-.924"/><path d="m16 21-3.105-6.21"/><path d="M16.485 5.94a2 2 0 0 1 1.455-2.425l1.09-.272a1 1 0 0 1 1.212.727l1.515 6.06a1 1 0 0 1-.727 1.213l-1.09.272a2 2 0 0 1-2.425-1.455z"/><path d="m6.158 8.633 1.114 4.456"/><path d="m8 21 3.105-6.21"/><circle cx="12" cy="13" r="2"/>',
    'gauge'          => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
    'monitor'        => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
    'library'        => '<rect width="8" height="18" x="3" y="3" rx="1"/><path d="M7 3v18"/><path d="M20.4 18.9c.2.5-.1 1.1-.6 1.3l-1.9.7c-.5.2-1.1-.1-1.3-.6L11.1 5.1c-.2-.5.1-1.1.6-1.3l1.9-.7c.5-.2 1.1.1 1.3.6Z"/>',
    'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'gamepad'        => '<line x1="6" x2="10" y1="11" y2="11"/><line x1="8" x2="8" y1="9" y2="13"/><line x1="15" x2="15.01" y1="12" y2="12"/><line x1="18" x2="18.01" y1="10" y2="10"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"/>',
    'medal'          => '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/><path d="M12 18v-2h-.5"/>',
    'zap'            => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    'calendar-days'  => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>',
    'joystick'       => '<path d="M21 17a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2Z"/><path d="M6 15v-2"/><path d="M12 15V9"/><circle cx="12" cy="6" r="3"/>',
    'play'           => '<polygon points="6 3 20 12 6 21 6 3"/>',
    // Brand mark (Simple Icons, CC0) — a filled shape, unlike the Lucide line icons above.
    'playstation'    => '<path fill="currentColor" stroke="none" d="M8.984 2.596v17.547l3.915 1.261V6.688c0-.69.304-1.151.794-.991.636.18.76.814.76 1.505v5.875c2.441 1.193 4.362-.002 4.362-3.152 0-3.237-1.126-4.675-4.438-5.827-1.307-.448-3.728-1.186-5.39-1.502zm4.656 16.241l6.296-2.275c.715-.258.826-.625.246-.818-.586-.192-1.637-.139-2.357.123l-4.205 1.5V14.98l.24-.085s1.201-.42 2.913-.615c1.696-.18 3.785.03 5.437.661 1.848.601 2.04 1.472 1.576 2.072-.465.6-1.622 1.036-1.622 1.036l-8.544 3.107V18.86zM1.807 18.6c-1.9-.545-2.214-1.668-1.352-2.32.801-.586 2.16-1.052 2.16-1.052l5.615-2.013v2.313L4.205 17c-.705.271-.825.632-.239.826.586.195 1.637.15 2.343-.12L8.247 17v2.074c-.12.03-.256.044-.39.073-1.939.331-3.996.196-6.038-.479z"/>',
    'nintendo'       => '<path fill="currentColor" stroke="none" d="M14.176 24h3.674c3.376 0 6.15-2.774 6.15-6.15V6.15C24 2.775 21.226 0 17.85 0H14.1c-.074 0-.15.074-.15.15v23.7c-.001.076.075.15.226.15zm4.574-13.199c1.351 0 2.399 1.125 2.399 2.398 0 1.352-1.125 2.4-2.399 2.4-1.35 0-2.4-1.049-2.4-2.4-.075-1.349 1.05-2.398 2.4-2.398zM11.4 0H6.15C2.775 0 0 2.775 0 6.15v11.7C0 21.226 2.775 24 6.15 24h5.25c.074 0 .15-.074.15-.149V.15c.001-.076-.075-.15-.15-.15zM9.676 22.051H6.15c-2.326 0-4.201-1.875-4.201-4.201V6.15c0-2.326 1.875-4.201 4.201-4.201H9.6l.076 20.102zM3.75 7.199c0 1.275.975 2.25 2.25 2.25s2.25-.975 2.25-2.25c0-1.273-.975-2.25-2.25-2.25s-2.25.977-2.25 2.25z"/>',
    'twitch'         => '<path fill="currentColor" stroke="none" d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/>',
    'xbox'           => '<path fill="currentColor" stroke="none" d="M4.102 21.033C6.211 22.881 8.977 24 12 24c3.026 0 5.789-1.119 7.902-2.967 1.877-1.912-4.316-8.709-7.902-11.417-3.582 2.708-9.779 9.505-7.898 11.417zm11.16-14.406c2.5 2.961 7.484 10.313 6.076 12.912C23.002 17.48 24 14.861 24 12.004c0-3.34-1.365-6.362-3.57-8.536 0 0-.027-.022-.082-.042-.063-.022-.152-.045-.281-.045-.592 0-1.985.434-4.805 3.246zM3.654 3.426c-.057.02-.082.041-.086.042C1.365 5.642 0 8.664 0 12.004c0 2.854.998 5.473 2.661 7.533-1.401-2.605 3.579-9.951 6.08-12.91-2.82-2.813-4.216-3.245-4.806-3.245-.131 0-.223.021-.281.046v-.002zM12 3.551S9.055 1.828 6.755 1.746c-.903-.033-1.454.295-1.521.339C7.379.646 9.659 0 11.984 0H12c2.334 0 4.605.646 6.766 2.085-.068-.046-.615-.372-1.52-.339C14.946 1.828 12 3.545 12 3.545v.006z"/>',
];

const STEAM_LOGO = 'M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.605 0 11.979 0zM7.54 18.21l-1.473-.61c.262.543.714.999 1.314 1.25 1.297.539 2.793-.076 3.332-1.375.263-.63.264-1.319.005-1.949s-.75-1.121-1.377-1.383c-.624-.26-1.29-.249-1.878-.03l1.523.63c.956.4 1.409 1.5 1.009 2.455-.397.957-1.497 1.41-2.454 1.012H7.54zm11.415-9.303c0-1.662-1.353-3.015-3.015-3.015-1.665 0-3.015 1.353-3.015 3.015 0 1.665 1.35 3.015 3.015 3.015 1.663 0 3.015-1.35 3.015-3.015zm-5.273-.005c0-1.252 1.013-2.266 2.265-2.266 1.249 0 2.266 1.014 2.266 2.266 0 1.251-1.017 2.265-2.266 2.265-1.253 0-2.265-1.014-2.265-2.265z';

function renderIcon(string $name, string $class): string
{
    if (!isset(ICONS[$name])) {
        return '';
    }

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ICONS[$name] . '</svg>';
}

function timeAgo(int $ts): string
{
    return Library::timeAgo($ts);
}

/**
 * The hover card over a game's art — see Library::steamInfo() for what's
 * in it. $attr marks the hero/previous-game tooltips so assets/app.js can
 * refill them on each poll; list-row tooltips are static.
 */
function renderTooltip(?array $info, string $attr = ''): string
{
    $html = '<span class="art-tooltip"' . ($attr !== '' ? ' ' . $attr : '') . ($info ? '' : ' hidden') . '>';
    if ($info) {
        $html .= '<span class="art-tooltip-title">' . e($info['title']) . '</span>';
        foreach ($info['groups'] as $group) {
            $html .= '<span class="art-tooltip-group' . (!empty($group['accent']) ? ' accent' : '') . '">';
            foreach ($group['lines'] as $line) {
                $html .= '<span class="art-tooltip-line">' . e($line) . '</span>';
            }
            $html .= '</span>';
        }
    }

    return $html . '</span>';
}

$config = Config::load();
$needsSetup = Config::needsSetup($config);
$config = $config ?? Config::DEFAULTS;

$library = null;
$current = null;
$previous = null;
$persona = null;
$mostPlayed = ['games' => [], 'note' => null];
$recent = [];
$genres = ['genres' => [], 'coverage' => 0, 'note' => null];
$lifetimeStats = [];
$retro = null;
$xbox = null;
$psn = null;
$psnAuthError = null;
$nintendo = null;
$twitchLive = null;
$nintendoAuthError = null;
$isPrivate = false;
$apiError = false;

$activeMostPlayedPeriod = Library::validUiPeriod($config['most_played_default_period'], 'all_time');
$activeGenrePeriod = Library::validUiPeriod($config['genre_default_period'], 'all_time');

if (!$needsSetup) {
    $library = Library::fromConfig($config);
    $tz = $library->timezone();

    $nowPlaying = $library->nowPlaying();
    $current = $nowPlaying['current'];
    $previous = $nowPlaying['previous'];
    $persona = $nowPlaying['persona'];
    $isPrivate = $library->isPrivate();
    $apiError = $persona === null;

    // Steam level + progress through it, for the header's player card.
    $badges = $library->steam()->badges();
    if ($persona && $badges) {
        $intoLevel = (int) ($badges['player_xp'] ?? 0) - (int) ($badges['player_xp_needed_current_level'] ?? 0);
        $levelSpan = $intoLevel + (int) ($badges['player_xp_needed_to_level_up'] ?? 0);
        $persona['level'] = (int) $badges['player_level'];
        $persona['xp_pct'] = $levelSpan > 0 ? (int) floor($intoLevel / $levelSpan * 100) : 0;
        $persona['xp_title'] = number_format((int) ($badges['player_xp'] ?? 0)) . ' XP · ' . number_format((int) ($badges['player_xp_needed_to_level_up'] ?? 0)) . ' XP to level ' . ($persona['level'] + 1);
    }

    $mostPlayed = $library->gamesForPeriod($activeMostPlayedPeriod, (int) $config['top_limit'], $tz);
    $recent = $library->recentlyPlayed((int) $config['recent_limit']);
    $genres = $library->genresForPeriod($activeGenrePeriod, $tz);
    $lifetimeStats = $library->lifetimeStats();

    if ($library->retro()->enabled()) {
        $raSummary = $library->retro()->summary();
        if ($raSummary) {
            $awards = array_count_values(array_filter(array_map(fn($r) => $r['HighestAwardKind'] ?? null, $library->retro()->completionProgress())));
            $retro = [
                'user'     => (string) ($raSummary['User'] ?? $library->retro()->username()),
                'avatar'   => RetroAchievements::media($raSummary['UserPic'] ?? null),
                'points'   => (int) ($raSummary['TotalPoints'] ?? 0),
                'rank'     => (int) ($raSummary['Rank'] ?? 0),
                'mastered' => ($awards['mastered'] ?? 0) + ($awards['completed'] ?? 0),
                'beaten'   => ($awards['beaten-hardcore'] ?? 0) + ($awards['beaten-softcore'] ?? 0),
                'games'    => $library->retro()->recentlyPlayed(6),
                'url'      => $library->retro()->profileUrl(),
            ];
        }
    }
}

if ($library && $library->xbox()->enabled()) {
    $xboxAccount = $library->xbox()->account();
    if ($xboxAccount) {
        $xboxTitles = $library->xbox()->titles();
        $xbox = [
            'account' => $xboxAccount,
            'played'  => count($xboxTitles),
            'hours'   => (int) round(array_sum(array_column($xboxTitles, 'minutes')) / 60),
            // Only games with something to show: titles Xbox merely knows
            // you launched (no playtime stat, no gamerscore, no
            // achievements) would just be a name and a date.
            'games'   => array_slice(array_filter($xboxTitles, fn($t) => $t['minutes'] > 0 || $t['gamerscore'] > 0 || $t['achievements'] > 0), 0, 6),
            'url'     => Xbox::profileUrl($xboxAccount['gamertag']),
        ];
    }
}

if ($library && $library->psn()->enabled()) {
    $psnSummary = $library->psn()->trophySummary();
    $psnAuthError = $library->psn()->authError();
    if ($psnSummary) {
        $psnGames = $library->psn()->games();
        $psnProfile = $library->psn()->profile();
        $psn = [
            'profile' => $psnProfile,
            'summary' => $psnSummary,
            'played'  => count($psnGames),
            'hours'   => (int) round(array_sum(array_column($psnGames, 'minutes')) / 60),
            // Same rule as the Xbox panel: skip games with nothing to show.
            'games'   => array_slice(array_filter($psnGames, fn($g) => $g['minutes'] > 0 || ($g['trophies']['earned_total'] ?? 0) > 0), 0, 6),
            'url'     => $psnProfile ? PlayStation::profileUrl($psnProfile['online_id']) : 'https://www.playstation.com',
        ];
    }
}

if ($library && $library->nintendo()->enabled()) {
    $nintendoGames = $library->nintendo()->games();
    $nintendoAuthError = $library->nintendo()->authError();
    if ($nintendoGames) {
        $nintendoWeek = $library->nintendo()->recentDays();
        $nintendo = [
            'played'    => count($nintendoGames),
            'hours'     => (int) round(array_sum(array_column($nintendoGames, 'minutes')) / 60),
            'week'      => $nintendoWeek,
            'week_mins' => array_sum(array_column($nintendoWeek, 'minutes')),
            'max_mins'  => max(array_column($nintendoGames, 'minutes')),
            // Same rule as the Xbox/PlayStation panels: skip games with nothing to show.
            'games'     => array_slice(array_filter($nintendoGames, fn($g) => $g['minutes'] > 0), 0, 6),
        ];
    }
}

$twitchEnabled = $library && $library->twitch()->enabled();
if ($twitchEnabled) {
    $twitchLive = $library->twitch()->liveStream();
}

/**
 * Twitch's embedded player only plays when told which site it's embedded
 * on (the "parent" parameter), which has to be this page's own hostname.
 */
function twitchPlayerUrl(string $channel): string
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost'));

    return 'https://player.twitch.tv/?' . http_build_query(['channel' => $channel, 'parent' => $host, 'muted' => 'true', 'autoplay' => 'true']);
}

$widgetDefs = [
    ['id' => 'unlock_clock', 'icon' => 'alarm-clock', 'title' => 'Achievement Clock', 'teaser' => 'When the trophies actually drop, across 24 hours'],
    ['id' => 'weekly_rhythm', 'icon' => 'calendar-range', 'title' => 'Weekly Rhythm', 'teaser' => 'Your play week, your streak, and 12 weeks at a glance'],
    ['id' => 'time_translated', 'icon' => 'hourglass', 'title' => 'Time Well Spent', 'teaser' => 'Your total hours, measured in speedruns, playthroughs and marathons'],
    ['id' => 'loot_tiers', 'icon' => 'swords', 'title' => 'Loot Tiers', 'teaser' => 'Your most-played games, rolled as a legendary drop'],
    ['id' => 'pile_of_shame', 'icon' => 'archive', 'title' => 'Pile of Shame', 'teaser' => 'The backlog, the famous ones in it, and how long it\'d take'],
    ['id' => 'completionist', 'icon' => 'trophy', 'title' => 'Completionist', 'teaser' => 'Perfect games, and the 100%s you\'re closest to'],
    ['id' => 'rarest', 'icon' => 'gem', 'title' => 'Rarest Unlocks', 'teaser' => 'Achievements almost nobody else has'],
    ['id' => 'hidden_gems', 'icon' => 'telescope', 'title' => 'Hidden Gems', 'teaser' => 'Games you love that few people have reviewed'],
    ['id' => 'mainstream', 'icon' => 'gauge', 'title' => 'Mainstream Meter', 'teaser' => 'How popular your favourites really are, by the numbers'],
    ['id' => 'platform_split', 'icon' => 'monitor', 'title' => 'Where You Play', 'teaser' => 'Lifetime hours by Windows, Linux, macOS and Steam Deck'],
];

$versionInfo = ['installed' => appVersion(), 'latest' => null, 'up_to_date' => null, 'release_url' => null, 'error' => null];
if (!empty($config['github_repo'])) {
    $versionInfo = (new VersionCheck($config['github_repo'], appVersion(), __DIR__, (int) $config['update_check_ttl']))->check();
}

function renderPeriodPicker(string $group, string $active): void
{
    echo '<div class="period-picker" data-period-group-wrap="' . e($group) . '">';
    foreach (Library::PERIOD_LABELS as $code => $label) {
        $activeClass = $code === $active ? ' active' : '';
        echo '<button type="button" class="period-btn' . $activeClass . '" data-period-group="' . e($group) . '" data-period="' . e($code) . '">' . e($label) . '</button>';
    }
    echo '</div>';
}

/**
 * One game list (Most Played / Recently Played). assets/app.js's
 * renderGameListContent() builds the same markup after a period switch.
 */
function renderGameList(array $rows, string $emptyMessage, ?string $note, bool $showLastPlayed = false): void
{
    if ($note) {
        echo '<p class="panel-note">' . e($note) . '</p>';
    }
    if (empty($rows)) {
        echo '<p class="empty-state">' . e($emptyMessage) . '</p>';
        return;
    }

    echo '<ol class="game-list">';
    foreach ($rows as $r) {
        $sub = $showLastPlayed ? 'Played ' . timeAgo((int) $r['last_played']) : $r['total_label'];
        if ($r['achievements']) {
            $sub .= ' · ' . $r['achievements']['unlocked'] . '/' . $r['achievements']['total'] . ' achievements';
        }
        $initial = strtoupper(mb_substr($r['name'], 0, 1));

        echo '<li class="game-row">'
            . '<span class="rank">' . (int) $r['rank'] . '</span>'
            . '<span class="art-hover"><a class="capsule" href="' . e($r['url']) . '" target="_blank" rel="noopener" tabindex="-1">'
            . '<img src="' . e($r['art']) . '" alt="" loading="lazy" data-fallback-sibling>'
            . '<span class="capsule-fallback" hidden>' . e($initial) . '</span></a>'
            . renderTooltip($r['info'] ?? null) . '</span>'
            . '<span class="meta"><a class="name" href="' . e($r['url']) . '" target="_blank" rel="noopener">' . e($r['name']) . '</a>'
            . '<span class="sub">' . e($sub) . '</span></span>'
            . '<span class="count">' . e($r['label'])
            . ($r['pct'] > 0 ? '<span class="bar"><span class="bar-fill" data-w="' . (int) $r['pct'] . '"></span></span>' : '')
            . '</span></li>';
    }
    echo '</ol>';
}

function genreColor(int $i): string
{
    return 'hsl(' . fmod($i * 137.508, 360) . ', 65%, 55%)';
}

function renderGenres(array $result): void
{
    if ($result['note']) {
        echo '<p class="panel-note">' . e($result['note']) . '</p>';
    }
    if (empty($result['genres'])) {
        echo '<p class="empty-state">No genre data for this period yet — store details fill in as cron.php runs.</p>';
        return;
    }

    echo '<div class="genre-bar">';
    foreach ($result['genres'] as $i => $g) {
        echo '<div class="genre-segment" data-w="' . $g['pct'] . '" data-bg="' . genreColor($i) . '" title="' . e($g['name'] . ' — ' . $g['pct'] . '%') . '"></div>';
    }
    echo '</div><ul class="genre-legend">';
    foreach ($result['genres'] as $i => $g) {
        echo '<li class="genre-legend-item" data-pct="' . $g['pct'] . '">'
            . '<span class="genre-swatch" data-bg="' . genreColor($i) . '"></span>'
            . '<span class="genre-name">' . e($g['name']) . '</span>'
            . '<span class="genre-pct">' . $g['pct'] . '% · ' . e(Library::formatMinutes($g['hours'] * 60)) . '</span></li>';
    }
    echo '</ul>';
    if ($result['coverage'] < 100) {
        echo '<p class="panel-note panel-note-after">Based on ' . (int) $result['coverage'] . '% of this period\'s playtime — the rest is from games whose store details haven\'t been fetched yet.</p>';
    }
}

/**
 * The status line above the game name. assets/app.js re-renders it on
 * every poll (and ticks the session timer every second).
 */
function heroStatus(?array $game): string
{
    if (!$game) {
        return 'Nothing played yet';
    }
    if ($game['playing']) {
        return '<span class="eq"><span></span><span></span><span></span></span> In game';
    }

    return 'Last played ' . e(timeAgo((int) $game['last_played']));
}

function renderHeroLinks(?array $game): string
{
    $html = '';
    foreach ($game['links'] ?? [] as $link) {
        $html .= '<a class="game-link game-link-' . e($link['kind']) . '" href="' . e($link['url']) . '"'
            . ($link['kind'] === 'launch' ? '' : ' target="_blank" rel="noopener"') . '>'
            . ($link['kind'] === 'launch' ? renderIcon('play', 'game-link-icon') : '') . e($link['label']) . '</a>';
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($config['app_name']) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#66c0f4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ICONS['gamepad'] . '</svg>') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@500;600;700&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/style.css') ?>">
<link rel="stylesheet" href="assets/theme.css?v=<?= (int) @filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body>

<div class="bg">
    <div class="bg-layer" data-bg-a></div>
    <div class="bg-layer" data-bg-b></div>
</div>
<div class="bg-scrim"></div>

<div class="wrap">
    <header class="site-header">
        <h1><?= renderIcon('gamepad', 'site-logo') ?><?= e($config['app_name']) ?></h1>
        <div class="header-right">
            <span class="updated" data-updated></span>
            <?php if ($persona): ?>
                <a class="persona" href="<?= e($persona['url']) ?>" target="_blank" rel="noopener">
                    <span class="persona-avatar">
                        <?php if ($persona['avatar']): ?><img src="<?= e($persona['avatar']) ?>" alt=""><?php endif; ?>
                        <span class="persona-dot<?= $persona['state'] > 0 ? ' online' : '' ?>" title="<?= $persona['state'] > 0 ? 'Online on Steam' : 'Offline on Steam' ?>"></span>
                    </span>
                    <span class="persona-meta">
                        <span class="persona-name"><?= e($persona['name']) ?></span>
                        <?php if (isset($persona['level'])): ?>
                            <span class="persona-xp" title="<?= e($persona['xp_title']) ?>">
                                <span class="level-badge">LV <?= (int) $persona['level'] ?></span>
                                <span class="xp-bar"><span class="xp-fill" data-w="<?= (int) $persona['xp_pct'] ?>"></span></span>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($twitchEnabled):
        $embedPlayer = !empty($config['twitch_embed_player']);
    ?>
        <section class="twitch-live" data-twitch data-embed="<?= $embedPlayer ? '1' : '0' ?>"
                 data-player-base="<?= e(twitchPlayerUrl($library->twitch()->channel())) ?>"<?= $twitchLive ? '' : ' hidden' ?>>
            <div class="twitch-player" data-twitch-player>
                <?php if ($twitchLive && $embedPlayer): ?>
                    <iframe src="<?= e(twitchPlayerUrl($twitchLive['channel'])) ?>" title="Live on Twitch" allow="autoplay; fullscreen" allowfullscreen credentialless></iframe>
                <?php elseif ($twitchLive): ?>
                    <img src="<?= e($twitchLive['thumbnail']) ?>" alt="Stream preview">
                <?php endif; ?>
            </div>
            <div class="twitch-info">
                <div class="twitch-badge"><span class="twitch-dot"></span> Live on Twitch</div>
                <a class="twitch-channel" data-twitch-channel href="<?= e($library->twitch()->channelUrl()) ?>" target="_blank" rel="noopener">
                    <?= renderIcon('twitch', 'twitch-logo') ?><span data-twitch-name><?= e($twitchLive['display_name'] ?? $library->twitch()->channel()) ?></span>
                </a>
                <p class="twitch-title" data-twitch-title><?= e($twitchLive['title'] ?? '') ?></p>
                <p class="twitch-meta">
                    <span data-twitch-game><?= e($twitchLive['game'] ?? '') ?></span>
                    <span class="twitch-sep">·</span>
                    <span data-twitch-viewers><?= number_format($twitchLive['viewers'] ?? 0) ?></span> watching
                    <span class="twitch-sep">·</span>
                    live for <span data-twitch-uptime data-started="<?= (int) ($twitchLive['started_at'] ?? 0) ?>"></span>
                </p>
                <a class="game-link game-link-launch twitch-watch" href="<?= e($library->twitch()->channelUrl()) ?>" target="_blank" rel="noopener">Watch on Twitch</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($needsSetup): ?>
        <div class="error-banner">
            <strong>Setup needed:</strong> copy <code>config.sample.php</code> to <code>config.php</code>
            and fill in your Steam <code>steam_api_key</code> and <code>steam_id</code>.
        </div>
    <?php elseif ($apiError): ?>
        <div class="error-banner">
            Couldn't reach Steam, or it didn't recognise the account. Check <code>steam_api_key</code> and
            <code>steam_id</code> in <code>config.php</code>.
        </div>
    <?php elseif ($isPrivate): ?>
        <div class="error-banner">
            Steam is hiding this account's games. In Steam, open <strong>Profile → Edit Profile → Privacy Settings</strong>
            and set <strong>Game details</strong> to <strong>Public</strong> (and untick "Always keep my total playtime private").
        </div>
    <?php endif; ?>

    <?php if ($psnAuthError && !$needsSetup): ?>
        <div class="error-banner"><?= e($psnAuthError) ?></div>
    <?php endif; ?>
    <?php if ($nintendoAuthError && !$needsSetup): ?>
        <div class="error-banner"><?= e($nintendoAuthError) ?></div>
    <?php endif; ?>

    <?php $heroInitial = strtoupper(mb_substr($current['name'] ?? '?', 0, 1)); ?>
    <section class="now-playing">
        <div class="art-hover">
            <div class="art-tile">
                <span class="art-tile-fallback" data-art-fallback<?= empty($current['art']) ? '' : ' hidden' ?>><?= e($heroInitial) ?></span>
                <img data-art-img src="<?= e($current['art'] ?? '') ?>" data-fallback-src="<?= e($current['art_fallback'] ?? '') ?>" alt="Game art"
                     <?= empty($current['art']) ? 'hidden' : '' ?>>
            </div>
            <?= renderTooltip($current['info'] ?? null, 'data-hero-tooltip') ?>
        </div>
        <div class="info">
            <div class="status-badge<?= !empty($current['playing']) ? ' live' : '' ?>" data-status-badge><?= heroStatus($current) ?></div>
            <p class="game-name" data-game-name><?= e($current['name'] ?? 'No recent games') ?></p>
            <p class="game-platform" data-game-platform><?= e($current['platform_label'] ?? '') ?></p>
            <p class="game-subtitle" data-game-subtitle><?= e($current['subtitle'] ?? '') ?></p>
            <p class="game-presence" data-game-presence<?= empty($current['presence']) ? ' hidden' : '' ?>><?= e($current['presence'] ?? '') ?></p>
            <p class="game-facts" data-game-facts><?= e(implode(' · ', $current['facts'] ?? [])) ?></p>
            <?php $ach = $current['achievements'] ?? null; ?>
            <div class="ach-progress" data-ach-wrap<?= $ach ? '' : ' hidden' ?>>
                <div class="ach-bar"><div class="ach-fill" data-ach-fill data-w="<?= (int) ($ach['pct'] ?? 0) ?>"></div></div>
                <span class="ach-label" data-ach-label><?= $ach ? e($ach['unlocked'] . ' / ' . $ach['total'] . ' ' . ($ach['noun'] ?? 'achievements') . ' · ' . $ach['pct'] . '%') : '' ?></span>
            </div>
            <div class="game-links" data-game-links><?= renderHeroLinks($current) ?></div>
        </div>
        <div class="prev-track" data-prev<?= $previous ? '' : ' hidden' ?>>
            <div class="art-hover">
                <div class="prev-track-thumb">
                    <span class="prev-track-thumb-fallback" data-prev-art-fallback<?= empty($previous['art']) ? '' : ' hidden' ?>><?= e(strtoupper(mb_substr($previous['name'] ?? '?', 0, 1))) ?></span>
                    <img data-prev-art-img src="<?= e($previous['art'] ?? '') ?>" data-fallback-src="<?= e($previous['art_fallback'] ?? '') ?>" alt=""
                         <?= empty($previous['art']) ? 'hidden' : '' ?>>
                </div>
                <?= renderTooltip($previous['info'] ?? null, 'data-prev-tooltip') ?>
            </div>
            <div class="prev-track-info">
                <div class="prev-track-label">Previously played</div>
                <div class="prev-track-name" data-prev-name><?= e($previous['name'] ?? '') ?></div>
                <div class="prev-track-artist" data-prev-meta><?= e(($previous['platform_label'] ?? '') . ($previous ? ' · ' . timeAgo((int) $previous['last_played']) : '')) ?></div>
            </div>
        </div>
    </section>

    <div class="panels">
        <section class="panel">
            <div class="panel-header-row">
                <h2>Most Played</h2>
                <?php if (!$needsSetup): ?>
                    <?php renderPeriodPicker('games', $activeMostPlayedPeriod); ?>
                <?php endif; ?>
            </div>
            <div data-period-content="games">
                <?php renderGameList($mostPlayed['games'], 'Nothing played in this period yet.', $mostPlayed['note']); ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header-row">
                <h2>Recently Played</h2>
            </div>
            <?php renderGameList($recent, 'No recently played games.', null, true); ?>
        </section>
    </div>

    <section class="panel panel-wide">
        <div class="panel-header-row">
            <h2>Genre Breakdown</h2>
            <?php if (!$needsSetup): ?>
                <div class="genre-controls">
                    <?php renderPeriodPicker('genre', $activeGenrePeriod); ?>
                    <label class="genre-threshold-label">
                        Show
                        <select data-genre-threshold>
                            <option value="0" selected>all genres</option>
                            <option value="1">above 1%</option>
                            <option value="2">above 2%</option>
                            <option value="5">above 5%</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>
        </div>
        <div data-period-content="genre">
            <?php renderGenres($genres); ?>
        </div>
    </section>

    <?php if ($retro): ?>
        <section class="panel panel-wide retro-panel">
            <div class="panel-header-row">
                <h2>RetroAchievements</h2>
                <a class="platform-user" href="<?= e($retro['url']) ?>" target="_blank" rel="noopener">
                    <?php if ($retro['avatar']): ?><img src="<?= e($retro['avatar']) ?>" alt=""><?php endif; ?>
                    <?= e($retro['user']) ?>
                </a>
            </div>
            <div class="platform-stats">
                <div><strong><?= number_format($retro['points']) ?></strong><span>points</span></div>
                <?php if ($retro['rank'] > 0): ?><div><strong>#<?= number_format($retro['rank']) ?></strong><span>rank</span></div><?php endif; ?>
                <div><strong><?= number_format($retro['mastered']) ?></strong><span>mastered / completed</span></div>
                <div><strong><?= number_format($retro['beaten']) ?></strong><span>beaten</span></div>
            </div>
            <?php if (empty($retro['games'])): ?>
                <p class="empty-state">No retro games played yet.</p>
            <?php else: ?>
                <ul class="platform-games">
                    <?php foreach ($retro['games'] as $g):
                        $possible = (int) ($g['NumPossibleAchievements'] ?? 0);
                        $achieved = (int) ($g['NumAchieved'] ?? 0);
                        $pct = $possible > 0 ? (int) floor($achieved / $possible * 100) : 0;
                    ?>
                        <li>
                            <a href="<?= e(RetroAchievements::gameUrl((int) ($g['GameID'] ?? 0))) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(RetroAchievements::media($g['ImageIcon'] ?? null)) ?>" alt="" loading="lazy" data-hide-on-error>
                            </a>
                            <div class="platform-game-meta">
                                <div class="name"><?= e($g['Title'] ?? '') ?></div>
                                <div class="sub"><?= e(($g['ConsoleName'] ?? '') . ' · ' . timeAgo(RetroAchievements::parseTime($g['LastPlayed'] ?? null))) ?></div>
                                <?php if ($possible > 0): ?>
                                    <div class="bar"><div class="bar-fill" data-w="<?= $pct ?>"></div></div>
                                    <div class="sub"><?= $achieved ?> / <?= $possible ?> achievements</div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($xbox): ?>
        <section class="panel panel-wide xbox-panel">
            <div class="panel-header-row">
                <h2>Xbox</h2>
                <a class="platform-user" href="<?= e($xbox['url']) ?>" target="_blank" rel="noopener">
                    <?php if ($xbox['account']['avatar']): ?><img src="<?= e($xbox['account']['avatar']) ?>" alt=""><?php endif; ?>
                    <?= e($xbox['account']['gamertag']) ?>
                </a>
            </div>
            <div class="platform-stats">
                <div><strong><?= number_format($xbox['account']['gamerscore']) ?></strong><span>gamerscore</span></div>
                <div><strong><?= number_format($xbox['played']) ?></strong><span>games played</span></div>
                <?php if ($xbox['hours'] > 0): ?><div title="From Xbox's per-game MinutesPlayed stat — some older and PC titles don't report it"><strong><?= number_format($xbox['hours']) ?></strong><span>hours tracked</span></div><?php endif; ?>
            </div>
            <?php if (empty($xbox['games'])): ?>
                <p class="empty-state">No Xbox games played yet.</p>
            <?php else: ?>
                <ul class="platform-games">
                    <?php foreach ($xbox['games'] as $g):
                        $devices = implode(' / ', array_unique(array_map([Xbox::class, 'deviceLabel'], $g['devices'])));
                    ?>
                        <li>
                            <span class="art-hover">
                                <a href="<?= e(Xbox::storeSearchUrl($g['name'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e($g['image']) ?>" alt="" loading="lazy" data-hide-on-error>
                                </a>
                                <?= renderTooltip($library->xboxInfo($g, $g['name'])) ?>
                            </span>
                            <div class="platform-game-meta">
                                <div class="name"><?= e($g['name']) ?></div>
                                <div class="sub"><?= e(implode(' · ', array_filter([$devices, $g['minutes'] > 0 ? Library::formatMinutes($g['minutes']) : '', timeAgo($g['last_played'])]))) ?></div>
                                <?php if ($g['gamerscore_total'] > 0): ?>
                                    <div class="bar"><div class="bar-fill" data-w="<?= (int) $g['progress_pct'] ?>"></div></div>
                                    <div class="sub"><?= number_format($g['gamerscore']) ?> / <?= number_format($g['gamerscore_total']) ?> G<?= $g['game_pass'] ? ' · Game Pass' : '' ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($psn): ?>
        <section class="panel panel-wide psn-panel">
            <div class="panel-header-row">
                <h2>PlayStation</h2>
                <?php if ($psn['profile']): ?>
                    <a class="platform-user" href="<?= e($psn['url']) ?>" target="_blank" rel="noopener">
                        <?php if ($psn['profile']['avatar']): ?><img src="<?= e($psn['profile']['avatar']) ?>" alt=""><?php endif; ?>
                        <?= e($psn['profile']['online_id']) ?><?= $psn['profile']['plus'] ? ' <span class="psn-plus" title="PlayStation Plus">+</span>' : '' ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="platform-stats">
                <div title="<?= (int) $psn['summary']['level_progress'] ?>% of the way to the next level"><strong><?= number_format($psn['summary']['level']) ?></strong><span>trophy level</span></div>
                <?php foreach (['platinum', 'gold', 'silver', 'bronze'] as $tier): ?>
                    <div class="trophy-stat trophy-<?= $tier ?>"><strong><?= number_format($psn['summary']['trophies'][$tier]) ?></strong><span><?= $tier ?></span></div>
                <?php endforeach; ?>
                <div><strong><?= number_format($psn['played']) ?></strong><span>games played</span></div>
                <?php if ($psn['hours'] > 0): ?><div title="PS4 and PS5 games only — Sony doesn't report playtime for older consoles"><strong><?= number_format($psn['hours']) ?></strong><span>hours played</span></div><?php endif; ?>
            </div>
            <?php if (empty($psn['games'])): ?>
                <p class="empty-state">No PlayStation games played yet.</p>
            <?php else: ?>
                <ul class="platform-games">
                    <?php foreach ($psn['games'] as $g): $t = $g['trophies']; ?>
                        <li>
                            <span class="art-hover">
                                <a href="<?= e(PlayStation::storeSearchUrl($g['name'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e($g['image']) ?>" alt="" loading="lazy" data-hide-on-error>
                                </a>
                                <?= renderTooltip($library->psnInfo($g)) ?>
                            </span>
                            <div class="platform-game-meta">
                                <div class="name"><?= e($g['name']) ?></div>
                                <div class="sub"><?= e(implode(' · ', array_filter([$g['platform'], $g['minutes'] > 0 ? Library::formatMinutes($g['minutes']) : '', timeAgo($g['last_played'])]))) ?></div>
                                <?php if ($t && $t['defined_total'] > 0): ?>
                                    <div class="bar"><div class="bar-fill" data-w="<?= (int) $t['progress'] ?>"></div></div>
                                    <div class="sub"><?= $t['earned_total'] ?> / <?= $t['defined_total'] ?> trophies<?= $t['earned']['platinum'] > 0 ? ' · <span class="trophy-platinum-text">Platinum</span>' : '' ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($nintendo): ?>
        <section class="panel panel-wide nintendo-panel">
            <div class="panel-header-row">
                <h2>Nintendo</h2>
            </div>
            <div class="nintendo-top">
                <div class="platform-stats">
                    <div><strong><?= number_format($nintendo['hours']) ?></strong><span>hours played</span></div>
                    <div><strong><?= number_format($nintendo['played']) ?></strong><span>games played</span></div>
                    <div><strong><?= e(Library::formatMinutes($nintendo['week_mins'])) ?></strong><span>last 7 days</span></div>
                </div>
                <?php if ($nintendo['week']):
                    $weekMax = max(1, max(array_column($nintendo['week'], 'minutes')));
                ?>
                    <div class="week-bars" aria-label="Minutes played each day over the last week">
                        <?php foreach ($nintendo['week'] as $day):
                            $tip = date('D j M', strtotime($day['date'])) . ': ' . ($day['minutes'] > 0 ? Library::formatMinutes($day['minutes']) : 'no play');
                            foreach ($day['games'] as $dg) {
                                $tip .= "\n" . $dg['name'] . ' — ' . Library::formatMinutes($dg['minutes']);
                            }
                        ?>
                            <div class="week-bar-col" title="<?= e($tip) ?>">
                                <div class="week-bar" data-h="<?= max(4, (int) round($day['minutes'] / $weekMax * 100)) ?>"></div>
                                <span><?= e(date('D', strtotime($day['date']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (empty($nintendo['games'])): ?>
                <p class="empty-state">No Nintendo playtime recorded yet.</p>
            <?php else: ?>
                <ul class="platform-games">
                    <?php foreach ($nintendo['games'] as $g): ?>
                        <li>
                            <span class="art-hover">
                                <a href="<?= e(Nintendo::storeSearchUrl($g['name'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e($g['image']) ?>" alt="" loading="lazy" data-hide-on-error>
                                </a>
                                <?= renderTooltip($library->nintendoInfo($g)) ?>
                            </span>
                            <div class="platform-game-meta">
                                <div class="name"><?= e($g['name']) ?></div>
                                <div class="sub"><?= e(implode(' · ', array_filter([implode(' / ', $g['platforms']), Library::formatMinutes($g['minutes']), timeAgo($g['last_played'])]))) ?></div>
                                <div class="bar"><div class="bar-fill" data-w="<?= max(2, (int) round($g['minutes'] / max(1, $nintendo['max_mins']) * 100)) ?>"></div></div>
                                <div class="sub"><?= number_format($g['days']) ?> day<?= $g['days'] === 1 ? '' : 's' ?> played</div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$needsSetup): ?>
        <div class="widget-grid">
            <?php foreach ($widgetDefs as $w): ?>
                <button type="button" class="widget-card" data-widget-id="<?= e($w['id']) ?>">
                    <?= renderIcon($w['icon'], 'widget-card-icon') ?>
                    <span class="widget-card-title"><?= e($w['title']) ?></span>
                    <span class="widget-card-teaser"><?= e($w['teaser']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="modal-overlay" data-modal-overlay hidden>
            <div class="modal" role="dialog" aria-modal="true">
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                <div class="modal-body" data-modal-body></div>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel panel-wide">
        <h2>Lifetime Stats</h2>
        <?php if (empty($lifetimeStats)): ?>
            <p class="empty-state">Stats unavailable.</p>
        <?php else: ?>
            <div class="stats-row">
                <?php foreach ($lifetimeStats as $key => $stat): ?>
                    <div class="stat-item"<?= !empty($stat['title']) ? ' title="' . e($stat['title']) . '"' : '' ?>>
                        <?= renderIcon($stat['icon'], 'stat-icon') ?>
                        <div class="stat-value" data-stat="<?= e($key) ?>"><?= e($stat['value']) ?></div>
                        <div class="stat-label"><?= e($stat['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer class="site-footer">
        <div class="powered-by-line">
            <span>Powered by</span>
            <a href="https://steamcommunity.com/dev" target="_blank" rel="noopener" title="Steam Web API" aria-label="Steam Web API">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="<?= STEAM_LOGO ?>"/></svg>
            </a>
            <?php if ($library && $library->xbox()->enabled()): ?>
                <a href="https://xbl.io" target="_blank" rel="noopener" title="Xbox via OpenXBL" aria-label="Xbox via OpenXBL">
                    <?= renderIcon('xbox', '') ?>
                </a>
            <?php endif; ?>
            <?php if ($library && $library->psn()->enabled()): ?>
                <a href="https://github.com/achievements-app/psn-api" target="_blank" rel="noopener" title="PlayStation via psn-api's endpoints" aria-label="PlayStation">
                    <?= renderIcon('playstation', '') ?>
                </a>
            <?php endif; ?>
            <?php if ($library && $library->nintendo()->enabled()): ?>
                <a href="https://www.nintendo.com" target="_blank" rel="noopener" title="Nintendo play history" aria-label="Nintendo">
                    <?= renderIcon('nintendo', '') ?>
                </a>
            <?php endif; ?>
            <?php if ($library && $library->retro()->enabled()): ?>
                <a href="https://api-docs.retroachievements.org" target="_blank" rel="noopener" title="RetroAchievements" aria-label="RetroAchievements">
                    <?= renderIcon('joystick', '') ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        // Site checks. DNSSEC is looked up here (cached 12h); the headers
        // check runs in the browser against what actually arrives — see
        // headers-check.php and assets/app.js.
        $siteDomain = Security::siteDomain($config);
        $dnssec = $siteDomain ? Security::dnssec($siteDomain) : null;
        $dnssecTitle = !$siteDomain ? 'Not checked — the dashboard isn\'t on a public domain (set site_domain in config.php)'
            : ($dnssec === null ? 'Couldn\'t check DNSSEC for ' . $siteDomain . ' right now'
            : ($dnssec ? $siteDomain . ' is DNSSEC-signed and validates' : $siteDomain . ' isn\'t DNSSEC-signed (or doesn\'t validate)'));
        ?>
        <div class="site-checks">
            <a class="site-check <?= $dnssec ? 'check-ok' : 'check-fail' ?>" title="<?= e($dnssecTitle) ?>"
               <?= $siteDomain ? 'href="https://dnssec-analyzer.verisignlabs.com/' . e($siteDomain) . '" target="_blank" rel="noopener"' : '' ?>>
                <span class="check-icon"><?= $dnssec ? '✓' : '✗' ?></span> DNSSEC
            </a>
            <a class="site-check check-pending" data-site-check="headers" title="Checking…"
               <?= $siteDomain ? 'href="https://securityheaders.com/?q=' . e(rawurlencode($siteDomain)) . '&amp;followRedirects=on" target="_blank" rel="noopener"' : '' ?>>
                <span class="check-icon">…</span> Security Headers
            </a>
        </div>
        <?php if ($library && $library->ledger()->started()): ?>
            <div class="ledger-line" title="Steam only reports lifetime playtime; XP Ledger records how it changes to build dated play history">
                Playtime ledger running since <?= e(date('j M Y', (int) $library->ledger()->started())) ?>
            </div>
        <?php endif; ?>
        <div class="version-line">
            <?php if (!empty($config['github_repo'])): ?>
                <a class="version-gh-link" href="https://github.com/<?= e($config['github_repo']) ?>" target="_blank" rel="noopener">
                    <svg class="github-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                        <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"></path>
                    </svg>
                    XP-Ledger
                </a>
            <?php else: ?>
                XP-Ledger
            <?php endif; ?>
            v<?= e(appVersion()) ?>
            <?php if (empty($config['github_repo'])): ?>
                &middot; <span class="version-muted">update check disabled</span>
            <?php elseif ($versionInfo['error']): ?>
                &middot; <span class="version-muted"><?= e($versionInfo['error']) ?></span>
            <?php elseif ($versionInfo['up_to_date']): ?>
                &middot; <span class="version-ok">up to date</span>
            <?php else: ?>
                &middot; <a class="version-update" href="<?= e($versionInfo['release_url']) ?>" target="_blank" rel="noopener">Update available: v<?= e($versionInfo['latest']) ?></a>
                — you're on v<?= e($versionInfo['installed']) ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($config['cron_enabled'])): ?>
            <div class="cron-line" title="cron.php is scheduled to snapshot playtime and refresh caches every 15 minutes">
                &#8635; Background refresh active
            </div>
        <?php endif; ?>
    </footer>
</div>

<script nonce="<?= e(Security::nonce()) ?>">
window.APP_CONFIG = { pollIntervalMs: <?= (int) $config['poll_interval_ms'] ?>, enabled: <?= $needsSetup ? 'false' : 'true' ?> };
window.INITIAL_GAME = <?= json_encode($current) ?>;
window.INITIAL_TWITCH = <?= json_encode($twitchLive) ?>;
</script>
<script src="assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
