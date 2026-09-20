<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$scheduleId = isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0;
$start = isset($_GET['start']) ? (int) $_GET['start'] : 0;
$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

if ($scheduleId > 0 && $start > 0) {
    $replay = RADIO_getReplay($scheduleId, $start);
    if ($replay === false) {
        $content = COM_showMessageText($LANG_RADIO['replay_not_available'], $LANG_RADIO['replays']);
        COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['replays'])));
        exit;
    }

    $content = '<div class="radio-replay"><h1>' . htmlspecialchars($replay['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
    $replayProgram = RADIO_getProgram($replay['program_id'], true);
    if ($replayProgram && !empty($replayProgram['cover_name'])) {
        $content .= '<p><img src="' . htmlspecialchars(RADIO_coverUrl('program', $replay['program_id']), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:360px;width:100%;height:auto"></p>';
    }
    if ($replayProgram && !empty($replayProgram['host'])) {
        $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($replayProgram['host'], ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['broadcast_date'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['start']) . '–' . date('H:i', $replay['end']) . '<br>'
        . '<strong>' . htmlspecialchars($LANG_RADIO['available_until'], ENT_QUOTES, 'UTF-8') . ':</strong> '
        . date('Y-m-d H:i', $replay['available_until']) . '</p>';

    if ($replay['description'] !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($replay['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    $published = 0;
    foreach ($replay['items'] as $item) {
        if ($item['status'] !== 'published' || !RADIO_hasReadAccess($item)) {
            continue;
        }
        $published++;
        $content .= '<article style="margin:0 0 1.25rem"><strong>'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . '<audio data-radio-media-id="' . (int) $item['media_id'] . '" data-radio-program-id="' . (int) $replay['program_id'] . '" data-radio-source="replay" controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $item['media_id'], false), ENT_QUOTES, 'UTF-8')
            . '"></audio></article>';
    }

    if ($published === 0) {
        $content .= '<p>' . htmlspecialchars($LANG_RADIO['replay_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_RADIO['back_to_replays'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

    $trackingJs = "<script>(function(){\nvar endpoint=' . json_encode($_CONF['site_url'] . '/radio/event.php') . ';\nfunction send(mediaId,programId,eventType,source,seconds){\n  if(!mediaId)return;\n  var body=new URLSearchParams();\n  body.set('media_id',mediaId);\n  body.set('program_id',programId||0);\n  body.set('event_type',eventType);\n  body.set('source',source);\n  body.set('seconds',Math.max(0,Math.round(seconds||0)));\n  if(navigator.sendBeacon){navigator.sendBeacon(endpoint,body);return;}\n  fetch(endpoint,{method:'POST',body:body,credentials:'same-origin',keepalive:true}).catch(function(){});\n}\nfunction bind(a){\n  var mediaId=parseInt(a.getAttribute('data-radio-media-id')||'0',10);\n  var programId=parseInt(a.getAttribute('data-radio-program-id')||'0',10);\n  var source=a.getAttribute('data-radio-source')||'catalogue';\n  var started=false,total=0,last=0;\n  a.addEventListener('play',function(){if(!started){started=true;send(mediaId,programId,'play',source,0);}last=Date.now();});\n  a.addEventListener('pause',function(){if(last){total+=(Date.now()-last)/1000;last=0;}});\n  a.addEventListener('ended',function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,'listen',source,total);total=0;}});\n  window.addEventListener('pagehide',function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,'listen',source,total);total=0;}});\n}\nvar audios=document.querySelectorAll('audio[data-radio-media-id]');\nfor(var i=0;i<audios.length;i++)bind(audios[i]);\n})();</script>";
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $replay['title'], 'footercode' => $trackingJs)));
    exit;
}

$replays = RADIO_getReplayOccurrences(50, time());
$content = '<div class="radio-replays"><h1>' . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</h1>';

if (count($replays) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['replays_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ul>';
    foreach ($replays as $replay) {
        $content .= '<li style="margin:0 0 1rem"><a href="'
            . htmlspecialchars($replay['url'], ENT_QUOTES, 'UTF-8') . '"><strong>'
            . htmlspecialchars($replay['title'], ENT_QUOTES, 'UTF-8') . '</strong></a><br><small>'
            . date('Y-m-d H:i', $replay['start']) . '–' . date('H:i', $replay['end'])
            . '</small></li>';
    }
    $content .= '</ul>';
}
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

COM_output(COM_createHTMLDocument($content, array('pagetitle' => $LANG_RADIO['replays'] . ' - ' . $title)));
