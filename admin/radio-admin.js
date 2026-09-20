(function () {
    'use strict';

    var upload = document.getElementById('radio-upload-file');
    var target = document.getElementById('radio-upload-duration');

    if (upload && target) {
        upload.addEventListener('change', function () {
            if (!upload.files || !upload.files[0]) {
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
