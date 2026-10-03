# YouTube Live beta

Radio 0.6.1 can optionally send selected scheduled programmes to YouTube Live without changing the existing browser Studio, public player or Automatic Radio behaviour.

## Scope

The beta supports:

- manual testing with one Radio programme;
- scheduled YouTube slots based on existing Radio schedule entries;
- server-side FFmpeg execution;
- RTMP or RTMPS ingest;
- 720p or 1080p lightweight black video;
- AAC audio at 96–192 kbit/s;
- private stream-key storage;
- automatic FFmpeg start/stop reconciliation by a CLI worker.

Current beta limitation: every media item in a YouTube programme must be a local Radio file. External/live media sources are rejected instead of silently producing an incomplete broadcast.

The YouTube worker is independent from the current browser playback. It does not yet replace the Radio engine or provide 24/7 Automatic Radio output.

## Requirements

The server needs:

- PHP CLI compatible with the Radio installation;
- FFmpeg available as `ffmpeg` in the worker user's PATH;
- permission for the worker user to read the Radio private media storage;
- outbound RTMPS access to YouTube.

Check FFmpeg:

```sh
command -v ffmpeg
ffmpeg -version
```

### Installing FFmpeg without root access

On shared hosting, FFmpeg may not be installed globally and the account may not have `sudo` access. A static x86_64 build can be installed in the account's own `~/bin` directory, provided the hosting provider allows user binaries.

Example for a Linux x86_64 account:

```sh
mkdir -p ~/bin
cd /tmp
wget https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
```

If `tar -xJf` works:

```sh
tar -xJf ffmpeg-release-amd64-static.tar.xz
```

Some restricted hosting environments block the external `xz` executable. If that happens and Python 3 has the `lzma` module:

```sh
python3 - <<'PY'
import lzma, shutil
src = "/tmp/ffmpeg-release-amd64-static.tar.xz"
dst = "/tmp/ffmpeg-release-amd64-static.tar"
with lzma.open(src, "rb") as f_in, open(dst, "wb") as f_out:
    shutil.copyfileobj(f_in, f_out)
print(dst)
PY
tar -xf /tmp/ffmpeg-release-amd64-static.tar
```

Then install the binaries:

```sh
cp /tmp/ffmpeg-*-amd64-static/ffmpeg ~/bin/
cp /tmp/ffmpeg-*-amd64-static/ffprobe ~/bin/
chmod 755 ~/bin/ffmpeg ~/bin/ffprobe
command -v ffmpeg
ffmpeg -version
```

The worker must be able to find the same `ffmpeg` executable when run from cron. If `~/bin` is not in the cron PATH, add it to the cron environment or use a wrapper that exports the required PATH.

FFmpeg must include at least the H.264 (`libx264`) and AAC encoders and RTMP/RTMPS protocol support.

## Configure YouTube

In Geeklog administration open **Radio → YouTube Live**.

1. Enable YouTube Live output.
2. Keep the default server:
   `rtmps://a.rtmps.youtube.com/live2`
3. Paste the YouTube stream key.
4. Choose **Scheduled slots** or **Manual test**.
5. For scheduled mode, select the existing Radio schedule entries that must also be sent to YouTube.
6. Save.

The stream key is stored in the standard Geeklog Radio configuration. Treat it like a password. It can also appear in the FFmpeg process command line on systems where users can inspect running processes, so rotate it if it has been exposed.

## Run the worker

The administration page displays the exact command for the current Geeklog installation.

Generic form:

```sh
php /path/to/private/plugins/radio/bin/youtube-live.php --geeklog-root=/path/to/public_html --host=example.com
```

On a multisite installation, `--host` is required so Geeklog can select the correct site configuration before `lib-common.php` is loaded. The administration page includes the current site's host automatically in the displayed command.

A single run reconciles the desired state:

- starts FFmpeg if a selected YouTube slot is active;
- leaves the matching FFmpeg process running if already active;
- stops FFmpeg when the slot ends or no manual broadcast is requested;
- replaces the process if the target programme changes.

For scheduled operation, run it once per minute:

```cron
* * * * * php /path/to/private/plugins/radio/bin/youtube-live.php --geeklog-root=/path/to/public_html --host=example.com >/dev/null 2>&1
```

The first/last minute of a scheduled YouTube slot can therefore have up to roughly one minute of scheduler latency. A future daemon/service mode can reduce this without changing the scheduling model.

## Manual test

1. Create or select a programme containing local audio media.
2. Open **Radio → YouTube Live**.
3. Enable YouTube Live output.
4. Select **Manual test**.
5. Select the programme.
6. Save the configuration.
7. Click **Request start**.
8. Run the worker command once.
9. Confirm the ingest in YouTube Studio.
10. Click **Request stop** and run the worker once again.

The worker status and last error are shown on the Radio YouTube administration page. FFmpeg output is written to `youtube-live.log` in Radio's media storage directory (the same storage area that contains `youtube-live.ffconcat`).

## Security

The stream key is never rendered back into the administration form. Radio stores the YouTube configuration file with restrictive file permissions when the operating system permits it.

As with any FFmpeg RTMP process, the destination URL may be visible to privileged users who can inspect process arguments on the server. Use normal server account isolation and rotate the YouTube stream key if it may have been exposed.

## Future work

Planned follow-up work can add:

- station artwork and current-title overlays;
- server-side transition parity with Radio;
- Automatic Radio / 24×7 server engine;
- a persistent systemd/Supervisor worker instead of cron;
- YouTube Data API creation of scheduled broadcast events;
- additional RTMP providers.


## Video output and YouTube bitrate warning

The current beta intentionally generates a simple black video frame while streaming the Radio programme audio. This keeps the first implementation independent from browser playback and avoids requiring a separate video source.

For 1280×720 output, Radio defaults to a 2500k video bitrate and uses constant-rate H.264 settings so that YouTube does not interpret the static black image as an abnormally low-bitrate stream. Audio defaults to 128k AAC.

A future version may replace the black frame with a configurable station image, artwork, or Now Playing display.


## Station card and Now Playing overlay

The YouTube output uses a lightweight generated station card instead of a full video source. The current layout contains:

- the Geeklog site/station name;
- the active Radio programme title;
- the current media title.

FFmpeg renders the card over a dark background with the `drawtext` filter. The worker writes the display text into private Radio storage files and FFmpeg reads them with `reload=1`, so the text can change without restarting the YouTube stream.

When the worker is run from cron once per minute, the Now Playing display is refreshed on each worker pass and may therefore lag a track change by up to about one minute.

Verify that the installed FFmpeg build supports the required filter:

```sh
ffmpeg -filters | grep drawtext
```

The static build installation described above normally includes the required FreeType/fontconfig support. If `drawtext` is unavailable, the YouTube process will fail and the FFmpeg log will contain the corresponding filter error.

The generated overlay text files are stored beside the other Radio YouTube runtime files, for example `youtube-live-station.txt`, `youtube-live-program.txt`, and `youtube-live-track.txt`.


## Automatic video visualization fallback

Radio detects the filters supported by the FFmpeg binary used by the worker and selects a safe video mode automatically:

1. `drawtext`: station card with station name, programme title, and current media title;
2. `showwaves`: animated audio waveform when `drawtext` is unavailable;
3. `showspectrum`: animated audio spectrum when `showwaves` is unavailable;
4. a simple dark color frame as the final fallback.

This prevents a missing optional FFmpeg filter from stopping the YouTube Live stream.

The worker also uses the exact FFmpeg executable found with `command -v ffmpeg`, which is important on shared hosting and cron environments where FFmpeg may be installed in a user directory such as `~/bin`.
