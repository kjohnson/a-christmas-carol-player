<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextTest extends TestCase
{
    private const SYNCED_TEXT = '{"blocks":[{"tag":"p","words":["Hello","world."]}],"timings":[0,0.5,0.5,1]}';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['audiobook.book.chapters' => [
            ['title' => 'Marley’s Ghost', 'file' => 'stave-01-marleys-ghost.mp3', 'duration' => 2250.58],
            ['title' => 'The First of the Three Spirits', 'file' => 'stave-02-the-first-of-the-three-spirits.mp3', 'duration' => 2128.48],
        ]]);
        Storage::disk('local')->put('text/chapter-01.json', self::SYNCED_TEXT);
    }

    /**
     * @return array<string, string>
     */
    private function playerHeaders(): array
    {
        return ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty'];
    }

    public function test_player_can_fetch_synced_text(): void
    {
        $response = $this->get('/text/1', $this->playerHeaders());

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame(self::SYNCED_TEXT, $response->getContent());
    }

    public function test_chapter_without_synced_text_is_not_found(): void
    {
        $this->get('/text/2', $this->playerHeaders())->assertNotFound();
    }

    public function test_unknown_chapter_is_not_found(): void
    {
        $this->get('/text/99', $this->playerHeaders())->assertNotFound();
    }

    public function test_opening_the_text_directly_in_the_browser_is_rejected(): void
    {
        $this->get('/text/1', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])->assertForbidden();
    }

    public function test_fetching_from_another_site_is_rejected(): void
    {
        $this->get('/text/1', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'empty'])->assertForbidden();
    }

    public function test_player_only_offers_text_for_synced_chapters(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewHas('book', fn (array $book) => $book['chapters']->pluck('hasText')->all() === [true, false]);
    }
}
