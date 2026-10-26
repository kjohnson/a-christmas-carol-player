<?php

namespace Tests\Feature;

use App\Http\Controllers\AudioController;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['audiobook.book.chapters' => [
            ['title' => 'Marley’s Ghost', 'file' => 'stave-01-marleys-ghost.mp3', 'duration' => 2250.58],
            ['title' => 'The First of the Three Spirits', 'file' => 'stave-02-the-first-of-the-three-spirits.mp3', 'duration' => 2128.48],
        ]]);
        Storage::disk('local')->put('audio/stave-01-marleys-ghost.mp3', str_repeat('a', 1000));
    }

    /**
     * @return array<string, string>
     */
    private function playerHeaders(): array
    {
        return ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'audio'];
    }

    public function test_signed_url_streams_audio_to_the_player(): void
    {
        $response = $this->get(AudioController::signedUrl(1), $this->playerHeaders());

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Content-Length', '1000')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_range_requests_are_supported_for_seeking(): void
    {
        $this->get(AudioController::signedUrl(1), [...$this->playerHeaders(), 'Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-99/1000');
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $this->get('/audio/1', $this->playerHeaders())->assertForbidden();
    }

    public function test_tampered_url_is_rejected(): void
    {
        $url = str_replace('/audio/1?', '/audio/2?', AudioController::signedUrl(1));

        $this->get($url, $this->playerHeaders())->assertForbidden();
    }

    public function test_expired_url_is_rejected(): void
    {
        $url = AudioController::signedUrl(1);

        $this->travel(config('audiobook.url_lifetime') + 1)->minutes();

        $this->get($url, $this->playerHeaders())->assertForbidden();
    }

    public function test_opening_the_url_directly_in_the_browser_is_rejected(): void
    {
        $this->get(AudioController::signedUrl(1), ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Dest' => 'document'])
            ->assertForbidden();
    }

    public function test_hotlinking_from_another_site_is_rejected(): void
    {
        $this->get(AudioController::signedUrl(1), ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'audio'])
            ->assertForbidden();
    }

    public function test_unknown_chapter_is_not_found(): void
    {
        $this->get(AudioController::signedUrl(99), $this->playerHeaders())->assertNotFound();
    }

    public function test_chapter_without_its_audio_file_is_not_found(): void
    {
        $this->get(AudioController::signedUrl(2), $this->playerHeaders())->assertNotFound();
    }

    public function test_there_is_no_audio_while_the_book_is_coming_soon(): void
    {
        config(['audiobook.book.chapters' => []]);

        $this->get(AudioController::signedUrl(1), $this->playerHeaders())->assertNotFound();
    }

    public function test_requests_are_rate_limited(): void
    {
        $url = AudioController::signedUrl(1);

        for ($i = 0; $i < 60; $i++) {
            $this->get($url, $this->playerHeaders())->assertOk();
        }

        $this->get($url, $this->playerHeaders())->assertTooManyRequests();
    }

    public function test_player_can_refresh_an_expired_url(): void
    {
        $response = $this->getJson('/audio/1/url', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty']);

        $response->assertOk();

        $this->get($response->json('url'), $this->playerHeaders())->assertOk();
    }

    public function test_refresh_is_rejected_from_another_site(): void
    {
        $this->getJson('/audio/1/url', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'empty'])
            ->assertForbidden();
    }
}
