const STORAGE_KEY = 'christmas-carol:player:v1';
const BACK_SECONDS = 15;
const FORWARD_SECONDS = 30;
const FOLLOW_PAUSE_MS = 6000;
// After a jump with the scrubber or a word, how long the way back is offered, and the smallest jump that offers it.
const RETURN_VISIBLE_MS = 10000;
const RETURN_MIN_JUMP_SECONDS = 5;
// Follow-along text sizes in rem, smallest to largest.
const TEXT_SIZES = [1, 1.125, 1.25, 1.5, 1.75, 2];
const DEFAULT_TEXT_SIZE = 1;

const { book } = JSON.parse(document.getElementById('audiobook-data').textContent);
const { chapters } = book;

const audio = document.getElementById('audio');
const sheet = document.getElementById('chapters');
const readerEl = document.getElementById('reader');
const $ = (selector) => document.querySelector(selector);
const ui = {
    toggle: $('[data-action="toggle"]'),
    prev: $('[data-action="prev"]'),
    next: $('[data-action="next"]'),
    seek: $('[data-bind="seek"]'),
    elapsed: $('[data-bind="elapsed"]'),
    remaining: $('[data-bind="remaining"]'),
    status: $('[data-bind="status"]'),
    chapterLabel: $('[data-bind="chapter-label"]'),
    chapterTitle: $('[data-bind="chapter-title"]'),
    player: $('#player'),
    textButton: $('[data-action="text"]'),
    followButton: $('[data-action="follow"]'),
    returnButton: $('.return-point'),
    returnMarker: $('[data-bind="return-marker"]'),
    returnTime: $('[data-bind="return-time"]'),
    coverView: $('[data-view="cover"]'),
    textView: $('[data-view="text"]'),
    icons: {
        play: $('[data-icon="play"]'),
        pause: $('[data-icon="pause"]'),
        loading: $('[data-icon="loading"]'),
    },
};

const state = {
    chapter: 0,
    // The position reached in each chapter.
    positions: {},
    view: 'cover',
    // Index into TEXT_SIZES.
    textSize: DEFAULT_TEXT_SIZE,
    pendingSeek: null,
    scrubbing: false,
    loading: false,
    booted: false,
};

// ---------------------------------------------------------------------------
// Persistence
// ---------------------------------------------------------------------------

function load() {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));

        if (saved) {
            state.positions = saved.positions ?? {};
            state.chapter = Math.min(Math.max(Number(saved.chapter) || 0, 0), chapters.length - 1);
            state.view = saved.view === 'text' ? 'text' : 'cover';
            state.textSize = TEXT_SIZES[saved.textSize] ? saved.textSize : DEFAULT_TEXT_SIZE;
        }
    } catch {
        // Storage unavailable or corrupt; start fresh.
    }
}

let lastSave = 0;

function save(force = false) {
    const now = Date.now();

    if (!force && now - lastSave < 5000) {
        return;
    }

    lastSave = now;

    // Only record the playhead once the current chapter's audio is actually loaded.
    if (state.booted && state.pendingSeek === null && audio.readyState >= HTMLMediaElement.HAVE_METADATA) {
        state.positions[state.chapter] = Math.floor(audio.currentTime);
    }

    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ chapter: state.chapter, positions: state.positions, view: state.view, textSize: state.textSize }),
        );
    } catch {
        // Ignore quota or privacy-mode errors.
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatTime(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));

    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = String(seconds % 60).padStart(2, '0');

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

function duration() {
    return Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : chapters[state.chapter].duration;
}

function currentTime() {
    return state.pendingSeek ?? audio.currentTime;
}

/**
 * A chapter's number as a string key, as used in its audio and text URLs.
 */
function chapterKey(index) {
    return String(index + 1);
}

function currentKey() {
    return chapterKey(state.chapter);
}

function chapterAt(key) {
    return chapters[Number(key) - 1];
}

/**
 * The index of the chapter after the one that's playing, or null at the end of the book.
 */
