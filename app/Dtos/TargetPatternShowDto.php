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
        public int        $deferredAlterEgosCount,
        public Collection $alterEgos,
        public string     $elapsed,
    )
    {
    }

    public static function fromTargetPattern(TargetPattern $targetPattern): self
    {
        $alterEgos = $targetPattern->alterEgos->map(fn($ae) => new PhraseDto(
            $ae->phrase,
            $ae->isFun,
            $ae->hasBoring,
            $ae->hasDeferred,
            $ae->starred,
            $ae->id
        ));

        return new self(
            id: $targetPattern->id,
            status: $targetPattern->status->value,
            template: $targetPattern->pattern->template,
            alterEgosCount: count($alterEgos),
            funAlterEgosCount: count($alterEgos->filter(fn($ae) => $ae->isFun)),
            boringAlterEgosCount: count($alterEgos->filter(fn($ae) => $ae->hasBoring)),
            deferredAlterEgosCount: count($alterEgos->filter(fn($ae) => $ae->hasDeferred)),
            alterEgos: $alterEgos,
            elapsed: number_format(($targetPattern->elapsed_ms ?? 0) / 1000, 1),
        );
    }
}
