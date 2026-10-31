<?php
/**
 * Reads ID3 tags and durations from audio files on the server.
 * Shared by playlists.php (all tracks) and index.php (the one track
 * of a shared link, for its link preview).
 */

const AUDIO_EXTENSIONS = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'flac', 'opus'];

/**
 * Decodes a 4-byte "syncsafe" (28-bit) integer used in ID3v2 headers.
 */
function syncsafe4($bytes)
{
    return ((ord($bytes[0]) & 0x7F) << 21) | ((ord($bytes[1]) & 0x7F) << 14) |
        ((ord($bytes[2]) & 0x7F) << 7) | (ord($bytes[3]) & 0x7F);
}

function toUtf8($text, $fromEncoding)
{
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', $fromEncoding);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $text;
}

/**
 * Decodes ID3v2 text content. The first byte is the encoding:
 * 0 = ISO-8859-1, 1 = UTF-16 with BOM, 2 = UTF-16BE, 3 = UTF-8.
 */
function decodeId3Text($encodingByte, $text)
{
    $text = rtrim($text, "\0");
    switch ($encodingByte) {
        case 0:
            return toUtf8($text, 'ISO-8859-1');
        case 1:
            if (substr($text, 0, 2) === "\xFF\xFE") {
                return toUtf8(substr($text, 2), 'UTF-16LE');
            }
            if (substr($text, 0, 2) === "\xFE\xFF") {
                return toUtf8(substr($text, 2), 'UTF-16BE');
            }
            return toUtf8($text, 'UTF-16');
        case 2:
            return toUtf8($text, 'UTF-16BE');
        default:
            return $text;
    }
}

/**
 * Reads title/artist/album from an ID3v2 tag at the start of the file.
 * Sets $audioStart to the byte offset where the audio data begins.
 */
function readId3v2($fh, &$audioStart)
{
    $tags = ['title' => null, 'artist' => null, 'album' => null];
    $audioStart = 0;

    $header = fread($fh, 10);
    if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
        return $tags;
    }

    $major = ord($header[3]);
    $flags = ord($header[5]);
    $tagSize = syncsafe4(substr($header, 6, 4));
    $audioStart = 10 + $tagSize;

    // Unknown version or unsynchronization: rather than get it wrong,
    // report no tags (the browser falls back to the file name).
    if ($major < 2 || $major > 4 || ($flags & 0x80)) {
        return $tags;
    }

    // Read the tag body, bounded to 1 MB so huge embedded album art
    // cannot blow up memory. Text frames usually come first.
    $body = fread($fh, min($tagSize, 1048576));
    $len = strlen($body);

    $ids = $major === 2
        ? ['TT2' => 'title', 'TP1' => 'artist', 'TAL' => 'album']
        : ['TIT2' => 'title', 'TPE1' => 'artist', 'TALB' => 'album'];
    $idLen = $major === 2 ? 3 : 4;
    $frameHeaderLen = $major === 2 ? 6 : 10;

    $pos = 0;
    while ($pos + $frameHeaderLen <= $len) {
        if (ord($body[$pos]) === 0) {
            break; // padding after the last frame
        }
        $frameId = substr($body, $pos, $idLen);
        if ($major === 2) {
            $size =
                (ord($body[$pos + 3]) << 16) |
                (ord($body[$pos + 4]) << 8) |
                ord($body[$pos + 5]);
        } elseif ($major === 4) {
            $size = syncsafe4(substr($body, $pos + 4, 4));
        } else {
            $size = unpack('N', substr($body, $pos + 4, 4))[1];
        }
        if ($size <= 0 || $pos + $frameHeaderLen + $size > $len) {
            break;
        }
        $field = $ids[$frameId] ?? null;
        if ($field !== null && $tags[$field] === null) {
            $content = substr($body, $pos + $frameHeaderLen, $size);
            if (strlen($content) >= 2) {
                $value = decodeId3Text(ord($content[0]), substr($content, 1));
                if ($value !== '') {
                    $tags[$field] = $value;
                }
            }
        }
        $pos += $frameHeaderLen + $size;
    }

    return $tags;
}

/**
 * Reads title/artist/album from an ID3v1 tag (last 128 bytes).
 */
function readId3v1($fh, $fileSize)
{
    if ($fileSize < 128) {
        return null;
    }
    fseek($fh, -128, SEEK_END);
    $tag = fread($fh, 128);
    if (substr($tag, 0, 3) !== 'TAG') {
        return null;
    }
    return [
        'title' => trim(toUtf8(substr($tag, 3, 30), 'ISO-8859-1')),
        'artist' => trim(toUtf8(substr($tag, 33, 30), 'ISO-8859-1')),
        'album' => trim(toUtf8(substr($tag, 63, 30), 'ISO-8859-1')),
    ];
}

/**
 * Estimates the duration (seconds) of an MP3 from its first frame
 * header. Accurate for CBR files and for VBR files that carry a
 * Xing/Info header with a frame count; otherwise a CBR estimate.
 */
