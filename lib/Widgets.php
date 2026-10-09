<?php

/**
 * Data for the clickable insight widgets. Each method returns a plain
 * array for widgets.php to JSON-encode (cached 15 minutes by
 * WidgetCache), with 'available' => false when there isn't enough data
 * yet to say anything honest.
 *
 * Where a number is derived rather than read straight from an API, the
 * payload says so in its own words (see each 'note') so the UI never
 * presents an estimate as a fact.
 */
class Widgets
{
    private Library $library;
    private array $config;

    public function __construct(Library $library, array $config)
    {
        $this->library = $library;
        $this->config = $config;
    }

    private function playedGames(): array
    {
        return array_filter($this->library->ownedGames(), fn($g) => $g['playtime'] > 0);
    }

    /**
     * When achievements get unlocked, by local hour of day — built from
     * Steam's exact unlock timestamps, so it reflects real history from
     * day one rather than only since XP Ledger started tracking.
     */
    public function unlockClock(): array
    {
        $unlocks = $this->library->achievements()->unlocks();
        if (count($unlocks) < 10) {
            return ['available' => false];
        }

        $tz = $this->library->timezone();
        $hours = array_fill(0, 24, 0);
        foreach ($unlocks as $u) {
            $hours[(int) (new DateTime('@' . $u['time']))->setTimezone($tz)->format('G')]++;
        }

        $peak = array_keys($hours, max($hours))[0];

        return [
            'available' => true,
            'hours'     => $hours,
            'total'     => count($unlocks),
            'peak_hour' => $peak,
            'label'     => self::clockLabel($hours),
            'note'      => 'From ' . number_format(count($unlocks)) . ' achievement unlock times across ' . number_format(count($this->library->achievements()->all())) . ' games checked so far.',
        ];
    }

    private static function clockLabel(array $hours): string
    {
        $total = max(1, array_sum($hours));
        $night = array_sum(array_slice($hours, 0, 5)) + $hours[23];
        $morning = array_sum(array_slice($hours, 5, 7));
        $evening = array_sum(array_slice($hours, 18, 5));

        if ($night / $total > 0.3) {
            return 'Raid-night owl — a big share of your unlocks happen after 11pm';
        }
        if ($morning / $total > 0.35) {
            return 'Early-bird grinder — you do your best work before noon';
        }
        if ($evening / $total > 0.45) {
            return 'Prime-time player — evenings are when the trophies drop';
        }

        return 'Round-the-clock — your unlocks are spread across the day';
    }

    /**
     * Playtime by weekday, plus the last 12 weeks day by day, from the
     * ledger. Needs XP Ledger to have been tracking for a while.
     */
    public function weeklyRhythm(): array
    {
        $ledger = $this->library->ledger();
        if (!$ledger->hasEntries()) {
            return ['available' => false, 'reason' => 'ledger'];
        }

        $tz = $this->library->timezone();
        $days = $ledger->weekday($tz);
        $since = (new DateTime('today', $tz))->modify('-83 days')->getTimestamp();
        $daily = $ledger->daily($tz, $since);

        // Build a fixed 12-week grid (oldest first) so gaps show as gaps.
        $grid = [];
        $cursor = new DateTime('@' . $since);
        $cursor->setTimezone($tz);
        for ($i = 0; $i < 84; $i++) {
            $key = $cursor->format('Y-m-d');
            $grid[] = ['date' => $key, 'minutes' => (int) round($daily[$key] ?? 0)];
            $cursor->modify('+1 day');
        }

        // Current streak: consecutive days with play, ending today (or
        // yesterday, so the streak doesn't "break" before you've had a
        // chance to play today).
        $streak = 0;
        for ($i = count($grid) - 1; $i >= 0; $i--) {
            if ($grid[$i]['minutes'] > 0) {
                $streak++;
            } elseif ($i < count($grid) - 1) {
                break;
            }
        }

        $peak = array_keys($days, max($days))[0];
        $names = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];

