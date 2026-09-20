<?php
require_once '../lib-common.php';
global $_CONF, $_RADIO_CONF, $LANG_RADIO;

$title = isset($_RADIO_CONF['public_title']) ? $_RADIO_CONF['public_title'] : 'Radio';
$endpoint = $_CONF['site_url'] . '/radio/now.php';

$content = '<div class="radio-live"><h1>' . htmlspecialchars($LANG_RADIO['listen_live'], ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p id="radio-live-status">' . htmlspecialchars($LANG_RADIO['live_ready'], ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p><strong id="radio-live-program"></strong><br><span id="radio-live-media"></span></p>'
    . '<audio id="radio-live-player" controls preload="metadata" style="width:100%;max-width:850px"></audio>'
    . '<p><button type="button" id="radio-live-start">' . htmlspecialchars($LANG_RADIO['start_listening'], ENT_QUOTES, 'UTF-8') . '</button></p>'
    . '<p><small>' . htmlspecialchars($LANG_RADIO['autoplay_notice'], ENT_QUOTES, 'UTF-8') . '</small></p>'
    . '<p><a href="' . htmlspecialchars($_CONF['site_url'] . '/radio/schedule.php', ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_RADIO['view_full_schedule'], ENT_QUOTES, 'UTF-8') . '</a></p></div>';

$js = '<script>(function(){'
    . 'var endpoint=' . json_encode($endpoint) . ';'
    . 'var player=document.getElementById("radio-live-player");'
    . 'var status=document.getElementById("radio-live-status");'
    . 'var program=document.getElementById("radio-live-program");'
    . 'var media=document.getElementById("radio-live-media");'
    . 'var start=document.getElementById("radio-live-start");'
    . 'var activeMedia="";'
    . 'var userStarted=false;'
    . 'function sync(play){'
    . 'fetch(endpoint,{cache:"no-store"}).then(function(r){return r.json();}).then(function(data){'
    . 'if(!data.now_playing&&!data.current_media){program.textContent="";media.textContent="";status.textContent=' . json_encode($LANG_RADIO['nothing_scheduled_now']) . ';return;}'
    . 'program.textContent=data.now_playing?data.now_playing.title:' . json_encode($LANG_RADIO['automatic_rotation']) . ';'
    . 'if(!data.current_media){media.textContent=' . json_encode($LANG_RADIO['duration_required_live']) . ';status.textContent="";return;}'
    . 'media.textContent=data.current_media.title;status.textContent="";'
    . 'if(activeMedia!==data.current_media.media_id){'
    . 'activeMedia=data.current_media.media_id;player.src=data.current_media.stream_url;'
    . 'player.addEventListener("loadedmetadata",function seekOnce(){player.removeEventListener("loadedmetadata",seekOnce);'
    . 'try{player.currentTime=data.current_media.offset;}catch(e){}'
    . 'if(play&&userStarted){player.play().catch(function(){});}});'
    . '}else if(Math.abs(player.currentTime-data.current_media.offset)>5&&!player.paused){try{player.currentTime=data.current_media.offset;}catch(e){}}'
    . '}).catch(function(){status.textContent=' . json_encode($LANG_RADIO['live_unavailable']) . ';});'
    . '}'
    . 'start.addEventListener("click",function(){userStarted=true;sync(true);});'
    . 'player.addEventListener("ended",function(){sync(true);});'
    . 'setInterval(function(){if(userStarted){sync(false);}},15000);'
    . 'sync(false);'
    . '})();</script>';

COM_output(COM_createHTMLDocument($content, array(
    'pagetitle' => $LANG_RADIO['listen_live'] . ' - ' . $title,
    'footercode' => $js
)));