function mp3Duration($fh, $audioStart, $fileSize, $hasId3v1)
{
    fseek($fh, $audioStart);
    $window = fread($fh, 65536);
    $len = strlen($window);

    $sampleRates = [ // by version bits: 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
        3 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        0 => [11025, 12000, 8000],
    ];
    $bitrates = [ // kbps, by version: 3 = MPEG1, 2 = MPEG2/2.5; by layer
        3 => [
            3 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
            2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
            1 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        ],
        2 => [
            3 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
            2 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
            1 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        ],
    ];

    $i = 0;
    while ($i + 4 <= $len) {
        if (ord($window[$i]) !== 0xFF || (ord($window[$i + 1]) & 0xE0) !== 0xE0) {
            $i++;
            continue;
        }
        $b1 = ord($window[$i + 1]);
        $b2 = ord($window[$i + 2]);
        $b3 = ord($window[$i + 3]);

        $versionBits = ($b1 >> 3) & 3;
        $layerBits = ($b1 >> 1) & 3;
        $bitrateIdx = ($b2 >> 4) & 0xF;
        $sampleIdx = ($b2 >> 2) & 3;
        if (
            $versionBits === 1 || $layerBits === 0 || $bitrateIdx === 0 ||
            $bitrateIdx === 15 || $sampleIdx === 3
        ) {
            $i++; // invalid frame candidate, keep scanning
            continue;
        }

        $sampleRate = $sampleRates[$versionBits][$sampleIdx];
        $tableVersion = $versionBits === 3 ? 3 : 2;
        $bitrateKbps = $bitrates[$tableVersion][$layerBits][$bitrateIdx - 1];

        // Samples per frame, needed to convert a frame count to seconds
        $samplesPerFrame = 0;
        if ($layerBits === 3) {
            $samplesPerFrame = 384; // Layer 1
        } elseif ($layerBits === 2) {
            $samplesPerFrame = 1152; // Layer 2
        } else {
            // Layer 3: half-size frames at low sample rates
            $samplesPerFrame = $versionBits === 3 ? 1152 : 576;
        }

        // VBR: a Xing/Info header sits right after this frame's side info
        $mono = (($b3 >> 6) & 3) === 3;
        $sideInfo = $versionBits === 3
            ? ($mono ? 17 : 32)
            : ($mono ? 9 : 17);
        $xingPos = $i + 4 + $sideInfo;
        if ($xingPos + 8 <= $len) {
            $marker = substr($window, $xingPos, 4);
            if ($marker === 'Xing' || $marker === 'Info') {
                $xingFlags = unpack('N', substr($window, $xingPos + 4, 4))[1];
                if ($xingFlags & 1 && $xingPos + 12 <= $len) {
                    $frames = unpack('N', substr($window, $xingPos + 8, 4))[1];
                    if ($frames > 0) {
                        return round($frames * $samplesPerFrame / $sampleRate, 3);
                    }
                }
            }
        }

        // CBR estimate: audio bytes / bitrate
        $audioBytes = $fileSize - $audioStart - ($hasId3v1 ? 128 : 0);
        if ($audioBytes > 0 && $bitrateKbps > 0) {
            return round($audioBytes * 8 / ($bitrateKbps * 1000), 3);
        }
        return null;
    }
    return null;
}

/**
 * Reads the duration (seconds) of a canonical RIFF/WAVE file from its
 * header byte rate.
 */
function wavDuration($fh, $fileSize)
{
    rewind($fh);
    $header = fread($fh, 44);
    if (strlen($header) < 44 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
        return null;
    }
    $byteRate = unpack('V', substr($header, 28, 4))[1];
    if ($byteRate <= 0 || $byteRate > 100000000) {
        return null;
    }
    return round($fileSize / $byteRate, 3);
}

/**
 * Reads tags and duration for one audio file. MP3 gets full ID3 tag
 * and duration parsing; WAV gets a duration from its header; other
 * formats are reported without metadata and the browser shows the
 * file name for them.
 */
function readAudioInfo($path, $extension)
{
    $info = [];
    if (!is_readable($path)) {
        return $info;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return $info;
    }
    $fileSize = filesize($path);

    if ($extension === 'mp3') {
        rewind($fh);
        $audioStart = 0;
        $tags = readId3v2($fh, $audioStart);
        $id3v1 = readId3v1($fh, $fileSize);
        if ($id3v1 !== null) {
            foreach (['title', 'artist', 'album'] as $field) {
                if (empty($tags[$field]) && $id3v1[$field] !== '') {
                    $tags[$field] = $id3v1[$field];
                }
            }
        }
        foreach ($tags as $field => $value) {
            if ($value !== null && $value !== '') {
                $info[$field] = $value;
            }
        }
        $duration = mp3Duration($fh, $audioStart, $fileSize, $id3v1 !== null);
        if ($duration !== null && $duration > 0) {
            $info['duration'] = $duration;
        }
    } elseif ($extension === 'wav') {
        $duration = wavDuration($fh, $fileSize);
        if ($duration !== null && $duration > 0) {
            $info['duration'] = $duration;
        }
    }

    fclose($fh);
    return $info;
}
