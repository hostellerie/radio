<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$program = RADIO_getProgram($id, true);
$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';

if ($program === false) {
    $content = COM_showMessageText($LANG_RADIO['program_not_found'], $title);
    COM_output(COM_createHTMLDocument($content, array('pagetitle' => $title)));
    exit;
}

$items = RADIO_getProgramItems($id);
$content = '<div class="radio-program"><h1>' . htmlspecialchars($program['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
if (!empty($program['cover_name'])) {
    $content .= '<p><img src="' . htmlspecialchars(RADIO_coverUrl('program', $id), ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:360px;width:100%;height:auto"></p>';
}
if (!empty($program['host'])) {
    $content .= '<p><strong>' . htmlspecialchars($LANG_RADIO['host'], ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($program['host'], ENT_QUOTES, 'UTF-8') . '</p>';
}
if ($program['description'] !== '') {
    $content .= '<p>' . nl2br(htmlspecialchars($program['description'], ENT_QUOTES, 'UTF-8')) . '</p>';
}
if (count($items) === 0) {
    $content .= '<p>' . htmlspecialchars($LANG_RADIO['program_public_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $content .= '<ol>';
    foreach ($items as $item) {
        if ($item['status'] !== 'published' || !RADIO_hasReadAccess($item)) {
            continue;
        }
        $content .= '<li style="margin:0 0 1.25rem"><strong>'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
            . '<audio data-radio-media-id="' . (int) $item['media_id'] . '" data-radio-program-id="' . (int) $id . '" data-radio-source="program" controls preload="none" style="width:100%;max-width:800px" src="'
            . htmlspecialchars(RADIO_mediaUrl((int) $item['media_id'], false), ENT_QUOTES, 'UTF-8')
            . '"></audio></li>';
    }
    $content .= '</ol>';
}
$content .= '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/replay.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['replays'], ENT_QUOTES, 'UTF-8') . '</a> · '
    . '<a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/index.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['back_to_library'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

$trackingJs = "<script>(function(){\nvar endpoint=' . json_encode($_CONF['site_url'] . '/radio/event.php') . ';\nfunction send(mediaId,programId,eventType,source,seconds){\n  if(!mediaId)return;\n  var body=new URLSearchParams();\n  body.set('media_id',mediaId);\n  body.set('program_id',programId||0);\n  body.set('event_type',eventType);\n  body.set('source',source);\n  body.set('seconds',Math.max(0,Math.round(seconds||0)));\n  if(navigator.sendBeacon){navigator.sendBeacon(endpoint,body);return;}\n  fetch(endpoint,{method:'POST',body:body,credentials:'same-origin',keepalive:true}).catch(function(){});\n}\nfunction bind(a){\n  var mediaId=parseInt(a.getAttribute('data-radio-media-id')||'0',10);\n  var programId=parseInt(a.getAttribute('data-radio-program-id')||'0',10);\n  var source=a.getAttribute('data-radio-source')||'catalogue';\n  var started=false,total=0,last=0;\n  a.addEventListener('play',function(){if(!started){started=true;send(mediaId,programId,'play',source,0);}last=Date.now();});\n  a.addEventListener('pause',function(){if(last){total+=(Date.now()-last)/1000;last=0;}});\n  a.addEventListener('ended',function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,'listen',source,total);total=0;}});\n  window.addEventListener('pagehide',function(){if(last){total+=(Date.now()-last)/1000;last=0;}if(total>0){send(mediaId,programId,'listen',source,total);total=0;}});\n}\nvar audios=document.querySelectorAll('audio[data-radio-media-id]');\nfor(var i=0;i<audios.length;i++)bind(audios[i]);\n})();</script>";
COM_output(COM_createHTMLDocument($content, array('pagetitle' => $program['title'], 'footercode' => $trackingJs)));