        return [
            'available'      => true,
            'days'           => array_map(fn($m) => round($m / 60, 1), $days),
            'grid'           => $grid,
            'streak'         => $streak,
            'peak_day'       => $peak,
            'label'          => array_sum($days) > 0 ? 'You play most on ' . $names[$peak] : 'No sessions recorded yet',
            'tracking_since' => $ledger->started(),
            'note'           => 'From playtime XP Ledger has recorded since ' . date('j M Y', (int) $ledger->started()) . '.',
        ];
    }

    /**
     * Total playtime, converted into things that aren't games.
     */
    public function timeTranslated(): array
    {
        $totalMinutes = array_sum(array_column($this->library->ownedGames(), 'playtime'));
        if ($totalMinutes <= 0) {
            return ['available' => false];
        }

        // Each comparison shows a whole-unit count plus a progress bar for
        // the fraction towards the next one, same as lastfm-dash's
        // Distance Listened. The big ones only appear once you've made a
        // real dent, so nobody's told they're "0.01 PhDs" in.
        $comparisons = [
            ['label' => 'feature films (2 hours each)', 'unit_minutes' => 120],
            ['label' => 'London → New York flights', 'unit_minutes' => 480],
            ['label' => 'full 24-hour days', 'unit_minutes' => 1440],
            ['label' => 'full-time working weeks (37.5 h)', 'unit_minutes' => 2250],
            ['label' => 'trips to the Moon at highway speed', 'unit_minutes' => 230640, 'min_count' => 0.05],
            ['label' => "full-time working years (about 1,700 h)", 'unit_minutes' => 102000, 'min_count' => 0.25],
        ];

        $results = [];
        foreach ($comparisons as $c) {
            $raw = $totalMinutes / $c['unit_minutes'];
            if (isset($c['min_count']) && $raw < $c['min_count']) {
                continue;
            }
            $whole = (int) floor($raw);
            $results[] = ['label' => $c['label'], 'count' => $whole, 'pct' => (int) floor(($raw - $whole) * 100)];
        }

        return [
            'available'   => true,
            'total_hours' => (int) round($totalMinutes / 60),
            'total_days'  => round($totalMinutes / 1440, 1),
            'comparisons' => $results,
        ];
    }

    /**
     * Your most-played games as a loot drop, by playtime rank.
     */
    public function lootTiers(): array
    {
        $games = $this->playedGames();
        // A game launched for ten minutes isn't loot — unless the library
        // is so small that's all there is.
        $substantial = array_filter($games, fn($g) => $g['playtime'] >= 60);
        if (count($substantial) >= 3) {
            $games = $substantial;
        }
        usort($games, fn($a, $b) => $b['playtime'] <=> $a['playtime']);
        $games = array_slice($games, 0, max(8, (int) ($this->config['loot_game_limit'] ?? 20)));

        if (count($games) < 3) {
            return ['available' => false];
        }

        $drops = [];
        foreach ($games as $i => $g) {
            $tier = $i < 2 ? 'legendary' : ($i < 6 ? 'epic' : ($i < 12 ? 'rare' : 'uncommon'));
            $drops[] = ['name' => $g['name'], 'tier' => $tier, 'hours' => Library::formatMinutes($g['playtime'])];
        }

        return ['available' => true, 'drops' => $drops];
    }

    /**
     * Unplayed and barely-touched games, the famous ones among them, and
     * a tongue-in-cheek estimate of how long clearing the lot would take.
     */
    public function pileOfShame(): array
    {
        $owned = $this->library->ownedGames();
        if (!$owned) {
            return ['available' => false];
        }

        $unplayed = array_filter($owned, fn($g) => $g['playtime'] === 0);
        $barely = array_filter($owned, fn($g) => $g['playtime'] > 0 && $g['playtime'] < 60);

        // The well-known ones: rank unplayed games by Steam review count.
        // Cache-only plus a small live budget; cron.php fills in the rest.
        $weights = [];
        foreach ($unplayed as $g) {
            $weights[$g['appid']] = 1;
        }
        $details = $this->library->storeDetails($weights, (int) ($this->config['store_live_budget'] ?? 10));
        $famous = [];
        foreach ($details as $appid => $d) {
            if (($d['type'] ?? 'game') === 'game' && $d['recommendations'] > 0) {
                $famous[] = ['name' => $owned[$appid]['name'], 'reviews' => $d['recommendations']];
            }
        }
        usort($famous, fn($a, $b) => $b['reviews'] <=> $a['reviews']);

        // Backlog forecast: unplayed count x the median time you've spent
        // on games you actually got into (1h+), divided by your recent
        // daily playtime. Deliberately rough, and labelled as such.
        $engaged = array_values(array_filter(array_column($owned, 'playtime'), fn($m) => $m >= 60));
        sort($engaged);
        $median = $engaged ? $engaged[intdiv(count($engaged), 2)] : 0;
        $dailyRate = array_sum(array_column($owned, 'playtime_2weeks')) / 14;
        $years = ($dailyRate > 0 && $median > 0) ? count($unplayed) * $median / $dailyRate / 365 : null;

        return [
            'available'      => true,
            'owned'          => count($owned),
            'unplayed'       => count($unplayed),
            'barely'         => count($barely),
            'unplayed_pct'   => (int) round(count($unplayed) / count($owned) * 100),
            'famous'         => array_slice($famous, 0, 8),
            'famous_checked' => count($details),
            'forecast_years' => $years !== null ? round($years, 1) : null,
            'forecast_note'  => $years !== null
                ? 'Your ' . count($unplayed) . ' unplayed games × the ' . Library::formatMinutes($median) . ' you typically give a game you get into, at your last-two-weeks pace of ' . Library::formatMinutes($dailyRate) . '/day. Not accounting for the games you\'ll buy in the meantime.'
                : null,
        ];
    }

    /**
     * Completion stats, perfect games, and the next easiest 100%s.
     */
    public function completionist(): array
    {
        $played = $this->playedGames();
        $achievements = $this->library->achievements();
        $summary = $achievements->summary(count($played));
        if ($summary['games_with_stats'] === 0) {
            return ['available' => false];
        }

        $owned = $this->library->ownedGames();
        $perfect = [];
        $close = [];
        foreach ($achievements->all() as $appid => $r) {
            if (!$r['has_stats'] || $r['total'] === 0) {
                continue;
            }
            $name = $owned[$appid]['name'] ?? ('App ' . $appid);
            if ($r['unlocked'] >= $r['total']) {
                $perfect[] = ['name' => $name, 'total' => $r['total']];
            } elseif ($r['unlocked'] / $r['total'] >= 0.5) {
                $close[] = [
                    'name'      => $name,
                    'unlocked'  => $r['unlocked'],
                    'total'     => $r['total'],
                    'remaining' => $r['total'] - $r['unlocked'],
                    'pct'       => (int) floor($r['unlocked'] / $r['total'] * 100),
                ];
            }
        }
        usort($close, fn($a, $b) => [$a['remaining'], -$a['pct']] <=> [$b['remaining'], -$b['pct']]);
        usort($perfect, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'available' => true,
            'summary'   => $summary,
            'perfect'   => array_slice($perfect, 0, 12),
            'close'     => array_slice($close, 0, 6),
        ];
    }

    /**
     * Your rarest unlocks, by Steam's global unlock percentage.
     */
    public function rarestUnlocks(): array
    {
        $steam = $this->library->steam();
        $owned = $this->library->ownedGames();
        $percentCache = [];
        $rare = [];

        foreach ($this->library->achievements()->unlocks() as $u) {
            $appid = $u['appid'];
            if (!array_key_exists($appid, $percentCache)) {
                $percentCache[$appid] = $steam->globalAchievementPercentages($appid, true);
            }
            $pct = $percentCache[$appid][$u['api']] ?? null;
            if ($pct === null || $pct <= 0) {
                continue;
            }
            $rare[] = [
                'game'    => $owned[$appid]['name'] ?? ('App ' . $appid),
                'name'    => $u['name'],
                'desc'    => $u['desc'],
                'percent' => round($pct, 1),
                'date'    => date('j M Y', $u['time']),
            ];
        }

        if (!$rare) {
            return ['available' => false];
        }
        usort($rare, fn($a, $b) => $a['percent'] <=> $b['percent']);

        return ['available' => true, 'unlocks' => array_slice($rare, 0, 10)];
    }

    /**
     * The top-played sample both Hidden Gems and the Mainstream Meter
     * draw from: games you've given 2h+, with store review counts.
     *
     * @return array<int, array{name:string, hours:string, reviews:int}>
     */
    private function reviewedTopGames(): array
    {
        $games = array_filter($this->library->ownedGames(), fn($g) => $g['playtime'] >= 120);
        usort($games, fn($a, $b) => $b['playtime'] <=> $a['playtime']);
        $games = array_slice($games, 0, (int) ($this->config['review_sample'] ?? 60));

        $weights = [];
        foreach ($games as $g) {
            $weights[$g['appid']] = $g['playtime'];
        }
        $details = $this->library->storeDetails($weights, (int) ($this->config['store_live_budget'] ?? 10));

        $out = [];
        foreach ($games as $g) {
            $d = $details[$g['appid']] ?? null;
            // Steam omits the review count entirely for some games; treat
            // that as unknown rather than as "zero reviews".
            if ($d && ($d['type'] ?? 'game') === 'game' && $d['recommendations'] > 0) {
                $out[] = ['name' => $g['name'], 'hours' => Library::formatMinutes($g['playtime']), 'reviews' => $d['recommendations']];
            }
        }

        return $out;
    }

    /**
     * Games you've sunk real time into that comparatively few people have
     * reviewed on Steam.
     */
    public function hiddenGems(): array
    {
        $games = $this->reviewedTopGames();
        if (count($games) < 3) {
            return ['available' => false];
        }
        usort($games, fn($a, $b) => $a['reviews'] <=> $b['reviews']);

        return ['available' => true, 'games' => array_slice($games, 0, 8), 'sampled' => count($games)];
    }

    /**
     * Median/average Steam review count across your most-played games.
     */
    public function mainstreamMeter(): array
    {
        $games = $this->reviewedTopGames();
        if (count($games) < 3) {
            return ['available' => false];
        }

        $counts = array_column($games, 'reviews');
        sort($counts);
        $median = $counts[intdiv(count($counts), 2)];

        if ($median >= 100000) {
            $label = 'Blockbuster main character — you play what everyone plays';
        } elseif ($median >= 20000) {
            $label = 'Front-page regular — popular picks, with taste';
        } elseif ($median >= 3000) {
            $label = 'Curator energy — well-loved, but not everywhere';
        } else {
            $label = 'Deep-cut digger — your favourites are other people\'s discoveries';
        }

        return [
            'available' => true,
            'median'    => $median,
            'average'   => (int) round(array_sum($counts) / count($counts)),
            'sampled'   => count($counts),
            'label'     => $label,
        ];
    }

    /**
     * Lifetime playtime split by operating system, straight from Steam's
     * own per-OS counters.
     */
    public function platformSplit(): array
    {
        $owned = $this->library->ownedGames();
        $total = array_sum(array_column($owned, 'playtime'));
        if ($total <= 0) {
            return ['available' => false];
        }

        $windows = array_sum(array_column($owned, 'windows'));
        $mac = array_sum(array_column($owned, 'mac'));
        $linux = array_sum(array_column($owned, 'linux'));
        $deck = array_sum(array_column($owned, 'deck'));

        $rows = [
            ['key' => 'windows', 'label' => 'Windows', 'minutes' => $windows],
            ['key' => 'linux', 'label' => 'Linux / SteamOS', 'minutes' => $linux],
            ['key' => 'mac', 'label' => 'macOS', 'minutes' => $mac],
        ];
        // Steam only started splitting playtime by OS around 2018, so
        // older hours belong to no platform at all.
        $unattributed = $total - $windows - $mac - $linux;
        if ($unattributed > 0) {
            $rows[] = ['key' => 'other', 'label' => 'Before per-OS tracking', 'minutes' => $unattributed];
        }

        foreach ($rows as &$row) {
            $row['hours'] = Library::formatMinutes($row['minutes']);
            $row['pct'] = round($row['minutes'] / $total * 100, 1);
        }
        unset($row);
        $rows = array_values(array_filter($rows, fn($r) => $r['minutes'] > 0));
        usort($rows, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

        return [
            'available'  => true,
            'rows'       => $rows,
            'deck_hours' => $deck > 0 ? Library::formatMinutes($deck) : null,
            'deck_pct'   => $deck > 0 ? round($deck / $total * 100, 1) : null,
        ];
    }
}
