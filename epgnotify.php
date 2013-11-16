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
		fwrite($file,"[time]\n");
		fwrite($file,"timeformat=\"D M j G:i:s T Y\"\n");
		fwrite($file,"\n");
		fwrite($file,"global['epgfile']=\"/var/cache/vdr/epg.data\"\n");
	}
	$config_user=parse_ini_file(getenv('HOME')."/.epgnotify.ini");
	
	# consider bad config (something unset)
	if (!isset($config_user['title'])) {$config_user['title'][0]="";}
	if (!isset($config_user['notitle'])) {$config_user['notitle'][0]="";}
	if (!isset($config_user['shortText'])) {$config_user['shortText'][0]="";}
	if (!isset($config_user['description'])) {$config_user['description'][0]="";}
	if (!isset($config_user['timeformat'])) {$config_user['timeformat']="D M j G:i:s T Y";}

	# load global config
	if (file_exists("/etc/epgnotify/global.ini")) {
	        # global config file should look like this: 
        	# [global]
        	# vdradmin-am['connect']="http://vdradmin:vdradmin@linux:8001"
        	# timezone="Europe/Vienna"
        	# charset="UTF-8"
		$config_global['global']=parse_ini_file("/etc/epgnotify/global.ini");
	}
	if (!isset($config_global['global']['epgfile'])) {
		$config_global['global']['epgfile']="/var/cache/vdr/epg.data";
	}
	if (!isset($config_global['global']['charset'])) {
	        $config_global['global']['charset']="ISO-8859-1";
	}
		
	# merge both configs
	return array_merge($config_global,$config_user);

}

###################### BEGIN OF SCRIPT ###################

# read config from files
$config=readConfig();

# set correct local timezone
if (isset($config['global']['timezone'])){
        date_default_timezone_set($config['global']['timezone']);
}

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
		        case "C": 
		                # new channel
		                # sscanf doesn't work in php 5 if a space is in channel name
		                #list($current_channel_id, $current_channel_name)=sscanf(substr($line,2),"%s %s");
		                $delim_idx=strpos(substr($line,2)," ")+2;
		                $current_channel_id=substr($line,2,$delim_idx-2);
		                $current_channel_name=substr($line,$delim_idx+1,strlen($line)-$delim_idx);
        	                # print_r($current_channel_id);
		                # echo "\n";
		                # print_r($current_channel_name);
		                # echo "\n\n";
		                
			case "E":
				#new program
				unset($program);
				# reset hit indicator at beginning of a new program description
				$hit=false;
				# read program data
				list($program['info']['eventID'], $program['info']['startTime'], $program['info']['duration'])=sscanf(substr($line,2),"%s %s %s");
				# startTime is in time_t format; convert to human readable format
				$program['info']['startTime']=date($config['timeformat'],intval($program['info']['startTime']));
				# channel name and ID had been read before
				$program['channel']['id']=$current_channel_id;
				$program['channel']['name']=$current_channel_name;
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
                        case "G":
                                # space seperated list of up to four integers according to ETSI EN 300 468
                                $program['info']['genre']=explode(" ",substr($line,2,strlen($line)-3));
                                break; 
                        case "X": 
                                # description of streams, can occur more than ones
				$program['streams'][]=substr($line,2,strlen($line)-3);
				break;
                        case "V": 
                                # VPS time in UTC
                                $program['channel']['VPS']=substr($line,2,strlen($line)-3);
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
                        case "c": # end of currently processed channel
                                # do nothing right now, maybe add debug output later
                                break;
		}
	}
	fclose($file);

	# check if new programs that matches the search filter were found
	if (isset($programSave)) {
	        # generate user friendly output
	        $mail_text="<!DOCTYPE html><html><head><title>Notification about newely found programs</title></head><body>";
	        $mail_text .= "<table border=\"1\" width=\"100%\">";
	        
	        # table header
	        $mail_text .= "<tr>";
	        $mail_text .= "<td align=\"center\"><b>Channel</b></td>";
	        $mail_text .= "<td align=\"center\"><b>Program</b></td>";
	        $mail_text .= "<td align=\"center\"><b>Description</b></td>";	        
	        $mail_text .= "<td align=\"center\"><b>Match</b></td>";
	        $mail_text .= "<td align=\"center\"><b>Streams</b></td>";
	        $mail_text .= "</tr>";
	        
	        # a row for each found program
	        foreach ($programSave as $program) {
	                $mail_text .= "<tr>";	 

	                # add channel information
	                $mail_text .= "<td align=\"center\">".  $program['channel']['id']. "<br><b>". $program['channel']['name']."</b>";
	                if (isset($program['channel']['VPS'])) {
	                        $mail_text .= "<br>VPS: ".$program['channel']['VPS'];
                        }
	                $mail_text .= "</td>";
	                
	                # add program information
	                $mail_text .= "<td align=\"center\">";
	                $mail_text .= $program['info']['startTime']."<br>";
	                $mail_text .= "<b>".$program['info']['title']."</b><br>";
	                $mail_text .= "EventID: ".$program['info']['eventID']." ";
	                if ( isset($program['info']['genre']) ){
	                        $mail_text .= "Genre: ";
        	                foreach ($program['info']['genre'] as $genre) {
        	                        $mail_text .=" ".$genre;
                                }
                                $mail_text .=" ";
	                }
	                $mail_text .= "Duration (min): ".sprintf("%d",intval($program['info']['duration'])/60);
                        if (isset($config['global']['vdradmin-am']['connect'])) {
                                $mail_text .= "<br><a href=\"".$config['global']['vdradmin-am']['connect']."/vdradmin.pl?";
                                $mail_text .= "aktion=timer_new_form";
                                $mail_text .= "&epg_id=".$program['info']['eventID'];
                                $mail_text .= "&channel_id=".$program['channel']['id'];
                                $mail_text .= "&referer=".base64_encode("./vdradmin.pl?aktion=timer_list");
                                $mail_text .= "\">Link to vdradmin-am</a>";
                        }
                        $mail_text .= "</td><td>";
                                
                        if (isset($program['info']['description'])) {
                                $mail_text .= $program['info']['description'];
                        }
                        $mail_text .="</td>";
                            
	                # add matches
	                $mail_text .= "<td>";
	                foreach (array_keys($program['match']) as $key) {
	                        $mail_text .= $key.": ".$program['match'][$key]."<br>";
                        }
                        $mail_text .= "</td>";
                        
	                # add streams (optional, may be not set)
	                $mail_text .= "<td>";
                        if (isset($program['streams'])) {
                                foreach ($program['streams'] as $stream) {
        	                        $mail_text .=  $stream ."<br>";
                                }
	                }
	                $mail_text .= "</td></tr>";
	        }
      
	        $mail_text .="</table></body></html>";
	        $mail_header = "MIME-Version: 1.0\r\n";
	        $mail_header .= "Content-Type: text/html; charset=".$config['global']['charset']."\r\n";
	        $mail_header .= "X-Mailer: PHP ". phpversion();
		# mail newly found programs to user's mail address
		$mail_success=mail($config['mail_address'], "epgnotify found ".count($programSave)." new programs for you" , $mail_text, $mail_header);
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