function followingChapter() {
    return state.chapter < chapters.length - 1 ? state.chapter + 1 : null;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/**
 * How many numbered chapters the book has, leaving out tracks like the credits.
 */
function chapterCount() {
    return chapters.filter((chapter) => chapter.number).length;
}

/**
 * "Stave 3 of 5", or just "Stave 1" for a book with only one.
 */
function chapterLabel(chapter) {
    const count = chapterCount();

    return count > 1 ? `${book.chapterName} ${chapter.number} of ${count}` : `${book.chapterName} ${chapter.number}`;
}

function renderChapter() {
    const chapter = chapters[state.chapter];

    ui.chapterLabel.textContent = chapter.number ? chapterLabel(chapter) : book.title;
    ui.chapterTitle.textContent = chapter.title;
    ui.next.disabled = followingChapter() === null;
    document.title = `${chapter.title} — ${book.title}`;

    document.querySelectorAll('[data-chapter]').forEach((row) => {
        row.setAttribute('aria-current', String(Number(row.dataset.chapter) === state.chapter));
    });

    renderChapterProgress();
    renderView();
    updateMediaSession();
}

function renderChapterProgress() {
    document.querySelectorAll('[data-chapter]').forEach((row) => {
        const index = Number(row.dataset.chapter);
        const playing = index === state.chapter;
        const position = chapterPosition(index);
        const total = playing ? duration() : chapters[index].duration;

        row.querySelector('[data-progress]').style.width = `${Math.min(100, (position / total) * 100)}%`;
    });
}

function renderTime() {
    const total = duration();
    const time = state.scrubbing ? Number(ui.seek.value) : currentTime();

    ui.seek.max = total;

    if (!state.scrubbing) {
        ui.seek.value = time;
    }

    ui.seek.style.setProperty('--progress', `${(time / total) * 100}%`);
    ui.elapsed.textContent = formatTime(time);
    ui.remaining.textContent = `-${formatTime(total - time)}`;

    let buffered = 0;

    for (let i = 0; i < audio.buffered.length; i++) {
        if (audio.buffered.start(i) <= time) {
            buffered = Math.max(buffered, audio.buffered.end(i));
        }
    }

    ui.seek.style.setProperty('--buffered', `${(buffered / total) * 100}%`);
    renderReturnMarker();
}

function renderPlayState() {
    const playing = !audio.paused;
    const icon = state.loading && playing ? 'loading' : playing ? 'pause' : 'play';

    Object.entries(ui.icons).forEach(([name, el]) => el.classList.toggle('hidden', name !== icon));
    ui.toggle.setAttribute('aria-label', playing ? 'Pause' : 'Play');

    if ('mediaSession' in navigator) {
        navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
    }
}

let statusTimeout;

function flash(message) {
    ui.status.textContent = message;
    clearTimeout(statusTimeout);
    statusTimeout = setTimeout(() => (ui.status.textContent = ''), 1500);
}

// ---------------------------------------------------------------------------
// Follow-along text
// ---------------------------------------------------------------------------

const reader = {
    // The key of the chapter whose text is showing.
    key: null,
    timings: null,
    words: [],
    current: -1,
    pausedUntil: 0,
    frame: null,
};

const textRequests = new Map();

function isReading() {
    return state.view === 'text' && chapters[state.chapter].hasText;
}

function renderView() {
    const reading = isReading();

    ui.textButton.classList.toggle('hidden', !chapters[state.chapter].hasText);
    ui.textButton.setAttribute('aria-pressed', String(reading));
    ui.player.toggleAttribute('data-reading', reading);
    ui.coverView.classList.toggle('hidden', reading);
    ui.textView.classList.toggle('hidden', !reading);

    if (reading) {
        loadReader();
    }

    syncReaderLoop();
}

function renderTextSize() {
    readerEl.style.setProperty('--reader-size', `${TEXT_SIZES[state.textSize]}rem`);
    $('[data-action="text-smaller"]').disabled = state.textSize === 0;
    $('[data-action="text-larger"]').disabled = state.textSize === TEXT_SIZES.length - 1;
}

function changeTextSize(step) {
    const size = Math.min(Math.max(state.textSize + step, 0), TEXT_SIZES.length - 1);

    if (size === state.textSize) {
        return;
    }

    state.textSize = size;
    renderTextSize();
    // Keep the word being read in view as the lines reflow.
    highlightWord({ force: true, scroll: 'instant' });
    save(true);
}

function toggleView() {
    state.view = state.view === 'text' ? 'cover' : 'text';
    reader.pausedUntil = 0;
    renderView();
    highlightWord({ force: true, scroll: 'instant' });
    save(true);
}

function fetchText(key) {
    if (!textRequests.has(key)) {
        const request = fetch(`/text/${key}`, { headers: { Accept: 'application/json' } }).then((response) => {
            if (!response.ok) {
                throw new Error(`Text request failed: ${response.status}`);
            }

            return response.json();
        });

        // Let a failed request be retried next time the text is opened.
        request.catch(() => textRequests.delete(key));
        textRequests.set(key, request);
    }

    return textRequests.get(key);
}

async function loadReader() {
    const key = currentKey();

    if (reader.key === key) {
        return;
    }

    reader.key = key;
    reader.timings = null;
    reader.words = [];
    reader.current = -1;
    readerEl.replaceChildren(readerStatus('Loading text…'));

    try {
        const text = await fetchText(key);

        if (reader.key === key) {
            renderReader(text);
        }
    } catch {
        if (reader.key === key) {
            reader.key = null;
            readerEl.replaceChildren(readerStatus('Couldn’t load the text'));
        }
    }
}

function readerStatus(message) {
    const status = document.createElement('p');
    status.className = 'reader-status';
    status.textContent = message;

    return status;
}

function renderReader({ blocks, timings }) {
    const fragment = document.createDocumentFragment();
    const words = [];

    for (const block of blocks) {
        if (block.tag === 'break') {
            const ornament = document.createElement('p');
            ornament.className = 'reader-break';
            ornament.setAttribute('aria-hidden', 'true');
            ornament.textContent = '* * *';
            fragment.append(ornament);
            continue;
        }

        const element = document.createElement(block.tag === 'heading' ? 'h2' : 'p');

        if (block.tag === 'quote') {
            element.className = 'reader-quote';
        }

        block.words.forEach((word, position) => {
            const span = document.createElement('span');
            span.dataset.w = words.length;
            span.textContent = word;
            words.push(span);
            element.append(span, position < block.words.length - 1 ? ' ' : '');
        });

        fragment.append(element);
    }

    readerEl.replaceChildren(fragment);
    reader.timings = timings;
    reader.words = words;
    reader.current = -1;
    readerEl.scrollTop = 0;
    highlightWord({ force: true, scroll: 'instant' });
}

/**
 * Index of the word being spoken at the given time: the last word that has started.
 */
function wordAt(time) {
    const timings = reader.timings;
    let low = 0;
    let high = timings.length / 2 - 1;
    let found = -1;

    while (low <= high) {
        const mid = (low + high) >> 1;

        if (timings[mid * 2] <= time) {
            found = mid;
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }

    return found;
}

function highlightWord({ force = false, scroll = 'smooth' } = {}) {
    if (!isReading() || !reader.timings || reader.key !== currentKey()) {
        return;
    }

    // Pick auto-scroll back up once the listener has stopped scrolling for a while.
    if (reader.pausedUntil && isFollowing()) {
        reader.pausedUntil = 0;
        ui.followButton.classList.add('hidden');
        force = true;
    }

    const index = wordAt(currentTime());

    if (index === reader.current && !force) {
        return;
    }

    const previous = reader.words[reader.current];
    const next = reader.words[index];

    if (previous) {
        previous.classList.remove('is-current');
    }

    if (previous?.parentElement !== next?.parentElement) {
        previous?.parentElement.classList.remove('is-current-block');
        next?.parentElement.classList.add('is-current-block');
    }

    next?.classList.add('is-current');
    reader.current = index;

    if (next && isFollowing()) {
        keepInView(next, scroll);
    }
}

function isFollowing() {
    return Date.now() >= reader.pausedUntil;
}

/**
 * Keep the spoken word around a third of the way down, scrolling only once
 * it drifts out of the comfortable reading band.
 */
function keepInView(word, behavior) {
    const view = readerEl.clientHeight;
    const offset = word.offsetTop - readerEl.scrollTop;

    if (behavior === 'instant' || offset < view * 0.15 || offset > view * 0.6) {
        readerEl.scrollTo({ top: word.offsetTop - view / 3, behavior });
    }
}

function pauseFollowing() {
    reader.pausedUntil = Date.now() + FOLLOW_PAUSE_MS;
    ui.followButton.classList.remove('hidden');
}

function resumeFollowing() {
    reader.pausedUntil = 0;
    ui.followButton.classList.add('hidden');
    highlightWord({ force: true });
}

/**
 * Word timings are finer than "timeupdate" (about 4 per second), so follow
 * along every frame while the text is on screen and the audio is playing.
 */
function syncReaderLoop() {
    const shouldRun = isReading() && !audio.paused && document.visibilityState === 'visible';

    if (shouldRun && reader.frame === null) {
        const tick = () => {
            highlightWord();
            reader.frame = requestAnimationFrame(tick);
        };

        reader.frame = requestAnimationFrame(tick);
    } else if (!shouldRun && reader.frame !== null) {
        cancelAnimationFrame(reader.frame);
        reader.frame = null;
    }
}

// ---------------------------------------------------------------------------
// Playback
// ---------------------------------------------------------------------------

function selectChapter(index, { autoplay = false, position } = {}) {
    if (!chapters[index]) {
        return;
    }

    save(true);
    clearReturn();

    const chapter = chapters[index];
    let start = position ?? state.positions[index] ?? 0;

    // A finished chapter starts over rather than resuming at the very end.
    if (start >= chapter.duration - 2) {
        start = 0;
    }

    state.chapter = index;
    state.pendingSeek = start;
    state.positions[index] = start;

    if (audio.src !== chapter.url) {
        audio.src = chapter.url;
    }

    if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) {
        applyPendingSeek();
    }

    renderChapter();
    renderTime();
    save(true);

    if (autoplay) {
        play();
    }
}

function applyPendingSeek() {
    if (state.pendingSeek !== null) {
        audio.currentTime = state.pendingSeek;
        state.pendingSeek = null;
    }
}

function play() {
    audio.play().catch((error) => {
        if (error.name !== 'AbortError') {
            flash('Tap play to start');
        }
    });
}

function seekTo(seconds) {
    const time = Math.min(Math.max(seconds, 0), duration() - 0.5);

    if (state.pendingSeek !== null) {
        state.pendingSeek = time;
    } else {
        audio.currentTime = time;
    }

    renderTime();
    renderChapterProgress();
    highlightWord({ force: true });
    updatePositionState();
}

/**
 * Where the listener was before jumping with the scrubber or a word. A run of
 * jumps keeps the first spot, so the button always leads back to it.
 */
const returnPoint = {
    chapter: null,
    time: null,
    timeout: null,
};

function jumpTo(seconds) {
    const from = currentTime();

    if (returnPoint.time === null && Math.abs(seconds - from) >= RETURN_MIN_JUMP_SECONDS) {
        returnPoint.chapter = state.chapter;
        returnPoint.time = from;
    }

    seekTo(seconds);
    resumeFollowing();

    if (returnPoint.time !== null) {
        showReturn();
    }
}

function showReturn() {
    ui.returnTime.textContent = formatTime(returnPoint.time);
    ui.returnButton.setAttribute('aria-label', `Go back to ${formatTime(returnPoint.time)}`);
    ui.returnButton.classList.remove('hidden');
    ui.returnMarker.classList.remove('hidden');
    renderReturnMarker();
    clearTimeout(returnPoint.timeout);
    returnPoint.timeout = setTimeout(clearReturn, RETURN_VISIBLE_MS);
}

function clearReturn() {
    clearTimeout(returnPoint.timeout);
    returnPoint.chapter = null;
    returnPoint.time = null;
    ui.returnButton.classList.add('hidden');
    ui.returnMarker.classList.add('hidden');
}

function renderReturnMarker() {
    if (returnPoint.time === null) {
        return;
    }

    const at = Math.min(returnPoint.time / duration(), 1);
    ui.returnMarker.style.setProperty('--at', String(at));

    // Centre the button over the marker (the thumb is 16px wide), without running off either end.
    const width = ui.seek.clientWidth;
    const half = ui.returnButton.offsetWidth / 2;
    const x = 8 + (width - 16) * at;
    ui.returnButton.style.left = `${Math.min(Math.max(x, half), width - half)}px`;
}

function goBack() {
    if (returnPoint.chapter === state.chapter) {
        resumeFollowing();
        seekTo(returnPoint.time);
    }

    clearReturn();
}

function skip(seconds) {
    seekTo(currentTime() + seconds);
}

function previous() {
    if (currentTime() > 5 || state.chapter === 0) {
        seekTo(0);
    } else {
        selectChapter(state.chapter - 1, { autoplay: !audio.paused, position: 0 });
    }
}

function next() {
    const following = followingChapter();

    if (following !== null) {
        selectChapter(following, { autoplay: !audio.paused, position: 0 });
    }
}

// ---------------------------------------------------------------------------
// Media Session (lock screen / headphone controls)
// ---------------------------------------------------------------------------

function updateMediaSession() {
    if (!('mediaSession' in navigator)) {
        return;
    }

    const chapter = chapters[state.chapter];

    navigator.mediaSession.metadata = new MediaMetadata({
        title: chapter.number ? `${book.chapterName === 'Chapter' ? 'Ch.' : book.chapterName} ${chapter.number}: ${chapter.title}` : chapter.title,
        artist: book.author,
        album: book.title,
        artwork: [{ src: book.cover, sizes: '1024x1024', type: 'image/jpeg' }],
    });
}

function updatePositionState() {
    if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState) {
        return;
    }

    try {
        navigator.mediaSession.setPositionState({
            duration: duration(),
            playbackRate: audio.playbackRate,
            position: Math.min(currentTime(), duration()),
        });
    } catch {
        // Some browsers reject state before metadata has loaded.
    }
}

