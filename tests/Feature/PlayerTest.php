<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlayerTest extends TestCase
{
    /**
     * Give the book its chapters, as it will have once the audio is added.
     */
    private function withChapters(): void
    {
        config(['audiobook.book.chapters' => [
            ['title' => 'Marley’s Ghost', 'file' => 'stave-01-marleys-ghost.mp3', 'duration' => 2250.58],
            ['title' => 'The First of the Three Spirits', 'file' => 'stave-02-the-first-of-the-three-spirits.mp3', 'duration' => 2128.48],
            ['title' => 'The Second of the Three Spirits', 'file' => 'stave-03-the-second-of-the-three-spirits.mp3', 'duration' => 2665.90],
        ]]);
    }

    public function test_the_book_is_coming_soon_until_it_has_chapters(): void
    {
        config(['audiobook.book.chapters' => []]);

        $this->get('/')
            ->assertOk()
            ->assertSee('A Christmas Carol')
            ->assertSee('Charles Dickens')
            ->assertSee('Coming soon')
            ->assertSee('A Ghost Story of Christmas')
            ->assertSeeInOrder(['Marley’s Ghost', 'The First of the Three Spirits', 'The Second of the Three Spirits', 'The Last of the Spirits', 'The End of It'])
            ->assertSee('data-action="toggle"', false)
            ->assertDontSee('data-chapter=', false);
    }

    public function test_playback_controls_are_disabled_while_the_book_is_coming_soon(): void
    {
        config(['audiobook.book.chapters' => []]);

        $content = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-action="toggle"[^>]*disabled/s', $content);
        $this->assertMatchesRegularExpression('/data-bind="seek"[^>]*disabled/s', $content);
    }

    public function test_player_lists_every_chapter(): void
    {
        $this->withChapters();

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Marley’s Ghost', 'The First of the Three Spirits', 'The Second of the Three Spirits'])
            ->assertSee('Stave 1 of 3')
            ->assertSee('Staves')
            ->assertDontSee('Coming soon');
    }

    public function test_the_narrator_is_credited_once_set(): void
    {
        $this->withChapters();
        config(['audiobook.book.narrator' => null]);

        $this->get('/')->assertOk()->assertDontSee('Read by');

        config(['audiobook.book.narrator' => 'Jane Reader']);

        $this->get('/')->assertOk()->assertSee('Read by Jane Reader');
    }

    public function test_each_chapter_has_an_options_menu_for_its_progress(): void
    {
        $this->withChapters();

        $response = $this->get('/')->assertOk();

        $this->assertSame(3, substr_count($response->getContent(), 'data-chapter-options='));
        $this->assertSame(3, substr_count($response->getContent(), 'data-action="reset-progress"'));
        $response->assertSeeInOrder(['aria-label="Options for Marley’s Ghost"', 'id="actions-0"', 'Reset progress'], false);
    }

    public function test_chapter_lengths_are_shown_in_minutes_and_seconds(): void
    {
        config(['audiobook.book.chapters' => [
            ['title' => 'Short', 'file' => 'short.mp3', 'duration' => 8.9],
            ['title' => 'Medium', 'file' => 'medium.mp3', 'duration' => 2250.5],
            ['title' => 'Long', 'file' => 'long.mp3', 'duration' => 4718.58],
        ]]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Short', '00:08', 'Medium', '37:30', 'Long', '78:38']);
    }

    public function test_cover_urls_change_when_the_cover_does(): void
    {
        $hash = substr(hash_file('xxh3', public_path('images/christmas-carol.jpg')), 0, 8);

        $this->get('/')
            ->assertOk()
            ->assertViewHas('book', fn (array $book) => $book['cover'] === asset('images/christmas-carol.jpg').'?v='.$hash);
    }

    public function test_player_links_to_signed_audio_urls_instead_of_files(): void
    {
        $this->withChapters();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('.mp3')
            ->assertViewHas('book', function (array $book) {
                $url = $book['chapters']->first()['url'];

                return str_starts_with($url, url('/audio/1?expires='))
                    && str_contains($url, 'signature=');
            });
    }

    public function test_player_knows_each_chapters_download_size(): void
    {
        $this->withChapters();
        Storage::fake('local');
        Storage::disk('local')->put('audio/stave-01-marleys-ghost.mp3', str_repeat('a', 1234));

        $this->get('/')
            ->assertOk()
            ->assertViewHas('book', fn (array $book) => $book['chapters'][0]['size'] === 1234 && $book['chapters'][1]['size'] === null);
    }

    public function test_tracks_that_are_not_chapters_are_left_unnumbered(): void
    {
        config(['audiobook.book.chapters' => [
            ['title' => 'Preface', 'file' => 'preface.mp3', 'duration' => 10.0, 'numbered' => false],
            ['title' => 'One', 'file' => 'stave-01-one.mp3', 'duration' => 10.0],
            ['title' => 'Two', 'file' => 'stave-02-two.mp3', 'duration' => 10.0],
            ['title' => 'Credits', 'file' => 'credits.mp3', 'duration' => 10.0, 'numbered' => false],
        ]]);

        $this->get('/')
            ->assertOk()
            ->assertViewHas('book', fn (array $book) => $book['chapters']->pluck('number')->all() === [null, 1, 2, null]);
    }

    public function test_every_configured_chapter_file_exists(): void
    {
        $disk = Storage::disk(config('audiobook.disk'));
        $chapters = config('audiobook.book.chapters');

        if ($chapters === [] || $disk->files('audio') === []) {
            $this->markTestSkipped('The audio is not in the repo; add it to storage/app/private/audio to check it.');
        }

        foreach ($chapters as $chapter) {
            $this->assertTrue($disk->exists("audio/{$chapter['file']}"), "Missing audio/{$chapter['file']}");
        }
    }

    public function test_audio_files_are_not_publicly_accessible(): void
    {
        $this->withChapters();

        foreach (config('audiobook.book.chapters') as $chapter) {
            $this->assertFileDoesNotExist(public_path($chapter['file']));
            $this->assertFileDoesNotExist(public_path("audio/{$chapter['file']}"));
        }
    }
}
