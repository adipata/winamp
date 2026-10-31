<?php
/**
 * Lists the playlists for the Webamp deployment.
 *
 * Every subfolder of mp3/ becomes one playlist (named after the folder).
 * For each MP3 we read the ID3 tags (v1 and v2) and estimate the duration
 * on the server, so the browser does not have to download every file
 * just to fill in the playlist. Files are only fetched when played.
 *
 * Called by index.php via fetch("playlists.php").
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/audioinfo.php';

$base = __DIR__ . '/mp3';

if (!is_dir($base)) {
    echo json_encode(['playlists' => []]);
    return;
}

// List the audio files first. Only stat() is needed here, which is much
// cheaper than reading the files, and it gives us the cache signature.
$folders = [];
$signature = '';
foreach (scandir($base) as $folder) {
    // Skip hidden entries and plain files: only folders become playlists
    if ($folder[0] === '.' || !is_dir($base . '/' . $folder)) {
        continue;
    }

    $files = [];
    foreach (scandir($base . '/' . $folder) as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, AUDIO_EXTENSIONS, true)) {
            continue;
        }
        $path = $base . '/' . $folder . '/' . $file;
        $files[$file] = $extension;
        $signature .= $folder . '/' . $file . '|' . @filesize($path) . '|' .
            @filemtime($path) . "\n";
    }
    if ($files !== []) {
        $folders[$folder] = $files;
    }
}

// The cache is reused only while every file still has the same name,
// size and modification time. Adding, removing, renaming or re-tagging a
// file changes the signature and triggers a rebuild. Bump the version
// when the output format of this script changes.
$signature = md5('v1' . "\n" . $signature);
$cacheFile = __DIR__ . '/playlists.cache.json';

$cached = @file_get_contents($cacheFile);
if ($cached !== false) {
    $newline = strpos($cached, "\n");
    if ($newline !== false && substr($cached, 0, $newline) === $signature) {
        echo substr($cached, $newline + 1);
        return;
    }
}

$playlists = [];
foreach ($folders as $folder => $files) {
    $tracks = [];
    foreach ($files as $file => $extension) {
        $tracks[] = ['file' => $file] +
            readAudioInfo($base . '/' . $folder . '/' . $file, $extension);
    }

    usort($tracks, function ($a, $b) {
        return strnatcasecmp($a['file'], $b['file']);
    });
    $playlists[] = ['name' => (string) $folder, 'tracks' => $tracks];
}

usort($playlists, function ($a, $b) {
    return strnatcasecmp($a['name'], $b['name']);
});

$json = json_encode(
    ['playlists' => $playlists],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

// Write to a temporary file and rename it into place, so a concurrent
// request never reads a half-written cache. If the folder is not
// writable, we simply serve the fresh result without caching it.
$tmpFile = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmpFile, $signature . "\n" . $json) !== false) {
    if (!@rename($tmpFile, $cacheFile)) {
        @unlink($tmpFile);
    }
}

echo $json;