function registerMediaSessionHandlers() {
    if (!('mediaSession' in navigator)) {
        return;
    }

    const handlers = {
        play,
        pause: () => audio.pause(),
        seekbackward: (details) => skip(-(details.seekOffset || BACK_SECONDS)),
        seekforward: (details) => skip(details.seekOffset || FORWARD_SECONDS),
        seekto: (details) => seekTo(details.seekTime),
        previoustrack: previous,
        nexttrack: next,
    };

    for (const [action, handler] of Object.entries(handlers)) {
        try {
            navigator.mediaSession.setActionHandler(action, handler);
        } catch {
            // Action not supported by this browser.
        }
    }
}

// ---------------------------------------------------------------------------
// Offline
// ---------------------------------------------------------------------------

// Must match the cache names in public/sw.js, which serves these to the audio element.
const AUDIO_CACHE = 'audio-v1';
// Used to estimate a chapter's size when the server couldn't report it (about 128 kbps).
const BYTES_PER_SECOND = 16000;

// Chapters are tracked by their key.
const offline = {
    supported: 'serviceWorker' in navigator && 'caches' in window,
    saved: new Set(),
    progress: new Map(),
    queue: [],
    // Whether "Save all" is running.
    all: false,
    running: false,
    controller: null,
    persisted: false,
};

