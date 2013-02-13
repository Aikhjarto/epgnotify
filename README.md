epgnotify
=========
It is a simple email notification for upcomming programs. It reads epg.data created by vdr (compatible with vdr 1.7) an sends a mails on a per user basis if newly added programs match certain search tags.

Prerequisties
=============
() vdr 1.7 (might work with 1.6 but its not confirmed)
() php parses


Installation
============
TODO: set up a Makefile with targets 'install' and 'uninstall'
TODO: run epgnotify once to create config file.

Usage
=====
Simply run "php epgnotify.php". Config is stored in ~/.epgconfig.ini (which is created and initialized with default values).
Its also possible to run it as cronjob to get daily information. Just add the following line to 'crontab -e'
0 0 * * * php /usr/local/bin/epgnotify.php

What it does
============
It reads search strings from ~/.epgconfig.ini individually for program, title, short description, and description of EPG data. EPG data is optained from vdr. If one or more programs from the EPG data matches any of search strings it generates an email with summary of all matching programms and sents it to the email-address given in ~/.epgnotify.ini.
Subsequentely, in ~/.epgnotify.cache programms are stored to prevent sending notifications about the same program several times.
If a program is not any more in the EPG data, it is also cleared from the cache. This prevents the cache to grow unnecessarily large, but program might get resent after a restart of vdr.
