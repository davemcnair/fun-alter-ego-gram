<?php

return [
    // Queue to use for background processing. If null/empty, dispatches use the default queue.
    // Configure via SEARCH_QUEUE in .env, e.g., SEARCH_QUEUE=search
    'queue' => env('SEARCH_QUEUE', null),
    // Proposed change 4: caching of match candidates per target signature and filters
    // Enable/disable caching (default: disabled)
    'enable_match_cache' => filter_var(env('ENABLE_MATCH_CACHE', false), FILTER_VALIDATE_BOOLEAN),
    // TTL in seconds for cached ID lists
    'match_cache_ttl' => (int) env('MATCH_CACHE_TTL', 120),
];