const offlineEls = {
    panel: $('[data-bind="offline"]'),
    status: $('[data-bind="offline-status"]'),
    downloadAll: $('[data-action="download-all"]'),
    remove: $('[data-action="remove-downloads"]'),
};

function chapterBytes(chapter) {
    return chapter.size ?? chapter.duration * BYTES_PER_SECOND;
}

function audioKey(key) {
    return `/offline/audio/${key}`;
}

function allKeys() {
    return chapters.map((chapter, index) => chapterKey(index));
}

async function initOffline() {
    if (!offline.supported) {
        return;
    }

    try {
        await navigator.serviceWorker.register('/sw.js');
        const registration = await navigator.serviceWorker.ready;

        // Save the page and the assets it loaded, so the player opens offline.
        const urls = new Set([location.pathname]);

        for (const entry of performance.getEntriesByType('resource')) {
            const url = new URL(entry.name);

            if (url.origin === location.origin && /^\/(build|images)\/|\.(webmanifest|png|ico)$/.test(url.pathname)) {
                urls.add(url.pathname);
            }
        }

        registration.active?.postMessage({ type: 'cache-shell', urls: [...urls] });

        const cache = await caches.open(AUDIO_CACHE);

        for (const request of await cache.keys()) {
            const key = new URL(request.url).pathname.replace(/^\/offline\/audio\//, '');

            if (chapterAt(key)) {
                offline.saved.add(key);
            }
        }
    } catch {
        offline.supported = false;
    }

    offlineEls.panel.classList.toggle('hidden', !offline.supported);
    renderOffline();

    if (!audio.paused) {
        saveUpcoming();
    }
}

/**
 * Keep the chapter that's playing and the one after it on the device.
 */
function saveUpcoming() {
    const following = followingChapter();

    download([currentKey(), ...(following !== null ? [chapterKey(following)] : [])]);
}

function download(keys) {
    if (!offline.supported) {
        return;
    }

    for (const key of keys) {
        if (chapterAt(key) && !offline.saved.has(key) && !offline.queue.includes(key)) {
            offline.queue.push(key);
        }
    }

    runQueue();
}

async function runQueue() {
    if (offline.running) {
        return;
    }

    offline.running = true;
    renderOffline();

    while (offline.queue.length) {
        const key = offline.queue[0];

        try {
            await downloadChapter(key);
            offline.queue.shift();
        } catch (error) {
            if (error.name === 'AbortError') {
                break;
            }

            if (error.retryAfter) {
                // Rate limited; wait it out and try the same chapter again.
                await new Promise((resolve) => setTimeout(resolve, error.retryAfter * 1000));
                continue;
            }

            // Offline or failed; leave it for the next time it's needed.
            offline.queue.shift();
            offline.progress.delete(key);
            renderOffline();

            if (!navigator.onLine) {
                offline.queue = [];
            }
        }
    }

    offline.running = false;
    offline.all = false;
    offline.controller = null;
    renderOffline();
}

async function downloadChapter(key) {
    if (!offline.persisted) {
        offline.persisted = true;
        // Ask the browser not to clear the downloads when it's short on space.
        navigator.storage?.persist?.().catch(() => {});
    }

    offline.controller = new AbortController();
    offline.progress.set(key, 0);
    renderOffline();

    let response = await fetchAudio(key);

    // The signed URL may have expired; get a fresh one and try once more.
    if (response.status === 403) {
        await refreshUrl(key);
        response = await fetchAudio(key);
    }

    if (response.status === 429) {
        throw Object.assign(new Error('Rate limited'), { retryAfter: Number(response.headers.get('Retry-After')) || 30 });
    }

    if (!response.ok || !response.body) {
        throw new Error(`Download failed: ${response.status}`);
    }

    const total = Number(response.headers.get('Content-Length')) || chapterBytes(chapterAt(key));
    const reader = response.body.getReader();
    const chunks = [];
    let received = 0;

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        chunks.push(value);
        received += value.length;
        offline.progress.set(key, Math.min(received / total, 0.99));
        renderOffline();
    }

    const expected = Number(response.headers.get('Content-Length'));

    if (expected && received !== expected) {
        throw new Error('Download incomplete');
    }

    const blob = new Blob(chunks, { type: 'audio/mpeg' });
    const cache = await caches.open(AUDIO_CACHE);

    await cache.put(
        audioKey(key),
        new Response(blob, { headers: { 'Content-Type': 'audio/mpeg', 'Content-Length': String(blob.size) } }),
    );

    offline.progress.delete(key);
    offline.saved.add(key);

    // The service worker keeps a copy of the text for offline reading.
    if (chapterAt(key).hasText) {
        fetchText(key).catch(() => {});
    }

    renderOffline();
}

