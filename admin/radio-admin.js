(function () {
    'use strict';

    var upload = document.getElementById('radio-upload-file');
    var target = document.getElementById('radio-upload-duration');
    var dropzone = document.getElementById('radio-upload-dropzone');
    var queue = document.getElementById('radio-upload-queue');
    var batchNote = document.getElementById('radio-upload-batch-note');

    function fileListToArray(files) {
        var items = [];
        if (!files) {
            return items;
        }
        for (var i = 0; i < files.length; i++) {
            items.push(files[i]);
        }
        return items;
    }

    function setFiles(files) {
        if (!upload || typeof DataTransfer === 'undefined') {
            return;
        }
        var transfer = new DataTransfer();
        for (var i = 0; i < files.length; i++) {
            transfer.items.add(files[i]);
        }
        upload.files = transfer.files;
    }

    function humanSize(bytes) {
        bytes = Math.max(0, parseInt(bytes || 0, 10));
        if (bytes < 1048576) {
            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function renderQueue() {
        if (!upload || !queue) {
            return;
        }

        var files = fileListToArray(upload.files);
        queue.innerHTML = '';
        if (batchNote) {
            batchNote.hidden = files.length < 2;
        }

        for (var i = 0; i < files.length; i++) {
            (function (index) {
                var row = document.createElement('div');
                row.className = 'radio-upload-queue__item';

                var info = document.createElement('div');
                info.className = 'radio-upload-queue__info';

                var name = document.createElement('strong');
                name.textContent = files[index].name;
                info.appendChild(name);

                var size = document.createElement('small');
                size.textContent = humanSize(files[index].size);
                info.appendChild(size);

                row.appendChild(info);

                if (typeof DataTransfer !== 'undefined') {
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'radio-admin__button radio-upload-queue__remove';
                    remove.textContent = dropzone ? (dropzone.getAttribute('data-remove-label') || 'Remove') : 'Remove';
                    remove.addEventListener('click', function () {
                        var current = fileListToArray(upload.files);
                        current.splice(index, 1);
                        setFiles(current);
                        renderQueue();
                    });
                    row.appendChild(remove);
                }

                queue.appendChild(row);
            }(i));
        }

        if (target && files.length > 1) {
            target.value = 0;
        }
    }

    function detectSingleDuration() {
        if (!upload || !target || !upload.files || upload.files.length !== 1) {
            return;
        }

        var audio = document.createElement('audio');
        var url = URL.createObjectURL(upload.files[0]);
        audio.preload = 'metadata';
        audio.src = url;

        audio.addEventListener('loadedmetadata', function () {
            if (isFinite(audio.duration) && audio.duration > 0) {
                target.value = Math.round(audio.duration);
            }
            URL.revokeObjectURL(url);
        });
        audio.addEventListener('error', function () {
            URL.revokeObjectURL(url);
        });
    }

    if (upload) {
        upload.addEventListener('change', function () {
            renderQueue();
            detectSingleDuration();
        });
    }

    if (dropzone && upload) {
        ['dragenter', 'dragover'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            if (!event.dataTransfer || !event.dataTransfer.files || !event.dataTransfer.files.length) {
                return;
            }

            if (typeof DataTransfer !== 'undefined') {
                var current = fileListToArray(upload.files);
                var dropped = fileListToArray(event.dataTransfer.files);
                setFiles(current.concat(dropped));
            } else {
                upload.files = event.dataTransfer.files;
            }

            renderQueue();
            detectSingleDuration();
        });
    }

    var editAudio = document.querySelector('.radio-duration-source');
    if (editAudio) {
        editAudio.addEventListener('loadedmetadata', function () {
            var id = editAudio.getAttribute('data-duration-target');
            var input = id ? document.getElementById(id) : null;
            if (input && parseInt(input.value, 10) <= 0
                && isFinite(editAudio.duration) && editAudio.duration > 0) {
                input.value = Math.round(editAudio.duration);
            }
        });
    }
}());
