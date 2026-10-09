<?php

declare(strict_types=1);

/*
 * EnergyFlow Score (docs/03-architecture.md §13). Weights add up to 1; the scales
 * are EnergyFlow's own design choices (not an industry standard) and are shown
 * in the "How is this calculated?" panel next to each sub-score.
 */
return [
    'window_days' => 7,
    'weights' => [
        'waste' => 0.35,
        'schedule' => 0.20,
        'health' => 0.15,
        'peak' => 0.10,
        'follow_through' => 0.10,
        'coverage' => 0.10,
    ],
    // Waste and off-schedule shares at which those sub-scores reach 0.
    'waste_share_zero' => 0.25,
    'off_schedule_share_zero' => 0.20,
    // Load factor (average ÷ peak kW) that scores 100.
    'load_factor_full' => 0.60,
];
