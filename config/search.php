<?php

return [
    // Maximum number of phrases to emit per pattern per runStep call.
    // 0 disables the cap (unlimited). Configure via PHRASES_PER_STEP_CAP in .env
    'phrases_per_step_cap' => (int) env('PHRASES_PER_STEP_CAP', 0),

    // When true, the UI will call the synchronous run-step endpoint each poll to advance work on the request thread.
    // When false (default), the UI will only poll progress and expect a background queue worker to process jobs.
    // Configure via FORCE_SYNC_STEPS in .env
    'force_sync_steps' => (bool) env('FORCE_SYNC_STEPS', false),
];
