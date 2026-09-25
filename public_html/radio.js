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
        var reserve = new Audio();
        standby.preload = 'auto';
        reserve.preload = 'auto';

        var mode = 'hard';
        var seconds = 0;
        var slotDuration = 0;
        var currentDuration = 0;
        var nextMedia = null;
        var nextNextMedia = null;
        var preparedUrl = '';
        var reserveUrl = '';
        var mixing = false;
        var fadeFrame = 0;
        var baseVolume = 1;

        function bufferedAhead(element) {
            if (!element || !element.buffered || element.buffered.length < 1) {
                return 0;
            }

            var position = element.currentTime || 0;
            var best = 0;
            try {
                for (var i = 0; i < element.buffered.length; i++) {
                    var start = element.buffered.start(i);
                    var end = element.buffered.end(i);
                    if (position >= start && position <= end) {
                        best = Math.max(best, end - position);
                    } else if (position === 0 && start <= 0.25) {
                        best = Math.max(best, end);
                    }
                }
            } catch (error) {}
            return Math.max(0, best);
        }

        function bufferTarget(item, minimum, maximum) {
            var duration = item ? (parseInt(item.duration || 0, 10) || 0) : 0;
            var target = duration > 0 ? Math.max(minimum, duration * 0.12) : minimum;
            return Math.min(maximum, target);
        }

        function reportBuffer() {
            if (!hooks || typeof hooks.bufferStatus !== 'function') {
                return;
            }
            hooks.bufferStatus({
                next_buffered: bufferedAhead(standby),
                next_ready: standby.readyState >= 3,
                reserve_ready: reserveUrl !== '' && (reserve.readyState >= 2 || bufferedAhead(reserve) > 0)
            });
        }

        function clearElement(element) {
            element.pause();
            element.removeAttribute('src');
            element.load();
        }

        function clearStandby() {
            clearElement(standby);
            preparedUrl = '';
        }

        function clearReserve() {
            clearElement(reserve);
            reserveUrl = '';
        }

        function maybePrefetchReserve() {
            reportBuffer();
            if (!nextNextMedia || !nextNextMedia.stream_url || !nextMedia) {
                clearReserve();
                return;
            }

            var target = bufferTarget(nextMedia, 3, 12);
            if (standby.readyState < 3 && bufferedAhead(standby) < target) {
                return;
            }

            if (reserveUrl !== nextNextMedia.stream_url) {
                clearReserve();
                reserveUrl = nextNextMedia.stream_url;
                reserve.src = reserveUrl;
                reserve.preload = 'auto';
                reserve.load();
            }
            reportBuffer();
        }

        standby.addEventListener('progress', maybePrefetchReserve);
        standby.addEventListener('canplay', maybePrefetchReserve);
        reserve.addEventListener('progress', reportBuffer);

        function prepare(data) {
            mode = data && data.transition_mode ? data.transition_mode : 'hard';
            seconds = data ? (parseInt(data.transition_seconds || 0, 10) || 0) : 0;
            slotDuration = data && data.current_media
                ? (parseInt(data.current_media.slot_duration || data.current_media.duration || 0, 10) || 0)
                : 0;
            currentDuration = data && data.current_media
                ? (parseInt(data.current_media.duration || 0, 10) || 0)
                : 0;
            nextMedia = data && data.next_media ? data.next_media : null;
            nextNextMedia = data && data.next_next_media ? data.next_next_media : null;

            if (!nextMedia || !nextMedia.stream_url) {
                clearStandby();
                clearReserve();
                reportBuffer();
                return;
            }

            if (preparedUrl !== nextMedia.stream_url) {
                clearStandby();
                preparedUrl = nextMedia.stream_url;
                standby.src = preparedUrl;
                standby.preload = 'auto';
                standby.load();
            }

            if (!nextNextMedia || reserveUrl !== nextNextMedia.stream_url) {
                clearReserve();
            }

            maybePrefetchReserve();
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
                clearReserve();
                audio.volume = baseVolume;
                mixing = false;
            });
        }

        function maybeStart() {
            maybePrefetchReserve();

            if (mode !== 'crossfade' || seconds < 1 || !nextMedia || mixing
                || audio.paused || slotDuration < 1) {
                return;
            }
            if (audio.currentTime + 0.08 < slotDuration) {
                return;
            }

            var required = Math.max(1.25, seconds + 0.75);
            var buffered = bufferedAhead(standby);
            if (standby.readyState < 3 && buffered < required) {
                reportBuffer();
                return;
            }

            if (currentDuration > 0
                && (currentDuration - audio.currentTime) < Math.max(0.35, seconds * 0.35)) {
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

            if ((mode === 'gapless' || mode === 'crossfade') && nextMedia) {
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
            clearReserve();
            nextMedia = null;
            nextNextMedia = null;
            reportBuffer();
        }

        return {
            prepare: prepare,
            maybeStart: maybeStart,
            handleEnded: handleEnded,
            reset: reset,
            isMixing: function () { return mixing; },
            bufferState: function () {
                return {
                    next_buffered: bufferedAhead(standby),
                    next_ready: standby.readyState >= 3,
                    reserve_ready: reserveUrl !== '' && (reserve.readyState >= 2 || bufferedAhead(reserve) > 0)
                };
            }
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
        var liveSource = '';
        var syncTick = 0;
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

            if (!data || (data.source === 'schedule' || data.source === 'semi-live') || !data.upcoming || !data.upcoming.length) {
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
            liveSource = data && data.source ? data.source : '';
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

            if (transitionManager.isMixing() && (data.source === 'rotation' || data.source === 'semi-live') && nextId !== mediaId) {
                return;
            }

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
            syncTick++;
            if (liveSource === 'semi-live' || syncTick % 5 === 0) {
                sync(userStarted && !audio.paused);
            }
        }, 3000);

        wave.draw();
        sync(false);
    }


    function initReplayPlayers() {
        var roots = qa('[data-radio-replay-player]');
        var script = document.getElementById('radio-public-js');
        var eventEndpoint = script ? script.getAttribute('data-event-endpoint') : '';

        for (var r = 0; r < roots.length; r++) {
            (function (root) {
                var audio = q('[data-radio-replay-audio]', root);
                var toggle = q('[data-radio-replay-toggle]', root);
                var progress = q('[data-radio-replay-progress]', root);
                var current = q('[data-radio-replay-current]', root);
                var total = q('[data-radio-replay-total]', root);
                var now = q('[data-radio-replay-now]', root);
                var chapterList = q('.radio-replay__chapters', root);
                var chapterButtons = qa('[data-radio-replay-chapter]', root);
                var programId = intAttr(root, 'data-radio-program-id');
                var trackingEnabled = root.getAttribute('data-radio-replay-track') !== '0';
                var trackingSource = root.getAttribute('data-radio-replay-source') || 'replay';
                var dynamicPlaylist = root.getAttribute('data-radio-replay-dynamic') === '1';
                var transitionMode = root.getAttribute('data-radio-transition-mode') || 'hard';
                var configuredCrossfade = parseInt(root.getAttribute('data-radio-crossfade-seconds') || '0', 10) || 0;
                var raw = root.getAttribute('data-radio-replay-items') || '[]';
                var items = [];

                try {
                    items = JSON.parse(raw);
                } catch (error) {
                    items = [];
                }

                if (!audio || !toggle || !progress || (!items.length && !dynamicPlaylist)) {
                    return;
                }

                var index = 0;
                var started = false;
                var listenStart = 0;
                var listened = 0;
                var queuePreload = new Audio();
                var queueReserve = new Audio();
                var queuePreloadUrl = '';
                var queueReserveUrl = '';
                var queueMixing = false;
                var queueFadeFrame = 0;
                var queueBufferTimer = 0;
                var queueTransitionTimer = 0;
                var queuePreloadLastKick = 0;
                var queueReserveLastKick = 0;
                queuePreload.preload = 'auto';
                queueReserve.preload = 'auto';


                var studioFx = (function () {
                    var context = null;
                    var low = null;
                    var mid = null;
                    var high = null;
                    var lowPass = null;
                    var highPass = null;
                    var dry = null;
                    var delay = null;
                    var feedback = null;
                    var wet = null;
                    var programmeGain = null;
                    var master = null;
                    var analyser = null;
                    var scopeData = null;
                    var scopeFreq = null;
                    var scopeFrame = 0;
                    var ready = false;
                    var samples = {};

                    function setup() {
                        if (ready) {
                            if (context && context.state === 'suspended') {
                                context.resume().catch(function () {});
                            }
                            return true;
                        }

                        var AudioContext = window.AudioContext || window.webkitAudioContext;
                        if (!AudioContext) {
                            return false;
                        }

                        try {
                            context = new AudioContext();

                            low = context.createBiquadFilter();
                            low.type = 'lowshelf';
                            low.frequency.value = 160;

                            mid = context.createBiquadFilter();
                            mid.type = 'peaking';
                            mid.frequency.value = 1000;
                            mid.Q.value = 0.8;

                            high = context.createBiquadFilter();
                            high.type = 'highshelf';
                            high.frequency.value = 6500;

                            lowPass = context.createBiquadFilter();
                            lowPass.type = 'lowpass';
                            lowPass.frequency.value = 22000;
                            lowPass.Q.value = 0.7;

                            highPass = context.createBiquadFilter();
                            highPass.type = 'highpass';
                            highPass.frequency.value = 20;
                            highPass.Q.value = 0.7;

                            dry = context.createGain();
                            dry.gain.value = 1;

                            delay = context.createDelay(1.0);
                            delay.delayTime.value = 0.32;

                            feedback = context.createGain();
                            feedback.gain.value = 0.32;

                            wet = context.createGain();
                            wet.gain.value = 0;

                            programmeGain = context.createGain();
                            programmeGain.gain.value = 1;

                            master = context.createGain();
                            master.gain.value = 1;

                            analyser = context.createAnalyser();
                            analyser.fftSize = 512;
                            analyser.smoothingTimeConstant = 0.72;
                            scopeData = new Uint8Array(analyser.fftSize);
                            scopeFreq = new Uint8Array(analyser.frequencyBinCount);

                            var elements = [audio, queuePreload, queueReserve];
                            for (var sourceIndex = 0; sourceIndex < elements.length; sourceIndex++) {
                                context.createMediaElementSource(elements[sourceIndex]).connect(low);
                            }

                            low.connect(mid);
                            mid.connect(high);
                            high.connect(lowPass);
                            lowPass.connect(highPass);

                            highPass.connect(dry);
                            dry.connect(programmeGain);

                            highPass.connect(delay);
                            delay.connect(feedback);
                            feedback.connect(delay);
                            delay.connect(wet);
                            wet.connect(programmeGain);

                            programmeGain.connect(master);

                            master.connect(analyser);
                            analyser.connect(context.destination);

                            ready = true;
                            drawScope();
                            if (context.state === 'suspended') {
                                context.resume().catch(function () {});
                            }
                            return true;
                        } catch (error) {
                            ready = false;
                            return false;
                        }
                    }

                    function drawScope() {
                        if (!ready || !analyser || scopeFrame) {
                            return;
                        }

                        var studioRoot = root.closest ? root.closest('[data-radio-studio]') : null;
                        var canvas = studioRoot
                            ? studioRoot.querySelector('[data-radio-djfx-scope]')
                            : null;
                        if (!canvas) {
                            canvas = document.querySelector('[data-radio-djfx-scope]');
                        }
                        if (!canvas || !canvas.getContext) {
                            return;
                        }

                        var ctx = canvas.getContext('2d');
                        if (!ctx) {
                            return;
                        }

                        function frame() {
                            if (!ready || !analyser) {
                                scopeFrame = 0;
                                return;
                            }

                            analyser.getByteTimeDomainData(scopeData);
                            analyser.getByteFrequencyData(scopeFreq);

                            var width = canvas.width;
                            var height = canvas.height;
                            var waveHeight = Math.round(height * 0.7);
                            ctx.clearRect(0, 0, width, height);

                            ctx.lineWidth = 2;
                            ctx.strokeStyle = '#61e7c7';
                            ctx.beginPath();
                            for (var i = 0; i < scopeData.length; i++) {
                                var x = i * width / (scopeData.length - 1);
                                var normalized = (scopeData[i] - 128) / 128;
                                var y = waveHeight * 0.5 + normalized * waveHeight * 0.42;
                                if (i === 0) {
                                    ctx.moveTo(x, y);
                                } else {
                                    ctx.lineTo(x, y);
                                }
                            }
                            ctx.stroke();

                            var bandTop = Math.round(height * 0.73);
                            var bars = 72;
                            var barWidth = width / bars;
                            ctx.fillStyle = 'rgba(97,231,199,.55)';
                            for (var b = 0; b < bars; b++) {
                                var freqIndex = Math.floor(b * scopeFreq.length / bars);
                                var level = scopeFreq[freqIndex] / 255;
                                var barHeight = Math.max(1, level * (height - bandTop));
                                ctx.fillRect(
                                    b * barWidth,
                                    height - barHeight,
                                    Math.max(1, barWidth - 2),
                                    barHeight
                                );
                            }

                            scopeFrame = window.requestAnimationFrame(frame);
                        }

                        scopeFrame = window.requestAnimationFrame(frame);
                    }

                    function setFilter(value) {
                        if (!setup()) {
                            return;
                        }
                        value = Math.max(-100, Math.min(100, parseFloat(value || 0) || 0));
                        if (value < 0) {
                            var lowRatio = Math.abs(value) / 100;
                            lowPass.frequency.value = 22000 * Math.pow(300 / 22000, lowRatio);
                            highPass.frequency.value = 20;
                        } else if (value > 0) {
                            var highRatio = value / 100;
                            lowPass.frequency.value = 22000;
                            highPass.frequency.value = 20 * Math.pow(2200 / 20, highRatio);
                        } else {
                            lowPass.frequency.value = 22000;
                            highPass.frequency.value = 20;
                        }
                    }

                    function ensureSample(pad) {
                        pad = parseInt(pad || 0, 10) || 0;
                        if (!pad) {
                            return null;
                        }

                        if (!samples[pad]) {
                            var sampleAudio = new Audio();
                            sampleAudio.preload = 'auto';
                            samples[pad] = {
                                audio: sampleAudio,
                                source: null,
                                gain: null,
                                url: ''
                            };
                        }
                        return samples[pad];
                    }

                    function assignSample(value) {
                        value = value || {};
                        var sample = ensureSample(value.pad);
                        if (!sample) {
                            return;
                        }

                        var url = value.url || '';
                        if (sample.url === url) {
                            return;
                        }

                        sample.audio.pause();
                        sample.url = url;
                        if (!url) {
                            sample.audio.removeAttribute('src');
                            sample.audio.load();
                            return;
                        }

                        sample.audio.src = url;
                        sample.audio.preload = 'auto';
                        sample.audio.load();
                    }

                    function playSample(value) {
                        value = value || {};
                        var sample = ensureSample(value.pad);
                        if (!sample || !sample.url || !setup()) {
                            return;
                        }

                        if (!sample.source) {
                            try {
                                sample.source = context.createMediaElementSource(sample.audio);
                                sample.gain = context.createGain();
                                // Pads are intentionally forward in the monitor mix.
                                sample.gain.gain.value = Math.pow(10, 8 / 20);
                                sample.source.connect(sample.gain);
                                sample.gain.connect(master);
                            } catch (error) {
                                return;
                            }
                        }

                        try {
                            sample.audio.currentTime = 0;
                        } catch (error) {}

                        // Briefly duck the programme while a pad jingle plays so speech/cues stay intelligible.
                        if (programmeGain && context) {
                            var nowTime = context.currentTime;
                            programmeGain.gain.cancelScheduledValues(nowTime);
                            programmeGain.gain.setValueAtTime(programmeGain.gain.value, nowTime);
                            programmeGain.gain.linearRampToValueAtTime(Math.pow(10, -6 / 20), nowTime + 0.06);
                        }

                        sample.audio.onended = function () {
                            if (!programmeGain || !context) {
                                return;
                            }
                            var releaseTime = context.currentTime;
                            programmeGain.gain.cancelScheduledValues(releaseTime);
                            programmeGain.gain.setValueAtTime(programmeGain.gain.value, releaseTime);
                            programmeGain.gain.linearRampToValueAtTime(1, releaseTime + 0.28);
                        };

                        var promise = sample.audio.play();
                        if (promise && typeof promise.catch === 'function') {
                            promise.catch(function () {});
                        }
                    }

                    function reset() {
                        if (!setup()) {
                            return;
                        }
                        low.gain.value = 0;
                        mid.gain.value = 0;
                        high.gain.value = 0;
                        wet.gain.value = 0;
                        setFilter(0);
                    }

                    audio.addEventListener('play', function () {
                        if (setup() && context && context.state === 'suspended') {
                            context.resume().catch(function () {});
                        }
                    });

                    return {
                        apply: function (control, value) {
                            if (control === 'sample-assign') {
                                assignSample(value);
                                return;
                            }
                            if (control === 'sample-play') {
                                playSample(value);
                                return;
                            }
                            if (!setup()) {
                                return;
                            }
                            if (control === 'low') {
                                low.gain.value = Math.max(-12, Math.min(12, value || 0));
                            } else if (control === 'mid') {
                                mid.gain.value = Math.max(-12, Math.min(12, value || 0));
                            } else if (control === 'high') {
                                high.gain.value = Math.max(-12, Math.min(12, value || 0));
                            } else if (control === 'filter') {
                                setFilter(value);
                            } else if (control === 'echo') {
                                wet.gain.value = value ? 0.28 : 0;
                            } else if (control === 'reset') {
                                reset();
                            }
                        }
                    };
                }());

                root.addEventListener('radio:djfx', function (event) {
                    var detail = event && event.detail ? event.detail : {};
                    studioFx.apply(detail.control || '', detail.value);
                });

                function refreshChapterButtons() {
                    chapterButtons = qa('[data-radio-replay-chapter]', root);
                }

                function queueBufferedAhead(element) {
                    if (!element || !element.buffered || element.buffered.length < 1) {
                        return 0;
                    }
                    var position = element.currentTime || 0;
                    var best = 0;
                    try {
                        for (var b = 0; b < element.buffered.length; b++) {
                            var start = element.buffered.start(b);
                            var end = element.buffered.end(b);
                            if (position >= start && position <= end) {
                                best = Math.max(best, end - position);
                            } else if (position === 0 && start <= 0.25) {
                                best = Math.max(best, end);
                            }
                        }
                    } catch (error) {}
                    return Math.max(0, best);
                }

                function emitQueueBufferStatus() {
                    var nextItem = index + 1 < items.length ? items[index + 1] : null;
                    var reserveItem = index + 2 < items.length ? items[index + 2] : null;
                    var currentItem = items[index] || null;
                    var declaredCurrentDuration = currentItem
                        ? (parseFloat(currentItem.duration || 0) || 0)
                        : 0;
                    var actualCurrentDuration = isFinite(audio.duration) && audio.duration > 0
                        ? audio.duration
                        : declaredCurrentDuration;
                    var currentPosition = Math.max(0, parseFloat(audio.currentTime || 0) || 0);
                    var currentRemaining = actualCurrentDuration > 0
                        ? Math.max(0, actualCurrentDuration - currentPosition)
                        : 0;

                    var detail = {
                        current_title: currentItem ? (currentItem.title || '') : '',
                        current_type: currentItem ? (currentItem.media_type_label || currentItem.media_type || '') : '',
                        current_duration: actualCurrentDuration,
                        current_position: currentPosition,
                        current_remaining: currentRemaining,
                        next_buffered: queueBufferedAhead(queuePreload),
                        next_ready: queuePreload.readyState >= 3,
                        next_title: nextItem ? (nextItem.title || '') : '',
                        next_type: nextItem ? (nextItem.media_type_label || nextItem.media_type || '') : '',
                        next_duration: nextItem ? (parseInt(nextItem.duration || 0, 10) || 0) : 0,
                        reserve_buffered: queueBufferedAhead(queueReserve),
                        reserve_ready: queueReserveUrl !== ''
                            && (queueReserve.readyState >= 2 || queueBufferedAhead(queueReserve) > 0),
                        reserve_title: reserveItem ? (reserveItem.title || '') : '',
                        reserve_type: reserveItem ? (reserveItem.media_type_label || reserveItem.media_type || '') : '',
                        reserve_duration: reserveItem ? (parseInt(reserveItem.duration || 0, 10) || 0) : 0
                    };
                    var event;
                    if (typeof CustomEvent === 'function') {
                        event = new CustomEvent('radio:buffer-status', {detail: detail});
                    } else {
                        event = document.createEvent('CustomEvent');
                        event.initCustomEvent('radio:buffer-status', false, false, detail);
                    }
                    root.dispatchEvent(event);
                }

                function clearQueueAudio(element) {
                    element.pause();
                    element.removeAttribute('src');
                    element.load();
                }

                function queueBufferTarget(item, minimum, maximum) {
                    var duration = item ? (parseInt(item.duration || 0, 10) || 0) : 0;
                    if (duration > 0 && duration <= 20) {
                        return Math.max(1, duration - 0.25);
                    }
                    var target = duration > 0 ? Math.max(minimum, duration * 0.12) : minimum;
                    return Math.min(maximum, target);
                }

                function kickQueueBuffer(element, url, item, reserveSlot) {
                    if (!element || !url || !item) {
                        return;
                    }

                    var target = queueBufferTarget(item, reserveSlot ? 2 : 4, reserveSlot ? 8 : 15);
                    if (queueBufferedAhead(element) >= target) {
                        return;
                    }

                    if (element.networkState === 2) {
                        return;
                    }

                    var nowTime = Date.now();
                    var lastKick = reserveSlot ? queueReserveLastKick : queuePreloadLastKick;
                    if (nowTime - lastKick < 12000) {
                        return;
                    }

                    if (reserveSlot) {
                        queueReserveLastKick = nowTime;
                    } else {
                        queuePreloadLastKick = nowTime;
                    }

                    element.preload = 'auto';
                    element.load();
                }

                function maintainQueueBuffers() {
                    var next = index + 1 < items.length ? items[index + 1] : null;
                    var reserve = index + 2 < items.length ? items[index + 2] : null;

                    if (next && queuePreloadUrl === (next.stream_url || '')) {
                        kickQueueBuffer(queuePreload, queuePreloadUrl, next, false);
                    }
                    if (reserve && queueReserveUrl === (reserve.stream_url || '')) {
                        kickQueueBuffer(queueReserve, queueReserveUrl, reserve, true);
                    }
                    maybePreloadQueueReserve();
                    emitQueueBufferStatus();
                }

                function maybePreloadQueueReserve() {
                    var next = index + 1 < items.length ? items[index + 1] : null;
                    var reserve = index + 2 < items.length ? items[index + 2] : null;
                    if (!reserve || !reserve.stream_url || !next) {
                        if (queueReserveUrl !== '') {
                            clearQueueAudio(queueReserve);
                            queueReserveUrl = '';
                        }
                        emitQueueBufferStatus();
                        return;
                    }

                    var target = queueBufferTarget(next, 4, 15);
                    if (queuePreload.readyState < 3 && queueBufferedAhead(queuePreload) < target) {
                        emitQueueBufferStatus();
                        return;
                    }

                    if (queueReserveUrl !== reserve.stream_url) {
                        clearQueueAudio(queueReserve);
                        queueReserveUrl = reserve.stream_url;
                        queueReserve.src = queueReserveUrl;
                        queueReserve.preload = 'auto';
                        queueReserve.load();
                    }
                    emitQueueBufferStatus();
                }

                function refreshQueuePreload() {
                    var next = index + 1 < items.length ? items[index + 1] : null;
                    if (!next || !next.stream_url) {
                        if (queuePreloadUrl !== '') {
                            clearQueueAudio(queuePreload);
                            queuePreloadUrl = '';
                        }
                        if (queueReserveUrl !== '') {
                            clearQueueAudio(queueReserve);
                            queueReserveUrl = '';
                        }
                        emitQueueBufferStatus();
                        return;
                    }

                    if (queuePreloadUrl !== next.stream_url) {
                        clearQueueAudio(queuePreload);
                        queuePreloadUrl = next.stream_url;
                        queuePreload.src = queuePreloadUrl;
                        queuePreload.preload = 'auto';
                        queuePreload.load();
                    }
                    maybePreloadQueueReserve();
                }

                function onQueueBufferProgress(event) {
                    if (event.currentTarget === queuePreload) {
                        maybePreloadQueueReserve();
                    }
                    emitQueueBufferStatus();
                }

                audio.addEventListener('progress', onQueueBufferProgress);
                audio.addEventListener('canplay', onQueueBufferProgress);
                queuePreload.addEventListener('progress', onQueueBufferProgress);
                queuePreload.addEventListener('canplay', onQueueBufferProgress);
                queueReserve.addEventListener('progress', onQueueBufferProgress);
                queueReserve.addEventListener('canplay', onQueueBufferProgress);

                queueBufferTimer = window.setInterval(maintainQueueBuffers, 10000);
                queueTransitionTimer = window.setInterval(monitorQueueTransition, 100);

                function itemOffset(i) {
                    return items[i] ? (parseInt(items[i].offset || 0, 10) || 0) : 0;
                }

                function itemPlayable(i) {
                    return !!(items[i]
                        && items[i].playable !== false
                        && items[i].playable !== 0
                        && items[i].stream_url);
                }

                function nextPlayableIndex(startIndex) {
                    for (var playableIndex = Math.max(0, startIndex); playableIndex < items.length; playableIndex++) {
                        if (itemPlayable(playableIndex)) {
                            return playableIndex;
                        }
                    }
                    return -1;
                }

                function totalDuration() {
                    for (var lastIndex = items.length - 1; lastIndex >= 0; lastIndex--) {
                        if (itemPlayable(lastIndex)) {
                            return itemOffset(lastIndex)
                                + (parseInt(items[lastIndex].duration || 0, 10) || 0);
                        }
                    }
                    return 0;
                }

                function totalPosition() {
                    return itemOffset(index) + (audio.currentTime || 0);
                }

                function activeOverlap() {
                    if (!items[index]) {
                        return 0;
                    }
                    return Math.max(0, parseFloat(items[index].transition_overlap || 0) || 0);
                }

                function stopQueueFade() {
                    if (queueFadeFrame) {
                        window.cancelAnimationFrame(queueFadeFrame);
                        queueFadeFrame = 0;
                    }
                    queueMixing = false;
                    audio.volume = 1;
                    queuePreload.pause();
                    queuePreload.volume = 1;
                }

                function advanceQueueAudioRole(nextIndex) {
                    var previousAudio = audio;
                    var bufferedReserve = queueReserve;
                    var bufferedReserveUrl = queueReserveUrl;

                    audio = queuePreload;
                    queuePreload = bufferedReserve;
                    queuePreloadUrl = bufferedReserveUrl;
                    queueReserve = previousAudio;
                    queueReserveUrl = '';

                    index = nextIndex;
                    started = false;
                    queueMixing = false;
                    queueFadeFrame = 0;

                    audio.volume = 1;
                    queuePreload.volume = 1;
                    queueReserve.pause();
                    queueReserve.volume = 1;
                    queueReserve.removeAttribute('src');
                    queueReserve.load();

                    refreshQueuePreload();
                    maintainQueueBuffers();
                    updateUi();
                }

                function startQueueCrossfade() {
                    if (queueMixing || transitionMode !== 'crossfade' || audio.paused
                        || index + 1 >= items.length || !itemPlayable(index)
                        || !itemPlayable(index + 1)) {
                        return;
                    }

                    var overlap = activeOverlap();
                    if (overlap <= 0) {
                        return;
                    }

                    var declaredDuration = parseFloat(items[index].duration || 0) || 0;
                    var actualDuration = isFinite(audio.duration) && audio.duration > 0
                        ? audio.duration
                        : declaredDuration;
                    var remaining = Math.max(0, actualDuration - (audio.currentTime || 0));
                    if (remaining > overlap + 0.12) {
                        return;
                    }

                    if (!queuePreloadUrl || queuePreload.readyState < 3) {
                        return;
                    }

                    queueMixing = true;
                    queuePreload.volume = 0;
                    try {
                        queuePreload.currentTime = 0;
                    } catch (error) {}

                    var promise = queuePreload.play();
                    if (!promise || typeof promise.then !== 'function') {
                        queueMixing = false;
                        queuePreload.volume = 1;
                        return;
                    }

                    promise.then(function () {
                        var startedAt = performance.now();
                        function fade(nowTime) {
                            if (!queueMixing) {
                                return;
                            }
                            var progressValue = Math.min(1, (nowTime - startedAt) / (overlap * 1000));
                            audio.volume = 1 - progressValue;
                            queuePreload.volume = progressValue;

                            if (progressValue < 1) {
                                queueFadeFrame = window.requestAnimationFrame(fade);
                                return;
                            }

                            var nextIndex = index + 1;

                            /*
                             * Promote N+1 and rotate the already-buffered N+2
                             * into the preload slot. This avoids throwing away
                             * reserve data after a long current track.
                             */
                            advanceQueueAudioRole(nextIndex);
                        }
                        queueFadeFrame = window.requestAnimationFrame(fade);
                    }).catch(function () {
                        queueMixing = false;
                        queuePreload.volume = 1;
                        audio.volume = 1;
                    });
                }

                function forceQueueHandoffIfNeeded() {
                    if (queueMixing || audio.paused || index + 1 >= items.length
                        || !itemPlayable(index) || !itemPlayable(index + 1)
                        || queuePreloadUrl !== (items[index + 1].stream_url || '')
                        || queuePreload.readyState < 2) {
                        return;
                    }

                    /*
                     * timeupdate can be sparse or throttled. Use the decoded
                     * duration as a last-moment watchdog so a ready N+1 starts
                     * just before N ends instead of leaving an audible gap.
                     */
                    var actualDuration = isFinite(audio.duration) && audio.duration > 0
                        ? audio.duration
                        : (parseFloat(items[index].duration || 0) || 0);
                    if (actualDuration < 1) {
                        return;
                    }

                    var remaining = actualDuration - (audio.currentTime || 0);
                    if (remaining > 0.18 || remaining < -0.5) {
                        return;
                    }

                    queueMixing = true;
                    queuePreload.volume = 1;
                    try {
                        queuePreload.currentTime = 0;
                    } catch (error) {}

                    var nextIndex = index + 1;
                    var promise = queuePreload.play();
                    if (!promise || typeof promise.then !== 'function') {
                        queueMixing = false;
                        return;
                    }

                    promise.then(function () {
                        if (!queueMixing || nextIndex !== index + 1) {
                            return;
                        }
                        advanceQueueAudioRole(nextIndex);
                    }).catch(function () {
                        queueMixing = false;
                    });
                }

                function monitorQueueTransition() {
                    if (!audio || audio.paused) {
                        return;
                    }
                    startQueueCrossfade();
                    if (!queueMixing) {
                        forceQueueHandoffIfNeeded();
                    }
                }

                function activeItemId() {
                    return items[index] ? (parseInt(items[index].item_id || 0, 10) || 0) : 0;
                }

                function updateProgressBounds() {
                    var duration = totalDuration();
                    progress.max = Math.max(0, duration);
                    if (total) {
                        total.textContent = formatTime(duration);
                    }
                }

                function updateActiveChapter() {
                    var activeId = activeItemId();
                    root.setAttribute('data-radio-current-item-id', activeId || 0);
                    for (var i = 0; i < chapterButtons.length; i++) {
                        var buttonItemId = parseInt(chapterButtons[i].getAttribute('data-radio-replay-item-id') || '0', 10) || 0;
                        var buttonIndex = parseInt(chapterButtons[i].getAttribute('data-radio-replay-chapter') || '-1', 10);
                        var active = activeId > 0 ? buttonItemId === activeId : buttonIndex === index;
                        if (active) {
                            chapterButtons[i].classList.add('is-active');
                            chapterButtons[i].setAttribute('aria-current', 'true');
                        } else {
                            chapterButtons[i].classList.remove('is-active');
                            chapterButtons[i].removeAttribute('aria-current');
                        }
                    }
                }

                function updateUi() {
                    var value = Math.max(0, Math.min(parseInt(progress.max || '0', 10) || 0, Math.round(totalPosition())));
                    progress.value = value;
                    if (current) {
                        current.textContent = formatTime(value);
                    }
                    if (now && items[index]) {
                        now.textContent = items[index].title || '';
                    }
                    toggle.textContent = audio.paused
                        ? (toggle.getAttribute('data-play-label') || 'Play')
                        : (toggle.getAttribute('data-pause-label') || 'Pause');
                    updateActiveChapter();
                    emitQueueBufferStatus();
                }

                function flush() {
                    if (!trackingEnabled) {
                        listenStart = 0;
                        listened = 0;
                        return;
                    }
                    if (listenStart) {
                        listened += (Date.now() - listenStart) / 1000;
                        listenStart = 0;
                    }
                    if (listened > 0 && items[index]) {
                        postEvent(
                            eventEndpoint,
                            parseInt(items[index].media_id || 0, 10) || 0,
                            programId,
                            'listen',
                            trackingSource,
                            listened
                        );
                        listened = 0;
                    }
                }

                function setItem(nextIndex, localOffset, shouldPlay) {
                    nextIndex = Math.max(0, Math.min(items.length - 1, nextIndex));
                    if (queueMixing) {
                        stopQueueFade();
                    }
                    flush();
                    if (nextIndex !== index) {
                        started = false;
                    }
                    index = nextIndex;
                    refreshQueuePreload();
                    var item = items[index];
                    audio.src = item.stream_url || '';
                    audio.load();

                    function applyOffset() {
                        try {
                            audio.currentTime = Math.max(0, localOffset || 0);
                        } catch (error) {}
                        updateUi();
                        if (shouldPlay) {
                            audio.play().catch(function () {});
                        }
                    }

                    if (audio.readyState >= 1) {
                        applyOffset();
                    } else {
                        audio.addEventListener('loadedmetadata', applyOffset, { once: true });
                    }
                }

                function seekGlobal(seconds, shouldPlay) {
                    var duration = totalDuration();
                    seconds = Math.max(0, Math.min(duration, parseFloat(seconds || 0) || 0));

                    var targetIndex = -1;
                    for (var i = 0; i < items.length; i++) {
                        if (!itemPlayable(i)) {
                            continue;
                        }
                        if (itemOffset(i) <= seconds) {
                            targetIndex = i;
                        } else {
                            break;
                        }
                    }

                    if (targetIndex < 0) {
                        targetIndex = nextPlayableIndex(0);
                    }
                    if (targetIndex < 0) {
                        return;
                    }

                    var localOffset = Math.max(0, seconds - itemOffset(targetIndex));
                    var itemDuration = parseInt(items[targetIndex].duration || 0, 10) || 0;
                    if (itemDuration > 0) {
                        localOffset = Math.min(localOffset, Math.max(0, itemDuration - 0.05));
                    }
                    setItem(targetIndex, localOffset, shouldPlay);
                }

                root.addEventListener('radio:seek-item', function (event) {
                    var itemId = event && event.detail
                        ? (parseInt(event.detail.item_id || 0, 10) || 0)
                        : 0;
                    if (!itemId) {
                        return;
                    }
                    for (var seekIndex = 0; seekIndex < items.length; seekIndex++) {
                        if ((parseInt(items[seekIndex].item_id || 0, 10) || 0) === itemId) {
                            setItem(seekIndex, 0, !audio.paused);
                            break;
                        }
                    }
                });

                function renderDynamicChapters() {
                    if (!dynamicPlaylist || !chapterList) {
                        return;
                    }

                    chapterList.innerHTML = '';
                    for (var i = 0; i < items.length; i++) {
                        var item = items[i];
                        var li = document.createElement('li');
                        var button = document.createElement('button');
                        var time = document.createElement('span');
                        var title = document.createElement('span');

                        button.type = 'button';
                        button.className = 'radio-replay__chapter';
                        button.setAttribute('data-radio-replay-chapter', i);
                        button.setAttribute('data-radio-replay-item-id', parseInt(item.item_id || 0, 10) || 0);
                        button.setAttribute('data-radio-replay-offset', parseInt(item.offset || 0, 10) || 0);

                        time.className = 'radio-replay__chapter-time';
                        time.textContent = formatTime(parseInt(item.offset || 0, 10) || 0);

                        title.className = 'radio-replay__chapter-title';
                        title.textContent = (item.author ? item.author + ' — ' : '') + (item.title || '');

                        button.appendChild(time);
                        button.appendChild(title);
                        li.appendChild(button);
                        chapterList.appendChild(li);
                    }
                    refreshChapterButtons();
                }

                function replacePlaylist(nextItems) {
                    if (!nextItems) {
                        return;
                    }

                    if (!nextItems.length) {
                        flush();
                        items = [];
                        index = 0;
                        root.setAttribute('data-radio-replay-items', '[]');
                        renderDynamicChapters();
                        updateProgressBounds();
                        audio.pause();
                        audio.removeAttribute('src');
                        audio.load();
                        refreshQueuePreload();
                        if (now) {
                            now.textContent = '';
                        }
                        updateUi();
                        return;
                    }

                    if (!items.length) {
                        items = nextItems;
                        index = 0;
                        root.setAttribute('data-radio-replay-items', JSON.stringify(items));
                        renderDynamicChapters();
                        updateProgressBounds();
                        audio.src = items[0].stream_url || '';
                        audio.load();
                        refreshQueuePreload();
                        updateUi();
                        return;
                    }

                    var currentId = activeItemId();
                    var currentMediaId = items[index] ? parseInt(items[index].media_id || 0, 10) || 0 : 0;
                    var nextIndex = -1;
                    for (var i = 0; i < nextItems.length; i++) {
                        var sameItem = currentId > 0
                            && (parseInt(nextItems[i].item_id || 0, 10) || 0) === currentId;
                        var sameLegacyMedia = currentId === 0 && currentMediaId > 0
                            && (parseInt(nextItems[i].media_id || 0, 10) || 0) === currentMediaId;
                        if (sameItem || sameLegacyMedia) {
                            nextIndex = i;
                            break;
                        }
                    }

                    if (nextIndex < 0) {
                        return;
                    }

                    items = nextItems;
                    index = nextIndex;
                    root.setAttribute('data-radio-replay-items', JSON.stringify(items));
                    renderDynamicChapters();
                    updateProgressBounds();
                    refreshQueuePreload();
                    updateUi();
                }

                toggle.addEventListener('click', function () {
                    if (audio.paused) {
                        if (audio.ended || !itemPlayable(index)) {
                            var startIndex = audio.ended ? 0 : index;
                            var playableIndex = nextPlayableIndex(startIndex);
                            if (playableIndex >= 0) {
                                setItem(playableIndex, 0, true);
                            }
                            return;
                        }
                        audio.play().catch(function () {});
                    } else {
                        if (queueMixing) {
                            stopQueueFade();
                        }
                        audio.pause();
                    }
                });

                progress.addEventListener('input', function () {
                    if (current) {
                        current.textContent = formatTime(progress.value);
                    }
                });

                progress.addEventListener('change', function () {
                    seekGlobal(progress.value, !audio.paused);
                });

                if (chapterList) {
                    chapterList.addEventListener('click', function (event) {
                        var target = event.target;
                        while (target && target !== chapterList
                            && !target.hasAttribute('data-radio-replay-chapter')) {
                            target = target.parentNode;
                        }
                        if (!target || target === chapterList) {
                            return;
                        }
                        var seconds = parseInt(target.getAttribute('data-radio-replay-offset') || '0', 10) || 0;
                        seekGlobal(seconds, true);
                    });
                }

                root.addEventListener('radio:playlist-update', function (event) {
                    if (event && event.detail && event.detail.items) {
                        replacePlaylist(event.detail.items);
                    }
                });

                function onReplayPlay(event) {
                    if (event.currentTarget !== audio) {
                        return;
                    }
                    if (trackingEnabled && !started && items[index]) {
                        started = true;
                        postEvent(
                            eventEndpoint,
                            parseInt(items[index].media_id || 0, 10) || 0,
                            programId,
                            'play',
                            trackingSource,
                            0
                        );
                    }
                    if (trackingEnabled) {
                        listenStart = Date.now();
                    }
                    updateUi();
                }

                function onReplayPause(event) {
                    if (event.currentTarget !== audio) {
                        return;
                    }
                    flush();
                    updateUi();
                }

                function onReplayTimeUpdate(event) {
                    if (event.currentTarget !== audio) {
                        return;
                    }
                    updateUi();
                    startQueueCrossfade();
                }

                function finishQueueTransitionAtEnded(nextIndex) {
                    if (nextIndex !== index + 1 || !items[nextIndex]
                        || queuePreloadUrl !== (items[nextIndex].stream_url || '')
                        || queuePreload.readyState < 2) {
                        return false;
                    }

                    if (queueFadeFrame) {
                        window.cancelAnimationFrame(queueFadeFrame);
                        queueFadeFrame = 0;
                    }

                    queueMixing = false;
                    audio.volume = 1;
                    queuePreload.volume = 1;

                    advanceQueueAudioRole(nextIndex);

                    if (audio.paused) {
                        var resumePromise = audio.play();
                        if (resumePromise && typeof resumePromise.catch === 'function') {
                            resumePromise.catch(function () {});
                        }
                    }
                    return true;
                }

                function promoteQueuePreload(nextIndex) {
                    if (nextIndex !== index + 1 || !items[nextIndex]
                        || queuePreloadUrl !== (items[nextIndex].stream_url || '')
                        || queuePreload.readyState < 2) {
                        return false;
                    }

                    advanceQueueAudioRole(nextIndex);

                    var playPromise = audio.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(function () {});
                    }
                    return true;
                }

                function onReplayEnded(event) {
                    if (event.currentTarget !== audio) {
                        return;
                    }

                    flush();
                    var followingIndex = nextPlayableIndex(index + 1);

                    if (followingIndex >= 0 && queueMixing) {
                        if (finishQueueTransitionAtEnded(followingIndex)) {
                            return;
                        }
                        stopQueueFade();
                    }

                    if (followingIndex >= 0) {
                        if (promoteQueuePreload(followingIndex)) {
                            return;
                        }
                        started = false;
                        setItem(followingIndex, 0, true);
                    } else {
                        updateUi();
                    }
                }

                audio.addEventListener('play', onReplayPlay);
                audio.addEventListener('pause', onReplayPause);
                audio.addEventListener('timeupdate', onReplayTimeUpdate);
                audio.addEventListener('ended', onReplayEnded);
                queuePreload.addEventListener('play', onReplayPlay);
                queuePreload.addEventListener('pause', onReplayPause);
                queuePreload.addEventListener('timeupdate', onReplayTimeUpdate);
                queuePreload.addEventListener('ended', onReplayEnded);
                queueReserve.addEventListener('play', onReplayPlay);
                queueReserve.addEventListener('pause', onReplayPause);
                queueReserve.addEventListener('timeupdate', onReplayTimeUpdate);
                queueReserve.addEventListener('ended', onReplayEnded);

                window.addEventListener('pagehide', function () {
                    if (queueBufferTimer) {
                        window.clearInterval(queueBufferTimer);
                    }
                    if (queueTransitionTimer) {
                        window.clearInterval(queueTransitionTimer);
                    }
                    stopQueueFade();
                    flush();
                });
                updateProgressBounds();
                refreshQueuePreload();
                updateUi();
            }(roots[r]));
        }
    }

    initReplayPlayers();
    bindTracking();
    bindPersistentPlayerLinks();

    initHomePlayer(q('[data-radio-home-live]'));
}());
