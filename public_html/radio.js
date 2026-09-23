(function () {
    'use strict';

    function q(selector, root) {
        return (root || document).querySelector(selector);
    }

    function bindPersistentPlayerLinks() {
        var links = document.querySelectorAll('.radio-public [data-radio-persistent-player]');
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
                    var inlineAudio = document.querySelector('#radio-home-audio');
                    if (inlineAudio && !inlineAudio.paused) {
                        inlineAudio.pause();
                    }
                    popup.location.href = href;
                }
                popup.focus();
            });
        }
    }

    function qa(selector, root) {
        return (root || document).querySelectorAll(selector);
    }

    function intAttr(el, name) {
        return parseInt(el.getAttribute(name) || '0', 10) || 0;
    }

    function formatTime(seconds) {
        seconds = Math.max(0, Math.round(seconds || 0));
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        return (hours > 0 ? hours + ':' : '')
            + (hours > 0 && minutes < 10 ? '0' : '')
            + minutes + ':' + (secs < 10 ? '0' : '') + secs;
    }

    function postEvent(endpoint, mediaId, programId, eventType, source, seconds) {
        if (!endpoint || !mediaId) {
            return;
        }
        var body = new URLSearchParams();
        body.set('media_id', mediaId);
        body.set('program_id', programId || 0);
        body.set('event_type', eventType);
        body.set('source', source || 'catalogue');
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

    function bindTracking() {
        var script = document.getElementById('radio-public-js');
        var eventEndpoint = script ? script.getAttribute('data-event-endpoint') : '';
        if (!eventEndpoint) {
            return;
        }
        var audios = qa('audio[data-radio-media-id]');
        for (var i = 0; i < audios.length; i++) {
            (function (audio) {
                var mediaId = intAttr(audio, 'data-radio-media-id');
                var programId = intAttr(audio, 'data-radio-program-id');
                var source = audio.getAttribute('data-radio-source') || 'catalogue';
                var started = false;
                var total = 0;
                var last = 0;

                function flush() {
                    if (last) {
                        total += (Date.now() - last) / 1000;
                        last = 0;
                    }
                }

                audio.addEventListener('play', function () {
                    if (!started) {
                        started = true;
                        postEvent(eventEndpoint, mediaId, programId, 'play', source, 0);
                    }
                    last = Date.now();
                });
                audio.addEventListener('pause', flush);
                audio.addEventListener('ended', function () {
                    flush();
                    if (total > 0) {
                        postEvent(eventEndpoint, mediaId, programId, 'listen', source, total);
                        total = 0;
                    }
                });
                window.addEventListener('pagehide', function () {
                    flush();
                    if (total > 0) {
                        postEvent(eventEndpoint, mediaId, programId, 'listen', source, total);
                        total = 0;
                    }
                });
            }(audios[i]));
        }
    }

    function waveform(canvas, audio) {
        if (!canvas || !audio) {
            return { setExternal: function () {}, start: function () {}, draw: function () {} };
        }

        var context = null;
        var analyser = null;
        var sourceNode = null;
        var data = null;
        var fallback = false;
        var external = false;
        var quietFrames = 0;
        var visualGain = 1;

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
                sourceNode = context.createMediaElementSource(audio);
                analyser = context.createAnalyser();
                analyser.fftSize = 256;
                analyser.smoothingTimeConstant = 0.65;
                data = new Uint8Array(analyser.fftSize);
                sourceNode.connect(analyser);
                analyser.connect(context.destination);
            } catch (error) {
                fallback = true;
                analyser = null;
                data = null;
            }
        }

        function drawFallback(ctx, width, height) {
            var points = 48;
            var t = Date.now() / 180;
            var samples = [];
            for (var i = 0; i < points; i++) {
                var x = i * width / (points - 1);
                var envelope = 0.25 + 0.75 * Math.sin(Math.PI * i / (points - 1));
                var y = height / 2
                    + Math.sin(t + i * 0.48) * envelope * height * 0.18
                    + Math.sin(t * 0.52 + i * 0.19) * height * 0.045;
                samples.push({x:x,y:y});
            }
            ctx.beginPath();
            ctx.moveTo(samples[0].x, samples[0].y);
            for (var s = 1; s < samples.length - 1; s++) {
                var midX = (samples[s].x + samples[s + 1].x) / 2;
                var midY = (samples[s].y + samples[s + 1].y) / 2;
                ctx.quadraticCurveTo(samples[s].x, samples[s].y, midX, midY);
            }
            ctx.lineTo(samples[samples.length - 1].x, samples[samples.length - 1].y);
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
                window.requestAnimationFrame(draw);
                return;
            }

            if (analyser && data && !external) {
                analyser.getByteTimeDomainData(data);

                var energy = 0;
                for (var e = 0; e < data.length; e++) {
                    energy += Math.abs(data[e] - 128);
                }

                if (energy < data.length * 0.6) {
                    quietFrames++;
                } else {
                    quietFrames = 0;
                }

                if (quietFrames < 10) {
                    var peak = 1;
                    for (var p = 0; p < data.length; p++) {
                        peak = Math.max(peak, Math.abs(data[p] - 128));
                    }

                    var targetGain = Math.min(10, Math.max(1.4, 54 / peak));
                    visualGain += (targetGain - visualGain) * 0.12;

                    var points = 72;
                    var samples = [];
                    for (var i = 0; i < points; i++) {
                        var sampleIndex = Math.floor(i * (data.length - 1) / (points - 1));
                        var normalized = ((data[sampleIndex] - 128) / 128) * visualGain;
                        normalized = Math.max(-1, Math.min(1, normalized));
                        samples.push({
                            x: i * width / (points - 1),
                            y: height / 2 + normalized * height * 0.39
                        });
                    }

                    ctx.beginPath();
                    ctx.moveTo(samples[0].x, samples[0].y);
                    for (var s = 1; s < samples.length - 1; s++) {
                        var midX = (samples[s].x + samples[s + 1].x) / 2;
                        var midY = (samples[s].y + samples[s + 1].y) / 2;
                        ctx.quadraticCurveTo(samples[s].x, samples[s].y, midX, midY);
                    }
                    ctx.lineTo(samples[samples.length - 1].x, samples[samples.length - 1].y);
                    ctx.stroke();
                } else {
                    drawFallback(ctx, width, height);
                }
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

    function initHomePlayer(root) {
        if (!root) {
            return;
        }

        var endpoint = root.getAttribute('data-now-endpoint');
        var eventEndpoint = root.getAttribute('data-event-endpoint');
        var audio = q('#radio-home-audio', root);
        var title = q('[data-radio-home-title]', root);
        var canvas = q('#radio-home-wave', root);

        if (!endpoint || !audio) {
            return;
        }

        var mediaId = 0;
        var programId = 0;
        var userStarted = false;
        var listenStart = 0;
        var endedAt = 0;
        var endedMediaId = 0;
        var endedRetryCount = 0;
        var transitionTimer = 0;
        var transitionProgramId = 0;
        var transitionRetryCount = 0;
        var wave = waveform(canvas, audio);
        var transitionManager = createTransitionManager(audio, {
            beforeTransition: function () {
                flush();
            },
            activate: function (item, resumeAt, done) {
                var nextId = parseInt(String(item.media_id || '').replace('media:', ''), 10) || 0;
                mediaId = nextId;
                if (title) {
                    title.textContent = item.title || '';
                    title.href = item.url || '#';
                }
                wave.setExternal(item.source_kind && item.source_kind !== 'local');
                audio.src = item.stream_url || '';
                audio.load();

                function startNext() {
                    try {
                        audio.currentTime = Math.max(0, resumeAt || 0);
                    } catch (error) {}
                    wave.start();
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

        function flush() {
            if (listenStart) {
                postEvent(eventEndpoint, mediaId, programId, 'listen', 'live-home',
                    (Date.now() - listenStart) / 1000);
                listenStart = 0;
            }
        }

        function seekAndMaybePlay(offset, play) {
            function applySeek() {
                try {
                    audio.currentTime = Math.max(0, offset || 0);
                } catch (error) {}
                if (play && userStarted) {
                    wave.start();
                    audio.play().catch(function () {});
                }
            }

            if (audio.readyState >= 1) {
                applySeek();
            } else {
                audio.addEventListener('loadedmetadata', function once() {
                    audio.removeEventListener('loadedmetadata', once);
                    applySeek();
                });
            }
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
                sync(userStarted, false, transitionProgramId);
            }, Math.max(0, delay));
        }

        function apply(data, play, fromEnded, expectedProgramId) {
            scheduleNextProgrammeTransition(data);

            if (!data.current_media) {
                flush();
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                transitionManager.reset();
                return;
            }

            var nextId = parseInt(String(data.current_media.media_id).replace('media:', ''), 10) || 0;
            var nextProgram = data.now_playing
                ? (parseInt(String(data.now_playing.program_id).replace('program:', ''), 10) || 0)
                : 0;

            if (expectedProgramId > 0 && nextProgram !== expectedProgramId
                && transitionRetryCount < 20) {
                transitionRetryCount++;
                window.setTimeout(function () {
                    sync(play, false, expectedProgramId);
                }, 250);
                return;
            }

            if (expectedProgramId > 0 && nextProgram === expectedProgramId) {
                transitionRetryCount = 0;
            }

            var offset = parseInt(data.current_media.offset || 0, 10);
            var streamUrl = data.current_media.stream_url || '';

            if (expectedProgramId > 0 && nextProgram === expectedProgramId) {
                offset = 0;
            }

            if (fromEnded && nextId !== endedMediaId && nextProgram === programId) {
                offset = 0;
            }

            if (fromEnded && nextId === endedMediaId
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

            wave.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

            if (title) {
                title.textContent = data.current_media.title;
                title.href = data.current_media.url || '#';
            }

            var changed = mediaId !== nextId || audio.getAttribute('src') !== streamUrl;
            mediaId = nextId;
            programId = nextProgram;
            transitionManager.prepare(data);

            if (changed) {
                flush();
                audio.src = streamUrl;
                audio.load();
                seekAndMaybePlay(offset, play);
                return;
            }

            if (Math.abs(audio.currentTime - offset) > 5) {
                try {
                    audio.currentTime = offset;
                } catch (error) {}
            }

            if (play && userStarted && audio.paused) {
                wave.start();
                audio.play().catch(function () {});
            }
        }

        function sync(play, fromEnded, expectedProgramId) {
            fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    apply(data, play, !!fromEnded, expectedProgramId || 0);
                })
                .catch(function () {});
        }

        audio.addEventListener('play', function () {
            if (!userStarted) {
                userStarted = true;
                audio.pause();
                sync(true);
                return;
            }
            wave.start();
            postEvent(eventEndpoint, mediaId, programId, 'play', 'live-home', 0);
            listenStart = Date.now();
        });
        audio.addEventListener('pause', flush);
        audio.addEventListener('ended', function () {
            flush();
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
            transitionManager.maybeStart();
        }, 100);

        window.setInterval(function () {
            sync(userStarted && !audio.paused);
        }, 15000);

        wave.draw();
        sync(false);
    }

    bindTracking();
    bindPersistentPlayerLinks();

    initHomePlayer(q('[data-radio-home-live]'));
}());
