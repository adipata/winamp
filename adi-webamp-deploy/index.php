<?php
/**
 * The Webamp page. A link to it can name a song to load:
 *
 *   ?playlist=<folder in mp3/>&track=<file in that folder>
 *
 * The browser part (below) reads these to load that song, and writes
 * them into the address bar as songs change, so the address can be
 * shared. This PHP part only fills in the page title and the link
 * preview tags (Open Graph) for the named song: services like WhatsApp,
 * Slack or Facebook build their preview from these tags without
 * running any JavaScript.
 */

require __DIR__ . '/audioinfo.php';

/**
 * A single file or folder name from the query string, or null if it is
 * missing or could point outside its folder ("..", slashes).
 */
function nameParam($key)
{
    $value = $_GET[$key] ?? null;
    if (
        !is_string($value) || $value === '' || $value[0] === '.' ||
        strpbrk($value, "/\\\0") !== false
    ) {
        return null;
    }
    return $value;
}

function formatDuration($seconds)
{
    $seconds = (int) round($seconds);
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function h($text)
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Absolute address of this page's folder: preview tags need full URLs
$https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' ||
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$baseUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
    rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

$pageTitle = 'Webamp';
$previewTitle = 'Webamp';
$previewDescription = 'Winamp in the browser. Pick a playlist and press play.';
$pageUrl = $baseUrl;

$playlist = nameParam('playlist');
$track = nameParam('track');
$playlistDir = __DIR__ . '/mp3/' . $playlist;

if ($playlist !== null && is_dir($playlistDir)) {
    $extension = strtolower(pathinfo((string) $track, PATHINFO_EXTENSION));
    if (
        $track !== null && in_array($extension, AUDIO_EXTENSIONS, true) &&
        is_file($playlistDir . '/' . $track)
    ) {
        $info = readAudioInfo($playlistDir . '/' . $track, $extension);
        // Same fallback as the player: file name without extension and
        // leading track number
        $song = $info['title'] ?? preg_replace(
            '/^\s*\d+\s*[-._)]+\s*/',
            '',
            preg_replace('/\.[^.]+$/', '', $track)
        );
        if (!empty($info['artist'])) {
            $song = $info['artist'] . ' - ' . $song;
        }
        $pageTitle = "$song - $playlist - Winamp";
        $previewTitle = $song;
        $previewDescription = "From the playlist \u{201C}$playlist\u{201D}" .
            (isset($info['duration']) ? ' · ' . formatDuration($info['duration']) : '') .
            ' · Play it on Webamp';
        $query = ['playlist' => $playlist, 'track' => $track];
    } else {
        $pageTitle = "$playlist - Winamp";
        $previewTitle = $playlist;
        $previewDescription = 'A playlist on Webamp';
        $query = ['playlist' => $playlist];
    }
    $pageUrl = $baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}
?>
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= h($pageTitle) ?></title>
    <meta name="description" content="<?= h($previewDescription) ?>" />
    <!-- Link preview (Open Graph), for the song named in the address -->
    <meta property="og:site_name" content="Webamp" />
    <meta property="og:type" content="website" />
    <meta property="og:title" content="<?= h($previewTitle) ?>" />
    <meta property="og:description" content="<?= h($previewDescription) ?>" />
    <meta property="og:url" content="<?= h($pageUrl) ?>" />
    <meta property="og:image" content="<?= h($baseUrl . 'winamp.png') ?>" />
    <meta property="og:image:width" content="256" />
    <meta property="og:image:height" content="256" />
    <meta name="twitter:card" content="summary" />
    <style>
      body {
        margin: 0;
        background: url("matrix.jpg") no-repeat center center fixed;
        background-size: cover;
      }
      /* Desktop icons: one per playlist folder */
      #desktop {
        position: absolute;
        top: 0;
        left: 0;
        height: 100vh;
        z-index: -1; /* behind Webamp's windows, like icons behind windows */
        display: flex;
        flex-direction: column;
        flex-wrap: wrap; /* many playlists fill a new column */
        align-content: flex-start;
        padding: 8px;
      }
      .desktop-icon {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 84px;
        margin: 3px;
        padding: 6px 2px;
        background: transparent;
        border: 1px dotted transparent;
        color: #fff;
        font: 12px Tahoma, Verdana, sans-serif;
        text-align: center;
        cursor: default; /* desktop icons get the arrow, not the hand */
        user-select: none; /* double clicks must not select the label text */
      }
      .desktop-icon:hover {
        border-color: rgba(255, 255, 255, 0.5);
      }
      .desktop-icon.selected {
        border-color: #0f0;
        background: rgba(0, 255, 0, 0.18);
      }
      .desktop-icon img {
        display: block;
        width: 48px;
        height: 48px;
        filter: drop-shadow(1px 1px 0 rgba(0, 0, 0, 0.9));
      }
      .desktop-icon .label {
        margin-top: 4px;
        text-shadow: 1px 1px 0 #000, 0 0 4px #000;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word;
      }
      .desktop-status {
        margin: 12px;
        max-width: 140px;
        color: #fff;
        font: 12px Tahoma, Verdana, sans-serif;
        text-shadow: 1px 1px 0 #000, 0 0 4px #000;
      }
      /* On narrow screens the icons become a dock along the bottom,
         so the centered Webamp windows never cover them */
      @media (max-width: 700px) {
        #desktop {
          top: auto;
          bottom: 0;
          left: 0;
          right: 0;
          height: auto;
          flex-direction: row;
          align-content: flex-start;
        }
      }
    </style>
  </head>

  <body>
    <div
      id="app"
      style="position: relative; z-index: 0; height: 100vh"
    >
      <!-- Webamp will render as a child of this div -->
    </div>
    <script type="module">
      import Webamp from "https://unpkg.com/webamp@^2/butterchurn";
      const app = document.getElementById("app");

      // Webamp plays through the Web Audio API (for the EQ and the
      // visualizer). iOS Safari treats Web Audio as "ambient" sound,
      // which the ring/silent switch mutes: the lock screen shows the
      // track progressing but nothing is heard. Declaring the page a
      // media player makes iOS play it even in silent mode (Safari 17+).
      if (navigator.audioSession) {
        navigator.audioSession.type = "playback";
      }

      // On narrow screens (phones) don't open the Milkdrop visualizer
      // window: it is heavy on mobile GPUs and dwarfs the small screen.
      // Omitting it from the layout keeps it closed (Webamp closes any
      // window that is missing from a provided layout). On desktop we
      // pass no layout, keeping Webamp's default: windows stacked on the
      // left, Milkdrop open to their right.
      const isMobile = window.matchMedia("(max-width: 700px)").matches;

      // Created inside the init flow below, once the playlist data has
      // been fetched: the default playlist is passed as initialTracks,
      // which preloads its first song without playing it.
      let webamp;

      // Builds a track object for Webamp from a server-provided track
      // description. playlists.php reads each file's ID3 tags and
      // duration on the server; passing them here means Webamp never
      // has to fetch the files just to fill in the playlist. Files are
      // only downloaded when actually played.
      function trackFromFile(folder, track) {
        // Fallback display name: strip extension and leading track number
        const defaultName = track.file
          .replace(/\.[^.]+$/, "")
          .replace(/^\s*\d+\s*[-._)]+\s*/, "");
        return {
          defaultName,
          // metaData is used instead of fetching the file's ID3 tags.
          // For untagged files the cleaned-up file name becomes the title.
          metaData: {
            artist: track.artist ?? "",
            title: track.title ?? defaultName,
            ...(track.album != null ? { album: track.album } : {}),
          },
          // duration is used instead of probing the file to measure it
          ...(track.duration != null ? { duration: track.duration } : {}),
          url: `mp3/${encodeURIComponent(folder)}/${encodeURIComponent(track.file)}`,
        };
      }

      // Kind of pointer used for the most recent press: "mouse",
      // "touch" or "pen". Touch and pen get single-tap loading.
      let lastPointerType = "mouse";
      document.addEventListener(
        "pointerdown",
        (e) => {
          lastPointerType = e.pointerType;
        },
        true
      );

      // Playlist loaded automatically when the page opens
      const INITIAL_PLAYLIST = "Muzici Patza";

      // Where each track came from, by its absolute URL, so the address
      // bar can name the current song (?playlist=…&track=…)
      const trackSources = new Map();

      function tracksFor(playlist) {
        return playlist.tracks.map((track) => {
          const webampTrack = trackFromFile(playlist.name, track);
          const { artist, title } = webampTrack.metaData;
          trackSources.set(new URL(webampTrack.url, location.href).href, {
            playlist: playlist.name,
            file: track.file,
            song: artist ? `${artist} - ${title}` : title,
          });
          return webampTrack;
        });
      }

      // Puts the current song in the address bar and tab title, so the
      // address can be shared. replaceState rather than pushState: song
      // changes should not pile up as Back-button entries. Files the
      // user drags in have no shareable address and leave it as it is.
      function showSongInAddress(trackInfo) {
        const source =
          trackInfo && trackSources.get(new URL(trackInfo.url, location.href).href);
        if (!source) return;
        const query = new URLSearchParams({
          playlist: source.playlist,
          track: source.file,
        });
        history.replaceState(null, "", `?${query}`);
        document.title = `${source.song} - ${source.playlist} - Winamp`;
      }

      // Guards against a double-tap loading the same playlist twice
      let lastLoaded = null;
      let lastLoadedAt = 0;

      // Tab title shows the loaded playlist
      function setTitleToPlaylist(playlist) {
        document.title = `${playlist.name} - Winamp`;
      }

      function loadPlaylist(playlist) {
        const now = Date.now();
        if (playlist === lastLoaded && now - lastLoadedAt < 400) return;
        lastLoaded = playlist;
        lastLoadedAt = now;
        // Title first: starting the first song then replaces it with
        // the song's title
        setTitleToPlaylist(playlist);
        webamp.setTracksToPlay(tracksFor(playlist));
      }

      function selectIcon(icon) {
        document
          .querySelectorAll(".desktop-icon.selected")
          .forEach((el) => el.classList.remove("selected"));
        icon.classList.add("selected");
      }

      function createDesktopIcon(playlist) {
        const icon = document.createElement("button");
        icon.type = "button";
        icon.className = "desktop-icon";
        icon.title = playlist.name;
        icon.setAttribute("aria-label", `Load playlist ${playlist.name}`);
        icon.innerHTML =
          `<img src="winamp.png" alt="" width="48" height="48" draggable="false">` +
          `<span class="label"></span>`;
        icon.querySelector(".label").textContent = playlist.name;

        icon.addEventListener("click", (e) => {
          const keyboard = e.detail === 0;
          const touch =
            lastPointerType === "touch" || lastPointerType === "pen";
          if (keyboard || touch) {
            // On touch (or keyboard), a single press opens the playlist
            loadPlaylist(playlist);
          }
          selectIcon(icon);
        });
        // Double click (mouse): open the playlist and start playing
        icon.addEventListener("dblclick", () => loadPlaylist(playlist));
        return icon;
      }

      (async () => {
        // Fetch the playlist data first: the default playlist becomes
        // the player's initialTracks, which loads it into the editor
        // and prebuffers the first song so pressing play starts
        // instantly. Nothing is played, so there is nothing for the
        // browser's autoplay policy to block.
        let playlists;
        let loadFailed = false;
        try {
          const response = await fetch("playlists.php");
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          playlists = (await response.json()).playlists;
        } catch {
          loadFailed = true;
          playlists = [];
        }

        // A shared link (?playlist=…&track=…) opens its playlist instead
        // of the default one. Unknown names fall back to the default.
        const params = new URLSearchParams(location.search);
        const sharedIndex = playlists.findIndex(
          (playlist) => playlist.name === params.get("playlist")
        );
        const initialIndex =
          sharedIndex !== -1
            ? sharedIndex
            : playlists.findIndex(
                (playlist) => playlist.name === INITIAL_PLAYLIST
              );

        webamp = new Webamp({
          initialTracks:
            initialIndex !== -1 ? tracksFor(playlists[initialIndex]) : [],
          // Expose the player to the OS media session: track info and
          // play/pause/prev/next controls on the lock screen, Control
          // Center, and hardware media keys. iOS Safari pauses all web
          // audio when the phone locks; this lets it be resumed from
          // the lock screen without unlocking and opening Safari.
          enableMediaSession: true,
          ...(isMobile && {
            windowLayout: {
              main: { position: { left: 0, top: 0 } },
              equalizer: { position: { left: 0, top: 116 } },
              playlist: {
                position: { left: 0, top: 232 },
                size: { extraHeight: 4, extraWidth: 0 },
              },
            },
          }),
        });

        if (initialIndex !== -1) {
          setTitleToPlaylist(playlists[initialIndex]);
        }

        // Make the shared song the current one. Webamp is stopped, so
        // this only loads it: the visitor presses play (browsers would
        // block starting audio before a click anyway).
        // setCurrentTrack() takes a track id despite its documented
        // "index" (Webamp passes it on as an id), so look the id up in
        // the playlist order.
        if (sharedIndex !== -1) {
          const trackIndex = playlists[sharedIndex].tracks.findIndex(
            (track) => track.file === params.get("track")
          );
          const trackId =
            webamp.store?.getState().playlist.trackOrder[trackIndex];
          if (trackIndex > 0 && trackId != null) {
            webamp.setCurrentTrack(trackId);
          }
        }

        webamp.onTrackDidChange(showSongInAddress);

        await webamp.renderInto(app);

        // Append the icon area after Webamp has rendered, so React's
        // initial render does not clear it again.
        const desktop = document.createElement("div");
        desktop.id = "desktop";
        app.appendChild(desktop);

        if (loadFailed || playlists.length === 0) {
          const status = document.createElement("div");
          status.className = "desktop-status";
          status.textContent = loadFailed
            ? "Playlists unavailable (needs PHP server)"
            : "No playlists found in mp3/";
          desktop.appendChild(status);
          return;
        }

        const icons = playlists.map((playlist) => {
          const icon = createDesktopIcon(playlist);
          desktop.appendChild(icon);
          return icon;
        });

        if (initialIndex !== -1) {
          selectIcon(icons[initialIndex]);
        }
      })().catch((e) => console.error("Webamp desktop failed:", e));
    </script>
  </body>
</html>
