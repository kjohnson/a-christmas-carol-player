<?php

namespace App\Console\Commands;

use App\Http\Controllers\TextController;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Transliterator;
use ZipArchive;

#[Signature('audiobook:sync {chapter? : Only sync this chapter (numbered from 1)}')]
#[Description('Match the ebook text to WhisperX transcripts so the player can follow along')]
class SyncAudiobookText extends Command
{
    /**
     * Elements that start a new block of text.
     */
    private const BLOCK_TAGS = ['p', 'div', 'blockquote', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article'];

    /**
     * How many transcript and book words to look ahead when they drift apart.
     *
     * @var array<int, array{window: int, confirm: int}>
     */
    private const SEARCHES = [
        ['window' => 40, 'confirm' => 2],
        ['window' => 400, 'confirm' => 4],
    ];

    private Filesystem $disk;

    private ?Transliterator $transliterator;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->disk = Storage::disk(config('audiobook.disk'));
        $this->transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        $book = config('audiobook.book');
        $chapters = array_values($book['chapters']);
        $chapterName = $book['chapter_name'] ?? 'Chapter';
        $only = $this->argument('chapter');

        if ($chapters === []) {
            $this->components->warn("{$book['title']} has no chapters yet, skipping.");

            return self::SUCCESS;
        }

        if (! isset($book['ebook'])) {
            $this->components->warn("{$book['title']} has no ebook to follow along with, skipping.");

            return self::SUCCESS;
        }

        if (! $this->disk->exists($book['ebook'])) {
            $this->components->error("Ebook not found at [{$book['ebook']}].");

            return self::FAILURE;
        }

        if ($only !== null && ! isset($chapters[(int) $only - 1])) {
            $this->components->error("{$book['title']} has no {$chapterName} [{$only}].");

            return self::FAILURE;
        }

        $sections = $this->readEbook($book['ebook']);

        foreach ($chapters as $index => $chapter) {
            $number = $index + 1;

            if ($only !== null && (int) $only !== $number) {
                continue;
            }

            $transcriptPath = 'transcripts/'.pathinfo($chapter['file'], PATHINFO_FILENAME).'.json';

            if (! $this->disk->exists($transcriptPath)) {
                $this->components->warn("{$chapterName} {$number}: no transcript at [{$transcriptPath}], skipping.");

                continue;
            }

            $parts = $this->chapterParts($sections, $chapters, $index, $chapterName);

            if ($parts === null) {
                $this->components->error("{$chapterName} {$number}: couldn't find \"{$chapterName} {$number}\" in the ebook. Set its \"epub\" key.");

                return self::FAILURE;
            }

            $blocks = $this->extractBlocks($parts);
            $transcript = $this->readTranscript($transcriptPath);
            [$timings, $matched] = $this->align($blocks, $transcript, (float) $chapter['duration']);

            $this->disk->put(TextController::path($number), json_encode(
                ['blocks' => $blocks, 'timings' => $timings],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            $total = count($timings) / 2;
            $rate = $total > 0 ? round($matched / $total * 100, 1) : 0;

            $this->components->twoColumnDetail(
                "{$chapterName} {$number}: {$chapter['title']}",
                "{$matched}/{$total} words matched ({$rate}%)",
            );
        }

        return self::SUCCESS;
    }

    /**
     * Read the ebook's reading order as a list of XHTML documents.
     *
     * @return array<int, array{href: string, title: string, html: string}>
     */
    private function readEbook(string $path): array
    {
        $local = tempnam(sys_get_temp_dir(), 'ebook');
        file_put_contents($local, $this->disk->get($path));

        $zip = new ZipArchive;

        try {
            if ($zip->open($local) !== true) {
                throw new RuntimeException("Couldn't open [{$path}] as an EPUB.");
            }

            $container = $this->loadXml((string) $zip->getFromName('META-INF/container.xml'));
            $opfPath = $container->query('//*[local-name()="rootfile"]/@full-path')->item(0)?->nodeValue
                ?? throw new RuntimeException('EPUB has no package document.');

            $opf = $this->loadXml((string) $zip->getFromName($opfPath));
            $base = str_contains($opfPath, '/') ? dirname($opfPath).'/' : '';

            $manifest = [];

            foreach ($opf->query('//*[local-name()="manifest"]/*[local-name()="item"]') as $item) {
                $manifest[$item->getAttribute('id')] = $item->getAttribute('href');
            }

            $sections = [];

            foreach ($opf->query('//*[local-name()="spine"]/*[local-name()="itemref"]') as $itemref) {
                $href = $manifest[$itemref->getAttribute('idref')] ?? null;
                $html = $href !== null ? $zip->getFromName($base.rawurldecode($href)) : false;

                if ($html === false) {
                    continue;
                }

                $document = $this->loadXml($html);

                $sections[] = [
                    'href' => $href,
                    'title' => trim($document->query('//*[local-name()="title"]')->item(0)?->textContent ?? ''),
                    'html' => $html,
                ];
            }

            return $sections;
        } finally {
            $zip->close();
            @unlink($local);
        }
    }

    /**
     * The parts of the ebook holding a chapter's text: a whole file, or the
     * run from the anchor its "epub" key points at up to the next chapter's.
     *
     * @param  array<int, array{href: string, title: string, html: string}>  $sections
     * @param  array<int, array{title: string, file: string, duration: float, epub?: string}>  $chapters
     * @return array<int, array{html: string, from: ?string, until: ?string}>|null
     */
    private function chapterParts(array $sections, array $chapters, int $index, string $chapterName): ?array
    {
        $start = $this->locate($sections, $index + 1, $chapters[$index]['epub'] ?? null, $chapterName);

        if ($start === null) {
            return null;
        }

        [$first, $from] = $start;

        if ($from === null) {
            return [['html' => $sections[$first]['html'], 'from' => null, 'until' => null]];
        }

        $next = isset($chapters[$index + 1]['epub'])
            ? $this->locate($sections, $index + 2, $chapters[$index + 1]['epub'], $chapterName)
            : null;

        // Without a next chapter to stop at, the chapter runs to the end of its file.
        [$last, $until] = $next !== null && $next[0] >= $first ? $next : [$first, null];

        $parts = [];

        for ($section = $first; $section <= $last; $section++) {
            // A next chapter that starts with a whole file ends this one at the end of the file before.
            if ($section === $last && $section !== $first && $until === null) {
                break;
            }

            $parts[] = [
                'html' => $sections[$section]['html'],
                'from' => $section === $first ? $from : null,
                'until' => $section === $last ? $until : null,
            ];
        }

        return $parts;
    }

    /**
     * Find where a chapter starts in the ebook, by "epub" key (a file, or
     * "file#anchor") or by a title naming it, e.g. "Stave 3".
     *
     * @param  array<int, array{href: string, title: string, html: string}>  $sections
     * @return array{0: int, 1: ?string}|null The section's index and the anchor, if any.
     */
    private function locate(array $sections, int $number, ?string $epub, string $chapterName): ?array
    {
        [$href, $anchor] = $epub !== null && str_contains($epub, '#') ? explode('#', $epub, 2) : [$epub, null];

        foreach ($sections as $index => $section) {
            $matches = $href !== null
                ? $section['href'] === $href
                : preg_match('/^'.preg_quote($chapterName, '/').'\s+'.$number.'\b/i', $section['title']) === 1;

            if ($matches) {
                return [$index, $anchor];
            }
        }

        return null;
    }

    /**
     * Split a chapter's XHTML into blocks of words, in reading order. Each
     * part is read from its "from" anchor (or the start) until its "until"
     * anchor (or the end).
     *
     * @param  array<int, array{html: string, from: ?string, until: ?string}>  $parts
     * @return array<int, array{tag: string, words: array<int, string>}>
     */
    private function extractBlocks(array $parts): array
    {
        $blocks = [];
        $buffer = '';
        $tag = 'p';
        $reading = true;
        $stopped = false;
        $from = null;
        $until = null;

        $flush = function () use (&$blocks, &$buffer, &$tag): void {
            $words = $this->splitWords($buffer);

            if ($words !== []) {
                $blocks[] = ['tag' => $tag, 'words' => $words];
            }

            $buffer = '';
        };

        $walk = function (DOMNode $node, string $context) use (&$walk, &$buffer, &$tag, &$blocks, &$reading, &$stopped, &$from, &$until, $flush): void {
            foreach ($node->childNodes as $child) {
                if ($stopped) {
                    return;
                }

                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    if ($reading) {
                        $buffer .= $child->textContent;
                        $tag = $context;
                    }

                    continue;
                }

                if (! $child instanceof DOMElement) {
                    continue;
                }

                $id = $child->getAttribute('id');

                if ($until !== null && $id === $until) {
                    $stopped = true;

                    return;
                }

                if ($id !== '' && $id === $from) {
                    $reading = true;
                }

                $name = strtolower($child->localName);

                if (str_contains(' '.$child->getAttribute('class').' ', ' ornamental-break ') || $name === 'hr') {
                    $flush();

                    // A rule straight after a heading is decoration, not a break in the text.
                    if ($reading && $blocks !== [] && ! in_array(end($blocks)['tag'], ['break', 'heading'], true)) {
                        $blocks[] = ['tag' => 'break', 'words' => []];
                    }

                    continue;
                }

                if (in_array($name, ['script', 'style', 'img', 'svg'], true)) {
                    continue;
                }

                if ($name === 'br') {
                    $flush();

                    continue;
                }

                if (in_array($name, self::BLOCK_TAGS, true)) {
                    $flush();
                    $walk($child, match (true) {
                        $name[0] === 'h' => 'heading',
                        $name === 'blockquote' || $context === 'quote' => 'quote',
                        default => $context,
                    });
                    $flush();

                    continue;
                }

                $walk($child, $context);
            }
        };

        foreach ($parts as $part) {
            $body = $this->loadXml($part['html'])->query('//*[local-name()="body"]')->item(0);
            $from = $part['from'];
            $until = $part['until'];
            $reading = $from === null;
            $stopped = false;

            if ($body !== null) {
                $walk($body, 'p');
                $flush();
            }
        }

        while ($blocks !== [] && end($blocks)['tag'] === 'break') {
            array_pop($blocks);
        }

        return $blocks;
    }

    /**
     * Read the words and their timings from a WhisperX JSON transcript.
     *
     * @return array<int, array{key: string, start: ?float, end: ?float}>
     */
    private function readTranscript(string $path): array
    {
        $data = json_decode($this->disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        $words = [];

        foreach ($data['segments'] ?? [] as $segment) {
            foreach ($segment['words'] ?? [] as $word) {
                $start = isset($word['start']) ? (float) $word['start'] : null;
                $end = isset($word['end']) ? (float) $word['end'] : null;

                foreach ($this->splitWords((string) ($word['word'] ?? '')) as $piece) {
                    $words[] = ['key' => $this->normalize($piece), 'start' => $start, 'end' => $end];
                }
            }
        }

        return $words;
    }

    /**
     * Give every book word a start and end time.
     *
     * Book words are matched to transcript words in order, looking ahead when
     * the narration skips or adds words. Words the narrator didn't say (or the
     * transcript misheard) are spread evenly over the gap between matches.
     *
     * @param  array<int, array{tag: string, words: array<int, string>}>  $blocks
     * @param  array<int, array{key: string, start: ?float, end: ?float}>  $transcript
     * @return array{0: array<int, float>, 1: int}
     */
    private function align(array $blocks, array $transcript, float $duration): array
    {
        $book = [];

        foreach ($blocks as $block) {
            foreach ($block['words'] as $word) {
                $book[] = ['key' => $this->normalize($word), 'length' => max(1, mb_strlen($word))];
            }
        }

        $bookKeys = array_column($book, 'key');
        $transcriptKeys = array_column($transcript, 'key');

        /** @var array<int, array{0: float, 1: float}> $times */
        $times = [];
        $i = 0;
        $j = 0;
        $bookCount = count($book);
        $transcriptCount = count($transcript);

        while ($i < $bookCount && $j < $transcriptCount) {
            if ($bookKeys[$i] !== '' && $bookKeys[$i] === $transcriptKeys[$j]) {
                if ($transcript[$j]['start'] !== null && $transcript[$j]['end'] !== null) {
                    $times[$i] = [$transcript[$j]['start'], $transcript[$j]['end']];
                }

                $i++;
                $j++;

                continue;
            }

            $next = $this->findResync($bookKeys, $transcriptKeys, $i, $j);

            if ($next === null) {
                $i++;
                $j++;
            } else {
                [$i, $j] = $next;
            }
        }

        return [$this->fillGaps($book, $times, $duration), count($times)];
    }

    /**
     * Find the nearest point ahead where the book and transcript agree again.
     *
     * @param  array<int, string>  $bookKeys
     * @param  array<int, string>  $transcriptKeys
     * @return array{0: int, 1: int}|null
     */
    private function findResync(array $bookKeys, array $transcriptKeys, int $i, int $j): ?array
    {
        foreach (self::SEARCHES as ['window' => $window, 'confirm' => $confirm]) {
            for ($distance = 1; $distance <= $window * 2; $distance++) {
                for ($bookSkip = max(0, $distance - $window); $bookSkip <= min($distance, $window); $bookSkip++) {
                    $a = $i + $bookSkip;
                    $b = $j + $distance - $bookSkip;

                    if ($this->agrees($bookKeys, $transcriptKeys, $a, $b, $confirm)) {
                        return [$a, $b];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether the next few words agree from these positions.
     *
     * @param  array<int, string>  $bookKeys
     * @param  array<int, string>  $transcriptKeys
     */
    private function agrees(array $bookKeys, array $transcriptKeys, int $a, int $b, int $length): bool
    {
        for ($k = 0; $k < $length; $k++) {
            $key = $bookKeys[$a + $k] ?? null;

            if ($key === null || $key === '' || $key !== ($transcriptKeys[$b + $k] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Interpolate times for unmatched words and flatten to [start, end, ...].
     *
     * @param  array<int, array{key: string, length: int}>  $book
     * @param  array<int, array{0: float, 1: float}>  $times
     * @return array<int, float>
     */
    private function fillGaps(array $book, array $times, float $duration): array
    {
        $count = count($book);
        $flat = [];
        $i = 0;
        $previousEnd = 0.0;

        while ($i < $count) {
            if (isset($times[$i])) {
                $start = max($times[$i][0], $previousEnd);
                $end = max($times[$i][1], $start);
                array_push($flat, round($start, 2), round($end, 2));
                $previousEnd = $end;
                $i++;

                continue;
            }

            $gapEnd = $i;

            while ($gapEnd < $count && ! isset($times[$gapEnd])) {
                $gapEnd++;
            }

            $until = max($gapEnd < $count ? $times[$gapEnd][0] : $duration, $previousEnd);
            $length = array_sum(array_column(array_slice($book, $i, $gapEnd - $i), 'length'));
            $cursor = $previousEnd;

            for (; $i < $gapEnd; $i++) {
                $next = $cursor + ($until - $previousEnd) * $book[$i]['length'] / $length;
                array_push($flat, round($cursor, 2), round($next, 2));
                $cursor = $next;
            }

            $previousEnd = $until;
        }

        return $flat;
    }

    /**
     * Split text into display words, breaking after dashes so "word—word"
     * lines up with how it's spoken.
     *
     * @return array<int, string>
     */
    private function splitWords(string $text): array
    {
        $text = str_replace("\u{00A0}", ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+|(?<=[\x{2014}\x{2013}])(?=\S)/u', $text) ?: [],
            fn (string $word) => $word !== '',
        ));
    }

    /**
     * Reduce a word to a comparable key: lowercase ASCII letters and digits.
     */
    private function normalize(string $word): string
    {
        $ascii = $this->transliterator?->transliterate($word) ?: mb_strtolower($word);

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii)) ?? '';
    }

    /**
     * Parse an XML or XHTML document for querying.
     */
    private function loadXml(string $xml): DOMXPath
    {
        $document = new DOMDocument;

        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            @$document->loadHTML($xml, LIBXML_NONET | LIBXML_NOERROR);
        }

        return new DOMXPath($document);
    }
}
