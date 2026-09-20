<?php
if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

function RADIO_ensureSourcesTable()
{
    global $_TABLES;
    $table = $_TABLES['radio_sources'];
    $result = DB_query("SHOW TABLES LIKE '" . DB_escapeString($table) . "'");
    if (DB_numRows($result) > 0) {
        return true;
    }
    DB_query("CREATE TABLE " . $table . " (
      source_id int(10) unsigned NOT NULL auto_increment,
      title varchar(255) NOT NULL default '',
      source_type varchar(24) NOT NULL default 'rss',
      source_url text,
      provider varchar(255) NOT NULL default '',
      enabled tinyint(1) unsigned NOT NULL default '1',
      etag varchar(255) NOT NULL default '',
      last_modified varchar(255) NOT NULL default '',
      last_checked datetime default NULL,
      last_status int(10) unsigned NOT NULL default '0',
      last_error text,
      owner_id int(10) unsigned NOT NULL default '2',
      group_id mediumint(8) unsigned NOT NULL default '1',
      perm_owner tinyint(1) unsigned NOT NULL default '3',
      perm_group tinyint(1) unsigned NOT NULL default '2',
      perm_members tinyint(1) unsigned NOT NULL default '2',
      perm_anon tinyint(1) unsigned NOT NULL default '0',
      created datetime NOT NULL,
      modified datetime NOT NULL,
      PRIMARY KEY (source_id),
      KEY enabled (enabled),
      KEY source_type (source_type)
    ) ENGINE=MyISAM");
    return !DB_error();
}

function RADIO_getFeedSource($id, $publishedOnly)
{
    global $_TABLES;
    if (!RADIO_ensureSourcesTable()) return false;
    $id = (int) $id;
    if ($id < 1) return false;
    $result = DB_query("SELECT * FROM {$_TABLES['radio_sources']} WHERE source_id=" . $id);
    if (DB_numRows($result) < 1) return false;
    $row = DB_fetchArray($result);
    if ($publishedOnly && (empty($row['enabled']) || !RADIO_hasReadAccess($row))) return false;
    return $row;
}

function RADIO_getFeedSources($limit, $enabledOnly)
{
    global $_TABLES;
    if (!RADIO_ensureSourcesTable()) return array();
    $limit = max(1, min(100, (int) $limit));
    $where = $enabledOnly ? " WHERE enabled=1" . RADIO_permissionSql('radio_sources', 0) : '';
    $result = DB_query("SELECT * FROM {$_TABLES['radio_sources']}" . $where . " ORDER BY modified DESC,source_id DESC LIMIT " . $limit);
    $rows = array();
    while ($row = DB_fetchArray($result)) $rows[] = $row;
    return $rows;
}

function RADIO_saveFeedSource($id, $data, &$error)
{
    global $_TABLES, $_USER;
    $error = '';
    if (!RADIO_ensureSourcesTable()) { $error='source_table_error'; return false; }

    $url = RADIO_externalUrlValidation(isset($data['source_url']) ? $data['source_url'] : '', $error);
    if ($url === false) return false;

    $title = trim(isset($data['source_title']) ? (string) $data['source_title'] : '');
    if ($title === '') { $error='source_title_required'; return false; }
    $provider = trim(isset($data['source_provider']) ? (string) $data['source_provider'] : '');
    $enabled = !empty($data['source_enabled']) ? 1 : 0;
    $syncMode = isset($data['sync_mode']) && $data['sync_mode'] === 'drafts' ? 'drafts' : 'preview';
    $now = date('Y-m-d H:i:s');

    if ((int)$id > 0) {
        $existing = RADIO_getFeedSource((int)$id, false);
        if ($existing === false || !RADIO_hasEditAccess($existing)) { $error='access_denied'; return false; }
        $groupId = isset($data['group_id']) ? max(1,(int)$data['group_id']) : (int)$existing['group_id'];
        list($po,$pg,$pm,$pa)=RADIO_permissionValues($data,$existing);
        DB_query("UPDATE {$_TABLES['radio_sources']} SET title='".DB_escapeString(substr($title,0,255))."',source_url='".DB_escapeString($url)."',provider='".DB_escapeString(substr($provider,0,255))."',enabled=".$enabled.",sync_mode='".DB_escapeString($syncMode)."',group_id=".$groupId.",perm_owner=".$po.",perm_group=".$pg.",perm_members=".$pm.",perm_anon=".$pa.",modified='".DB_escapeString($now)."' WHERE source_id=".(int)$id);
        return DB_error() ? false : (int)$id;
    }

    $owner=isset($_USER['uid'])?(int)$_USER['uid']:2;
    $groupId=isset($data['group_id'])?max(1,(int)$data['group_id']):RADIO_defaultGroupId();
    list($po,$pg,$pm,$pa)=RADIO_permissionValues($data,array('perm_owner'=>3,'perm_group'=>2,'perm_members'=>2,'perm_anon'=>0));
    DB_query("INSERT INTO {$_TABLES['radio_sources']} (title,source_type,source_url,provider,enabled,sync_mode,owner_id,group_id,perm_owner,perm_group,perm_members,perm_anon,created,modified) VALUES ('".DB_escapeString(substr($title,0,255))."','rss','".DB_escapeString($url)."','".DB_escapeString(substr($provider,0,255))."',".$enabled.",'".DB_escapeString($syncMode)."',".$owner.",".$groupId.",".$po.",".$pg.",".$pm.",".$pa.",'".DB_escapeString($now)."','".DB_escapeString($now)."')");
    return DB_error()?false:(int)DB_insertId();
}

