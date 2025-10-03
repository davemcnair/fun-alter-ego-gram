<?php

namespace App\Dtos;

use App\Models\TargetPattern;
use Spatie\LaravelData\Data;

class TargetPatternShowDto extends Data
{
    public function __construct(
        public int $id,
        public string $status,
        public string $template,
        public int $alterEgosCount,
        public array $alterEgoPhrases,
        public string $elapsed,
    ) {
    }

    /**
     * Build the DTO from a Target model, performing all calculations needed by the view.
     */
    public static function fromTargetPattern(TargetPattern $targetPattern): self
    {
        $phrases = $targetPattern->alterEgos()->pluck('phrase')->all();

        return new self(
            id: $targetPattern->id,
            status: $targetPattern->status->value,
            template: $targetPattern->pattern->template,
            alterEgosCount: count($phrases),
            alterEgoPhrases: $phrases,
            elapsed: number_format($targetPattern->elapsed_ms/1000, 1),
        );
    }

}
