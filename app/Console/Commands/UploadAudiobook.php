<?php

namespace App\Console\Commands;

use App\Http\Controllers\AudioController;
use App\Http\Controllers\TextController;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('audiobook:upload {disk : The disk to upload to, e.g. "s3" for the Laravel Cloud bucket} {--from=local : The disk holding the files}')]
#[Description('Copy the chapter audio and synced text to object storage')]
class UploadAudiobook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $from = Storage::disk($this->option('from'));
        $to = Storage::disk($this->argument('disk'));

        if ($this->option('from') === $this->argument('disk')) {
            $this->components->error('The source and destination disks are the same.');

            return self::FAILURE;
        }

        $paths = [];

        foreach (array_values(config('audiobook.book.chapters')) as $index => $chapter) {
            $paths[] = AudioController::path($chapter['file']);

            if ($from->exists(TextController::path($index + 1))) {
                $paths[] = TextController::path($index + 1);
            }
        }

        foreach ($paths as $path) {
            if (! $from->exists($path)) {
                $this->components->warn("Missing [{$path}], skipping.");

                continue;
            }

            if ($to->exists($path) && $to->size($path) === $from->size($path)) {
                $this->components->twoColumnDetail($path, 'already uploaded');

                continue;
            }

            $this->components->task($path, function () use ($from, $to, $path) {
                $stream = $from->readStream($path);

                try {
                    return $to->writeStream($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
