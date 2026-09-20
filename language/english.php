<?php
$LANG_RADIO = array(
    'plugin_name' => 'Radio',
    'admin_title' => 'Radio',
    'admin_intro' => 'Manage the audio library, programmes and schedules.',
    'storage' => 'Persistent storage',
    'storage_ready' => 'Ready',
    'storage_unavailable' => 'Unavailable',
    'open_configuration' => 'Open Radio configuration',
    'upload_title' => 'Add audio',
    'audio_file' => 'Audio file',
    'title' => 'Title',
    'description' => 'Description',
    'type' => 'Type',
    'status' => 'Status',
    'draft' => 'Draft',
    'published' => 'Published',
    'allow_download' => 'Allow download',
    'upload' => 'Upload audio',
    'library' => 'Audio library',
    'library_empty' => 'The audio library is empty.',
    'file' => 'File',
    'save' => 'Save',
    'delete' => 'Delete',
    'confirm_delete' => 'Delete this audio item and its stored file?',
    'download' => 'Download',
    'back_to_library' => 'Back to Radio',
    'public_empty' => 'No published audio is available yet.',
    'media_not_found' => 'This audio item is not available.',
    'upload_saved' => 'The audio file was added.',
    'upload_failed' => 'The audio file could not be added.',
    'upload_error' => 'The upload did not complete successfully.',
    'upload_size' => 'The audio file exceeds the configured size limit or is empty.',
    'upload_type' => 'The selected file is not an accepted audio format.',
    'upload_move' => 'The uploaded file could not be moved into persistent Radio storage.',
    'database_error' => 'The audio metadata could not be saved.',
    'invalid_token' => 'The request could not be validated. Reload the page and try again.',
    'access_denied' => 'You do not have permission for this Radio action.',
    'media_saved' => 'The audio metadata was updated.',
    'media_save_failed' => 'The audio metadata could not be updated.',
    'media_deleted' => 'The audio item was deleted.',
    'media_delete_failed' => 'The audio item could not be deleted.',
    'type_music' => 'Music',
    'type_podcast' => 'Podcast',
    'type_interview' => 'Interview',
    'type_show' => 'Show',
    'type_chronicle' => 'Chronicle',
    'type_jingle' => 'Jingle',
    'type_announcement' => 'Announcement',
    'type_promo' => 'Promo'
);
$LANG_configsections['radio'] = array('label' => 'Radio', 'title' => 'Radio Configuration');
$LANG_configsubgroups['radio'] = array('sg_main' => 'Main Settings');
$LANG_tab['radio'] = array('tab_main' => 'Main');
$LANG_fs['radio'] = array('fs_main' => 'General');
$LANG_confignames['radio'] = array(
    'enabled' => 'Enable Radio?',
    'public_title' => 'Public title',
    'default_replay_days' => 'Default replay duration (days)',
    'allow_downloads' => 'Allow downloads globally?',
    'max_upload_mb' => 'Maximum audio upload size (MB)'
);
$LANG_configselects['radio'] = array(0 => array('Enabled' => 1, 'Disabled' => 0));