function fetchAudio(key) {
    return fetch(chapterAt(key).url, { signal: offline.controller.signal });
}

async function refreshUrl(key) {
    const response = await fetch(`/audio/${key}/url`, { headers: { Accept: 'application/json' } });

    if (response.ok) {
        chapterAt(key).url = (await response.json()).url;
    }

    return response.ok;
}

function downloadAll() {
    offline.all = true;
    download(allKeys());
}

function stopDownloads() {
    offline.queue = [];
    offline.all = false;
    offline.controller?.abort();
    offline.progress.clear();
    renderOffline();
}

async function removeDownloads() {
    stopDownloads();

    const cache = await caches.open(AUDIO_CACHE);

    for (const key of allKeys()) {
        offline.saved.delete(key);
        await cache.delete(audioKey(key));
    }

    renderOffline();
}

function renderOffline() {
    if (!offline.supported) {
        return;
    }

    const keys = allKeys();
    const saved = keys.filter((key) => offline.saved.has(key));
    const remaining = keys.filter((key) => !offline.saved.has(key));
    const current = offline.queue.find((key) => offline.progress.has(key));

    if (current !== undefined) {
        offlineEls.status.textContent = `Saving “${chapterAt(current).title}”… ${Math.round(offline.progress.get(current) * 100)}%`;
    } else if (remaining.length === 0) {
        offlineEls.status.textContent = `All ${book.chapterNamePlural.toLowerCase()} saved for offline`;
    } else if (saved.length) {
        offlineEls.status.textContent = `${saved.length} of ${keys.length} saved for offline`;
    } else {
        offlineEls.status.textContent = 'Listen without a connection';
    }

    const megabytes = Math.round(remaining.reduce((sum, key) => sum + chapterBytes(chapterAt(key)), 0) / 1e6);

    offlineEls.downloadAll.textContent = saved.length ? `Save the rest (${megabytes} MB)` : `Save all (${megabytes} MB)`;
    offlineEls.downloadAll.classList.toggle('hidden', remaining.length === 0 || offline.all);
    offlineEls.remove.textContent = offline.all ? 'Stop' : 'Remove';
    offlineEls.remove.classList.toggle('hidden', !offline.all && saved.length === 0);
}

