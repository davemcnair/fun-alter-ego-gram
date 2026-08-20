<?php

namespace Tests\Feature;

use App\Models\Token;
use App\Models\TokenSignatureWord;
use App\Services\WordCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordIndexPageTest extends TestCase
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

    public function test_index_renders_snapshot_filters_and_delete_keeps_representative(): void
    {
        $catalog = app(WordCatalog::class);
        $fun = $catalog->add('forename', 'adam', 'fun');
        $catalog->add('forename', 'dama', 'ok');

        $this->get(route('words.index'))
            ->assertOk()
            ->assertSee('Words')
            ->assertSee('adam')
            ->assertSee('dama')
            ->assertSee('Show (1)')
            ->assertSee('Has anagrams')
            ->assertDontSee('id="uncommitted"', false);

        $this->get(route('words.index', ['q' => 'adam', 'exact' => 1]))
            ->assertOk()
            ->assertSee('adam')
            ->assertDontSee('>dama</td>', false);

        $this->delete(route('api.words.destroy', $fun))
            ->assertRedirect(route('words.index'));

        $this->assertNull(TokenSignatureWord::find($fun->id));
        $this->assertFalse((bool) TokenSignatureWord::where('word', 'dama')->first()->is_deferred);
    }
}
