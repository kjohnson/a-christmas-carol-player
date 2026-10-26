<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve a chapter's configuration (numbered from 1), or abort if the
     * chapter doesn't exist.
     *
     * @return array{title: string, file: string, duration: float, epub?: string, numbered?: bool, number?: int}
     */
    protected function chapterConfig(int $chapter): array
    {
        $chapters = array_values(config('audiobook.book.chapters'));

        abort_unless(isset($chapters[$chapter - 1]), 404);

        return $chapters[$chapter - 1];
    }

    /**
     * Whether the request looks like it came from the player rather than a
     * pasted link, a hotlinking site, or a script.
     *
     * Browsers send Fetch Metadata headers automatically. Older browsers that
     * don't send them at all are let through so they can still listen.
     *
     * @param  array<int, string>  $allowedDestinations
     */
    protected function isPlayerRequest(Request $request, array $allowedDestinations): bool
    {
        $site = $request->header('Sec-Fetch-Site');
        $destination = $request->header('Sec-Fetch-Dest');

        if ($site === null && $destination === null) {
            return true;
        }

        return in_array($site, ['same-origin', 'none'], true)
            && in_array($destination, $allowedDestinations, true);
    }
}
