<?php
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file cannot be used on its own.');
}

$_SQL[] = "CREATE TABLE {$_TABLES['radio_media']} (
  media_id int(10) unsigned NOT NULL auto_increment,
  title varchar(255) NOT NULL default '',
  description text,
  media_type varchar(32) NOT NULL default 'music',
  author varchar(255) NOT NULL default '',
  series_title varchar(255) NOT NULL default '',
  season_number int(10) unsigned NOT NULL default '0',
  episode_number int(10) unsigned NOT NULL default '0',
  cover_name varchar(255) NOT NULL default '',
  storage_name varchar(255) NOT NULL default '',
  original_name varchar(255) NOT NULL default '',
  mime_type varchar(96) NOT NULL default '',
  duration int(10) unsigned NOT NULL default '0',
  file_size bigint(20) unsigned NOT NULL default '0',
  status varchar(24) NOT NULL default 'draft',
  allow_download tinyint(1) unsigned NOT NULL default '1',
  hits int(10) unsigned NOT NULL default '0',
  owner_id int(10) unsigned NOT NULL default '2',
  group_id mediumint(8) unsigned NOT NULL default '1',
  perm_owner tinyint(1) unsigned NOT NULL default '3',
  perm_group tinyint(1) unsigned NOT NULL default '2',
  perm_members tinyint(1) unsigned NOT NULL default '2',
  perm_anon tinyint(1) unsigned NOT NULL default '2',
  created datetime NOT NULL,
  modified datetime NOT NULL,
  PRIMARY KEY (media_id),
  KEY status_modified (status, modified),
  KEY media_type (media_type)
) ENGINE=MyISAM;";

$_SQL[] = "CREATE TABLE {$_TABLES['radio_programs']} (
  program_id int(10) unsigned NOT NULL auto_increment,
  title varchar(255) NOT NULL default '',
  description text,
  host varchar(255) NOT NULL default '',
  cover_name varchar(255) NOT NULL default '',
  status varchar(24) NOT NULL default 'draft',
  owner_id int(10) unsigned NOT NULL default '2',
  group_id mediumint(8) unsigned NOT NULL default '1',
  perm_owner tinyint(1) unsigned NOT NULL default '3',
  perm_group tinyint(1) unsigned NOT NULL default '2',
  perm_members tinyint(1) unsigned NOT NULL default '2',
  perm_anon tinyint(1) unsigned NOT NULL default '2',
  created datetime NOT NULL,
  modified datetime NOT NULL,
  PRIMARY KEY (program_id),
  KEY status_modified (status, modified)
) ENGINE=MyISAM;";

$_SQL[] = "CREATE TABLE {$_TABLES['radio_program_items']} (
  item_id int(10) unsigned NOT NULL auto_increment,
  program_id int(10) unsigned NOT NULL,
  media_id int(10) unsigned NOT NULL,
  sort_order int(10) unsigned NOT NULL default '0',
  PRIMARY KEY (item_id),
  KEY program_order (program_id, sort_order),
  KEY media_id (media_id)
) ENGINE=MyISAM;";

$_SQL[] = "CREATE TABLE {$_TABLES['radio_schedule']} (
  schedule_id int(10) unsigned NOT NULL auto_increment,
  program_id int(10) unsigned NOT NULL,
  starts_at datetime NOT NULL,
  ends_at datetime DEFAULT NULL,
  recurrence varchar(32) NOT NULL default 'once',
  weekdays varchar(32) NOT NULL default '',
  active_from date DEFAULT NULL,
  active_until date DEFAULT NULL,
  enabled tinyint(1) unsigned NOT NULL default '1',
  PRIMARY KEY (schedule_id),
  KEY starts_at (starts_at),
  KEY program_id (program_id)
) ENGINE=MyISAM;";


$_SQL[] = "CREATE TABLE {$_TABLES['radio_events']} (
  event_id bigint(20) unsigned NOT NULL auto_increment,
  media_id int(10) unsigned NOT NULL default '0',
  program_id int(10) unsigned NOT NULL default '0',
  event_type varchar(24) NOT NULL default 'play',
  source varchar(24) NOT NULL default 'catalogue',
  seconds_listened int(10) unsigned NOT NULL default '0',
  created datetime NOT NULL,
  PRIMARY KEY (event_id),
  KEY created (created),
  KEY media_event (media_id,event_type),
  KEY source_event (source,event_type)
) ENGINE=MyISAM;";
