<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Minimal, framework-free metrics helper that emits structured log lines.
 * Counters can be scraped from logs later; timers measure durations in ms.
 */
final class Metrics
{
    /**
     * Increment a counter by value.
     *
     * @param string $name Metric name, e.g., targets_created
     * @param int $value Amount to increment (default 1)
     * @param array<string, int|string|bool|null> $tags Context/labels for the metric
     */
    public static function counter(string $name, int $value = 1, array $tags = []): void
    {
        try {
            Log::info('metric.counter', array_merge([
                'metric' => true,
                'type' => 'counter',
                'name' => $name,
                'value' => $value,
            ], $tags));
        } catch (\Throwable $e) {
            // swallow
        }
    }

    /**
     * Start a timer. Returns a token to pass to end().
     *
     * @param string $name Metric name, e.g., fill_duration_ms
     * @param array<string, int|string|bool|null> $tags Context tags
     * @return array{t0: float, name: string, tags: array<string, int|string|bool|null>}
     */
    public static function start(string $name, array $tags = []): array
    {
        return ['t0' => microtime(true), 'name' => $name, 'tags' => $tags];
    }

    /**
     * End a timer previously started with start().
     * Emits a structured log line with duration_ms and provided tags.
     *
     * @param array{t0: float, name: string, tags: array<string, int|string|bool|null>} $token
     * @param array<string, int|string|bool|null> $extraTags Additional tags to merge
     * @return int Duration in milliseconds (rounded)
     */
    public static function end(array $token, array $extraTags = []): int
    {
        $durationMs = (int) round((microtime(true) - ($token['t0'] ?? microtime(true))) * 1000);
        $tags = array_merge($token['tags'] ?? [], $extraTags);
        try {
            Log::info('metric.timer', array_merge([
                'metric' => true,
                'type' => 'timer',
                'name' => $token['name'] ?? 'timer',
                'duration_ms' => $durationMs,
            ], $tags));
        } catch (\Throwable $e) {
            // swallow
        }
        return $durationMs;
    }
}
