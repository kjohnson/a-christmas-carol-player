<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audio Location
    |--------------------------------------------------------------------------
    |
    | The book's ebook and its "audio", "text" and "transcripts" folders sit
    | at the root of a private disk. Nothing is served from a public URL. On
    | the local disk audio is streamed through a signed route; on
    | S3-compatible object storage (e.g. a private Laravel Cloud bucket)
    | listeners are redirected to a short-lived pre-signed URL.
    |
    | Leave AUDIOBOOK_DISK unset to use the default disk: "local" here, and
    | the bucket attached as the default disk on Laravel Cloud.
    |
    | Signed player URLs expire after "url_lifetime" minutes. The player
    | fetches a fresh one automatically if a listener comes back later.
    |
    */

    'disk' => env('AUDIOBOOK_DISK'),

    'url_lifetime' => (int) env('AUDIOBOOK_URL_LIFETIME', 360),

    /*
    |--------------------------------------------------------------------------
    | Book
    |--------------------------------------------------------------------------
    |
    | The "subtitle" is Dickens's own, from the title page. The "narrator"
    | is shown as "Read by …" once it's set. Until the book has chapters,
    | it's shown as coming soon, listing its "upcoming_chapters" titles.
    | Dickens divided the book into five "staves" (verses of a carol), so
    | its chapters are named "Stave".
    |
    | Running "php artisan audiobook:sync" matches each chapter's text in the
    | ebook against a WhisperX transcript of its audio (in "transcripts") and
    | writes word timings to the "text" folder, which the player reads.
    | Chapters are found in the ebook by a "Stave N" title (after
    | "chapter_name") matching their place in the list. Add an "epub" key to
    | a chapter to point at a specific file instead, or at an anchor in one
    | ("file.xhtml#id"): the chapter then runs from that anchor up to the
    | next chapter's, carrying on into the following files if need be. The
    | Project Gutenberg ebook keeps the first four staves in one file, so
    | each stave points at the anchor on its heading.
    |
    | Durations are in seconds, so the chapter list can show lengths before
    | any audio has been loaded. Get them with:
    | ffprobe -v error -show_entries format=duration -of csv=p=0 file.mp3
    |
    | Set "numbered" to false for tracks that aren't chapters, like the
    | preface or the credits, so they're listed without a chapter number. A
    | chapter's "number" can be set instead.
    |
    */

    'book' => [
        'title' => 'A Christmas Carol',
        'subtitle' => 'A Ghost Story of Christmas',
        'author' => 'Charles Dickens',
        'narrator' => 'Kokoro',
        'cover' => 'images/christmas-carol.jpg',
        'ebook' => 'A Christmas Carol.epub',
        'chapter_name' => 'Stave',
        'chapters' => [
            ['title' => 'Marley’s Ghost', 'file' => 'stave-01-marleys-ghost.mp3', 'duration' => 2978.23, 'epub' => '1597581767207061809_46-h-0.htm.xhtml#link1'],
            ['title' => 'The First of the Three Spirits', 'file' => 'stave-02-the-first-of-the-three-spirits.mp3', 'duration' => 2757.50, 'epub' => '1597581767207061809_46-h-0.htm.xhtml#link2'],
            ['title' => 'The Second of the Three Spirits', 'file' => 'stave-03-the-second-of-the-three-spirits.mp3', 'duration' => 3658.25, 'epub' => '1597581767207061809_46-h-0.htm.xhtml#link3'],
            ['title' => 'The Last of the Spirits', 'file' => 'stave-04-the-last-of-the-spirits.mp3', 'duration' => 2413.92, 'epub' => '1597581767207061809_46-h-0.htm.xhtml#link4'],
            ['title' => 'The End of It', 'file' => 'stave-05-the-end-of-it.mp3', 'duration' => 1044.19, 'epub' => '1597581767207061809_46-h-1.htm.xhtml#link5'],
        ],
        'upcoming_chapters' => [
            'Marley’s Ghost',
            'The First of the Three Spirits',
            'The Second of the Three Spirits',
            'The Last of the Spirits',
            'The End of It',
        ],
    ],

];
