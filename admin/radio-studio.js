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
        var buffered = Math.max(0, Math.round(parseFloat(detail.next_buffered || 0) || 0));
        var loadingLabel = studio.getAttribute('data-buffer-loading-label') || 'Buffering';
        var readyLabel = studio.getAttribute('data-buffer-ready-label') || 'Ready';
        var reserveLabel = studio.getAttribute('data-buffer-reserve-label') || 'Reserve ready';

        if (detail.reserve_ready) {
            bufferStatus.textContent = reserveLabel + (buffered > 0 ? ' · ' + buffered + ' s' : '');
        } else if (detail.next_ready || buffered >= 3) {
            bufferStatus.textContent = readyLabel + (buffered > 0 ? ' · ' + buffered + ' s' : '');
        } else {
            bufferStatus.textContent = loadingLabel + (buffered > 0 ? ' · ' + buffered + ' s' : '');
        }
    }

    player.addEventListener('radio:buffer-status', function (event) {
        setBufferStatus(event && event.detail ? event.detail : {});
    });

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

    function dispatchPlaylist(items) {
        if (!items || !items.length) {
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

            var meta = document.createElement('span');
            meta.className = 'radio-admin__muted';
            if (item.playable === false || item.playable === 0) {
                meta.textContent = studio.getAttribute('data-queue-not-ready-label') || 'Not playable';
                li.classList.add('is-not-ready');
            } else if (parseInt(item.duration || 0, 10) > 0) {
                meta.textContent = resultMeta(item);
            }
            li.appendChild(meta);

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

    function mutate(body, successLabel) {
        if (tokenName && tokenValue) {
            body.set(tokenName, tokenValue);
        }

        fetch(mutationEndpoint || endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                updateToken(data);
                if (!data.ok) {
                    throw new Error(data.error || 'mutation_failed');
                }
                version = data.version || version;
                renderQueue(data.items || []);
                dispatchPlaylist(data.items || []);
                setStatus(successLabel || '');
                if (successLabel) {
                    window.setTimeout(function () { setStatus(''); }, 1200);
                }
            })
            .catch(function () {
                setStatus(studio.getAttribute('data-error-label') || 'Error');
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
        body.set('current_item_id', player.getAttribute('data-radio-current-item-id') || '0');

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
