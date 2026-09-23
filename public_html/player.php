<?php
require_once '../lib-common.php';

global $_CONF, $_RADIO_CONF, $LANG_RADIO;

if (isset($_RADIO_CONF['enabled']) && !$_RADIO_CONF['enabled']) {
    header('HTTP/1.1 503 Service Unavailable');
    exit;
}

$title = isset($_RADIO_CONF['public_title']) && trim((string) $_RADIO_CONF['public_title']) !== ''
    ? trim((string) $_RADIO_CONF['public_title'])
    : (isset($LANG_RADIO['plugin_name']) ? $LANG_RADIO['plugin_name'] : 'Radio');

$siteUrl = rtrim($_CONF['site_url'], '/');
$nowUrl = $siteUrl . '/radio/now.php';
$eventUrl = $siteUrl . '/radio/event.php';
$radioUrl = $siteUrl . '/radio/index.php';
$autoplay = isset($_GET['autoplay']) && (int) $_GET['autoplay'] === 1;
$cssPath = !empty($_CONF['path_html'])
    ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio-player.css'
    : '';
if ($cssPath === '' || !is_file($cssPath)) {
    $cssPath = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio-player.css';
}
$jsPath = !empty($_CONF['path_html'])
    ? rtrim($_CONF['path_html'], '/\\') . '/radio/radio-player.js'
    : '';
if ($jsPath === '' || !is_file($jsPath)) {
    $jsPath = rtrim($_CONF['path'], '/\\') . '/plugins/radio/public_html/radio-player.js';
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(isset($_CONF['language']) ? $_CONF['language'] : 'en', ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($siteUrl . '/radio/radio-player.css?v=' . rawurlencode(RADIO_assetVersion($cssPath)), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<main class="radio-persistent"
      data-radio-player
      data-now-endpoint="<?php echo htmlspecialchars($nowUrl, ENT_QUOTES, 'UTF-8'); ?>"
      data-event-endpoint="<?php echo htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8'); ?>"
      data-empty-label="<?php echo htmlspecialchars($LANG_RADIO['nothing_scheduled_now'], ENT_QUOTES, 'UTF-8'); ?>"
      data-listen-label="<?php echo htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8'); ?>"
      data-pause-label="<?php echo htmlspecialchars($LANG_RADIO['public_pause'], ENT_QUOTES, 'UTF-8'); ?>"
      data-autoplay="<?php echo $autoplay ? '1' : '0'; ?>">
    <header class="radio-persistent__header">
        <div>
            <div class="radio-persistent__brand"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="radio-persistent__status"><span class="radio-persistent__dot" aria-hidden="true"></span><?php echo htmlspecialchars($LANG_RADIO['public_on_air'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <a class="radio-persistent__site-link" href="<?php echo htmlspecialchars($radioUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($LANG_RADIO['persistent_open_radio'], ENT_QUOTES, 'UTF-8'); ?></a>
    </header>

    <section class="radio-persistent__now">
        <p class="radio-persistent__program" data-radio-player-program></p>
        <h1 class="radio-persistent__title" data-radio-player-title><?php echo htmlspecialchars($LANG_RADIO['persistent_loading'], ENT_QUOTES, 'UTF-8'); ?></h1>
    </section>

    <canvas class="radio-persistent__scope" data-radio-player-scope width="720" height="100" aria-hidden="true"></canvas>

    <div class="radio-persistent__controls">
        <button type="button" class="radio-persistent__play" data-radio-player-play>
            <?php echo htmlspecialchars($LANG_RADIO['public_listen'], ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </div>

    <audio data-radio-player-audio preload="metadata"></audio>
    <p class="radio-persistent__hint"><?php echo htmlspecialchars($LANG_RADIO['persistent_hint'], ENT_QUOTES, 'UTF-8'); ?></p>
</main>
<script src="<?php echo htmlspecialchars($siteUrl . '/radio/radio-player.js?v=' . rawurlencode(RADIO_assetVersion($jsPath)), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
