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
            return { setExternal: function () {}, start: function () {} };
        }

        var context = null;
        var analyser = null;
        var sourceNode = null;
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
                sourceNode = context.createMediaElementSource(audio);
                analyser = context.createAnalyser();
                analyser.fftSize = 64;
                data = new Uint8Array(analyser.frequencyBinCount);
                sourceNode.connect(analyser);
                analyser.connect(context.destination);
            } catch (error) {
                fallback = true;
                analyser = null;
            }
        }

        function draw() {
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                return;
            }
            var width = canvas.width;
            var height = canvas.height;
            var bars = 24;
            var gap = 3;
            var barWidth = Math.max(2, (width - (bars - 1) * gap) / bars);
            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = window.getComputedStyle(canvas).color || '#000';

            var i;
            if (audio.paused) {
                for (i = 0; i < bars; i++) {
                    ctx.fillRect(i * (barWidth + gap), height / 2 - 1, barWidth, 2);
                }
            } else if (analyser && data) {
                analyser.getByteFrequencyData(data);
                for (i = 0; i < bars; i++) {
                    var index = Math.floor(i * data.length / bars);
                    var amplitude = Math.max(3, (data[index] / 255) * (height - 4));
                    ctx.fillRect(i * (barWidth + gap), (height - amplitude) / 2, barWidth, amplitude);
                }
            } else {
                var t = Date.now() / 180;
                for (i = 0; i < bars; i++) {
                    var fallbackAmplitude = 4 + Math.abs(Math.sin(t + i * .55)) * (height - 8);
                    ctx.fillRect(i * (barWidth + gap), (height - fallbackAmplitude) / 2, barWidth, fallbackAmplitude);
                }
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
        var listenLabel = root.getAttribute('data-listen-label') || 'Listen';
        var pauseLabel = root.getAttribute('data-pause-label') || 'Pause';
        var audio = q('#radio-home-audio', root);
        var button = q('#radio-home-listen', root);
        var title = q('[data-radio-home-title]', root);
        var progressBox = q('[data-radio-progress]', root);
        var progress = q('progress', progressBox);
        var elapsedNode = q('[data-radio-elapsed]', root);
        var durationNode = q('[data-radio-duration]', root);
        var canvas = q('#radio-home-wave', root);

        if (!endpoint || !audio || !button || !progress || !progressBox) {
            return;
        }

        var mediaId = 0;
        var programId = 0;
        var duration = intAttr(progressBox, 'data-duration');
        var offset = intAttr(progressBox, 'data-offset');
        var userStarted = false;
        var listenStart = 0;
        var wave = waveform(canvas, audio);

        function updateProgress() {
            progress.max = Math.max(1, duration);
            progress.value = Math.max(0, Math.min(duration, offset));
            if (elapsedNode) {
                elapsedNode.textContent = formatTime(offset);
            }
            if (durationNode) {
                durationNode.textContent = formatTime(duration);
            }
        }

        function flush() {
            if (listenStart) {
                postEvent(eventEndpoint, mediaId, programId, 'listen', 'live-home',
                    (Date.now() - listenStart) / 1000);
                listenStart = 0;
            }
        }

        function apply(data, play) {
            if (!data.current_media) {
                flush();
                audio.pause();
                audio.removeAttribute('src');
                audio.load();
                button.textContent = listenLabel;
                return;
            }

            var nextId = parseInt(String(data.current_media.media_id).replace('media:', ''), 10) || 0;
            var nextProgram = data.now_playing
                ? (parseInt(String(data.now_playing.program_id).replace('program:', ''), 10) || 0)
                : 0;

            duration = parseInt(data.current_media.duration || 0, 10);
            offset = parseInt(data.current_media.offset || 0, 10);
            wave.setExternal(data.current_media.source_kind && data.current_media.source_kind !== 'local');

            if (title) {
                title.textContent = data.current_media.title;
                title.href = data.current_media.url || '#';
            }

            var changed = mediaId !== nextId || audio.getAttribute('src') !== data.current_media.stream_url;
            mediaId = nextId;
            programId = nextProgram;
            updateProgress();

            function seekAndPlay() {
                try {
                    audio.currentTime = offset;
                } catch (error) {}
                if (play && userStarted) {
                    wave.start();
                    audio.play().catch(function () {});
                }
            }

            if (changed) {
                flush();
                audio.src = data.current_media.stream_url;
                audio.load();
                audio.addEventListener('loadedmetadata', function once() {
                    audio.removeEventListener('loadedmetadata', once);
                    seekAndPlay();
                });
            } else {
                if (!audio.paused && Math.abs(audio.currentTime - offset) > 5) {
                    try {
                        audio.currentTime = offset;
                    } catch (error) {}
                }
                if (play && userStarted && audio.paused) {
                    wave.start();
                    audio.play().catch(function () {});
                }
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

        button.addEventListener('click', function () {
            if (!userStarted) {
                userStarted = true;
                wave.start();
                sync(true);
                return;
            }
            if (audio.paused) {
                sync(true);
            } else {
                audio.pause();
            }
        });

        audio.addEventListener('play', function () {
            button.textContent = pauseLabel;
            postEvent(eventEndpoint, mediaId, programId, 'play', 'live-home', 0);
            listenStart = Date.now();
        });
        audio.addEventListener('pause', function () {
            button.textContent = listenLabel;
            flush();
        });
        audio.addEventListener('ended', function () {
            flush();
            sync(true);
        });
        window.addEventListener('pagehide', flush);

        window.setInterval(function () {
            if (offset < duration) {
                offset++;
                updateProgress();
            }
        }, 1000);

        window.setInterval(function () {
            sync(userStarted && !audio.paused);
        }, 15000);

        updateProgress();
        wave.draw();
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
        var player = q('#radio-live-player', root);
        var status = q('#radio-live-status', root);
        var program = q('#radio-live-program', root);
        var media = q('#radio-live-media', root);
        var start = q('#radio-live-start', root);

        if (!endpoint || !player || !status || !program || !media || !start) {
            return;
        }

        var activeMedia = '';
        var activeMediaId = 0;
        var activeProgramId = 0;
        var listenStart = 0;
        var userStarted = false;

        function flush() {
            if (listenStart) {
                postEvent(eventEndpoint, activeMediaId, activeProgramId, 'listen', 'live',
                    (Date.now() - listenStart) / 1000);
                listenStart = 0;
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
                                player.play().catch(function () {
                                    status.textContent = unavailableLabel;
                                });
                            }
                        });
                    } else if (Math.abs(player.currentTime - data.current_media.offset) > 5 && !player.paused) {
                        try {
                            player.currentTime = data.current_media.offset;
                        } catch (error) {}
                    }
                })
                .catch(function () {
                    status.textContent = unavailableLabel;
                });
        }

        start.addEventListener('click', function () {
            userStarted = true;
            sync(true);
        });
        player.addEventListener('play', function () {
            if (activeMediaId) {
                postEvent(eventEndpoint, activeMediaId, activeProgramId, 'play', 'live', 0);
                listenStart = Date.now();
            }
        });
        player.addEventListener('pause', flush);
        player.addEventListener('ended', function () {
            sync(true);
        });
        window.addEventListener('pagehide', flush);
        window.setInterval(function () {
            if (userStarted) {
                sync(false);
            }
        }, 15000);
        sync(false);
    }

    bindTracking();

    initHomePlayer(q('[data-radio-home-live]'));
    initLivePage(q('.radio-live[data-now-endpoint]'));
}());
