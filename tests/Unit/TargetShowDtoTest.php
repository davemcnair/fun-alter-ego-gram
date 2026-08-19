<?php

namespace Tests\Unit;

use App\Dtos\SignatureDto;
use App\Dtos\TargetShowDto;
use App\Enums\TargetStatus;
use App\Models\Signature;
use App\Models\Target;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetShowDtoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Token::insert([
            ['name' => 'forename', 'prio' => 1, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => true, 'max_multiples' => 1],
            ['name' => 'surname',  'prio' => 2, 'min_length' => 2, 'allow_nearly' => false, 'has_fun' => true, 'has_boring' => false, 'max_multiples' => 1],
        ]);
    }

    public function test_completed_is_true_only_when_processed(): void
    {
        $dto = SignatureDto::fromWord('jane');
        $sig = Signature::firstOrCreate(['signature' => $dto->signature], $dto->defaults);
        $target = Target::create([
            'name' => 'Jane',
            'signature_id' => $sig->id,
            'normalized_key' => 'jane',
            'status' => TargetStatus::processing,
        ]);

        $progress = TargetShowDto::fromTarget($target);
        $this->assertSame('processing', $progress->status);
        $this->assertFalse($progress->completed);

        $target->status = TargetStatus::processed;
        $target->save();

        $progress = TargetShowDto::fromTarget($target->fresh());
        $this->assertSame('processed', $progress->status);
        $this->assertTrue($progress->completed);
        $this->assertFalse($progress->hasUncommitted);
    }
}
