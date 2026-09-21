(function () {
    'use strict';

    function q(selector, root) {
        return (root || document).querySelector(selector);
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
        var wave = waveform(canvas, audio);

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

        function apply(data, play) {
            if (!data.current_media) {
                flush();
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                return;
            }

            var nextId = parseInt(String(data.current_media.media_id).replace('media:', ''), 10) || 0;
            var nextProgram = data.now_playing
                ? (parseInt(String(data.now_playing.program_id).replace('program:', ''), 10) || 0)
                : 0;
            var offset = parseInt(data.current_media.offset || 0, 10);
            var streamUrl = data.current_media.stream_url || '';

            wave.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

            if (title) {
                title.textContent = data.current_media.title;
                title.href = data.current_media.url || '#';
            }

            var changed = mediaId !== nextId || audio.getAttribute('src') !== streamUrl;
            mediaId = nextId;
            programId = nextProgram;

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

        function sync(play) {
            fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    apply(data, play);
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
            sync(true);
        });
        window.addEventListener('pagehide', flush);

        window.setInterval(function () {
            sync(userStarted && !audio.paused);
        }, 15000);

        wave.draw();
        sync(false);
    }

    function initLivePage(root) {
        if (!root) {
            return;
        }

        var endpoint = root.getAttribute('data-now-endpoint');
        var eventEndpoint = root.getAttribute('data-event-endpoint');
        var nothingLabel = root.getAttribute('data-nothing-label') || '';
        var onAirLabel = root.getAttribute('data-on-air-label') || 'On air';
        var incompleteLabel = root.getAttribute('data-incomplete-label') || '';
        var unavailableLabel = root.getAttribute('data-unavailable-label') || '';
        var listenLabel = root.getAttribute('data-listen-label') || 'Listen';
        var pauseLabel = root.getAttribute('data-pause-label') || 'Pause';
        var player = q('#radio-live-player', root);
        var status = q('#radio-live-status', root);
        var program = q('#radio-live-program', root);
        var media = q('#radio-live-media', root);
        var start = q('#radio-live-start', root);
        var canvas = q('#radio-live-wave', root);

        if (!endpoint || !player || !status || !program || !media || !start) {
            return;
        }

        var activeMedia = '';
        var activeMediaId = 0;
        var activeProgramId = 0;
        var listenStart = 0;
        var userStarted = false;
        var wave = waveform(canvas, player);

        function flush() {
            if (listenStart) {
                postEvent(eventEndpoint, activeMediaId, activeProgramId, 'listen', 'live',
                    (Date.now() - listenStart) / 1000);
                listenStart = 0;
            }
        }

        function updateControl() {
            var playing = !player.paused && !player.ended;
            start.textContent = playing ? pauseLabel : listenLabel;
            start.setAttribute('aria-label', playing ? pauseLabel : listenLabel);
        }

        function sync(play) {
            fetch(endpoint, { cache: 'no-store', credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.now_playing && !data.current_media) {
                        program.textContent = '';
                        media.textContent = '';
                        status.textContent = nothingLabel;
                        player.removeAttribute('src');
                        player.load();
                        return;
                    }

                    program.textContent = data.now_playing ? data.now_playing.title : onAirLabel;
                    if (!data.current_media) {
                        media.textContent = incompleteLabel;
                        status.textContent = '';
                        player.removeAttribute('src');
                        player.load();
                        return;
                    }

                    media.textContent = data.current_media.title;
                    status.textContent = '';
                    wave.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

                    if (activeMedia !== data.current_media.media_id) {
                        flush();
                        activeMedia = data.current_media.media_id;
                        activeMediaId = parseInt(String(data.current_media.media_id).replace('media:', ''), 10) || 0;
                        activeProgramId = data.now_playing
                            ? (parseInt(String(data.now_playing.program_id).replace('program:', ''), 10) || 0)
                            : 0;
                        player.src = data.current_media.stream_url;
                        player.load();
                        player.addEventListener('loadedmetadata', function once() {
                            player.removeEventListener('loadedmetadata', once);
                            try {
                                player.currentTime = data.current_media.offset;
                            } catch (error) {}
                            if (play && userStarted) {
                                wave.start();
                                player.play().catch(function () {
                                    status.textContent = unavailableLabel;
                                });
                            }
                        });
                    } else {
                        if (Math.abs(player.currentTime - data.current_media.offset) > 5 && !player.paused) {
                            try {
                                player.currentTime = data.current_media.offset;
                            } catch (error) {}
                        }
                        if (play && userStarted && player.paused) {
                            wave.start();
                            player.play().catch(function () {
                                status.textContent = unavailableLabel;
                            });
                        }
                    }
                })
                .catch(function () {
                    status.textContent = unavailableLabel;
                });
        }

        start.addEventListener('click', function () {
            if (!player.paused && !player.ended) {
                player.pause();
                return;
            }
            userStarted = true;
            sync(true);
        });
        player.addEventListener('play', function () {
            updateControl();
            if (activeMediaId) {
                postEvent(eventEndpoint, activeMediaId, activeProgramId, 'play', 'live', 0);
                listenStart = Date.now();
            }
        });
        player.addEventListener('pause', function () {
            flush();
            updateControl();
        });
        player.addEventListener('ended', function () {
            updateControl();
            sync(true);
        });
        window.addEventListener('pagehide', flush);
        window.setInterval(function () {
            if (userStarted) {
                sync(false);
            }
        }, 15000);
        wave.draw();
        updateControl();
        sync(false);
    }

    bindTracking();

    initHomePlayer(q('[data-radio-home-live]'));
    initLivePage(q('.radio-live[data-now-endpoint]'));
}());
