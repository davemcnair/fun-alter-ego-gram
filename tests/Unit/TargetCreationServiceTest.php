<?php

namespace Tests\Unit;

use App\Models\Token;
use App\Services\TargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TargetCreationServiceTest extends TestCase
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

    public function test_create_with_invalid_name_throws_422(): void
    {
        $this->expectException(HttpException::class);
        try {
            app(TargetService::class)->create('!!!');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }
}
