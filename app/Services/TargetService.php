<?php

namespace App\Services;

use App\Enums\TargetStatus;
use App\Models\Signature;
use App\Models\Target;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Log;

class TargetService
{
    public function create(string $name): Target
    {
        $originalInput = $name;
        $name = trim($name);
        $normalizedKey = NameNormalizer::canonicalKey($name);

        if ($normalizedKey === '') {
            Log::warning('TargetService.create invalid name after normalization', [
                'original_input' => mb_substr($originalInput, 0, 80),
            ]);
            abort(422, 'Name is invalid after normalization');
        }
        $display = NameNormalizer::displayName($name);

        $signatureDto = NameNormalizer::anagramSignature($name);
        $signature = Signature::where('signature', $signatureDto->signature)->first();
        if (!$signature) {
            $signature = new Signature();
            $signature->signature = $signatureDto->signature;
            foreach ($signatureDto->defaults as $attr => $value) {
                $signature->$attr = $value;
            }
            $signature->save();
        }
        $found = Target::where('normalized_key', $normalizedKey)->first();
        if ($found) {
            return $found;
        }
        $target = new Target();
        $target->normalized_key = $normalizedKey;
        $target->name = $display;
        $target->status = TargetStatus::filterable;
        $target->signature_id = $signature->id;
        $target->save();
        return $target;
    }
}
