<?php

/**
 * Builds the id => compute-closure map for every widget endpoint. Shared
 * between widgets.php (serves a widget on demand), cron.php (pre-warms
 * them on a schedule) and the MCP server, so the definitions can't drift
 * out of sync.
 */
class WidgetRegistry
{
    /**
     * Widget ids that take no extra query params — the set cron.php
     * pre-warms directly. "games" and "genre" are parameterized by period
     * (and panel), so cron.php warms each combination separately.
     */
    public const SIMPLE_IDS = [
        'unlock_clock',
        'weekly_rhythm',
        'time_translated',
        'loot_tiers',
        'pile_of_shame',
        'completionist',
        'rarest',
        'hidden_gems',
        'mainstream',
        'platform_split',
    ];

    public const PERIOD_IDS = ['games', 'genre'];

    public static function handlers(Library $library, Widgets $widgets, array $config): array
    {
        $tz = $library->timezone();

        return [
            'unlock_clock'    => fn() => $widgets->unlockClock(),
            'weekly_rhythm'   => fn() => $widgets->weeklyRhythm(),
            'time_translated' => fn() => $widgets->timeTranslated(),
            'loot_tiers'      => fn() => $widgets->lootTiers(),
            'pile_of_shame'   => fn() => $widgets->pileOfShame(),
            'completionist'   => fn() => $widgets->completionist(),
            'rarest'          => fn() => $widgets->rarestUnlocks(),
            'hidden_gems'     => fn() => $widgets->hiddenGems(),
            'mainstream'      => fn() => $widgets->mainstreamMeter(),
            'platform_split'  => fn() => $widgets->platformSplit(),
            'games'           => function () use ($library, $config, $tz) {
                $period = Library::validUiPeriod($_GET['period'] ?? '');
                $result = $library->gamesForPeriod($period, (int) ($config['top_limit'] ?? 8), $tz);

                return ['available' => !empty($result['games'])] + $result;
            },
            'genre'           => function () use ($library, $tz) {
                $period = Library::validUiPeriod($_GET['period'] ?? '');
                $result = $library->genresForPeriod($period, $tz);

                return ['available' => !empty($result['genres'])] + $result;
            },
        ];
    }
}
