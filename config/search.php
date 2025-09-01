<?php

return [
    // Maximum number of phrases to emit per pattern per runStep call.
    // 0 disables the cap (unlimited). Configure via PHRASES_PER_STEP_CAP in .env
    'phrases_per_step_cap' => (int) env('PHRASES_PER_STEP_CAP', 0),
];
