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
ffmpeg -version
```

## Configure YouTube

In Geeklog administration open **Radio → YouTube Live**.

1. Enable YouTube Live output.
2. Keep the default server:
   `rtmps://a.rtmps.youtube.com/live2`
3. Paste the YouTube stream key.
4. Choose **Scheduled slots** or **Manual test**.
5. For scheduled mode, select the existing Radio schedule entries that must also be sent to YouTube.
6. Save.

The saved stream key is kept in Radio private storage and is not shown back in the form.

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

The worker status and last error are shown on the Radio YouTube administration page. FFmpeg output is written to `youtube-live.log` in Radio private storage.

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
