# A Christmas Carol — Audiobook Player

A mobile-first Laravel site for listening to *A Christmas Carol* by Charles Dickens: all five staves, narrated
from the [Project Gutenberg ebook](https://www.gutenberg.org/ebooks/46) with the
[Kokoro-82M](https://huggingface.co/hexgrad/Kokoro-82M) text-to-speech model (voice `bm_lewis` at 0.8 speed), with
follow-along text for every stave.

Copied from the After Moses audiobook player, cut down to a single book and themed for Christmas Eve at
Scrooge's: claret and mulled-wine backgrounds, snow-white text by firelight and a candle-gold accent, with
IM Fell English for titles, a slow snowfall, and a lit chamberstick and holly for the cover, app icons and
favicon.

- Stave list with per-stave progress
- Resume where you left off (saved in the browser's localStorage)
- Back 15s / forward 30s
- Follow-along text: the ebook's text with the spoken word highlighted (tap a word to jump there)
- Works offline: the stave that's playing and the next one are saved on the device automatically, and
  "Save all" in the staves menu saves the whole book. A service worker (`public/sw.js`) serves saved
  staves, including seeking, and keeps the player itself available without a connection.
- Lock-screen and headphone controls via the Media Session API
- Installable to the home screen (web app manifest)

No database is required.

## Local development

```sh
composer install
npm install
cp .env.example .env && php artisan key:generate
composer run dev
```

> `php artisan serve` doesn't support HTTP range requests, so seeking in Safari/iOS only works against a real web server (Laravel Cloud, Herd, Valet, nginx).

## The book

The book is set up in `book` in `config/audiobook.php`. Its files sit at the root of `storage/app/private/`:

```
storage/app/private/
    A Christmas Carol.epub
    audio/stave-01-marleys-ghost.mp3, …
    transcripts/stave-01-marleys-ghost.json, …
    text/chapter-01.json, …              (written by audiobook:sync)
```

If `chapters` is ever empty, the player shows the book as coming soon, with its `upcoming_chapters` titles
dimmed in the staves menu and the playback controls disabled.

The ebook, audio and transcripts aren't committed to git; the synced text is. The cover is
`public/images/christmas-carol.jpg` (1024×1024 JPEG). To use the MP3's embedded art instead:
`ffmpeg -i stave.mp3 -an -vf scale=1024:1024 public/images/christmas-carol.jpg`.

## Narrating

`scripts/narrate.py` reads a stave from the ebook and writes its MP3 to `storage/app/private/audio/`, with pauses
after the heading and between paragraphs. It reads the heading as "Stave One. Marley's Ghost." rather than the
ebook's capitals, and leaves out the captions of John Leech's illustrations. It runs in its own Python
environment:

```sh
uv venv --python 3.11 ~/.venvs/kokoro
uv pip install --python ~/.venvs/kokoro/bin/python torch --index-url https://download.pytorch.org/whl/cpu
uv pip install --python ~/.venvs/kokoro/bin/python "kokoro>=0.9" soundfile pip

~/.venvs/kokoro/bin/python scripts/narrate.py 1              # bm_lewis at 0.8
~/.venvs/kokoro/bin/python scripts/narrate.py 1 --limit 4    # just the heading and first two paragraphs
~/.venvs/kokoro/bin/python scripts/narrate.py 1 --voice bm_daniel --speed 0.9
```

On a CPU it takes roughly half the length of the audio. Stopping it with Ctrl+C saves what's been narrated so
far. After re-narrating a stave, update its `duration` and redo its follow-along text.

## Adding the audio

1. Drop the MP3s in `storage/app/private/audio/` (lowercase, no spaces, e.g. `stave-01-marleys-ghost.mp3`).
2. Give `chapters` an entry per track: its title, file, and duration in seconds. Set `numbered` to `false` for
   tracks that aren't staves, like a preface or credits:

   ```sh
   ffprobe -v error -show_entries format=duration -of csv=p=0 storage/app/private/audio/stave-01-marleys-ghost.mp3
   ```

   Each stave's `epub` key points at the anchor on its heading in the Gutenberg ebook.

3. Upload them to the bucket with `php artisan audiobook:upload s3`.

## Follow-along text

Put a WhisperX JSON transcript of each stave in `transcripts/`, named after its MP3, and run
`php artisan audiobook:sync` (or `php artisan audiobook:sync 4` for one stave):

```sh
whisperx storage/app/private/audio/stave-04-the-last-of-the-spirits.mp3 --model small.en --language en \
    --compute_type int8 --output_format json --output_dir storage/app/private/transcripts
php artisan audiobook:sync 4
```

Staves are found in the ebook by their `epub` key: a file, or an anchor in one (`file.xhtml#id`), in which case
the stave runs from that anchor up to the next stave's, carrying on into the following files if need be (the
end of Stave IV spills into the Gutenberg ebook's second file). Without an `epub` key, a stave is found by a
"Stave N" title. The synced text lands in `text/`. Staves without it don't show the text button.

## Deploying to Laravel Cloud

1. Push this repo to GitHub/GitLab/Bitbucket and create an application in Laravel Cloud.
2. Build command: `composer install --no-dev && npm ci && npm run build && php artisan optimize`
3. Deploy command: leave empty (there are no migrations to run).
4. Environment variables: `SESSION_DRIVER=cookie`, `CACHE_STORE=file` (no database needed).

### Hosting the audio in Object Storage

The MP3s are too large for the repo, so they live in a private bucket. Locally they sit in
`storage/app/private/audio` (git-ignored), outside the web root. The player only receives signed links
(`/audio/{stave}?expires=…&signature=…`) that expire after `AUDIOBOOK_URL_LIFETIME` minutes (default 360),
are rate limited, and are refused when opened directly in a browser tab or embedded on another site. To set
up the bucket:

1. Add a **private** Laravel Object Storage bucket to the environment and mark it as the **default disk**
   (any disk name). Redeploy so the bucket's connection is injected. Listeners are then redirected to
   short-lived pre-signed URLs.
2. Copy one of the bucket's access keys (Organization > Resources > Object storage > your bucket >
   Access keys > View credentials) into your local `.env`:

   ```dotenv
   AWS_ACCESS_KEY_ID=…
   AWS_SECRET_ACCESS_KEY=…
   AWS_BUCKET=…
   AWS_ENDPOINT=…
   AWS_DEFAULT_REGION=auto
   ```

3. Upload the files:

   ```sh
   php artisan audiobook:upload s3
   ```

   This copies `audio/*.mp3` and `text/*.json` from local storage into the same folders in the bucket,
   skipping files that are already uploaded unchanged. Run it again after adding staves or re-running
   `audiobook:sync`. The player checks for new text every 5 minutes.

`AUDIOBOOK_DISK` can name a disk other than the default, but isn't needed on Laravel Cloud.
