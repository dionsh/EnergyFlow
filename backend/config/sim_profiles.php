<?php

declare(strict_types=1);

/*
 * Default behaviour per simulated machine model. These are SIMULATION PARAMETERS
 * for a fictional plant (see docs/03-architecture.md §4.3), not statistics.
 * A machine's sim_profile JSON can override any value.
 */
return [
    'injection_moulding' => [
        'run_kw' => 14.0,          // mean power while cycling
        'standby_kw' => 3.6,       // heater bands holding temperature
        'warmup_kw' => 6.5,        // first 30 min of a shift
        'cycle_s' => 30,           // one moulding shot
        'micro_stop' => 0.12,      // share of scheduled time in standby
        'pf_run' => 0.84,
        'pf_standby' => 0.95,
        'oil_temp' => 50.0,
    ],
    'compressor' => [
        'loaded_kw' => 10.9,
        'unloaded_kw' => 3.4,
        'prod_demand' => 0.55,     // share of capacity needed by production
        'leak' => 0.21,            // share of capacity lost to leaks whenever pressurised
        'max_cycles_h' => 40,      // load/unload cycles per hour at 50 % load
        'pf_loaded' => 0.86,
        'pf_unloaded' => 0.52,
        'temp_base' => 58.0,
        'temp_load' => 14.0,
    ],
    'chiller' => [
        'base_kw' => 2.4,
        'temp_kw' => 4.2,          // extra kW at warm outdoor temperatures
        'pf' => 0.85,
    ],
    'hvac' => [
        'heat_kw' => 5.6,
        'cool_kw' => 4.4,
        'fan_kw' => 0.35,
        'setback' => 0.12,         // share of heating kept outside occupied hours
        'pf' => 0.90,
    ],
    'pump' => [
        'on_kw' => 2.85,
        'duty_min' => 15,          // minutes on per hour during production
        'pf' => 0.82,
    ],
    'lighting' => [
        'on_kw' => 3.05,
        'pf' => 0.92,
    ],
    'office' => [
        'base_kw' => 0.5,
        'office_kw' => 1.35,
        'pf' => 0.95,
    ],
    'incomer' => [
        'unmonitored_kw' => 0.7,           // always-on loads not on a sub-meter
        'unmonitored_production_kw' => 1.1, // extra during production (tools, dryer)
    ],
];
