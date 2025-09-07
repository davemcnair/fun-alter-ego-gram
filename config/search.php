<?php

return [
    // Maximum number of phrases to emit per pattern per runStep call.
    // 0 disables the cap (unlimited). Configure via PHRASES_PER_STEP_CAP in .env
    'phrases_per_step_cap' => (int) env('PHRASES_PER_STEP_CAP', 0),

    // Soft time budget for background slice processing (ms). 0 disables time slicing.
    // Configure via SLICE_MS_BUDGET in .env
    'slice_ms_budget' => (int) env('SLICE_MS_BUDGET', 1200),

    // Queue to use for background processing. If null/empty, dispatches use the default queue.
    // Configure via SEARCH_QUEUE in .env, e.g., SEARCH_QUEUE=search
    'queue' => env('SEARCH_QUEUE', null),
];
