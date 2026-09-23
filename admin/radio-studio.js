(function () {
    'use strict';

    var studio = document.querySelector('[data-radio-studio]');
    var player = document.querySelector('[data-radio-replay-player]');
    if (!studio || !player || typeof fetch !== 'function') {
        return;
    }

    var endpoint = studio.getAttribute('data-radio-studio-endpoint') || '';
    var programId = parseInt(studio.getAttribute('data-radio-program-id') || '0', 10) || 0;
    var tokenName = studio.getAttribute('data-radio-csrf-name') || '';
    var tokenValue = studio.getAttribute('data-radio-csrf-token') || '';
    var form = studio.querySelector('[data-radio-studio-search]');
    var results = studio.querySelector('[data-radio-studio-results]');
    var status = studio.querySelector('[data-radio-studio-status]');
    var version = '';
    var pollTimer = 0;

    function setStatus(message) {
        if (status) {
            status.textContent = message || '';
        }
    }

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

    function addMedia(mediaId, position) {
        var body = new URLSearchParams();
        body.set('program_id', programId);
        body.set('action', 'add');
        body.set('media_id', mediaId);
        body.set('position', position);
        body.set('current_item_id', player.getAttribute('data-radio-current-item-id') || '0');
        if (tokenName && tokenValue) {
            body.set(tokenName, tokenValue);
        }

        setStatus(studio.getAttribute('data-adding-label') || 'Adding…');

        fetch(endpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                updateToken(data);
                if (!data.ok) {
                    throw new Error(data.error || 'add_failed');
                }
                version = data.version || version;
                dispatchPlaylist(data.items || []);
                setStatus(studio.getAttribute('data-added-label') || 'Added');
                window.setTimeout(function () {
                    setStatus('');
                }, 1800);
            })
            .catch(function () {
                setStatus(studio.getAttribute('data-error-label') || 'Error');
            });
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
                    return;
                }
                if (data.version && data.version !== version) {
                    version = data.version;
                    dispatchPlaylist(data.items || []);
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
