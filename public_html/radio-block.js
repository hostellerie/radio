(function () {
    'use strict';

    function q(selector, root) {
        return (root || document).querySelector(selector);
    }

    function bindPersistentPlayerLinks() {
        var links = document.querySelectorAll('.radio-block [data-radio-persistent-player]');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function (event) {
                var href = this.getAttribute('href');
                if (!href) {
                    return;
                }
                event.preventDefault();

                var popup = window.open(
                    '',
                    'radio-player',
                    'width=460,height=520,resizable=yes,scrollbars=yes'
                );
                if (!popup) {
                    window.location.href = href;
                    return;
                }

                var needsNavigation = true;
                try {
                    needsNavigation = !popup.location.href
                        || popup.location.href === 'about:blank';
                } catch (error) {}

                if (needsNavigation) {
                    var inlineAudio = document.querySelector('.radio-block__audio');
                    if (inlineAudio && !inlineAudio.paused) {
                        inlineAudio.pause();
                    }
                    popup.location.href = href;
                }
                popup.focus();
            });
        }
    }

    function postEvent(endpoint, mediaId, programId, eventType, seconds) {
        if (!endpoint || !mediaId) {
            return;
        }

        var body = new URLSearchParams();
        body.set('media_id', mediaId);
        body.set('program_id', programId || 0);
        body.set('event_type', eventType);
        body.set('source', 'live-home');
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

    function createScope(canvas, audio) {
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
                analyser = null;
                data = null;
            }
        }

        function drawFallback(ctx, width, height) {
            var points = 28;
            var t = Date.now() / 180;

            ctx.beginPath();
            for (var i = 0; i < points; i++) {
                var x = i * width / (points - 1);
                var envelope = Math.sin(Math.PI * i / (points - 1));
                var y = height / 2
                    + Math.sin(t + i * .7) * envelope * height * .24
                    + Math.sin(t * .55 + i * .25) * height * .05;

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
            ctx.lineWidth = 1.4;

            if (audio.paused) {
                ctx.beginPath();
                ctx.moveTo(0, height / 2);
                ctx.lineTo(width, height / 2);
                ctx.stroke();
                window.requestAnimationFrame(draw);
                return;
            }

            if (analyser && data && !external) {
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

    function createTransitionManager(audio, hooks) {
        var standby = new Audio();
        standby.preload = 'auto';
        var mode = 'hard';
        var seconds = 0;
        var slotDuration = 0;
        var nextMedia = null;
        var preparedUrl = '';
        var mixing = false;
        var fadeFrame = 0;
        var baseVolume = 1;

        function clearStandby() {
            standby.pause();
            standby.removeAttribute('src');
            standby.load();
            preparedUrl = '';
        }

        function prepare(data) {
            mode = data && data.transition_mode ? data.transition_mode : 'hard';
            seconds = data ? (parseInt(data.transition_seconds || 0, 10) || 0) : 0;
            slotDuration = data && data.current_media
                ? (parseInt(data.current_media.slot_duration || data.current_media.duration || 0, 10) || 0)
                : 0;
            nextMedia = data && data.next_media ? data.next_media : null;

            if (!nextMedia || mode === 'hard' || !nextMedia.stream_url) {
                clearStandby();
                return;
            }

            if (preparedUrl !== nextMedia.stream_url) {
                clearStandby();
                preparedUrl = nextMedia.stream_url;
                standby.src = preparedUrl;
                standby.preload = 'auto';
                standby.load();
            }
        }

        function handoff(resumeAt) {
            if (!nextMedia) {
                mixing = false;
                audio.volume = baseVolume;
                return;
            }

            var item = nextMedia;
            hooks.activate(item, Math.max(0, resumeAt || 0), function () {
                clearStandby();
                audio.volume = baseVolume;
                mixing = false;
            });
        }

        function maybeStart() {
            if (mode !== 'crossfade' || seconds < 1 || !nextMedia || mixing
                || audio.paused || slotDuration < 1) {
                return;
            }
            if (audio.currentTime + 0.08 < slotDuration) {
                return;
            }

            mixing = true;
            baseVolume = Math.max(0, Math.min(1, audio.volume));
            hooks.beforeTransition(nextMedia);

            try {
                standby.currentTime = 0;
            } catch (error) {}
            standby.volume = 0;

            var promise = standby.play();
            if (!promise || typeof promise.then !== 'function') {
                mixing = false;
                audio.volume = baseVolume;
                return;
            }

            promise.then(function () {
                var started = performance.now();

                function fade(now) {
                    if (!mixing) {
                        return;
                    }
                    var progress = Math.min(1, (now - started) / (seconds * 1000));
                    audio.volume = baseVolume * (1 - progress);
                    standby.volume = baseVolume * progress;

                    if (progress < 1) {
                        fadeFrame = window.requestAnimationFrame(fade);
                        return;
                    }

                    handoff(standby.currentTime || seconds);
                }

                fadeFrame = window.requestAnimationFrame(fade);
            }).catch(function () {
                mixing = false;
                audio.volume = baseVolume;
            });
        }

        function handleEnded() {
            if (mixing) {
                return true;
            }
            if (mode === 'gapless' && nextMedia) {
                baseVolume = Math.max(0, Math.min(1, audio.volume));
                hooks.beforeTransition(nextMedia);
                handoff(0);
                return true;
            }
            return false;
        }

        function reset() {
            if (fadeFrame) {
                window.cancelAnimationFrame(fadeFrame);
                fadeFrame = 0;
            }
            mixing = false;
            audio.volume = baseVolume;
            clearStandby();
            nextMedia = null;
        }

        return {
            prepare: prepare,
            maybeStart: maybeStart,
            handleEnded: handleEnded,
            reset: reset,
            isMixing: function () { return mixing; }
        };
    }

    function initBlock(root) {
        var endpoint = root.getAttribute('data-now-endpoint');
        var eventEndpoint = root.getAttribute('data-event-endpoint');
        var listenLabel = root.getAttribute('data-listen-label') || 'Listen';
        var pauseLabel = root.getAttribute('data-pause-label') || 'Pause';
        var button = q('.radio-block__play', root);
        var audio = q('.radio-block__audio', root);
        var canvas = q('.radio-block__scope', root);
        var title = q('[data-radio-block-title]', root);
        var program = q('[data-radio-block-program]', root);

        if (!endpoint || !button || !audio || !canvas) {
            return;
        }

        var mediaId = 0;
        var programId = 0;
        var listenStart = 0;
        var wantedPlaying = false;
        var endedAt = 0;
        var endedMediaId = 0;
        var endedRetryCount = 0;
        var transitionTimer = 0;
        var transitionProgramId = 0;
        var transitionRetryCount = 0;
        var scope = createScope(canvas, audio);
        var transitionManager = createTransitionManager(audio, {
            beforeTransition: function () {
                flush();
            },
            activate: function (item, resumeAt, done) {
                mediaId = parseInt(String(item.media_id || '').replace('media:', ''), 10) || 0;
                if (title) {
                    title.textContent = item.title || '';
                    title.href = item.url || '#';
                }
                scope.setExternal(item.source_kind && item.source_kind !== 'local');
                audio.src = item.stream_url || '';
                audio.load();

                function startNext() {
                    try {
                        audio.currentTime = Math.max(0, resumeAt || 0);
                    } catch (error) {}
                    scope.start();
                    audio.play().then(function () {
                        if (done) {
                            done();
                        }
                        window.setTimeout(function () {
                            sync(true, false, 0);
                        }, 200);
                    }).catch(function () {
                        if (done) {
                            done();
                        }
                        updateButton();
                    });
                }

                if (audio.readyState >= 1) {
                    startNext();
                } else {
                    audio.addEventListener('loadedmetadata', function once() {
                        audio.removeEventListener('loadedmetadata', once);
                        startNext();
                    });
                }
            }
        });

        function updateButton() {
            var playing = !audio.paused;
            root.classList.toggle('is-playing', playing);
            button.setAttribute('aria-label', playing ? pauseLabel : listenLabel);
            button.setAttribute('title', playing ? pauseLabel : listenLabel);
        }

        function flush() {
            if (!listenStart) {
                return;
            }
            postEvent(
                eventEndpoint,
                mediaId,
                programId,
                'listen',
                (Date.now() - listenStart) / 1000
            );
            listenStart = 0;
        }

        function scheduleNextProgrammeTransition(data) {
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

            transitionProgramId = parseInt(
                String(data.upcoming[0].program_id || '').replace('program:', ''),
                10
            ) || 0;

            transitionTimer = window.setTimeout(function () {
                transitionTimer = 0;
                sync(wantedPlaying, false, transitionProgramId);
            }, Math.max(0, delay));
        }

        function apply(data, autoplay, fromEnded, expectedProgramId) {
            scheduleNextProgrammeTransition(data);

            if (!data.current_media) {
                flush();
                wantedPlaying = false;
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                transitionManager.reset();
                updateButton();
                return;
            }

            var nextMediaId = parseInt(String(data.current_media.media_id).replace('media:', ''), 10) || 0;
            var nextProgramId = data.now_playing
                ? (parseInt(String(data.now_playing.program_id).replace('program:', ''), 10) || 0)
                : 0;

            if (transitionManager.isMixing() && data.source === 'rotation' && nextMediaId !== mediaId) {
                return;
            }

            if (expectedProgramId > 0 && nextProgramId !== expectedProgramId
                && transitionRetryCount < 20) {
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
            var offset = parseInt(data.current_media.offset || 0, 10);
            if (expectedProgramId > 0 && nextProgramId === expectedProgramId) {
                offset = 0;
            }

            if (fromEnded && nextMediaId !== endedMediaId && nextProgramId === programId) {
                offset = 0;
            }
            var changed = mediaId !== nextMediaId || audio.getAttribute('src') !== streamUrl;

            if (fromEnded && nextMediaId === endedMediaId
                && endedAt > 0 && offset >= Math.max(0, endedAt - 2)
                && endedRetryCount < 20) {
                endedRetryCount++;
                window.setTimeout(function () {
                    sync(true, true);
                }, 500);
                return;
            }

            if (fromEnded) {
                endedAt = 0;
                endedMediaId = 0;
                endedRetryCount = 0;
            }

            mediaId = nextMediaId;
            programId = nextProgramId;
            transitionManager.prepare(data);
            scope.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

            if (title) {
                title.textContent = data.current_media.title || '';
                title.href = data.current_media.url || '#';
            }
            if (program) {
                program.textContent = data.now_playing ? (data.now_playing.title || '') : '';
            }

            function seekAndPlay() {
                try {
                    audio.currentTime = Math.max(0, offset);
                } catch (error) {}

                if (autoplay && wantedPlaying) {
                    scope.start();
                    audio.play().catch(function () {
                        wantedPlaying = false;
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
                scope.start();
                audio.play().catch(function () {
                    wantedPlaying = false;
                    updateButton();
                });
            }
        }

        function sync(autoplay, fromEnded, expectedProgramId) {
            fetch(endpoint, {
                cache: 'no-store',
                credentials: 'same-origin'
            })
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
                    wantedPlaying = false;
                    audio.pause();
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
            scope.start();
            sync(true);
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
            if (!wantedPlaying) {
                return;
            }
            if (transitionManager.handleEnded()) {
                return;
            }
            endedAt = audio.currentTime || 0;
            endedMediaId = mediaId;
            endedRetryCount = 0;
            sync(true, true);
        });

        window.addEventListener('pagehide', flush);
        window.setInterval(function () {
            if (wantedPlaying) {
                transitionManager.maybeStart();
            }
        }, 100);

        window.setInterval(function () {
            sync(wantedPlaying && !audio.paused);
        }, 15000);

        scope.draw();
        sync(false);
        updateButton();
    }

    function init() {
        bindPersistentPlayerLinks();
        var blocks = document.querySelectorAll('.radio-block[data-now-endpoint]');
        for (var i = 0; i < blocks.length; i++) {
            initBlock(blocks[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