function RADIO_deleteFeedSource($id)
{
    global $_TABLES;
    $row=RADIO_getFeedSource((int)$id,false);
    if ($row===false || !RADIO_hasEditAccess($row)) return false;
    DB_query("DELETE FROM {$_TABLES['radio_sources']} WHERE source_id=".(int)$id);
    return !DB_error();
}

function RADIO_resolveRedirectUrl($base,$location)
{
    $location=trim((string)$location);
    if ($location==='') return '';
    if (preg_match('#^https?://#i',$location)) return $location;
    $p=@parse_url($base);
    if (!is_array($p)||empty($p['scheme'])||empty($p['host'])) return '';
    $root=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.(int)$p['port']:'');
    if (substr($location,0,1)==='/') return $root.$location;
    $path=isset($p['path'])?$p['path']:'/';
    return $root.preg_replace('#/[^/]*$#','/',$path).$location;
}

function RADIO_httpFetchBounded($url,$requestHeaders,&$diagnostic)
{
    $diagnostic=array('status'=>0,'headers'=>array(),'error'=>'','url'=>$url);
    $maxBytes=1048576;
    $current=$url;

    for ($redirect=0;$redirect<=3;$redirect++) {
        $validationError='';
        if (RADIO_externalUrlValidation($current,$validationError)===false) {
            $diagnostic['error']=$validationError; return false;
        }
        $body=false; $status=0; $headers=array(); $error='';

        if (function_exists('curl_init')) {
            $buffer='';
            $ch=curl_init($current);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,false);
            curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);
            curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,4);
            curl_setopt($ch,CURLOPT_TIMEOUT,10);
            curl_setopt($ch,CURLOPT_USERAGENT,'Geeklog-Radio/'.RADIO_PLUGIN_VERSION);
            curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);
            curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);
            curl_setopt($ch,CURLOPT_HTTPHEADER,$requestHeaders);
            curl_setopt($ch,CURLOPT_HEADERFUNCTION,function($h,$line) use (&$headers){$n=strlen($line);$line=trim($line);if($line!==''&&strpos($line,':')!==false)$headers[]=$line;return $n;});
            curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($h,$chunk) use (&$buffer,$maxBytes){if(strlen($buffer)+strlen($chunk)>$maxBytes)return 0;$buffer.=$chunk;return strlen($chunk);});
            $ok=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            if($ok===false)$error=curl_error($ch);else $body=$buffer;
            curl_close($ch);
        } elseif ((bool)ini_get('allow_url_fopen')) {
            $ctx=stream_context_create(array('http'=>array('method'=>'GET','timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'header'=>"User-Agent: Geeklog-Radio/".RADIO_PLUGIN_VERSION."\r\n".implode("\r\n",$requestHeaders)."\r\n"),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true)));
            $body=@file_get_contents($current,false,$ctx,0,$maxBytes+1);
            if(is_string($body)&&strlen($body)>$maxBytes){$body=false;$error='response_too_large';}
            if(isset($http_response_header)&&is_array($http_response_header)){foreach($http_response_header as $line){if(preg_match('#^HTTP/\S+\s+([0-9]{3})#i',$line,$m))$status=(int)$m[1];elseif(strpos($line,':')!==false)$headers[]=$line;}}
            if($body===false&&$error==='')$error='fetch_failed';
        } else $error='http_transport_unavailable';

        $parsed=array();
        foreach($headers as $line){list($name,$value)=explode(':',$line,2);$parsed[strtolower(trim($name))]=trim($value);}
        $diagnostic=array('status'=>$status,'headers'=>$parsed,'error'=>$error,'url'=>$current);

        if($status>=300&&$status<400&&isset($parsed['location'])){
            if($redirect>=3){$diagnostic['error']='too_many_redirects';return false;}
            $next=RADIO_resolveRedirectUrl($current,$parsed['location']);
            if($next===''){$diagnostic['error']='invalid_redirect';return false;}
            $current=$next; continue;
        }
        if($status===304)return '';
        if($status<200||$status>=300||!is_string($body)){if($diagnostic['error']==='')$diagnostic['error']='http_status_'.$status;return false;}
        return $body;
    }
    $diagnostic['error']='too_many_redirects';
    return false;
}

function RADIO_parseFeedXml($xml,&$error)
{
    $error='';
    if(!is_string($xml)||trim($xml)===''){ $error='feed_empty'; return false; }
    $previous=libxml_use_internal_errors(true);
    if(function_exists('libxml_disable_entity_loader')) @libxml_disable_entity_loader(true);
    $doc=@simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    if($doc===false){$error='feed_xml_invalid';return false;}

    $items=array(); $feedTitle='';
    if(isset($doc->channel)){
        $feedTitle=trim((string)$doc->channel->title);
        foreach($doc->channel->item as $item){
            $url='';$mime='';$length=0;
            if(isset($item->enclosure)){ $a=$item->enclosure->attributes(); $url=isset($a['url'])?trim((string)$a['url']):''; $mime=isset($a['type'])?trim((string)$a['type']):''; $length=isset($a['length'])?(int)$a['length']:0; }
            $urlError=''; if($url===''||RADIO_externalUrlValidation($url,$urlError)===false)continue;
            if($mime!=='' && strpos(strtolower($mime),'audio/')!==0 && strtolower($mime)!=='application/ogg')continue;
            $items[]=array('key'=>sha1($url),'title'=>trim((string)$item->title),'description'=>trim(strip_tags((string)$item->description)),'url'=>$url,'mime'=>$mime,'length'=>$length,'published'=>trim((string)$item->pubDate),'guid'=>trim((string)$item->guid));
            if(count($items)>=50)break;
        }
    } else {
        $feedTitle=trim((string)$doc->title);
        foreach($doc->entry as $entry){
            $url='';$mime='';
            foreach($entry->link as $link){$a=$link->attributes();if(isset($a['rel'])&&(string)$a['rel']==='enclosure'&&isset($a['href'])){$url=trim((string)$a['href']);$mime=isset($a['type'])?trim((string)$a['type']):'';break;}}
            $urlError=''; if($url===''||RADIO_externalUrlValidation($url,$urlError)===false)continue;
            if($mime!=='' && strpos(strtolower($mime),'audio/')!==0 && strtolower($mime)!=='application/ogg')continue;
            $summary=isset($entry->summary)?(string)$entry->summary:(isset($entry->content)?(string)$entry->content:'');
            $items[]=array('key'=>sha1($url),'title'=>trim((string)$entry->title),'description'=>trim(strip_tags($summary)),'url'=>$url,'mime'=>$mime,'length'=>0,'published'=>trim((string)(isset($entry->published)?$entry->published:$entry->updated)),'guid'=>trim((string)$entry->id));
            if(count($items)>=50)break;
        }
    }
    return array('title'=>$feedTitle,'items'=>$items);
}

function RADIO_fetchFeedSource($source,&$error,$conditional=true)
{
    global $_TABLES;
    $error='';
    if(!is_array($source)){ $error='source_not_found'; return false; }

    $headers=array('Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.1');
    if($conditional && !empty($source['etag']))$headers[]='If-None-Match: '.$source['etag'];
    if($conditional && !empty($source['last_modified']))$headers[]='If-Modified-Since: '.$source['last_modified'];

    $diag=array(); $body=RADIO_httpFetchBounded($source['source_url'],$headers,$diag); $now=date('Y-m-d H:i:s');
    $etag=isset($diag['headers']['etag'])?$diag['headers']['etag']:$source['etag'];
    $lm=isset($diag['headers']['last-modified'])?$diag['headers']['last-modified']:$source['last_modified'];

    if($body===false){
        $error=$diag['error']!==''?$diag['error']:'feed_fetch_failed';
        DB_query("UPDATE {$_TABLES['radio_sources']} SET last_checked='".DB_escapeString($now)."',last_status=".(int)$diag['status'].",last_error='".DB_escapeString($error)."' WHERE source_id=".(int)$source['source_id']);
        return false;
    }
    if($body===''){
        DB_query("UPDATE {$_TABLES['radio_sources']} SET last_checked='".DB_escapeString($now)."',last_status=304,last_error='' WHERE source_id=".(int)$source['source_id']);
        return array('title'=>$source['title'],'items'=>array(),'not_modified'=>true);
    }

    $parseError=''; $parsed=RADIO_parseFeedXml($body,$parseError);
    if($parsed===false){$error=$parseError;DB_query("UPDATE {$_TABLES['radio_sources']} SET last_checked='".DB_escapeString($now)."',last_status=".(int)$diag['status'].",last_error='".DB_escapeString($error)."' WHERE source_id=".(int)$source['source_id']);return false;}

    DB_query("UPDATE {$_TABLES['radio_sources']} SET last_checked='".DB_escapeString($now)."',last_status=".(int)$diag['status'].",last_error='',etag='".DB_escapeString(substr($etag,0,255))."',last_modified='".DB_escapeString(substr($lm,0,255))."' WHERE source_id=".(int)$source['source_id']);
    $parsed['not_modified']=false; return $parsed;
}

function RADIO_importFeedEpisode($source,$episode,&$error)
{
    global $_TABLES;
    $externalId=$episode['guid']!==''?$episode['guid']:$episode['key'];
    $provider=$source['provider']!==''?$source['provider']:$source['title'];
    $existing=DB_query("SELECT media_id FROM {$_TABLES['radio_media']} WHERE source_kind='external' AND (source_url='".DB_escapeString($episode['url'])."' OR (source_provider='".DB_escapeString($provider)."' AND source_external_id='".DB_escapeString($externalId)."')) LIMIT 1");
    if(DB_numRows($existing)>0){$error='feed_episode_exists';return false;}
    $data=array('source_kind'=>'external','source_url'=>$episode['url'],'title'=>$episode['title']!==''?$episode['title']:'Podcast episode','description'=>$episode['description'],'media_type'=>'podcast','duration'=>0,'source_provider'=>$provider,'source_external_id'=>$externalId,'source_attribution'=>$source['title'],'source_license'=>'','status'=>'draft','group_id'=>$source['group_id'],'perm_owner'=>$source['perm_owner'],'perm_group'=>$source['perm_group'],'perm_members'=>$source['perm_members'],'perm_anon'=>$source['perm_anon']);
    return RADIO_saveExternalMedia($data,$error);
}


function RADIO_ensureFeedSyncSchema()
{
    global $_TABLES;

    if (!RADIO_ensureSourcesTable()) {
        return false;
    }

    $ok = true;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'sync_mode', "varchar(16) NOT NULL default 'preview'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'last_sync', "datetime default NULL") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'last_sync_new', "int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'last_sync_existing', "int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'last_sync_imported', "int(10) unsigned NOT NULL default '0'") && $ok;
    $ok = RADIO_ensureSchemaColumn($_TABLES['radio_sources'], 'last_sync_errors', "int(10) unsigned NOT NULL default '0'") && $ok;

    $table = $_TABLES['radio_source_sync_log'];
    $result = DB_query("SHOW TABLES LIKE '" . DB_escapeString($table) . "'");
    if (DB_numRows($result) === 0) {
        DB_query("CREATE TABLE " . $table . " (
          log_id bigint(20) unsigned NOT NULL auto_increment,
          source_id int(10) unsigned NOT NULL default '0',
          sync_mode varchar(16) NOT NULL default 'preview',
          status varchar(24) NOT NULL default 'ok',
          new_count int(10) unsigned NOT NULL default '0',
          existing_count int(10) unsigned NOT NULL default '0',
          imported_count int(10) unsigned NOT NULL default '0',
          error_count int(10) unsigned NOT NULL default '0',
          message varchar(255) NOT NULL default '',
          created datetime NOT NULL,
          PRIMARY KEY (log_id),
          KEY source_created (source_id,created)
        ) ENGINE=MyISAM");
        $ok = !DB_error() && $ok;
    }

    return $ok;
}

function RADIO_setFeedSourceSyncMode($id, $mode)
{
    global $_TABLES;

    $source = RADIO_getFeedSource((int) $id, false);
    if ($source === false || !RADIO_hasEditAccess($source)) {
        return false;
    }

    $mode = $mode === 'drafts' ? 'drafts' : 'preview';
    DB_query(
        "UPDATE {$_TABLES['radio_sources']} SET sync_mode='" . DB_escapeString($mode)
        . "',modified='" . DB_escapeString(date('Y-m-d H:i:s')) . "' WHERE source_id=" . (int) $id
    );
    return !DB_error();
}

function RADIO_feedEpisodeExistingId($source, $episode)
{
    global $_TABLES;

    $externalId = $episode['guid'] !== '' ? $episode['guid'] : $episode['key'];
    $provider = $source['provider'] !== '' ? $source['provider'] : $source['title'];

    $result = DB_query(
        "SELECT media_id FROM {$_TABLES['radio_media']} WHERE source_kind='external' AND ("
        . "source_url='" . DB_escapeString($episode['url']) . "' OR ("
        . "source_provider='" . DB_escapeString($provider) . "' AND "
        . "source_external_id='" . DB_escapeString($externalId) . "')) LIMIT 1"
    );

    if (DB_numRows($result) < 1) {
        return 0;
    }
    $row = DB_fetchArray($result);
    return (int) $row['media_id'];
}

function RADIO_classifyFeedEpisodes($source, $items)
{
    $result = array();
    foreach ($items as $episode) {
        $existingId = RADIO_feedEpisodeExistingId($source, $episode);
        $episode['existing_media_id'] = $existingId;
        $episode['is_new'] = $existingId < 1;
        $result[] = $episode;
    }
    return $result;
}

function RADIO_logFeedSync($sourceId, $mode, $status, $summary, $message)
{
    global $_TABLES;

    if (!RADIO_ensureFeedSyncSchema()) {
        return false;
    }

    DB_query(
        "INSERT INTO {$_TABLES['radio_source_sync_log']} "
        . "(source_id,sync_mode,status,new_count,existing_count,imported_count,error_count,message,created) VALUES ("
        . (int) $sourceId . ","
        . "'" . DB_escapeString($mode) . "',"
        . "'" . DB_escapeString($status) . "',"
        . (int) $summary['new'] . ","
        . (int) $summary['existing'] . ","
        . (int) $summary['imported'] . ","
        . (int) $summary['errors'] . ","
        . "'" . DB_escapeString(substr((string) $message, 0, 255)) . "',"
        . "'" . DB_escapeString(date('Y-m-d H:i:s')) . "')"
    );

    return !DB_error();
}

function RADIO_syncFeedSource($sourceId, $importLimit, &$error)
{
    global $_TABLES;

    $error = '';
    $source = RADIO_getFeedSource((int) $sourceId, false);
    if ($source === false) {
        $error = 'source_not_found';
        return false;
    }
    if (empty($source['enabled'])) {
        $error = 'feed_source_disabled';
        return false;
    }

    $mode = isset($source['sync_mode']) && $source['sync_mode'] === 'drafts' ? 'drafts' : 'preview';
    $importLimit = max(0, min(50, (int) $importLimit));
    $summary = array('new' => 0, 'existing' => 0, 'imported' => 0, 'errors' => 0);

    $fetchError = '';
    $feed = RADIO_fetchFeedSource($source, $fetchError, true);
    if ($feed === false) {
        $error = $fetchError !== '' ? $fetchError : 'feed_fetch_failed';
        RADIO_logFeedSync($sourceId, $mode, 'error', $summary, $error);
        return false;
    }

    if (!empty($feed['not_modified'])) {
        DB_query(
            "UPDATE {$_TABLES['radio_sources']} SET last_sync='" . DB_escapeString(date('Y-m-d H:i:s')) . "',"
            . "last_sync_new=0,last_sync_existing=0,last_sync_imported=0,last_sync_errors=0 "
            . "WHERE source_id=" . (int) $sourceId
        );
        RADIO_logFeedSync($sourceId, $mode, 'not_modified', $summary, '304 Not Modified');
        $summary['not_modified'] = true;
        return $summary;
    }

    $episodes = RADIO_classifyFeedEpisodes($source, $feed['items']);
    foreach ($episodes as $episode) {
        if (!$episode['is_new']) {
            $summary['existing']++;
            continue;
        }

        $summary['new']++;
        if ($mode !== 'drafts' || $summary['imported'] >= $importLimit) {
            continue;
        }

        $importError = '';
        if (RADIO_importFeedEpisode($source, $episode, $importError) !== false) {
            $summary['imported']++;
        } else {
            $summary['errors']++;
        }
    }

    $now = date('Y-m-d H:i:s');
    DB_query(
        "UPDATE {$_TABLES['radio_sources']} SET "
        . "last_sync='" . DB_escapeString($now) . "',"
        . "last_sync_new=" . (int) $summary['new'] . ","
        . "last_sync_existing=" . (int) $summary['existing'] . ","
        . "last_sync_imported=" . (int) $summary['imported'] . ","
        . "last_sync_errors=" . (int) $summary['errors'] . " "
        . "WHERE source_id=" . (int) $sourceId
    );

    RADIO_logFeedSync(
        $sourceId,
        $mode,
        $summary['errors'] > 0 ? 'partial' : 'ok',
        $summary,
        ''
    );

    $summary['not_modified'] = false;
    return $summary;
}

function RADIO_syncEnabledFeeds($importLimit)
{
    $sources = RADIO_getFeedSources(100, true);
    $result = array(
        'sources' => count($sources),
        'ok' => 0,
        'errors' => 0,
        'new' => 0,
        'existing' => 0,
        'imported' => 0
    );

    foreach ($sources as $source) {
        $error = '';
        $summary = RADIO_syncFeedSource((int) $source['source_id'], $importLimit, $error);
        if ($summary === false) {
            $result['errors']++;
            continue;
        }
        $result['ok']++;
        $result['new'] += (int) $summary['new'];
        $result['existing'] += (int) $summary['existing'];
        $result['imported'] += (int) $summary['imported'];
        $result['errors'] += (int) $summary['errors'];
    }

    return $result;
}

function RADIO_getFeedSyncLog($sourceId, $limit)
{
    global $_TABLES;

    if (!RADIO_ensureFeedSyncSchema()) {
        return array();
    }

    $limit = max(1, min(50, (int) $limit));
    $where = (int) $sourceId > 0 ? " WHERE source_id=" . (int) $sourceId : '';
    $result = DB_query(
        "SELECT * FROM {$_TABLES['radio_source_sync_log']}" . $where
        . " ORDER BY created DESC,log_id DESC LIMIT " . $limit
    );

    $rows = array();
    while ($row = DB_fetchArray($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function RADIO_feedSyncSummary()
{
    global $_TABLES;

    if (!RADIO_ensureFeedSyncSchema()) {
        return array(
            'enabled_sources' => 0,
            'sources_with_errors' => 0,
            'last_sync' => '',
            'last_imported' => 0
        );
    }

    $enabledResult = DB_query(
        "SELECT COUNT(*) AS total FROM {$_TABLES['radio_sources']} WHERE enabled=1"
        . RADIO_permissionSql('radio_sources', 0)
    );
    $enabledRow = DB_fetchArray($enabledResult);
    $enabled = is_array($enabledRow) ? (int) $enabledRow['total'] : 0;

    $errorResult = DB_query(
        "SELECT COUNT(*) AS total FROM {$_TABLES['radio_sources']} WHERE enabled=1 AND last_error<>''"
        . RADIO_permissionSql('radio_sources', 0)
    );
    $errorRow = DB_fetchArray($errorResult);
    $errors = is_array($errorRow) ? (int) $errorRow['total'] : 0;

    $result = DB_query(
        "SELECT last_sync,last_sync_imported FROM {$_TABLES['radio_sources']} "
        . "WHERE last_sync IS NOT NULL" . RADIO_permissionSql('radio_sources', 0)
        . " ORDER BY last_sync DESC LIMIT 1"
    );
    $row = DB_numRows($result) > 0 ? DB_fetchArray($result) : array();

    return array(
        'enabled_sources' => $enabled,
        'sources_with_errors' => $errors,
        'last_sync' => isset($row['last_sync']) ? $row['last_sync'] : '',
        'last_imported' => isset($row['last_sync_imported']) ? (int) $row['last_sync_imported'] : 0
    );
}
