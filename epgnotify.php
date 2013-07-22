<?php
# EPGNOTIFY implements a brief e-mail notification of new programms found in vdr's epg.data file
# Copyright (C) 2013  Thomas Wagner
#  
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#        
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#                                    
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

function readConfig(){
	# read user config file
	if (!file_exists(getenv('HOME')."/.epgnotify.ini")) {
		# if file does not exists: write a new one
		$file=fopen(getenv('HOME')."/.epgnotify.ini",'w');
		fwrite($file,"; file should be encoded in ISO-8859-1\n");
		fwrite($file,"[searchStrings]\n");
		fwrite($file,";title[]=\"Pulp Fiction\"\n");
		fwrite($file,";title[]=\"2 Fast\"\n");
		fwrite($file,";title[]=\"Ö3\"\n");
		fwrite($file,";title[]=\"Musik\"\n");
		fwrite($file,";title[]=\"Sherlock\"\n");		
		fwrite($file,";title[]=\"Sherlock Yack\"\n");
		fwrite($file,";titleExact[]=\"Super\"\n");
		fwrite($file,"title[]=\"\"\n");
		fwrite($file,"shortText[]=\"\"\n");
		fwrite($file,"description[]=\"\"\n");
		fwrite($file,"\n");
		fwrite($file,"[mail]\n");
		fwrite($file,"mail_address=\"".getenv('USER')."@".getenv('HOSTNAME')."\"\n");
		fwrite($file,"\n");
		fwrite($file,"global['epgfile']=\"/etc/vdr/epg.data\"\n");
	}
	$config_user=parse_ini_file(getenv('HOME')."/.epgnotify.ini");
	
	# consider bad config (something unset)
	if (!isset($config_user['title'])) {$config_user['title'][0]="";}
	if (!isset($config_user['notitle'])) {$config_user['notitle'][0]="";}
	if (!isset($config_user['shortText'])) {$config_user['shortText'][0]="";}
	if (!isset($config_user['description'])) {$config_user['description'][0]="";}

	# load global config
	if (file_exists("/etc/epgnotify/global.ini")) {
		$config_global=parse_ini_file("/etc/epgnotify/global.ini");
	}
	else {
		$config_global['global']['epgfile']="/var/cache/vdr/epg.data";
	}
	
	# merge both configs
	return array_merge($config_global,$config_user);

}

###################### BEGIN OF SCRIPT ###################

# read config from files
$config=readConfig();

# read cache (programs for which a notification has been sent and that are still in the epg database)
if (file_exists(getenv('HOME')."/.epgnotify.cache")) {
	$cache=unserialize(file_get_contents(getenv('HOME')."/.epgnotify.cache"));
	if ($cache==false){ 
		$cache = array();
	}
} else {
	$cache = array();
}

# read and process egpfile if exists
if (file_exists($config['global']['epgfile'])) {
	# open epg database from vdr
	$file=fopen($config['global']['epgfile'],"r");

	$hit=false; # helper variable; indicates if currently processed program is noteworthy to store
	$eventIDCount=0; # counter for found event IDs in epg data

	# read epg data line per line; see http://www.vdr-wiki.de/wiki/index.php/Epg.data
	while (!feof($file)) {
		# read next line (is of format X data1 data2 ...)
		$line=fgets($file);
		
		# optionally: encoding convertion (some stations send their data in a different encoding as they say they do)
		#$line=mb_convert_encoding($line,'ISO-8859-9','ISO-8859-15');

		# switch line identifier (first character of line)
		switch (substr($line,0,1)) {
			case "E":
				#new program
				unset($program);
				# reset hit indicator at beginning of a new program description
				$hit=false;
				# read program data
				list($program['info']['eventID'], $program['info']['startTime'], $program['info']['duration'])=sscanf(substr($line,2),"%s %s %s");
				# startTime is in time_t format; convert to human readable format
				$program['info']['startTime']=date("D M j G:i:s T Y",intval($program['info']['startTime']));
				# store eventIDs for later purging the cache
				$eventID[$eventIDCount]=$program['info']['eventID'];
				$eventIDCount=$eventIDCount+1;
				break;
				
			case "T":
				# read program's title
				$program['info']['title']=substr($line,2,strlen($line)-3);
				# search for matching string in title
				foreach ($config['title'] as $search) {
					# skip empty strings (these are placeholders in config file)
					if (strlen($search)>0 && !(stripos($program['info']['title'],$search) === false)) {
                                                $hit=true;
						foreach ($config['notitle'] as $nosearch) {
						        if (strlen($nosearch)>0 && !(stripos($program['info']['title'],$nosearch) === false)) {
						                $hit=false;
                                                        }
                                                }
                                                if ($hit == true) {
	        				        $program['match']['hitT']=$search;
                                                }
					}
					if (strlen($search)>0 && strcasecmp($program['info']['title'],$search) == 0) {
					        $hit=true;
					        if ($hit==true) {
					                $program['match']['hitTExact']=$search;
					        }
					}
				}
				break;
				
			case "S":
				# read program's short description
				$program['info']['short']=substr($line,2,strlen($line)-3);
				# search for matching string in short description
				foreach ($config['shortText'] as $search) {
					# skip empty strings (these are placeholders in config file)
					if (strlen($search)>0) {
						if (!(stripos($program['info']['title'],$search) === false)){
							$hit=true;
							$program['match']['hitS']=$search;
						}
					}
				}
				break;
				
			case "D":
				# read programs's description
				$program['info']['description']=substr($line,2,strlen($line)-3);
				# search for matching string in  description
				foreach ($config['description'] as $search) {
					# skip empty strings (these are placeholders in config file)
					if (strlen($search)>0) {
						if (!(stripos($program['info']['description'],$search) === false)){
							$hit=true;
							$program['match']['hitD']=$search;
						}
					}
				}
				break;
				
			case "e":
				# end of currently processed program 
				# check if search algorithm had a hit
				if ($hit==true) {
					# check if was already sent
					$cached=false;
					foreach ($cache as $cached_program) {
						if ($program['info']==$cached_program['info']) {
							$cached=true;
							$program['match']['cached']=true;
							break;
						}
					}
					# if notification wasn't already sent, append to the new program list
					if (!$cached) {
						$programSave[]=$program;
					}
				}
				break;
		}
	}
	fclose($file);

	# check if new programs that matches the search filter were found
	if (isset($programSave)) {
		# mail newly found programs to user's mail address
		$mail_success=mail($config['mail_address'], "epgnotify found ".count($programSave)." new programs for you" , print_r($programSave,true));
		# add newly found programs to cached list
		$cache=array_merge($programSave,$cache);

	}

	# purge cache (loop every program in cache of sent notifications and remove that ones that are not in the epg data any more)
	for ($i=0; $i<count($cache); $i++) {
		$found=false;
		foreach ($eventID as $evtID) {
			if ($cache[$i]['info']['eventID']==$evtID){
				$found=true;
				break;
			}
		}
		if (!$found) {
			unset($cache[$i]);
		}
	}
	# reduce indicess (not leave an empty index back)
	$cache=array_merge($cache); 
	# save cache
	file_put_contents(getenv('HOME')."/.epgnotify.cache",serialize($cache));

} else {
  # write a mail if epg.file does not exists
  mail($config['mail_address'], "epgnotify error, no epg data found", "Dear User,\n Unfortunately the epg data file ".$config['global']['epgfile']." as specified in ".getenv('HOME')."/.epgnotify.ini"." could not be opened for reading. Please check if the file exists and is readable.");
}

?>