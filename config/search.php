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

    // Feature flag: enable SQL-side exact subset pruning using per-letter counts on token_signatures
    // Configure via SEARCH_SQL_SUBSET (true/false)
    'sql_subset_pruning' => filter_var(env('SEARCH_SQL_SUBSET', false), FILTER_VALIDATE_BOOLEAN),

    // Safety flag during rollout: when true, re-verify subset relation in PHP after SQL filtering
    // Configure via SEARCH_VERIFY_SUBSET (true/false)
    'verify_subset_in_php' => filter_var(env('SEARCH_VERIFY_SUBSET', true), FILTER_VALIDATE_BOOLEAN),

    // Proposed change 4: caching of match candidates per target signature and filters
    // Enable/disable caching (default: disabled)
    'enable_match_cache' => filter_var(env('ENABLE_MATCH_CACHE', false), FILTER_VALIDATE_BOOLEAN),
    // TTL in seconds for cached ID lists
    'match_cache_ttl' => (int) env('MATCH_CACHE_TTL', 120),
];
