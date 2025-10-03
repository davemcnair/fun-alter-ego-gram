<?php

namespace App\Dtos;

use App\Models\TargetPattern;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class TargetPatternShowDto extends Data
{
    public function __construct(
        public int        $id,
        public string     $status,
        public string     $template,
        public int        $alterEgosCount,
        public int        $funAlterEgosCount,
        public int        $boringAlterEgosCount,
        public Collection $alterEgos,
        public string     $elapsed,
    )
    {
    }

    /**
     * Build the DTO from a Target model, performing all calculations needed by the view.
     */
    public static function fromTargetPattern(TargetPattern $targetPattern): self
    {
        $alterEgos = $targetPattern->alterEgos->map(fn($ae) => new PhraseDto(
            $ae->phrase,
            $ae->isFun,
            $ae->hasBoring,
            $ae->starred
        ));

        return new self(
            id: $targetPattern->id,
            status: $targetPattern->status->value,
            template: $targetPattern->pattern->template,
            alterEgosCount: count($alterEgos),
            funAlterEgosCount: count($alterEgos->filter(fn($ae) => $ae->isFun)),
            boringAlterEgosCount: count($alterEgos->filter(fn($ae) => $ae->hasBoring)),
            alterEgos: $alterEgos,
            elapsed: number_format($targetPattern->elapsed_ms / 1000, 1),
        );
    }

}
