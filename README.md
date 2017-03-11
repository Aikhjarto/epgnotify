epgnotify
=========
It is a simple email notification for upcoming programs. It reads epg.data created by vdr (compatible with vdr 1.7 and 2.0) an sends a mails on a per user basis if newly added programs match certain search tags. The mail includes a link to directly schedule a recording for found programs via vdradmin-am.

Prerequisites
=============
* vdr 2.0 or 1.7 (might work with 1.6 but its not confirmed)
* php parser (tested with php 5.3) with mail capabilities and php5-mbstring
* optional: vdradmin-am (tested with version 3.6.9) for directly scheduling a recording via link

Usage
=====
Simply run `php epgnotify.php` or `./epgnotify.php`. Config is stored in `~/.epgconfig.ini` (which is created and initialized with default values).
It's good practice to run periodically with e.g. cron to get daily information. Just add the following line to 'crontab -e'
```0 0 * * * /usr/local/bin/epgnotify.php```

What it does in detail
======================
It reads search strings from ~/.epgconfig.ini individually for program, title, short description, description, streams,... of EPG data. EPG data is obtained from vdr.

If one or more programs from the EPG data matches any of search strings it generates an email with summary of all matching programs and sends it to the email-address given in ~/.epgnotify.ini.

Subsequently, in ~/.epgnotify.cache programs are stored to prevent sending notifications about the same program several times.
If a program is not any more in the EPG data, it is also cleared from the cache. This prevents the cache to grow unnecessarily large, but program might get resent after a restart of vdr.
