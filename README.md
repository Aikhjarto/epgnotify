epgnotify
=========
It is a simple email notification for upcomming programs. It reads epg.data created by vdr (compatible with vdr 1.7 and 2.0) an sends a mails on a per user basis if newly added programs match certain search tags.

Prerequisties
=============
* vdr 2.0 or 1.7 (might work with 1.6 but its not confirmed)
* php parser (tested with php 5.3)

Usage
=====
Simply run "php epgnotify.php". Config is stored in ~/.epgconfig.ini (which is created and initialized with default values).
It's good practice to run periodically with e.g. cron to get daily information. Just add the following line to 'crontab -e'
0 0 * * * php /path/to/epgnotify.php

What it does in detail
======================
It reads search strings from ~/.epgconfig.ini individually for program, title, short description, description, streams,... of EPG data. EPG data is obtained from vdr. If one or more programs from the EPG data matches any of search strings it generates an email with summary of all matching programms and sents it to the email-address given in ~/.epgnotify.ini.
Subsequentely, in ~/.epgnotify.cache programms are stored to prevent sending notifications about the same program several times.
If a program is not any more in the EPG data, it is also cleared from the cache. This prevents the cache to grow unnecessarily large, but program might get resent after a restart of vdr.
