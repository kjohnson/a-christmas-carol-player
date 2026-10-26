<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SyncAudiobookTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['audiobook.book' => [
            'title' => 'Test Book',
            'ebook' => 'Test Book.epub',
            'chapters' => [
                ['title' => 'Europan Extraction', 'file' => 'chapter-01-europan-extraction.mp3', 'duration' => 20.0],
                ['title' => 'Rings of Interference', 'file' => 'chapter-02-rings-of-interferance.mp3', 'duration' => 20.0],
            ],
        ]]);

        Storage::disk('local')->put('Test Book.epub', $this->makeEpub([
            'front.xhtml' => $this->xhtml('Contents', '<p>Chapter 1 starts on page one.</p>'),
            'one.xhtml' => $this->xhtml('Chapter 1: Europan Extraction', implode('', [
                '<h2>Chapter 1: Europan Extraction</h2>',
                '<blockquote><em>We lived on.</em><br/><br/><b>Alexei</b></blockquote>',
                '<span class="ornamental-break"><img src="break.png" alt=""/></span>',
                '<p><span class="lead_word">A</span> <span class="lead_word">SLEEK</span> craft drifted&#x2014;slowly.</p>',
                '<p>&#x201C;I&#x2019;m Vicente Luna,&#x201D; he said.</p>',
            ])),
            'two.xhtml' => $this->xhtml('Chapter 2: Rings of Interference', '<p>Elsewhere.</p>'),
        ]));
    }

    public function test_chapter_text_is_split_into_blocks_of_words(): void
    {
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one europan extraction we lived on alexei a sleek craft drifted slowly im vicente luna he said'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $this->assertSame([
            ['tag' => 'heading', 'words' => ['Chapter', '1:', 'Europan', 'Extraction']],
            ['tag' => 'quote', 'words' => ['We', 'lived', 'on.']],
            ['tag' => 'quote', 'words' => ['Alexei']],
            ['tag' => 'break', 'words' => []],
            ['tag' => 'p', 'words' => ['A', 'SLEEK', 'craft', 'drifted—', 'slowly.']],
            ['tag' => 'p', 'words' => ['“I’m', 'Vicente', 'Luna,”', 'he', 'said.']],
        ], $this->synced(1)['blocks']);
    }

    public function test_matching_words_get_the_narrators_timings(): void
    {
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one europan extraction we lived on alexei a sleek craft drifted slowly im vicente luna he said'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $timings = $this->synced(1)['timings'];

        // "Europan" is the third spoken word and the third book word.
        $this->assertEquals([2, 2.5], array_slice($timings, 4, 2));
        // "said." is the last of 18 words in both.
        $this->assertEquals([17, 17.5], array_slice($timings, 34, 2));
    }

    public function test_extra_and_skipped_narration_stays_in_sync(): void
    {
        // The narrator adds "um, well" and skips "craft drifted".
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one europan extraction we lived on alexei um well a sleek slowly im vicente luna he said'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $timings = $this->synced(1)['timings'];

        // "A" (book word 8) is spoken as word 10.
        $this->assertEquals([10, 10.5], array_slice($timings, 16, 2));
        // "slowly." (book word 12) is spoken as word 12.
        $this->assertEquals([12, 12.5], array_slice($timings, 24, 2));
        // The skipped "craft drifted—" fit in the gap between "SLEEK" and "slowly.".
        $this->assertGreaterThanOrEqual(11.5, $timings[20]);
        $this->assertLessThanOrEqual(12.0, $timings[23]);
    }

    public function test_timings_never_go_backwards(): void
    {
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('something else entirely he said'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $timings = $this->synced(1)['timings'];
        $sorted = $timings;
        sort($sorted);

        $this->assertSame($sorted, $timings);
        $this->assertCount(36, $timings);
    }

    public function test_chapters_without_a_transcript_are_skipped(): void
    {
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        Storage::disk('local')->assertExists('text/chapter-01.json');
        Storage::disk('local')->assertMissing('text/chapter-02.json');
    }

    public function test_a_single_chapter_can_be_synced(): void
    {
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one'));
        $this->putTranscript('chapter-02-rings-of-interferance', $this->spoken('elsewhere'));

        $this->artisan('audiobook:sync', ['chapter' => 2])->assertSuccessful();

        Storage::disk('local')->assertMissing('text/chapter-01.json');
        $this->assertSame([['tag' => 'p', 'words' => ['Elsewhere.']]], $this->synced(2)['blocks']);
    }

    public function test_a_chapter_can_point_at_a_specific_ebook_file(): void
    {
        config(['audiobook.book.chapters.1.epub' => 'one.xhtml']);
        $this->putTranscript('chapter-02-rings-of-interferance', $this->spoken('chapter one'));

        $this->artisan('audiobook:sync', ['chapter' => 2])->assertSuccessful();

        $this->assertSame('Europan', $this->synced(2)['blocks'][0]['words'][2]);
    }

    public function test_chapters_are_found_by_the_books_chapter_name(): void
    {
        config(['audiobook.book.chapter_name' => 'Stave']);
        Storage::disk('local')->put('Test Book.epub', $this->makeEpub([
            'one.xhtml' => $this->xhtml('Stave 1: Marley’s Ghost', '<p>Marley was dead.</p>'),
        ]));
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('marley was dead'));

        $this->artisan('audiobook:sync', ['chapter' => 1])->assertSuccessful();

        $this->assertSame([['tag' => 'p', 'words' => ['Marley', 'was', 'dead.']]], $this->synced(1)['blocks']);
    }

    public function test_chapters_sharing_a_file_run_from_their_anchor_to_the_next(): void
    {
        $this->useAnchoredEbook();
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('stave one marley was dead'));
        $this->putTranscript('chapter-02-rings-of-interferance', $this->spoken('stave two when scrooge awoke'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $this->assertSame([
            ['tag' => 'heading', 'words' => ['STAVE', 'ONE.']],
            ['tag' => 'heading', 'words' => ['MARLEY’S', 'GHOST.']],
            ['tag' => 'p', 'words' => ['Marley', 'was', 'dead.']],
        ], $this->synced(1)['blocks']);
        $this->assertSame([
            ['tag' => 'heading', 'words' => ['STAVE', 'TWO.']],
            ['tag' => 'p', 'words' => ['When', 'Scrooge', 'awoke.']],
        ], $this->synced(2)['blocks']);
    }

    public function test_a_chapter_carries_on_into_the_next_file_until_the_next_chapter(): void
    {
        $this->useAnchoredEbook();
        config(['audiobook.book.chapters' => [
            ['title' => 'Marley’s Ghost', 'file' => 'stave-01.mp3', 'duration' => 20.0, 'epub' => 'one.xhtml#link1'],
            ['title' => 'The First of the Three Spirits', 'file' => 'stave-02.mp3', 'duration' => 20.0, 'epub' => 'one.xhtml#link2'],
            ['title' => 'The End of It', 'file' => 'stave-03.mp3', 'duration' => 20.0, 'epub' => 'two.xhtml#link3'],
        ]]);
        $this->putTranscript('stave-02', $this->spoken('stave two when scrooge awoke still dark'));
        $this->putTranscript('stave-03', $this->spoken('stave three yes the bedpost was his own'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        $this->assertSame([
            ['tag' => 'heading', 'words' => ['STAVE', 'TWO.']],
            ['tag' => 'p', 'words' => ['When', 'Scrooge', 'awoke.']],
            ['tag' => 'p', 'words' => ['Still', 'dark.']],
        ], $this->synced(2)['blocks']);
        $this->assertSame([
            ['tag' => 'heading', 'words' => ['STAVE', 'THREE.']],
            ['tag' => 'p', 'words' => ['Yes!', 'The', 'bedpost', 'was', 'his', 'own.']],
        ], $this->synced(3)['blocks']);
    }

    /**
     * An ebook laid out like Project Gutenberg's: two chapters in one file, the
     * end of the second spilling into the next file, then a third chapter.
     */
    private function useAnchoredEbook(): void
    {
        config(['audiobook.book.chapters.0.epub' => 'one.xhtml#link1', 'audiobook.book.chapters.1.epub' => 'one.xhtml#link2']);

        Storage::disk('local')->put('Test Book.epub', $this->makeEpub([
            'one.xhtml' => $this->xhtml('A Christmas Carol', implode('', [
                '<h1>A Christmas Carol</h1>',
                '<p>Contents</p>',
                '<hr/><h2><a id="link1">STAVE ONE.</a></h2><hr/><h4>MARLEY’S GHOST.</h4>',
                '<p>Marley was dead.</p>',
                '<hr/><h2><a id="link2">STAVE TWO.</a></h2>',
                '<p>When Scrooge awoke.</p>',
            ])),
            'two.xhtml' => $this->xhtml('Stave Three', implode('', [
                '<p>Still dark.</p>',
                '<hr/><h2><a id="link3">STAVE THREE.</a></h2>',
                '<p>Yes! The bedpost was his own.</p>',
            ])),
            'license.xhtml' => $this->xhtml('License', '<p>Not part of the book.</p>'),
        ]));
    }

    public function test_a_book_without_chapters_yet_is_skipped(): void
    {
        config(['audiobook.book.chapters' => []]);

        $this->artisan('audiobook:sync')->assertSuccessful();

        Storage::disk('local')->assertMissing('text/chapter-01.json');
    }

    public function test_a_book_without_an_ebook_is_skipped(): void
    {
        config(['audiobook.book.ebook' => null]);
        $this->putTranscript('chapter-01-europan-extraction', $this->spoken('chapter one'));

        $this->artisan('audiobook:sync')->assertSuccessful();

        Storage::disk('local')->assertMissing('text/chapter-01.json');
    }

    public function test_unknown_chapter_fails(): void
    {
        $this->artisan('audiobook:sync', ['chapter' => 3])->assertFailed();
    }

    public function test_missing_ebook_fails(): void
    {
        config(['audiobook.book.ebook' => 'missing.epub']);

        $this->artisan('audiobook:sync')->assertFailed();
    }

    /**
     * Build WhisperX-style output where word N is spoken from N to N + 0.5 seconds.
     *
     * @return array<string, mixed>
     */
    private function spoken(string $text): array
    {
        $words = [];

        foreach (explode(' ', $text) as $index => $word) {
            $words[] = ['word' => $word, 'start' => (float) $index, 'end' => $index + 0.5, 'score' => 0.9];
        }

        return ['segments' => [['start' => 0, 'end' => count($words), 'text' => $text, 'words' => $words]]];
    }

    /**
     * @param  array<string, mixed>  $transcript
     */
    private function putTranscript(string $name, array $transcript): void
    {
        Storage::disk('local')->put("transcripts/{$name}.json", json_encode($transcript));
    }

    /**
     * @return array{blocks: array<int, array{tag: string, words: array<int, string>}>, timings: array<int, float>}
     */
    private function synced(int $chapter): array
    {
        return json_decode(Storage::disk('local')->get(sprintf('text/chapter-%02d.json', $chapter)), true);
    }

    private function xhtml(string $title, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE html>'
            .'<html xmlns="http://www.w3.org/1999/xhtml"><head><title>'.$title.'</title></head>'
            .'<body><div class="chapter">'.$body.'</div></body></html>';
    }

    /**
     * @param  array<string, string>  $documents
     */
    private function makeEpub(array $documents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epub');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');

        $items = '';
        $spine = '';

        foreach (array_keys($documents) as $index => $href) {
            $items .= "<item id=\"item{$index}\" href=\"{$href}\" media-type=\"application/xhtml+xml\"/>";
            $spine .= "<itemref idref=\"item{$index}\"/>";
        }

        $zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0"><manifest>'.$items.'</manifest><spine>'.$spine.'</spine></package>');

        foreach ($documents as $href => $html) {
            $zip->addFromString("OEBPS/{$href}", $html);
        }

        $zip->close();

        $contents = file_get_contents($path);
        unlink($path);

        return $contents;
    }
}