// ---------------------------------------------------------------------------
// Chapter sheet
// ---------------------------------------------------------------------------

function chapterPosition(index) {
    return index === state.chapter ? currentTime() : state.positions[index] ?? 0;
}

/**
 * Expand a chapter's row to show its actions, collapsing any other, or
 * collapse it if it's already expanded.
 */
function toggleChapterActions(button) {
    const expand = button.getAttribute('aria-expanded') !== 'true';

    collapseChapterActions();

    if (expand) {
        const actions = document.getElementById(button.getAttribute('aria-controls'));

        actions.querySelector('[data-action="reset-progress"]').disabled =
            chapterPosition(Number(button.dataset.chapterOptions)) < 1;
        actions.classList.remove('hidden');
        button.setAttribute('aria-expanded', 'true');
    }
}

function collapseChapterActions() {
    document.querySelectorAll('[data-chapter-options][aria-expanded="true"]').forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
        document.getElementById(button.getAttribute('aria-controls')).classList.add('hidden');
    });
}

/**
 * Start a chapter over from the beginning, the next time it's played or now
 * if it's the one that's loaded.
 */
function resetChapter(index) {
    if (index === state.chapter) {
        seekTo(0);
    }

    state.positions[index] = 0;

    save(true);
    renderTime();
    renderChapterProgress();
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------

audio.addEventListener('loadedmetadata', () => {
    applyPendingSeek();
    renderTime();
    highlightWord({ force: true });
    updatePositionState();
});

audio.addEventListener('timeupdate', () => {
    renderTime();
    renderChapterProgress();
    highlightWord();
    save();
});

audio.addEventListener('progress', renderTime);
audio.addEventListener('play', () => {
    renderPlayState();
    syncReaderLoop();
    saveUpcoming();
});
audio.addEventListener('pause', () => {
    renderPlayState();
    syncReaderLoop();
    save(true);
});

audio.addEventListener('waiting', () => {
    state.loading = true;
    renderPlayState();
});

for (const event of ['playing', 'canplay', 'seeked']) {
    audio.addEventListener(event, () => {
        state.loading = false;
        renderPlayState();
        updatePositionState();
    });
}

audio.addEventListener('ended', () => {
    state.positions[state.chapter] = chapters[state.chapter].duration;

    const following = followingChapter();

    if (following !== null) {
        selectChapter(following, { autoplay: true, position: 0 });
    } else {
        save(true);
        flash('The end');
    }
});

// Audio URLs are signed and expire, so on failure fetch a fresh one and retry once.
const refreshedChapters = new Set();

audio.addEventListener('error', async () => {
    const key = currentKey();
    const wasPlaying = state.loading || !audio.paused;

    if (!refreshedChapters.has(key)) {
        refreshedChapters.add(key);

        try {
            if (await refreshUrl(key)) {
                if (currentKey() === key) {
                    selectChapter(state.chapter, { autoplay: wasPlaying, position: currentTime() });
                }

                return;
            }
        } catch {
            // Fall through to the error message.
        }
    }

    state.loading = false;
    renderPlayState();
    flash('Couldn’t load audio');
});

audio.addEventListener('loadeddata', () => refreshedChapters.delete(currentKey()));

ui.seek.addEventListener('input', () => {
    state.scrubbing = true;
    renderTime();
});

ui.seek.addEventListener('change', () => {
    state.scrubbing = false;
    jumpTo(Number(ui.seek.value));
});

document.addEventListener('click', (event) => {
    const action = event.target.closest('[data-action]')?.dataset.action;
    const chapterRow = event.target.closest('[data-chapter]');
    const optionsButton = event.target.closest('[data-chapter-options]');
    const word = event.target.closest('[data-w]');

    if (word && reader.timings) {
        // Selecting text shouldn't also jump the audio.
        if (!window.getSelection()?.isCollapsed) {
            return;
        }

        jumpTo(reader.timings[Number(word.dataset.w) * 2]);

        return;
    }

    if (optionsButton) {
        toggleChapterActions(optionsButton);

        return;
    }

    if (chapterRow) {
        const index = Number(chapterRow.dataset.chapter);

        if (index === state.chapter) {
            play();
        } else {
            selectChapter(index, { autoplay: true });
        }

        sheet.close();

        return;
    }

    switch (action) {
        case 'toggle':
            audio.paused ? play() : audio.pause();
            break;
        case 'back':
            skip(-BACK_SECONDS);
            break;
        case 'forward':
            skip(FORWARD_SECONDS);
            break;
        case 'prev':
            previous();
            break;
        case 'next':
            next();
            break;
        case 'text':
            toggleView();
            break;
        case 'follow':
            resumeFollowing();
            break;
        case 'return':
            goBack();
            break;
        case 'text-smaller':
            changeTextSize(-1);
            break;
        case 'text-larger':
            changeTextSize(1);
            break;
        case 'download-all':
            downloadAll();
            break;
        case 'remove-downloads':
            offline.all ? stopDownloads() : removeDownloads();
            break;
        case 'reset-progress': {
            const options = event.target.closest('li').querySelector('[data-chapter-options]');

            resetChapter(Number(options.dataset.chapterOptions));
            collapseChapterActions();
            options.focus();
            break;
        }
        case 'open-chapters':
            renderOffline();
            renderChapterProgress();
            sheet.showModal();
            break;
    }
});

// Reading ahead or back pauses auto-scroll for a moment.
for (const event of ['wheel', 'touchmove']) {
    readerEl.addEventListener(event, pauseFollowing, { passive: true });
}

readerEl.addEventListener('keydown', (event) => {
    if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End'].includes(event.code)) {
        pauseFollowing();
    }
});

// Close the sheet when tapping the backdrop.
sheet.addEventListener('click', (event) => {
    if (event.target === sheet) {
        sheet.close();
    }
});

sheet.addEventListener('close', collapseChapterActions);

document.addEventListener('keydown', (event) => {
    if (sheet.open || !chapters.length || event.target.matches('input, textarea')) {
        return;
    }

    if (event.code === 'Space') {
        event.preventDefault();
        audio.paused ? play() : audio.pause();
    } else if (event.code === 'ArrowLeft') {
        skip(-BACK_SECONDS);
    } else if (event.code === 'ArrowRight') {
        skip(FORWARD_SECONDS);
    }
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        save(true);
    }

    syncReaderLoop();
});

window.addEventListener('pagehide', () => save(true));
window.addEventListener('resize', renderReturnMarker);

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

// Until the audio has been added, the book is only listed as coming soon.
if (chapters.length) {
    load();
    renderTextSize();
    registerMediaSessionHandlers();
    renderPlayState();
    selectChapter(state.chapter);
    state.booted = true;
    initOffline();
}
