(function () {
    'use strict';

    function q(selector, root) {
        return (root || document).querySelector(selector);
    }

    function postEvent(endpoint, mediaId, programId, eventType, seconds) {
        if (!endpoint || !mediaId) {
            return;
        }
        var body = new URLSearchParams();
        body.set('media_id', mediaId);
        body.set('program_id', programId || 0);
        body.set('event_type', eventType);
        body.set('source', 'persistent');
        body.set('seconds', Math.max(0, Math.round(seconds || 0)));

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, body);
            return;
        }
        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            keepalive: true
        }).catch(function () {});
    }

    function waveform(canvas, audio) {
        var context = null;
        var analyser = null;
        var source = null;
        var data = null;
        var fallback = false;
        var external = false;

        function setup() {
            if (context || fallback || external) {
                return;
            }
            try {
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) {
                    fallback = true;
                    return;
                }
                context = new AudioContext();
                source = context.createMediaElementSource(audio);
                analyser = context.createAnalyser();
                analyser.fftSize = 128;
                analyser.smoothingTimeConstant = 0.72;
                data = new Uint8Array(analyser.fftSize);
                source.connect(analyser);
                analyser.connect(context.destination);
            } catch (error) {
                fallback = true;
            }
        }

        function drawFallback(ctx, width, height) {
            var points = 36;
            var t = Date.now() / 180;
            ctx.beginPath();
            for (var i = 0; i < points; i++) {
                var x = i * width / (points - 1);
                var envelope = Math.sin(Math.PI * i / (points - 1));
                var y = height / 2
                    + Math.sin(t + i * .58) * envelope * height * .23
                    + Math.sin(t * .5 + i * .2) * height * .045;
                if (i === 0) {
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
            }
            ctx.stroke();
        }

        function draw() {
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }
            var width = canvas.width;
            var height = canvas.height;
            ctx.clearRect(0, 0, width, height);
            ctx.strokeStyle = window.getComputedStyle(canvas).color || '#000';
            ctx.lineWidth = 1.5;

            if (audio.paused) {
                ctx.beginPath();
                ctx.moveTo(0, height / 2);
                ctx.lineTo(width, height / 2);
                ctx.stroke();
            } else if (analyser && data && !external) {
                analyser.getByteTimeDomainData(data);
                ctx.beginPath();
                for (var i = 0; i < data.length; i++) {
                    var x = i * width / (data.length - 1);
                    var y = (data[i] / 255) * height;
                    if (i === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                }
                ctx.stroke();
            } else {
                drawFallback(ctx, width, height);
            }
            window.requestAnimationFrame(draw);
        }

        return {
            setExternal: function (value) {
                external = !!value;
                if (external) {
                    fallback = true;
                }
            },
            start: function () {
                setup();
                if (context && context.state === 'suspended') {
                    context.resume().catch(function () {});
                }
            },
            draw: draw
        };
    }

    function init() {
        var root = q('[data-radio-player]');
        if (!root) {
            return;
        }

        var endpoint = root.getAttribute('data-now-endpoint');
        var eventEndpoint = root.getAttribute('data-event-endpoint');
        var emptyLabel = root.getAttribute('data-empty-label') || 'Nothing is on air right now.';
        var listenLabel = root.getAttribute('data-listen-label') || 'Listen';
        var pauseLabel = root.getAttribute('data-pause-label') || 'Pause';
        var audio = q('[data-radio-player-audio]', root);
        var button = q('[data-radio-player-play]', root);
        var title = q('[data-radio-player-title]', root);
        var program = q('[data-radio-player-program]', root);
        var canvas = q('[data-radio-player-scope]', root);
        var wave = waveform(canvas, audio);

        var mediaId = 0;
        var programId = 0;
        var wantedPlaying = false;
        var listenStart = 0;
        var endedAt = 0;
        var endedMediaId = 0;
        var endedRetryCount = 0;
        var transitionTimer = 0;
        var transitionProgramId = 0;
        var transitionRetryCount = 0;

        function updateButton() {
            button.textContent = audio.paused ? listenLabel : pauseLabel;
        }

        function flush() {
            if (!listenStart) {
                return;
            }
            postEvent(eventEndpoint, mediaId, programId, 'listen', (Date.now() - listenStart) / 1000);
            listenStart = 0;
        }

        function scheduleTransition(data) {
            if (transitionTimer) {
                window.clearTimeout(transitionTimer);
                transitionTimer = 0;
            }
            transitionProgramId = 0;
            if (!data || data.source === 'schedule' || !data.upcoming || !data.upcoming.length) {
                return;
            }
            var serverNow = Date.parse(data.generated_at || '');
            var nextStart = Date.parse(data.upcoming[0].start || '');
            if (!isFinite(serverNow) || !isFinite(nextStart) || nextStart <= serverNow) {
                return;
            }
            var delay = nextStart - serverNow;
            if (delay > 86400000) {
                return;
            }
            transitionProgramId = parseInt(String(data.upcoming[0].program_id || '').replace('program:', ''), 10) || 0;
            transitionTimer = window.setTimeout(function () {
                transitionTimer = 0;
                sync(wantedPlaying, false, transitionProgramId);
            }, Math.max(0, delay));
        }

        function apply(data, autoplay, fromEnded, expectedProgramId) {
            scheduleTransition(data);

            if (!data.current_media) {
                flush();
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                mediaId = 0;
                programId = 0;
                title.textContent = emptyLabel;
                program.textContent = '';
                updateButton();
                return;
            }

            var nextMediaId = parseInt(String(data.current_media.media_id || '').replace('media:', ''), 10) || 0;
            var nextProgramId = data.now_playing
                ? (parseInt(String(data.now_playing.program_id || '').replace('program:', ''), 10) || 0)
                : 0;

            if (expectedProgramId > 0 && nextProgramId !== expectedProgramId && transitionRetryCount < 20) {
                transitionRetryCount++;
                window.setTimeout(function () {
                    sync(autoplay, false, expectedProgramId);
                }, 250);
                return;
            }

            if (expectedProgramId > 0 && nextProgramId === expectedProgramId) {
                transitionRetryCount = 0;
            }

            var streamUrl = data.current_media.stream_url || '';
            var offset = parseInt(data.current_media.offset || 0, 10) || 0;
            if (expectedProgramId > 0 && nextProgramId === expectedProgramId) {
                offset = 0;
            }

            if (fromEnded && nextMediaId === endedMediaId
                && endedAt > 0 && offset >= Math.max(0, endedAt - 2)
                && endedRetryCount < 20) {
                endedRetryCount++;
                window.setTimeout(function () {
                    sync(true, true, 0);
                }, 500);
                return;
            }

            if (fromEnded) {
                endedAt = 0;
                endedMediaId = 0;
                endedRetryCount = 0;
            }

            var changed = mediaId !== nextMediaId || audio.getAttribute('src') !== streamUrl;
            mediaId = nextMediaId;
            programId = nextProgramId;
            title.textContent = data.current_media.title || '';
            program.textContent = data.now_playing ? (data.now_playing.title || '') : '';
            wave.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

            function seekAndPlay() {
                try {
                    audio.currentTime = Math.max(0, offset);
                } catch (error) {}
                if (autoplay && wantedPlaying) {
                    wave.start();
                    audio.play().catch(function () {
                        updateButton();
                    });
                }
            }

            if (changed) {
                flush();
                audio.src = streamUrl;
                audio.load();
                if (audio.readyState >= 1) {
                    seekAndPlay();
                } else {
                    audio.addEventListener('loadedmetadata', function once() {
                        audio.removeEventListener('loadedmetadata', once);
                        seekAndPlay();
                    });
                }
                return;
            }

            if (!audio.paused && Math.abs(audio.currentTime - offset) > 5) {
                try {
                    audio.currentTime = offset;
                } catch (error) {}
            }

            if (autoplay && wantedPlaying && audio.paused) {
                wave.start();
                audio.play().catch(function () {
                    updateButton();
                });
            }
        }

        function sync(autoplay, fromEnded, expectedProgramId) {
            fetch(endpoint, {cache: 'no-store', credentials: 'same-origin'})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    apply(data, autoplay, !!fromEnded, expectedProgramId || 0);
                })
                .catch(function () {
                    updateButton();
                });
        }

        button.addEventListener('click', function () {
            if (!audio.paused) {
                wantedPlaying = false;
                audio.pause();
                return;
            }
            wantedPlaying = true;
            wave.start();
            sync(true, false, 0);
        });

        audio.addEventListener('play', function () {
            updateButton();
            if (mediaId) {
                postEvent(eventEndpoint, mediaId, programId, 'play', 0);
                listenStart = Date.now();
            }
        });
        audio.addEventListener('pause', function () {
            flush();
            updateButton();
        });
        audio.addEventListener('ended', function () {
            flush();
            if (wantedPlaying) {
                endedAt = audio.currentTime || 0;
                endedMediaId = mediaId;
                endedRetryCount = 0;
                sync(true, true, 0);
            }
        });
        window.addEventListener('pagehide', flush);
        window.setInterval(function () {
            sync(wantedPlaying && !audio.paused, false, 0);
        }, 15000);

        wave.draw();
        sync(false, false, 0);
        updateButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
