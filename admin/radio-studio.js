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
    var broadcastActive = false;
    var broadcastCurrentItemId = 0;
    var version = '';
    var pollTimer = 0;

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

        var nextBuffered = Math.max(0, Math.round(parseFloat(detail.next_buffered || 0) || 0));
        var reserveBuffered = Math.max(0, Math.round(parseFloat(detail.reserve_buffered || 0) || 0));
        var nextDuration = Math.max(0, parseInt(detail.next_duration || 0, 10) || 0);
        var reserveDuration = Math.max(0, parseInt(detail.reserve_duration || 0, 10) || 0);
        var nextLabel = studio.getAttribute('data-buffer-next-label') || 'Next';
        var reserveLabel = studio.getAttribute('data-buffer-reserve-short-label') || 'Reserve';
        var loadingLabel = studio.getAttribute('data-buffer-loading-label') || 'Buffering';

        if (!detail.next_title && !detail.reserve_title) {
            bufferStatus.textContent = loadingLabel;
            return;
        }

        var parts = [];
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

        bufferStatus.textContent = parts.join(' | ');
    }

    player.addEventListener('radio:buffer-status', function (event) {
        setBufferStatus(event && event.detail ? event.detail : {});
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
                updateDjValue(this);
                dispatchDjFx(this.getAttribute('data-radio-djfx') || '', parseFloat(this.value || '0') || 0);
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
                    dispatchDjFx('echo', active ? 1 : 0);
                    return;
                }

                if (control === 'reset') {
                    for (var resetIndex = 0; resetIndex < djRanges.length; resetIndex++) {
                        djRanges[resetIndex].value = '0';
                        updateDjValue(djRanges[resetIndex]);
                    }
                    var echoButton = djFx.querySelector('[data-radio-djfx-button="echo"]');
                    if (echoButton) {
                        echoButton.setAttribute('aria-pressed', 'false');
                        echoButton.classList.remove('is-active');
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

    function updateBroadcast(data) {
        if (!data) {
            return;
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
            up.addEventListener('click', (function (id) {
                return function () { mutateItem('move_up', id); };
            }(itemId)));
            actions.appendChild(up);

            var down = document.createElement('button');
            down.type = 'button';
            down.className = 'radio-program-item__action';
            down.textContent = '↓';
            down.title = studio.getAttribute('data-move-down-label') || 'Move down';
            down.setAttribute('aria-label', down.title);
            down.disabled = i === items.length - 1;
            down.addEventListener('click', (function (id) {
                return function () { mutateItem('move_down', id); };
            }(itemId)));
            actions.appendChild(down);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'radio-program-item__action radio-program-item__action--remove';
            remove.textContent = '−';
            remove.title = studio.getAttribute('data-remove-label') || 'Remove';
            remove.setAttribute('aria-label', remove.title);
            remove.addEventListener('click', (function (id) {
                return function () { mutateItem('remove', id); };
            }(itemId)));
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
                next.addEventListener('click', function () {
                    addMedia(item.media_id, 'next');
                });
                actions.appendChild(next);

                var end = document.createElement('button');
                end.type = 'button';
                end.className = 'radio-program-picker__add';
                end.textContent = '+';
                end.title = studio.getAttribute('data-add-end-label') || 'Add to end';
                end.setAttribute('aria-label', end.title);
                end.addEventListener('click', function () {
                    addMedia(item.media_id, 'end');
                });
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
        if (tokenName && tokenValue) {
            body.set(tokenName, tokenValue);
        }

        fetch(mutationEndpoint || endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            cache: 'no-store'
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
        fetch(studioUrl('state'), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                updateToken(data);
                if (!data.ok) {
                    return;
                }
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
            .catch(function () {});
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
    pollTimer = window.setInterval(syncState, 3000);
    window.addEventListener('pagehide', function () {
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }
    });
}());
