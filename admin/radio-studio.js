(function () {
    'use strict';

    var studio = document.querySelector('[data-radio-studio]');
    var player = document.querySelector('[data-radio-replay-player]');
    if (!studio || !player || typeof fetch !== 'function') {
        return;
    }

    var endpoint = studio.getAttribute('data-radio-studio-endpoint') || '';
    var mutationEndpoint = studio.getAttribute('data-radio-studio-mutation-endpoint') || '';
    var programId = parseInt(studio.getAttribute('data-radio-program-id') || '0', 10) || 0;
    var tokenName = studio.getAttribute('data-radio-csrf-name') || '';
    var tokenValue = studio.getAttribute('data-radio-csrf-token') || '';
    var form = studio.querySelector('[data-radio-studio-search]');
    var results = studio.querySelector('[data-radio-studio-results]');
    var queue = studio.querySelector('[data-radio-studio-queue]');
    var status = studio.querySelector('[data-radio-studio-status]');
    var bufferStatus = studio.querySelector('[data-radio-studio-buffer]');
    var broadcastButton = studio.querySelector('[data-radio-studio-broadcast]');
    var broadcastState = studio.querySelector('[data-radio-studio-broadcast-state]');
    var recordButton = studio.querySelector('[data-radio-studio-record]');
    var recordState = studio.querySelector('[data-radio-studio-record-state]');
    var youtubeButton = studio.querySelector('[data-radio-studio-youtube]');
    var youtubeState = studio.querySelector('[data-radio-studio-youtube-state]');
    var broadcastActive = false;
    var youtubeLiveActive = false;
    var youtubeLiveStarting = false;
    var youtubeLiveStopping = false;
    var youtubeLiveRecorder = null;
    var youtubeLiveSessionId = '';
    var youtubeLiveChunkIndex = 0;
    var youtubeLiveQueue = Promise.resolve();
    var youtubeLiveFailed = false;
    var youtubeLiveMimeType = '';
    var youtubeLivePollTimer = 0;
    var youtubeLiveLastTrack = '';
    var recordingActive = false;
    var recordingStarting = false;
    var recordingRecorder = null;
    var recordingSessionId = '';
    var recordingChunkIndex = 0;
    var recordingQueue = Promise.resolve();
    var recordingFailed = false;
    var recordingMimeType = '';
    var broadcastCurrentItemId = 0;
    var version = '';
    var pollTimer = 0;
    var pollStopped = false;
    var mutationInFlight = 0;
    var stateRevision = 0;
    var stateRequestInFlight = false;
    var stateNextAllowedAt = 0;
    var studioPostSerial = Promise.resolve();

    function setStatus(message) {
        if (status) {
            status.textContent = message || '';
        }
    }

    function setBufferStatus(detail) {
        if (!bufferStatus) {
            return;
        }
        detail = detail || {};

        var currentRemaining = Math.max(0, parseFloat(detail.current_remaining || 0) || 0);
        var nextBuffered = Math.max(0, Math.round(parseFloat(detail.next_buffered || 0) || 0));
        var reserveBuffered = Math.max(0, Math.round(parseFloat(detail.reserve_buffered || 0) || 0));
        var nextDuration = Math.max(0, parseInt(detail.next_duration || 0, 10) || 0);
        var reserveDuration = Math.max(0, parseInt(detail.reserve_duration || 0, 10) || 0);
        var currentLabel = studio.getAttribute('data-buffer-current-label') || 'Current';
        var remainingLabel = studio.getAttribute('data-buffer-remaining-label') || 'remaining';
        var nextLabel = studio.getAttribute('data-buffer-next-label') || 'Next';
        var reserveLabel = studio.getAttribute('data-buffer-reserve-short-label') || 'Reserve';
        var loadingLabel = studio.getAttribute('data-buffer-loading-label') || 'Buffering';

        if (!detail.current_title && !detail.next_title && !detail.reserve_title) {
            bufferStatus.textContent = loadingLabel;
            return;
        }

        function compactTime(seconds) {
            seconds = Math.max(0, Math.ceil(parseFloat(seconds || 0) || 0));
            var minutes = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return (minutes < 10 ? '0' : '') + minutes + ':' + (secs < 10 ? '0' : '') + secs;
        }

        var parts = [];
        if (detail.current_title) {
            var currentPart = currentLabel + ': '
                + (detail.current_type ? detail.current_type + ' · ' : '')
                + detail.current_title;
            if (currentRemaining > 0) {
                currentPart += ' · ' + compactTime(currentRemaining) + ' ' + remainingLabel;
            }
            parts.push(currentPart);
        }
        if (detail.next_title) {
            var nextPart = nextLabel + ': '
                + (detail.next_type ? detail.next_type + ' · ' : '')
                + detail.next_title + ' · '
                + nextBuffered + (nextDuration > 0 ? '/' + nextDuration : '') + ' s';
            parts.push(nextPart);
        }
        if (detail.reserve_title) {
            var reservePart = reserveLabel + ': '
                + (detail.reserve_type ? detail.reserve_type + ' · ' : '')
                + detail.reserve_title + ' · '
                + reserveBuffered + (reserveDuration > 0 ? '/' + reserveDuration : '') + ' s';
            parts.push(reservePart);
        }

        while (bufferStatus.firstChild) {
            bufferStatus.removeChild(bufferStatus.firstChild);
        }
        for (var partIndex = 0; partIndex < parts.length; partIndex++) {
            var line = document.createElement('div');
            line.className = 'radio-studio__buffer-line';
            line.textContent = parts[partIndex];
            bufferStatus.appendChild(line);
        }
    }

    player.addEventListener('radio:buffer-status', function (event) {
        var detail = event && event.detail ? event.detail : {};
        setBufferStatus(detail);
        if (youtubeLiveActive && detail.current_title
            && detail.current_title !== youtubeLiveLastTrack) {
            updateYoutubeLiveMetadata(detail.current_title);
        }
    });


    function dispatchDjFx(control, value) {
        var event;
        var detail = {control: control, value: value};
        if (typeof CustomEvent === 'function') {
            event = new CustomEvent('radio:djfx', {detail: detail});
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('radio:djfx', false, false, detail);
        }
        player.dispatchEvent(event);
    }

    function updateDjValue(range) {
        if (!range || !djFx) {
            return;
        }
        var control = range.getAttribute('data-radio-djfx') || '';
        var output = djFx.querySelector('[data-radio-djfx-value="' + control + '"]');
        if (!output) {
            return;
        }
        var value = parseFloat(range.value || '0') || 0;
        if (control === 'filter') {
            output.textContent = value > 0 ? '+' + value : String(value);
        } else if (control === 'pan') {
            output.textContent = value < 0 ? 'L' + Math.abs(value) : (value > 0 ? 'R' + value : 'C');
        } else {
            output.textContent = (value > 0 ? '+' : '') + value + ' dB';
        }
    }

    var djFx = studio.querySelector('[data-radio-studio-djfx]');
    if (djFx) {
        var djRanges = djFx.querySelectorAll('[data-radio-djfx]');

        for (var djIndex = 0; djIndex < djRanges.length; djIndex++) {
            updateDjValue(djRanges[djIndex]);
            djRanges[djIndex].addEventListener('input', function () {
                var control = this.getAttribute('data-radio-djfx') || '';
                var value = parseFloat(this.value || '0') || 0;

                if (control === 'pan') {
                    var snap = parseFloat(this.getAttribute('data-radio-djfx-center-snap') || '0') || 0;
                    if (snap > 0 && Math.abs(value) <= snap) {
                        value = 0;
                        this.value = '0';
                    }
                }

                updateDjValue(this);
                dispatchDjFx(control, value);
            });
        }

        var djSpringControls = djFx.querySelectorAll('[data-radio-djfx-spring]');
        for (var springIndex = 0; springIndex < djSpringControls.length; springIndex++) {
            (function (spring) {
                var control = spring.getAttribute('data-radio-djfx-spring') || '';
                var range = djFx.querySelector('[data-radio-djfx="' + control + '"]');
                var springOutput = djFx.querySelector('[data-radio-djfx-value="' + control + '"]');

                function updateSpringValue(value) {
                    if (!springOutput) {
                        return;
                    }
                    if (control === 'pan') {
                        springOutput.textContent = value < 0
                            ? 'L' + Math.abs(value)
                            : (value > 0 ? 'R' + value : 'C');
                    } else {
                        springOutput.textContent = value > 0 ? '+' + value : String(value);
                    }
                }

                function applySpring() {
                    var value = parseFloat(spring.value || '0') || 0;
                    if (range) {
                        range.value = String(value);
                        updateDjValue(range);
                    }
                    updateSpringValue(value);
                    dispatchDjFx(control, value);
                }

                function releaseSpring() {
                    spring.value = '0';
                    if (range) {
                        range.value = '0';
                        updateDjValue(range);
                    }
                    updateSpringValue(0);
                    dispatchDjFx(control, 0);
                }

                spring.addEventListener('input', applySpring);
                spring.addEventListener('change', releaseSpring);
                spring.addEventListener('pointerup', releaseSpring);
                spring.addEventListener('pointercancel', releaseSpring);
                spring.addEventListener('touchend', releaseSpring);
                spring.addEventListener('keyup', function (event) {
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight'
                        || event.key === 'Home' || event.key === 'End') {
                        releaseSpring();
                    }
                });
            }(djSpringControls[springIndex]));
        }

        var djPushTimers = {};
        var djPushButtons = djFx.querySelectorAll('[data-radio-djfx-push]');
        for (var pushIndex = 0; pushIndex < djPushButtons.length; pushIndex++) {
            djPushButtons[pushIndex].addEventListener('click', function () {
                var control = this.getAttribute('data-radio-djfx-push') || '';
                var value = parseFloat(this.getAttribute('data-radio-djfx-push-value') || '0') || 0;
                var range = djFx.querySelector('[data-radio-djfx="' + control + '"]');
                if (!control || !range) {
                    return;
                }

                if (djPushTimers[control]) {
                    window.clearTimeout(djPushTimers[control]);
                }

                range.value = String(value);
                updateDjValue(range);
                dispatchDjFx(control, value);
                this.classList.add('is-active');

                var button = this;
                djPushTimers[control] = window.setTimeout(function () {
                    range.value = '0';
                    updateDjValue(range);
                    dispatchDjFx(control, 0);
                    button.classList.remove('is-active');
                    djPushTimers[control] = 0;
                }, 220);
            });
        }

        var djButtons = djFx.querySelectorAll('[data-radio-djfx-button]');
        for (var djButtonIndex = 0; djButtonIndex < djButtons.length; djButtonIndex++) {
            djButtons[djButtonIndex].addEventListener('click', function () {
                var control = this.getAttribute('data-radio-djfx-button') || '';
                if (control === 'echo') {
                    var active = this.getAttribute('aria-pressed') !== 'true';
                    this.setAttribute('aria-pressed', active ? 'true' : 'false');
                    this.classList.toggle('is-active', active);
                    dispatchDjFx(control, active ? 1 : 0);
                    return;
                }

                if (control === 'reset') {
                    for (var resetIndex = 0; resetIndex < djRanges.length; resetIndex++) {
                        if (djRanges[resetIndex].getAttribute('data-radio-djfx') === 'headroom') {
                            continue;
                        }
                        djRanges[resetIndex].value = '0';
                        updateDjValue(djRanges[resetIndex]);
                    }
                    var echoButton = djFx.querySelector('[data-radio-djfx-button="echo"]');
                    if (echoButton) {
                        echoButton.setAttribute('aria-pressed', 'false');
                        echoButton.classList.remove('is-active');
                    }

                    var springControls = djFx.querySelectorAll('[data-radio-djfx-spring]');
                    for (var springResetIndex = 0; springResetIndex < springControls.length; springResetIndex++) {
                        springControls[springResetIndex].value = '0';
                    }
                    var panOutput = djFx.querySelector('[data-radio-djfx-value="pan"]');
                    if (panOutput) {
                        panOutput.textContent = 'C';
                    }
                }

                dispatchDjFx(control, 1);
            });
        }

        var padStorageKey = 'radio.djfx.pads.' + programId;
        var savedPads = {};
        try {
            savedPads = JSON.parse(window.localStorage.getItem(padStorageKey) || '{}') || {};
        } catch (error) {
            savedPads = {};
        }

        function savePads() {
            try {
                window.localStorage.setItem(padStorageKey, JSON.stringify(savedPads));
            } catch (error) {}
        }

        function assignPad(select) {
            var pad = parseInt(select.getAttribute('data-radio-djfx-pad-select') || '0', 10) || 0;
            if (!pad) {
                return;
            }

            var option = select.options[select.selectedIndex] || null;
            var mediaId = parseInt(select.value || '0', 10) || 0;
            var url = option ? (option.getAttribute('data-stream-url') || '') : '';
            var label = option ? (option.textContent || '') : '';

            savedPads[pad] = {
                media_id: mediaId,
                url: url,
                label: label
            };
            savePads();

            var padLabel = djFx.querySelector('[data-radio-djfx-pad-label="' + pad + '"]');
            var padButton = djFx.querySelector('[data-radio-djfx-pad-play="' + pad + '"]');
            if (padLabel) {
                padLabel.textContent = mediaId && label ? label : 'PAD ' + pad;
            }
            if (padButton) {
                padButton.disabled = !mediaId || !url;
                padButton.classList.toggle('is-assigned', !!mediaId && !!url);
            }

            // Keep the sample engine fed from the single Studio pad assignment.
            dispatchDjFx('sample-assign', {
                pad: pad,
                media_id: mediaId,
                url: url,
                label: label
            });
        }

        var padSelects = djFx.querySelectorAll('[data-radio-djfx-pad-select]');
        for (var padSelectIndex = 0; padSelectIndex < padSelects.length; padSelectIndex++) {
            var padSelect = padSelects[padSelectIndex];
            var padNumber = parseInt(padSelect.getAttribute('data-radio-djfx-pad-select') || '0', 10) || 0;
            var savedPad = savedPads[padNumber] || null;

            if (savedPad && savedPad.media_id) {
                padSelect.value = String(savedPad.media_id);
            }

            padSelect.addEventListener('change', function () {
                assignPad(this);
            });

            assignPad(padSelect);
        }

        var padButtons = djFx.querySelectorAll('[data-radio-djfx-pad-play]');
        for (var padButtonIndex = 0; padButtonIndex < padButtons.length; padButtonIndex++) {
            padButtons[padButtonIndex].addEventListener('click', function () {
                var pad = parseInt(this.getAttribute('data-radio-djfx-pad-play') || '0', 10) || 0;
                if (pad) {
                    dispatchDjFx('sample-play', {pad: pad});
                }
            });
        }
    }


    var focusButton = studio.querySelector('[data-radio-studio-focus]');
    var focusStorageKey = 'radio.studio.focus.' + programId;

    function setStudioFocus(active) {
        active = !!active;
        document.documentElement.classList.toggle('radio-studio-focus-active', active);
        document.body.classList.toggle('radio-studio-focus-active', active);
        studio.classList.toggle('radio-studio--focus', active);

        if (focusButton) {
            focusButton.setAttribute('aria-pressed', active ? 'true' : 'false');
            focusButton.textContent = active
                ? (focusButton.getAttribute('data-focus-off-label') || 'Exit dark focus')
                : (focusButton.getAttribute('data-focus-on-label') || 'Dark focus');
        }

        try {
            window.localStorage.setItem(focusStorageKey, active ? '1' : '0');
        } catch (error) {}

        if (active) {
            // The analyser shares the same Web Audio graph as the Studio player.
            dispatchDjFx('activate', 1);
        }
    }

    if (focusButton) {
        focusButton.addEventListener('click', function () {
            setStudioFocus(!studio.classList.contains('radio-studio--focus'));
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && studio.classList.contains('radio-studio--focus')) {
            setStudioFocus(false);
        }
    });

    try {
        if (window.localStorage.getItem(focusStorageKey) === '1') {
            setStudioFocus(true);
        }
    } catch (error) {}

    function bindStudioActionDelegation() {
        document.addEventListener('click', function (event) {
            var button = event.target;
            while (button && button !== document
                && !button.hasAttribute('data-radio-studio-action')
                && !button.hasAttribute('data-radio-studio-add')) {
                button = button.parentNode;
            }

            if (!button || button === document || button.disabled) {
                return;
            }

            if (button.hasAttribute('data-radio-studio-action')) {
                event.preventDefault();
                var action = button.getAttribute('data-radio-studio-action') || '';
                var itemId = parseInt(button.getAttribute('data-radio-studio-item-id') || '0', 10) || 0;
                if (itemId && (action === 'move_up' || action === 'move_down' || action === 'remove')) {
                    mutateItem(action, itemId);
                }
                return;
            }

            if (button.hasAttribute('data-radio-studio-add')) {
                event.preventDefault();
                var mediaId = parseInt(button.getAttribute('data-radio-studio-add') || '0', 10) || 0;
                var position = button.getAttribute('data-radio-studio-position') || 'end';
                if (mediaId) {
                    addMedia(mediaId, position);
                }
            }
        });
    }

    bindStudioActionDelegation();

    function interceptStudioInlineForms() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.classList || !form.classList.contains('radio-studio__inline-form')) {
                return;
            }

            var actionInput = form.querySelector('input[name="studio_action"]');
            if (!actionInput) {
                return;
            }

            event.preventDefault();

            var action = actionInput.value || '';
            var itemInput = form.querySelector('input[name="item_id"]');
            var mediaInput = form.querySelector('input[name="media_id"]');
            var positionInput = form.querySelector('input[name="position"]');

            if (action === 'remove' || action === 'move_up' || action === 'move_down') {
                var itemId = itemInput ? (parseInt(itemInput.value || '0', 10) || 0) : 0;
                if (itemId) {
                    mutateItem(action, itemId);
                }
                return;
            }

            if (action === 'add') {
                var mediaId = mediaInput ? (parseInt(mediaInput.value || '0', 10) || 0) : 0;
                var position = positionInput ? (positionInput.value || 'end') : 'end';
                if (mediaId) {
                    addMedia(mediaId, position);
                }
            }
        });
    }

    interceptStudioInlineForms();

    function updateToken(data) {
        if (!data) {
            return;
        }
        if (data.csrf_name) {
            tokenName = data.csrf_name;
            studio.setAttribute('data-radio-csrf-name', tokenName);
        }
        if (data.csrf_token) {
            tokenValue = data.csrf_token;
            studio.setAttribute('data-radio-csrf-token', tokenValue);
        }
    }

    function queueStudioPost(callback) {
        var request = studioPostSerial.then(callback);
        studioPostSerial = request.catch(function () {});
        return request;
    }

    function studioPost(formData) {
        return queueStudioPost(function () {
            if (tokenName && tokenValue) {
                formData.append(tokenName, tokenValue);
            }
            if (!formData.has('program_id')) {
                formData.append('program_id', String(programId));
            }

            return fetch(mutationEndpoint || endpoint, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (error) {
                        var responseType = /^\s*</.test(text) ? 'html_response' : 'invalid_json';
                        var detail = 'http_' + response.status;
                        if (response.redirected) {
                            detail += '_redirected';
                        }

                        if (responseType === 'html_response') {
                            var titleMatch = text.match(/<title[^>]*>([\s\S]*?)<\/title>/i);
                            if (titleMatch && titleMatch[1]) {
                                var title = titleMatch[1]
                                    .replace(/<[^>]+>/g, ' ')
                                    .replace(/\s+/g, ' ')
                                    .trim();
                                if (title) {
                                    detail += '_' + title.substring(0, 120);
                                }
                            }
                        }

                        var serverStage = response.headers.get('X-Radio-Studio-Stage');
                        if (serverStage) {
                            detail += '_stage_' + serverStage;
                        }

                        if (!text || !text.trim()) {
                            detail += '_empty_response';
                        } else if (responseType === 'invalid_json') {
                            var plain = text
                                .replace(/\s+/g, ' ')
                                .trim()
                                .substring(0, 160);
                            if (plain) {
                                detail += '_' + plain;
                            }
                        }

                        throw new Error(responseType + ':' + detail);
                    }
                    updateToken(data);
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || ('http_' + response.status));
                    }
                    return data;
                });
            });
        });
    }

    function setRecordingUi(active, message) {
        recordingActive = !!active;
        if (recordButton) {
            recordButton.disabled = recordingStarting;
            recordButton.setAttribute('aria-pressed', recordingActive ? 'true' : 'false');
            recordButton.classList.toggle('is-active', recordingActive);
            recordButton.textContent = recordingActive
                ? (studio.getAttribute('data-record-stop-label') || 'Stop recording')
                : (studio.getAttribute('data-record-start-label') || 'Record');
        }
        if (recordState) {
            recordState.textContent = message || (recordingActive
                ? (studio.getAttribute('data-recording-label') || 'Recording master mix')
                : '');
        }
        studio.classList.toggle('is-recording', recordingActive);
    }

    function recordingSupportedMime() {
        if (typeof window.MediaRecorder !== 'function') {
            return '';
        }
        var candidates = [
            'audio/webm;codecs=opus',
            'audio/ogg;codecs=opus',
            'audio/webm',
            'audio/mp4'
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (typeof window.MediaRecorder.isTypeSupported !== 'function'
                || window.MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

    function recordingMasterStream() {
        if (!player.radioStudioMaster
            || typeof player.radioStudioMaster.getStream !== 'function') {
            return null;
        }
        try {
            if (typeof player.radioStudioMaster.prepare === 'function') {
                player.radioStudioMaster.prepare();
            }
            return player.radioStudioMaster.getStream();
        } catch (error) {
            return null;
        }
    }

    function abortRecordingSession() {
        if (!recordingSessionId) {
            return Promise.resolve();
        }
        var data = new FormData();
        data.append('studio_action', 'recording_abort');
        data.append('session_id', recordingSessionId);
        return studioPost(data).catch(function () {});
    }

    function failRecording(error) {
        recordingFailed = true;
        recordingStarting = false;
        var detail = error && error.message ? String(error.message) : '';
        setRecordingUi(false, (studio.getAttribute('data-record-failed-label') || 'Studio recording failed.')
            + (detail ? ' (' + detail + ')' : ''));

        if (recordingRecorder && recordingRecorder.state !== 'inactive') {
            try {
                recordingRecorder.stop();
            } catch (stopError) {}
        }
    }

    function uploadRecordingChunk(blob, index) {
        var data = new FormData();
        data.append('studio_action', 'recording_chunk');
        data.append('session_id', recordingSessionId);
        data.append('chunk_index', String(index));
        data.append('chunk', blob, 'chunk-' + index + '.bin');
        return studioPost(data);
    }

    function finalizeRecording() {
        return recordingQueue.then(function () {
            if (recordingFailed) {
                return abortRecordingSession();
            }

            var data = new FormData();
            data.append('studio_action', 'recording_stop');
            data.append('session_id', recordingSessionId);
            return studioPost(data).then(function (result) {
                var recording = result.recording || {};
                var message = studio.getAttribute('data-record-saved-label') || 'Recording saved.';
                if (recording.filename) {
                    message += ' ' + recording.filename;
                }
                recordingStarting = false;
                setRecordingUi(false, message);
            });
        }).catch(function (error) {
            failRecording(error);
            return abortRecordingSession();
        }).then(function () {
            recordingRecorder = null;
            recordingSessionId = '';
            recordingChunkIndex = 0;
            recordingQueue = Promise.resolve();
            recordingFailed = false;
            recordingMimeType = '';
        });
    }

    function startRecording() {
        if (recordingActive || recordingStarting) {
            return;
        }

        recordingMimeType = recordingSupportedMime();
        var stream = recordingMasterStream();
        if (!recordingMimeType || !stream || !stream.getAudioTracks || stream.getAudioTracks().length < 1) {
            setRecordingUi(false, studio.getAttribute('data-record-unsupported-label')
                || 'This browser cannot capture the Studio master mix.');
            return;
        }

        recordingStarting = true;
        if (recordButton) {
            recordButton.disabled = true;
        }
        if (recordState) {
            recordState.textContent = studio.getAttribute('data-record-starting-label') || 'Starting recording…';
        }

        var startData = new FormData();
        startData.append('studio_action', 'recording_start');
        startData.append('mime_type', recordingMimeType);

        studioPost(startData).then(function (result) {
            var recording = result.recording || {};
            recordingSessionId = recording.session_id || '';
            if (!recordingSessionId) {
                throw new Error('recording_session_missing');
            }

            var options = recordingMimeType ? {mimeType: recordingMimeType} : undefined;
            recordingRecorder = new window.MediaRecorder(stream, options);
            recordingChunkIndex = 0;
            recordingQueue = Promise.resolve();
            recordingFailed = false;

            recordingRecorder.addEventListener('dataavailable', function (event) {
                if (!event.data || event.data.size < 1 || recordingFailed) {
                    return;
                }
                var chunkIndex = recordingChunkIndex++;
                recordingQueue = recordingQueue.then(function () {
                    return uploadRecordingChunk(event.data, chunkIndex);
                }).catch(function (error) {
                    failRecording(error);
                    throw error;
                });
            });

            recordingRecorder.addEventListener('error', function (event) {
                failRecording(event && event.error ? event.error : new Error('media_recorder_error'));
            });

            recordingRecorder.addEventListener('stop', function () {
                finalizeRecording();
            });

            recordingRecorder.start(2000);
            recordingStarting = false;
            setRecordingUi(true, studio.getAttribute('data-recording-label') || 'Recording master mix');
        }).catch(function (error) {
            recordingStarting = false;
            failRecording(error);
            abortRecordingSession();
        });
    }

    function stopRecording() {
        if (!recordingRecorder || recordingRecorder.state === 'inactive') {
            return;
        }
        if (recordButton) {
            recordButton.disabled = true;
        }
        if (recordState) {
            recordState.textContent = studio.getAttribute('data-record-starting-label') || 'Finalizing recording…';
        }
        try {
            recordingRecorder.requestData();
        } catch (error) {}
        try {
            recordingRecorder.stop();
        } catch (error) {
            failRecording(error);
        }
    }

    if (recordButton) {
        if (typeof window.MediaRecorder !== 'function') {
            recordButton.disabled = true;
            if (recordState) {
                recordState.textContent = studio.getAttribute('data-record-unsupported-label')
                    || 'This browser cannot capture the Studio master mix.';
            }
        } else {
            recordButton.addEventListener('click', function () {
                if (recordingActive) {
                    stopRecording();
                } else {
                    startRecording();
                }
            });
        }
    }

    function youtubeMetricText(live) {
        live = live || {};
        var metrics = live.metrics || {};
        var parts = [];

        if (metrics.fps !== null && typeof metrics.fps !== 'undefined') {
            parts.push((studio.getAttribute('data-youtube-live-fps-label') || 'FPS')
                + ' ' + Number(metrics.fps).toFixed(1));
        }
        if (metrics.bitrate_kbps !== null && typeof metrics.bitrate_kbps !== 'undefined') {
            parts.push((studio.getAttribute('data-youtube-live-bitrate-label') || 'Bitrate')
                + ' ' + Math.round(Number(metrics.bitrate_kbps)) + ' kb/s');
        }
        if (metrics.speed !== null && typeof metrics.speed !== 'undefined') {
            parts.push((studio.getAttribute('data-youtube-live-speed-label') || 'Speed')
                + ' ' + Number(metrics.speed).toFixed(2) + 'x');
        }

        return parts.join(' · ');
    }

    function setYoutubeLiveUi(live) {
        live = live || {state: 'idle'};
        var state = live.state || 'idle';
        var activeState = state === 'starting' || state === 'live' || state === 'stopping';

        youtubeLiveActive = state === 'starting' || state === 'live';
        youtubeLiveStarting = state === 'starting';
        youtubeLiveStopping = state === 'stopping';

        if (live.session_id) {
            youtubeLiveSessionId = live.session_id;
        }

        if (youtubeButton) {
            youtubeButton.disabled = youtubeLiveStopping;
            youtubeButton.setAttribute('aria-pressed', activeState ? 'true' : 'false');
            youtubeButton.classList.toggle('is-active', activeState);
            youtubeButton.textContent = activeState
                ? (studio.getAttribute('data-youtube-live-stop-label') || 'Stop YouTube')
                : (studio.getAttribute('data-youtube-live-start-label') || 'Live YouTube');
        }

        if (youtubeState) {
            var label = '';
            if (state === 'starting') {
                label = studio.getAttribute('data-youtube-live-starting-label') || 'Connecting YouTube Live…';
            } else if (state === 'live') {
                label = studio.getAttribute('data-youtube-live-active-label') || 'YouTube LIVE';
            } else if (state === 'stopping') {
                label = studio.getAttribute('data-youtube-live-stopping-label') || 'Stopping YouTube Live…';
            } else if (state === 'error') {
                label = studio.getAttribute('data-youtube-live-failed-label') || 'Studio YouTube Live failed.';
                if (live.last_error) {
                    label += ' (' + live.last_error + ')';
                }
            }

            var metrics = youtubeMetricText(live);
            youtubeState.textContent = label + (label && metrics ? ' · ' : '') + metrics;
        }

        studio.classList.toggle('is-youtube-live', activeState);

        if (activeState) {
            scheduleYoutubeLivePoll();
        } else if (youtubeLivePollTimer) {
            window.clearTimeout(youtubeLivePollTimer);
            youtubeLivePollTimer = 0;
        }
    }

    function pollYoutubeLiveStatus() {
        if (!youtubeButton || pollStopped) {
            return;
        }

        fetch(studioUrl('live_status'), {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            updateToken(data);
            if (data.ok && data.youtube_live) {
                setYoutubeLiveUi(data.youtube_live);
            }
        }).catch(function () {
            // Keep the current visible state; the server-side timeout owns cleanup.
        }).then(function () {
            if (youtubeLiveActive || youtubeLiveStarting || youtubeLiveStopping) {
                scheduleYoutubeLivePoll();
            }
        });
    }

    function scheduleYoutubeLivePoll() {
        if (pollStopped || youtubeLivePollTimer) {
            return;
        }
        youtubeLivePollTimer = window.setTimeout(function () {
            youtubeLivePollTimer = 0;
            pollYoutubeLiveStatus();
        }, 2000);
    }

    function failYoutubeLive(error) {
        youtubeLiveFailed = true;
        youtubeLiveStarting = false;
        youtubeLiveStopping = false;
        youtubeLiveActive = false;

        if (youtubeLiveRecorder && youtubeLiveRecorder.state !== 'inactive') {
            try {
                youtubeLiveRecorder.stop();
            } catch (stopError) {}
        }

        setYoutubeLiveUi({
            state: 'error',
            session_id: youtubeLiveSessionId,
            last_error: error && error.message ? String(error.message) : 'studio_youtube_live_failed'
        });
    }

    function uploadYoutubeLiveChunk(blob, index) {
        var data = new FormData();
        data.append('studio_action', 'youtube_live_chunk');
        data.append('session_id', youtubeLiveSessionId);
        data.append('chunk_index', String(index));
        data.append('chunk', blob, 'live-' + index + '.bin');

        return studioPost(data).then(function (result) {
            if (result.youtube_live) {
                setYoutubeLiveUi(result.youtube_live);
            }
            return result;
        });
    }

    function updateYoutubeLiveMetadata(title) {
        title = String(title || '');
        if (!youtubeLiveActive || !youtubeLiveSessionId || !title
            || title === youtubeLiveLastTrack) {
            return;
        }

        youtubeLiveLastTrack = title;
        var data = new FormData();
        data.append('studio_action', 'youtube_live_metadata');
        data.append('session_id', youtubeLiveSessionId);
        data.append('track_title', title);
        studioPost(data).catch(function () {
            // Metadata failure must not interrupt the audio stream.
        });
    }

    function startYoutubeLive() {
        if (youtubeLiveActive || youtubeLiveStarting || youtubeLiveStopping) {
            return;
        }

        youtubeLiveMimeType = recordingSupportedMime();
        var stream = recordingMasterStream();
        if (!youtubeLiveMimeType || !stream || !stream.getAudioTracks
            || stream.getAudioTracks().length < 1) {
            if (youtubeState) {
                youtubeState.textContent = studio.getAttribute('data-youtube-live-unsupported-label')
                    || 'This browser cannot send the Studio master mix to YouTube.';
            }
            return;
        }

        youtubeLiveStarting = true;
        youtubeLiveFailed = false;
        youtubeLiveLastTrack = '';
        setYoutubeLiveUi({state: 'starting'});

        var startData = new FormData();
        startData.append('studio_action', 'youtube_live_start');
        startData.append('mime_type', youtubeLiveMimeType);

        studioPost(startData).then(function (result) {
            var live = result.youtube_live || {};
            youtubeLiveSessionId = live.session_id || '';
            if (!youtubeLiveSessionId) {
                throw new Error('studio_youtube_session_missing');
            }

            var options = youtubeLiveMimeType ? {mimeType: youtubeLiveMimeType} : undefined;
            youtubeLiveRecorder = new window.MediaRecorder(stream, options);
            youtubeLiveChunkIndex = 0;
            youtubeLiveQueue = Promise.resolve();
            youtubeLiveFailed = false;

            youtubeLiveRecorder.addEventListener('dataavailable', function (event) {
                if (!event.data || event.data.size < 1 || youtubeLiveFailed) {
                    return;
                }
                var chunkIndex = youtubeLiveChunkIndex++;
                youtubeLiveQueue = youtubeLiveQueue.then(function () {
                    return uploadYoutubeLiveChunk(event.data, chunkIndex);
                }).catch(function (error) {
                    failYoutubeLive(error);
                    throw error;
                });
            });

            youtubeLiveRecorder.addEventListener('error', function (event) {
                failYoutubeLive(event && event.error ? event.error : new Error('media_recorder_error'));
            });

            youtubeLiveRecorder.start(1000);
            youtubeLiveStarting = false;
            setYoutubeLiveUi(live);
            scheduleYoutubeLivePoll();
        }).catch(function (error) {
            failYoutubeLive(error);
        });
    }

    function finalizeYoutubeLiveStop() {
        return youtubeLiveQueue.then(function () {
            if (!youtubeLiveSessionId) {
                return;
            }
            var data = new FormData();
            data.append('studio_action', 'youtube_live_stop');
            data.append('session_id', youtubeLiveSessionId);
            return studioPost(data).then(function (result) {
                if (result.youtube_live) {
                    setYoutubeLiveUi(result.youtube_live);
                }
                scheduleYoutubeLivePoll();
            });
        }).catch(function (error) {
            failYoutubeLive(error);
        }).then(function () {
            youtubeLiveRecorder = null;
            youtubeLiveQueue = Promise.resolve();
            youtubeLiveChunkIndex = 0;
        });
    }

    function stopYoutubeLive() {
        if ((!youtubeLiveActive && !youtubeLiveStarting) || youtubeLiveStopping) {
            return;
        }

        youtubeLiveStopping = true;
        if (youtubeButton) {
            youtubeButton.disabled = true;
        }
        if (youtubeState) {
            youtubeState.textContent = studio.getAttribute('data-youtube-live-stopping-label')
                || 'Stopping YouTube Live…';
        }

        if (youtubeLiveRecorder && youtubeLiveRecorder.state !== 'inactive') {
            youtubeLiveRecorder.addEventListener('stop', function onStop() {
                finalizeYoutubeLiveStop();
            }, {once: true});
            try {
                youtubeLiveRecorder.requestData();
            } catch (error) {}
            try {
                youtubeLiveRecorder.stop();
            } catch (error) {
                failYoutubeLive(error);
            }
        } else {
            finalizeYoutubeLiveStop();
        }
    }

    if (youtubeButton) {
        if (typeof window.MediaRecorder !== 'function') {
            youtubeButton.disabled = true;
            if (youtubeState) {
                youtubeState.textContent = studio.getAttribute('data-youtube-live-unsupported-label')
                    || 'This browser cannot send the Studio master mix to YouTube.';
            }
        } else {
            youtubeButton.addEventListener('click', function () {
                if (youtubeLiveActive || youtubeLiveStarting) {
                    stopYoutubeLive();
                } else {
                    startYoutubeLive();
                }
            });
        }
    }

    function updateBroadcast(data) {
        if (!data) {
            return;
        }

        if (data.youtube_live) {
            setYoutubeLiveUi(data.youtube_live);
        }

        broadcastActive = !!data.broadcast_active;
        broadcastCurrentItemId = parseInt(data.broadcast_current_item_id || 0, 10) || 0;

        if (broadcastButton) {
            broadcastButton.textContent = broadcastActive
                ? (studio.getAttribute('data-broadcast-stop-label') || 'Stop broadcast')
                : (studio.getAttribute('data-broadcast-start-label') || 'Broadcast');
            broadcastButton.classList.toggle('is-live', broadcastActive);
            broadcastButton.setAttribute('aria-pressed', broadcastActive ? 'true' : 'false');
        }

        if (broadcastState) {
            broadcastState.textContent = broadcastActive
                ? (studio.getAttribute('data-broadcast-active-label') || 'Semi-live broadcast active')
                : '';
        }

        studio.classList.toggle('is-broadcasting', broadcastActive);
    }

    function dispatchPlaylist(items) {
        if (!items) {
            return;
        }
        var event;
        if (typeof CustomEvent === 'function') {
            event = new CustomEvent('radio:playlist-update', {
                detail: {items: items}
            });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('radio:playlist-update', false, false, {
                items: items
            });
        }
        player.dispatchEvent(event);
    }

    function queryString(params) {
        var search = new URLSearchParams();
        for (var key in params) {
            if (Object.prototype.hasOwnProperty.call(params, key)
                && params[key] !== '' && params[key] !== null) {
                search.set(key, params[key]);
            }
        }
        return search.toString();
    }

    function studioUrl(action, extra) {
        var params = extra || {};
        params.action = action;
        params.program_id = programId;
        return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + queryString(params);
    }

    function resultMeta(item) {
        var parts = [];
        if (item.author) {
            parts.push(item.author);
        }
        if (item.media_type) {
            parts.push(item.media_type);
        }
        if (parseInt(item.duration || 0, 10) > 0) {
            var seconds = parseInt(item.duration || 0, 10);
            var hours = Math.floor(seconds / 3600);
            var minutes = Math.floor((seconds % 3600) / 60);
            var secs = seconds % 60;
            parts.push(
                (hours > 0 ? hours + ':' : '')
                + (hours > 0 && minutes < 10 ? '0' : '')
                + minutes + ':' + (secs < 10 ? '0' : '') + secs
            );
        }
        return parts.join(' · ');
    }

    function addBadge(container, text) {
        if (!text) {
            return;
        }
        var badge = document.createElement('span');
        badge.className = 'radio-admin__badge';
        badge.textContent = text;
        container.appendChild(badge);
    }

    function renderQueue(items) {
        if (!queue) {
            return;
        }

        queue.innerHTML = '';
        if (!items || !items.length) {
            var empty = document.createElement('p');
            empty.className = 'radio-admin__muted';
            empty.textContent = studio.getAttribute('data-queue-empty-label') || 'Empty programme';
            queue.appendChild(empty);
            return;
        }

        var list = document.createElement('ol');
        list.className = 'radio-studio__queue';

        var currentItemId = parseInt(player.getAttribute('data-radio-current-item-id') || '0', 10) || 0;
        var currentIndex = -1;
        for (var c = 0; c < items.length; c++) {
            if ((parseInt(items[c].item_id || 0, 10) || 0) === currentItemId) {
                currentIndex = c;
                break;
            }
        }

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var itemId = parseInt(item.item_id || 0, 10) || 0;
            var li = document.createElement('li');
            li.className = 'radio-studio__queue-item';
            li.setAttribute('data-item-id', itemId);

            if (itemId === currentItemId && currentItemId > 0) {
                li.classList.add('is-current');
            } else if (currentIndex >= 0 && i === currentIndex + 1) {
                li.classList.add('is-next');
            }

            var type = document.createElement('span');
            type.className = 'radio-admin__badge radio-studio__queue-type';
            type.textContent = item.media_type_label || item.media_type || '';
            li.appendChild(type);

            var duration = document.createElement('span');
            duration.className = 'radio-studio__queue-duration';
            var durationSeconds = parseInt(item.duration || 0, 10) || 0;
            if (durationSeconds > 0) {
                var durationHours = Math.floor(durationSeconds / 3600);
                var durationMinutes = Math.floor((durationSeconds % 3600) / 60);
                var durationSecs = durationSeconds % 60;
                duration.textContent =
                    (durationHours < 10 ? '0' : '') + durationHours + ':'
                    + (durationMinutes < 10 ? '0' : '') + durationMinutes + ':'
                    + (durationSecs < 10 ? '0' : '') + durationSecs;
            } else {
                duration.textContent = '--:--';
            }
            li.appendChild(duration);

            var title = document.createElement('button');
            title.type = 'button';
            title.className = 'radio-studio__queue-title radio-studio__queue-seek';
            title.textContent = (item.author ? item.author + ' — ' : '') + (item.title || '');
            title.addEventListener('click', (function (id) {
                return function () {
                    var event;
                    var detail = {item_id: id};
                    if (typeof CustomEvent === 'function') {
                        event = new CustomEvent('radio:seek-item', {detail: detail});
                    } else {
                        event = document.createEvent('CustomEvent');
                        event.initCustomEvent('radio:seek-item', false, false, detail);
                    }
                    player.dispatchEvent(event);
                };
            }(itemId)));
            li.appendChild(title);

            if (item.playable === false || item.playable === 0) {
                var state = document.createElement('span');
                state.className = 'radio-admin__muted radio-studio__queue-state';
                state.textContent = studio.getAttribute('data-queue-not-ready-label') || 'Not playable';
                li.classList.add('is-not-ready');
                li.appendChild(state);
            }

            var actions = document.createElement('span');
            actions.className = 'radio-program-item__actions';

            var up = document.createElement('button');
            up.type = 'button';
            up.className = 'radio-program-item__action';
            up.textContent = '↑';
            up.title = studio.getAttribute('data-move-up-label') || 'Move up';
            up.setAttribute('aria-label', up.title);
            up.disabled = i === 0;
            up.setAttribute('data-radio-studio-action', 'move_up');
            up.setAttribute('data-radio-studio-item-id', itemId);
            actions.appendChild(up);

            var down = document.createElement('button');
            down.type = 'button';
            down.className = 'radio-program-item__action';
            down.textContent = '↓';
            down.title = studio.getAttribute('data-move-down-label') || 'Move down';
            down.setAttribute('aria-label', down.title);
            down.disabled = i === items.length - 1;
            down.setAttribute('data-radio-studio-action', 'move_down');
            down.setAttribute('data-radio-studio-item-id', itemId);
            actions.appendChild(down);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'radio-program-item__action radio-program-item__action--remove';
            remove.textContent = '−';
            remove.title = studio.getAttribute('data-remove-label') || 'Remove';
            remove.setAttribute('aria-label', remove.title);
            remove.setAttribute('data-radio-studio-action', 'remove');
            remove.setAttribute('data-radio-studio-item-id', itemId);
            actions.appendChild(remove);

            li.appendChild(actions);
            list.appendChild(li);
        }

        queue.appendChild(list);
    }

    function renderResults(items) {
        if (!results) {
            return;
        }
        results.innerHTML = '';

        if (!items || !items.length) {
            var empty = document.createElement('p');
            empty.className = 'radio-admin__muted';
            empty.textContent = studio.getAttribute('data-empty-label') || 'No result';
            results.appendChild(empty);
            return;
        }

        for (var i = 0; i < items.length; i++) {
            (function (item) {
                var row = document.createElement('article');
                row.className = 'radio-program-picker__item';

                var info = document.createElement('div');
                info.className = 'radio-program-picker__info';

                var title = document.createElement('strong');
                title.textContent = item.title || '';
                info.appendChild(title);

                var meta = document.createElement('div');
                meta.className = 'radio-admin__muted';
                meta.textContent = resultMeta(item);
                info.appendChild(meta);

                var classification = document.createElement('div');
                classification.className = 'radio-admin__classification';
                addBadge(classification, item.category || '');
                addBadge(classification, item.collection || '');
                if (item.tags) {
                    var tags = String(item.tags).split(',');
                    for (var t = 0; t < tags.length; t++) {
                        addBadge(classification, tags[t].replace(/^\s+|\s+$/g, ''));
                    }
                }
                info.appendChild(classification);

                var actions = document.createElement('div');
                actions.className = 'radio-studio__result-actions';

                var next = document.createElement('button');
                next.type = 'button';
                next.className = 'radio-admin__button';
                next.textContent = studio.getAttribute('data-play-next-label') || 'Play next';
                next.setAttribute('data-radio-studio-add', item.media_id);
                next.setAttribute('data-radio-studio-position', 'next');
                actions.appendChild(next);

                var end = document.createElement('button');
                end.type = 'button';
                end.className = 'radio-program-picker__add';
                end.textContent = '+';
                end.title = studio.getAttribute('data-add-end-label') || 'Add to end';
                end.setAttribute('aria-label', end.title);
                end.setAttribute('data-radio-studio-add', item.media_id);
                end.setAttribute('data-radio-studio-position', 'end');
                actions.appendChild(end);

                row.appendChild(info);
                row.appendChild(actions);
                results.appendChild(row);
            }(items[i]));
        }
    }

    function search() {
        if (!form) {
            return;
        }

        var data = new FormData(form);
        var params = {};
        data.forEach(function (value, key) {
            if (value !== '') {
                params[key] = value;
            }
        });

        setStatus(studio.getAttribute('data-searching-label') || 'Searching…');

        fetch(studioUrl('search', params), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                updateToken(data);
                updateBroadcast(data);
                if (!data.ok) {
                    throw new Error(data.error || 'search_failed');
                }
                renderResults(data.results || []);
                setStatus('');
            })
            .catch(function () {
                setStatus(studio.getAttribute('data-error-label') || 'Error');
            });
    }

    function mutate(body, successLabel, retried) {
        mutationInFlight++;
        stateRevision++;

        queueStudioPost(function () {
            if (tokenName && tokenValue) {
                body.set(tokenName, tokenValue);
            }

            return fetch(mutationEndpoint || endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                cache: 'no-store'
            });
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (error) {
                        var kind = /^\s*</.test(text) ? 'html_response' : 'invalid_json';
                        throw new Error(kind + '_http_' + response.status);
                    }
                    return {response: response, data: data};
                });
            })
            .then(function (result) {
                var data = result.data || {};
                updateToken(data);
                updateBroadcast(data);

                if (!data.ok) {
                    if (data.error === 'invalid_token' && !retried && tokenName && tokenValue) {
                        body.set(tokenName, tokenValue);
                        return mutate(body, successLabel, true);
                    }
                    var serverDetail = data.error || ('http_' + result.response.status);
                    if (data.message) {
                        serverDetail += ': ' + data.message;
                    }
                    if (data.file && data.line) {
                        serverDetail += ' @ ' + data.file + ':' + data.line;
                    }
                    throw new Error(serverDetail);
                }

                if (data.php_warnings && data.php_warnings.length) {
                    var warning = data.php_warnings[0];
                    setStatus(
                        (studio.getAttribute('data-error-label') || 'Error')
                        + ' (PHP warning: ' + warning.message
                        + ' @ ' + warning.file + ':' + warning.line + ')'
                    );
                }

                version = data.version || version;
                stateRevision++;
                renderQueue(data.items || []);
                dispatchPlaylist(data.items || []);
                setStatus(successLabel || '');
                if (successLabel) {
                    window.setTimeout(function () { setStatus(''); }, 1200);
                }
                return true;
            })
            .catch(function (error) {
                var generic = studio.getAttribute('data-error-label') || 'Error';
                var detail = error && error.message ? String(error.message) : '';
                setStatus(detail && detail !== 'mutation_failed'
                    ? generic + ' (' + detail + ')'
                    : generic);
            })
            .then(function () {
                mutationInFlight = Math.max(0, mutationInFlight - 1);
            }, function () {
                mutationInFlight = Math.max(0, mutationInFlight - 1);
            });
    }

    function mutateItem(action, itemId) {
        var body = new URLSearchParams();
        body.set('program_id', programId);
        body.set('studio_action', action);
        body.set('item_id', itemId);
        mutate(body, '');
    }

    function addMedia(mediaId, position) {
        var body = new URLSearchParams();
        body.set('program_id', programId);
        body.set('studio_action', 'add');
        body.set('media_id', mediaId);
        body.set('position', position);
        body.set(
            'current_item_id',
            broadcastActive && broadcastCurrentItemId
                ? broadcastCurrentItemId
                : (player.getAttribute('data-radio-current-item-id') || '0')
        );

        setStatus(studio.getAttribute('data-adding-label') || 'Adding…');
        mutate(body, studio.getAttribute('data-added-label') || 'Added');
    }

    function syncState() {
        if (mutationInFlight > 0 || stateRequestInFlight || Date.now() < stateNextAllowedAt) {
            return;
        }

        stateRequestInFlight = true;
        var requestRevision = stateRevision;
        fetch(studioUrl('state'), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    stateNextAllowedAt = Date.now() + 15000;
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                stateNextAllowedAt = 0;

                // Ignore a state response that started before a playlist mutation.
                if (requestRevision !== stateRevision || mutationInFlight > 0) {
                    return;
                }
                updateToken(data);
                if (!data.ok) {
                    return;
                }
                updateBroadcast(data);
                if (!version) {
                    version = data.version || '';
                    renderQueue(data.items || []);
                    dispatchPlaylist(data.items || []);
                    return;
                }
                if (data.version && data.version !== version) {
                    version = data.version;
                    renderQueue(data.items || []);
                    dispatchPlaylist(data.items || []);
                } else {
                    renderQueue(data.items || []);
                }
            })
            .catch(function () {
                if (stateNextAllowedAt === 0) {
                    stateNextAllowedAt = Date.now() + 10000;
                }
            })
            .then(function () {
                stateRequestInFlight = false;
                scheduleStatePoll();
            });
    }

    function statePollDelay() {
        if (document.hidden) {
            return 120000;
        }

        /*
         * Audio continuity is entirely local to the Studio player.
         * State polling is only a safety net for changes made elsewhere.
         */
        var base = broadcastActive ? 30000 : 60000;
        var jitter = Math.floor(Math.random() * 10001) - 5000;
        return Math.max(20000, base + jitter);
    }

    function scheduleStatePoll(delay) {
        if (pollStopped) {
            return;
        }
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }
        pollTimer = window.setTimeout(function () {
            pollTimer = 0;
            syncState();
        }, typeof delay === 'number' ? Math.max(0, delay) : statePollDelay());
    }

    if (broadcastButton) {
        broadcastButton.addEventListener('click', function () {
            var body = new URLSearchParams();
            body.set('program_id', programId);
            body.set('studio_action', broadcastActive ? 'broadcast_stop' : 'broadcast_start');
            body.set('current_item_id', player.getAttribute('data-radio-current-item-id') || '0');

            setStatus('');
            mutate(
                body,
                broadcastActive
                    ? (studio.getAttribute('data-broadcast-stopped-label') || 'Broadcast stopped')
                    : (studio.getAttribute('data-broadcast-started-label') || 'Broadcast started')
            );
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            search();
        });
    }

    search();
    syncState();

    document.addEventListener('visibilitychange', function () {
        if (pollStopped) {
            return;
        }
        if (!document.hidden) {
            scheduleStatePoll(1000);
        } else {
            scheduleStatePoll();
        }
    });

    window.addEventListener('pagehide', function () {
        pollStopped = true;
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = 0;
        }

        /*
         * Do not leave the browser recorder running after Studio navigation.
         * Already uploaded chunks remain private and are marked aborted if the
         * final short request can still be sent.
         */
        if (recordingRecorder && recordingRecorder.state !== 'inactive') {
            try {
                recordingRecorder.stop();
            } catch (error) {}
        }

        if (youtubeLivePollTimer) {
            window.clearTimeout(youtubeLivePollTimer);
            youtubeLivePollTimer = 0;
        }
        if (youtubeLiveRecorder && youtubeLiveRecorder.state !== 'inactive') {
            try {
                youtubeLiveRecorder.stop();
            } catch (error) {}
        }
    });
}());
