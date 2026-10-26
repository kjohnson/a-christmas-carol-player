<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class PlayerController extends Controller
{
    /**
     * Show the audiobook player.
     */
    public function __invoke(): View
    {
        $book = config('audiobook.book');
        $chapterName = $book['chapter_name'] ?? 'Chapter';
        $number = 0;

        return view('player', [
            'book' => [
                'title' => $book['title'],
                'subtitle' => $book['subtitle'] ?? null,
                'author' => $book['author'],
                'narrator' => $book['narrator'] ?? null,
                'chapterName' => $chapterName,
                'chapterNamePlural' => Str::plural($chapterName),
                'cover' => self::versionedAsset($book['cover']),
                'upcomingChapters' => $book['upcoming_chapters'] ?? [],
                'chapters' => collect($book['chapters'])
                    ->values()
                    ->map(function (array $chapter, int $index) use (&$number): array {
                        return [
                            'number' => $chapter['number'] ?? (($chapter['numbered'] ?? true) ? ++$number : null),
                            'title' => $chapter['title'],
                            'duration' => $chapter['duration'],
                            'url' => AudioController::signedUrl($index + 1),
                            'size' => AudioController::size($index + 1),
                            'hasText' => TextController::exists($index + 1),
                        ];
                    }),
            ],
        ]);
    }

    /**
     * The URL of a public file, with a short hash of its contents so a
     * changed file gets a new URL. The service worker keeps images it has
     * already saved, so an unchanged URL would keep showing the old one.
     */
    private static function versionedAsset(string $path): string
    {
        $file = public_path($path);

        return is_file($file) ? asset($path).'?v='.substr(hash_file('xxh3', $file), 0, 8) : asset($path);
    }
}
