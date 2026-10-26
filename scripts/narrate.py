"""Narrate a stave of the ebook with Kokoro (https://huggingface.co/hexgrad/Kokoro-82M).

    ~/.venvs/kokoro/bin/python scripts/narrate.py 1

reads Stave One from storage/app/private/A Christmas Carol.epub and writes
storage/app/private/audio/stave-01-marleys-ghost.mp3. Paragraphs, the line of the carol sung at
Scrooge's keyhole and the stave heading each get their own pause. Pass --limit to narrate only the
first few blocks as a sample; stopping with Ctrl+C keeps what's been narrated so far.
"""

import argparse
import re
import subprocess
import tempfile
import zipfile
from html import unescape
from pathlib import Path

import numpy as np
import soundfile as sf
from kokoro import KPipeline

ROOT = Path(__file__).resolve().parent.parent
STORAGE = ROOT / 'storage/app/private'
EBOOK = STORAGE / 'A Christmas Carol.epub'
SAMPLE_RATE = 24000

# The Project Gutenberg ebook's two files holding the staves, in reading order. Each stave runs from
# the anchor on its heading up to the next one's, as the "epub" keys in config/audiobook.php do.
FILES = ['OEBPS/1597581767207061809_46-h-0.htm.xhtml', 'OEBPS/1597581767207061809_46-h-1.htm.xhtml']

# Stave number → (anchor, title), matching config/audiobook.php.
STAVES = {
    1: ('link1', 'Marley’s Ghost'),
    2: ('link2', 'The First of the Three Spirits'),
    3: ('link3', 'The Second of the Three Spirits'),
    4: ('link4', 'The Last of the Spirits'),
    5: ('link5', 'The End of It'),
}

NUMBERS = ['', 'One', 'Two', 'Three', 'Four', 'Five']

# Seconds of silence after each kind of block.
PAUSES = {'heading': 0.9, 'title': 1.6, 'paragraph': 0.6, 'verse': 0.6}

# Each voice's speaking speed, where 1.0 isn't right for it.
SPEEDS = {'bm_daniel': 0.8, 'bm_fable': 0.8, 'bm_george': 0.8, 'bm_lewis': 0.8}

# Words printed in capitals for emphasis that would otherwise be read as letters ("benefit US").
SPOKEN = {r'\bUS\b': 'us'}


def clean(html):
    text = unescape(re.sub(r'<[^>]+>', '', re.sub(r'<br\b[^>]*>', ' ', html)))

    for pattern, spoken in SPOKEN.items():
        text = re.sub(pattern, spoken, text)

    return re.sub(r'\s+', ' ', text).strip()


def blocks(html, number, title):
    """The stave as (kind, text) pairs, in reading order.

    The heading is read from STAVES rather than the ebook, which prints it in capitals. The ebook's
    other headings are the captions of John Leech's illustrations, which aren't read.
    """
    yield 'heading', f'Stave {NUMBERS[number]}.'
    yield 'title', f'{title}.'

    for tag, body in re.findall(r'<(p|td)\b[^>]*>(.*?)</\1>', html, re.S):
        text = clean(body)

        if text:
            yield ('verse' if tag == 'td' else 'paragraph'), text


def stave_html(number):
    """The ebook's XHTML from this stave's anchor up to the next stave's."""
    with zipfile.ZipFile(EBOOK) as epub:
        html = ''.join(epub.read(file).decode('utf-8') for file in FILES)

    start = html.index(f'id="{STAVES[number][0]}"')
    following = STAVES.get(number + 1)

    return html[start:html.index(f'id="{following[0]}"') if following else len(html)]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('stave', type=int, choices=STAVES)
    parser.add_argument('--voice', default='bm_lewis')
    parser.add_argument('--speed', type=float, help="defaults to the voice's entry in SPEEDS, or 1.0")
    parser.add_argument('--limit', type=int, help='only narrate this many blocks (paragraphs, the heading)')
    args = parser.parse_args()

    title = STAVES[args.stave][1]
    speed = args.speed or SPEEDS.get(args.voice, 1.0)
    slug = re.sub(r'[^a-z0-9]+', '-', title.lower().replace('’', '')).strip('-')
    out = STORAGE / 'audio' / f'stave-{args.stave:02d}-{slug}.mp3'
    out.parent.mkdir(exist_ok=True)

    # American voices start with "a", British ones with "b".
    pipeline = KPipeline(lang_code=args.voice[0], repo_id='hexgrad/Kokoro-82M')
    parts = []
    items = list(blocks(stave_html(args.stave), args.stave, title))[:args.limit]

    try:
        for n, (kind, text) in enumerate(items, 1):
            parts += [result.audio.numpy() for result in pipeline(text, voice=args.voice, speed=speed, split_pattern=None)]
            parts.append(np.zeros(int(PAUSES[kind] * SAMPLE_RATE), dtype=np.float32))
            print(f'\r{n}/{len(items)} blocks', end='', flush=True)
    except KeyboardInterrupt:
        print(' — stopped, saving what was narrated', end='')

    print()

    if not parts:
        return

    with tempfile.NamedTemporaryFile(suffix='.wav') as wav:
        sf.write(wav.name, np.concatenate(parts), SAMPLE_RATE)
        subprocess.run([
            'ffmpeg', '-y', '-loglevel', 'error', '-i', wav.name,
            '-codec:a', 'libmp3lame', '-b:a', '64k', '-ac', '1',
            '-metadata', f'title={title}', '-metadata', 'artist=Charles Dickens',
            '-metadata', 'album=A Christmas Carol', '-metadata', f'track={args.stave}',
            '-metadata', f'comment=Narrated by Kokoro-82M ({args.voice} at {speed})',
            str(out),
        ], check=True)

    print(out)


if __name__ == '__main__':
    main()
