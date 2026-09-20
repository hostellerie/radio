<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

function RADIO_adminTemplate($file)
{
    global $_CONF;
    $template = COM_newTemplate($_CONF['path'] . 'plugins/radio/templates/admin');
    $template->set_file('page', $file);
    return $template;
}

function RADIO_adminHeaderCode()
{
    global $_CONF;
    return '<link rel="stylesheet" href="'
        . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/radio/radio-admin.css', ENT_QUOTES, 'UTF-8')
        . '">';
}

function RADIO_adminConfigurationButton()
{
    global $_CONF, $LANG_RADIO;

    if (!SEC_hasRights('config.radio.tab_main')) {
        return '';
    }

    $template = RADIO_adminTemplate('configuration-button.thtml');
    $template->set_var(array(
        'configuration_url' => htmlspecialchars(
            $_CONF['site_admin_url'] . '/configuration.php',
            ENT_QUOTES,
            'UTF-8'
        ),
        'configuration_label' => htmlspecialchars(
            $LANG_RADIO['admin_configuration'],
            ENT_QUOTES,
            'UTF-8'
        )
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminNavItem($active, $key, $url, $label, $allowed)
{
    if (!$allowed) {
        return '';
    }

    return '<a class="' . ($active === $key ? 'is-active' : '') . '" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

function RADIO_adminNavigation($active)
{
    global $_CONF, $LANG_RADIO;

    $adminBase = $_CONF['site_admin_url'] . '/plugins/radio/';
    $template = RADIO_adminTemplate('navigation.thtml');
    $template->set_var(array(
        'nav_aria_label' => htmlspecialchars($LANG_RADIO['admin_navigation'], ENT_QUOTES, 'UTF-8'),
        'nav_library' => RADIO_adminNavItem(
            $active,
            'library',
            $adminBase . 'index.php',
            $LANG_RADIO['library'],
            SEC_hasRights('radio.admin')
        ),
        'nav_programs' => RADIO_adminNavItem(
            $active,
            'programs',
            $adminBase . 'programs.php',
            $LANG_RADIO['programs'],
            SEC_hasRights('radio.schedule')
        ),
        'nav_schedule' => RADIO_adminNavItem(
            $active,
            'schedule',
            $adminBase . 'schedule.php',
            $LANG_RADIO['schedule'],
            SEC_hasRights('radio.schedule')
        ),
        'nav_rotation' => RADIO_adminNavItem(
            $active,
            'rotation',
            $adminBase . 'rotation.php',
            $LANG_RADIO['automatic_rotation'],
            SEC_hasRights('radio.admin')
        ),
        'nav_sources' => RADIO_adminNavItem(
            $active,
            'sources',
            $adminBase . 'sources.php',
            $LANG_RADIO['feed_sources'],
            SEC_hasRights('radio.admin')
        ),
        'nav_stats' => RADIO_adminNavItem(
            $active,
            'stats',
            $adminBase . 'stats.php',
            $LANG_RADIO['statistics'],
            SEC_hasRights('radio.admin')
        )
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminRenderPage($active, $title, $intro, $helpTitle, $helpText, $pageContent, $message)
{
    $template = RADIO_adminTemplate('page.thtml');
    $template->set_var(array(
        'page_title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        'page_intro' => htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
        'configuration_button' => RADIO_adminConfigurationButton(),
        'navigation' => RADIO_adminNavigation($active),
        'message' => $message,
        'help_title' => htmlspecialchars($helpTitle, ENT_QUOTES, 'UTF-8'),
        'help_text' => htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8'),
        'page_content' => $pageContent
    ));

    return $template->finish($template->parse('output', 'page'));
}

function RADIO_adminMediaTypeLabel($type)
{
    global $LANG_RADIO;
    $key = 'type_' . (string) $type;
    return isset($LANG_RADIO[$key]) ? $LANG_RADIO[$key] : (string) $type;
}

function RADIO_adminStatusLabel($status)
{
    global $LANG_RADIO;
    $key = (string) $status;
    return isset($LANG_RADIO[$key]) ? $LANG_RADIO[$key] : $key;
}

function RADIO_adminSourceKindLabel($kind)
{
    global $LANG_RADIO;
    return $kind === 'live' ? $LANG_RADIO['source_live'] : $LANG_RADIO['source_external'];
}

function RADIO_adminSyncModeLabel($mode)
{
    global $LANG_RADIO;
    $key = $mode === 'drafts' ? 'feed_sync_drafts' : 'feed_sync_preview';
    return $LANG_RADIO[$key];
}
