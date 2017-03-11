#!/usr/bin/php
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
		$file=fopen(getenv('HOME')."/.epgnotify.ini",'w+');
		fwrite($file,"; file should be encoded in UTF-8 (same as for epg.data)\n");
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
	if (!isset($config_global) || !array_key_exists('epgfile',$config_global['global']) ) {
		$config_global['global']['epgfile']="/var/cache/vdr/epg.data";
	}
	
	if (!array_key_exists('charset',$config_global['global'])) {
	        $config_global['global']['charset']="UTF-8";
	}
	if (!array_key_exists('svdrphost',$config_global['global'])) {
	        $config_global['global']['svdrphost']="linux.private.lan";
	}
#	echo("config_global");					
#       print_r($config_global);
        
#	echo("config_+");					
#       print_r($config_user+$config_global);
        
#	echo("config_merge");
#       print_r(array_merge($config_user,$config_global));
        
	# merge both configs
#	echo("config_merge2");
#	print_r(array_merge($config_global,$config_user));
	
	return array_merge($config_user,$config_global);


}

###################### BEGIN OF SCRIPT ###################

# read config from files
$config=readConfig();

# set correct local timezone according to config
if (array_key_exists('timezone',$config['global'])){
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

$tmp_epgfile="/tmp/epgdata_".get_current_user().".tmp";
# get epg data either from local epg file or from VDR plugin svdrp
if (file_exists($config['global']['epgfile'])) {
        copy($config['global']['epgfile'],$tmp_epgfile);
} else {
        $fp = fsockopen($config['global']['svdrphost'], 6419, $errno, $errstr, 30);
        if (!$fp) {
            	echo "$errstr ($errno)\n";
            } else {
	            $fw=fopen($tmp_epgfile,"w");
	            if (!$fw) {
                    	echo "$errstr ($errno)\n";
	            } else {
			    $out = "LSTE\n";
			    $out .= "QUIT\n";
			    fwrite($fp, $out);
			    while (!feof($fp)) {
				$line=fgets($fp);
				if ( mb_strpos($line,"215-") === 0 ) {    
				    # lines containing data starts with 215-
				    #echo $line;
				    fwrite($fw,mb_substr($line,4,mb_strlen($line)-4));
				}
			    }
			    fclose($fp);
		    }
            }
}

# read and process egpfile if exists
# TODO: check if epg data is out of date or even not present
# outdate data can be checked storing the highest date of broadcast while iterating over the file. If i lies in the past, egpdata is outdated. If its empty, we got not epg data.
if (file_exists($tmp_epgfile)) {
	# open epg database from vdr
	$file=fopen($tmp_epgfile,"r");

	$hit=false; # helper variable; indicates if currently processed program is noteworthy to store
	$eventIDCount=0; # counter for found event IDs in epg data

	# read epg data line per line; see http://www.vdr-wiki.de/wiki/index.php/Epg.data
	while (!feof($file)) {
		# read next line (is of format X data1 data2 ...)
		$line=fgets($file);
		
		# optionally: encoding conversion (some stations send their data in a different encoding as they say they do)
		#$line=mb_convert_encoding($line,'ISO-8859-9','ISO-8859-15');

		# switch line identifier (first character of line)
		switch (mb_substr($line,0,1)) {
		        case "C": 
		                # new channel
		                # sscanf doesn't work in php 5 if a space is in channel name
		                #list($current_channel_id, $current_channel_name)=sscanf(mb_substr($line,2),"%s %s");
		                $delim_idx=mb_strpos(mb_substr($line,2)," ")+2;
		                $current_channel_id=mb_substr($line,2,$delim_idx-2);
		                $current_channel_name=mb_substr($line,$delim_idx+1,mb_strlen($line)-$delim_idx-2);
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
				list($program['info']['eventID'], $program['info']['startTime'], $program['info']['duration'])=sscanf(mb_substr($line,2),"%s %s %s");
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
				$program['info']['title']=mb_substr($line,2,mb_strlen($line)-3);
				# search for matching string in title
				foreach ($config['title'] as $search) {
					# skip empty strings (these are place-holders in config file)
					if (mb_strlen($search)>0 && !(stripos($program['info']['title'],$search) === false)) {
                                                $hit=true;
						foreach ($config['notitle'] as $nosearch) {
						        if (mb_strlen($nosearch)>0 && !(mb_stripos($program['info']['title'],$nosearch) === false)) {
						                $hit=false;
                                                        }
                                                }
                                                if ($hit == true) {
	        				        $program['match']['hitT']=$search;
                                                }
					}
					if (mb_strlen($search)>0 && strcasecmp($program['info']['title'],$search) == 0) {
					        $hit=true;
					        if ($hit==true) {
					                $program['match']['hitTExact']=$search;
					        }
					}
				}
				break;
				
			case "S":
				# read program's short description
				$program['info']['short']=mb_substr($line,2,mb_strlen($line)-3);
				# search for matching string in short description
				foreach ($config['shortText'] as $search) {
					# skip empty strings (these are place-holders in config file)
					if (mb_strlen($search)>0) {
						if (!(mb_stripos($program['info']['short'],$search) === false)){
							$hit=true;
							$program['match']['hitS']=$search;
						}
					}
				}
				break;
				
			case "D":
				# read programs's description
				$program['info']['description']=mb_substr($line,2,mb_strlen($line)-3);
				# search for matching string in  description
				foreach ($config['description'] as $search) {
					# skip empty strings (these are place-holders in config file)
					if (mb_strlen($search)>0) {
						if (!(mb_stripos($program['info']['description'],$search) === false)){
							$hit=true;
							$program['match']['hitD']=$search;
						}
					}
				}
				break;
                        case "G":
                                # space separated list of up to four integers according to ETSI EN 300 468
                                $program['info']['genre']=explode(" ",mb_substr($line,2,mb_strlen($line)-3));
                                break; 
                        case "X": 
                                # description of streams, can occur more than ones
				$program['streams'][]=mb_substr($line,2,mb_strlen($line)-3);
				break;
                        case "V": 
                                # VPS time in UTC
                                $program['channel']['VPS']=mb_substr($line,2,mb_strlen($line)-3);
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
	        $mail_text  = "<!DOCTYPE html>\n<html>\n<head>";
		$mail_text .= "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">\n";
	        $mail_text .= "<title>Notification about newly found programs</title>\n";
	        $mail_text .= "<style type=\"text/css\">";
	        $mail_text .= "ul {padding-left:2em;}\n";
		$mail_text .= "table, td, th {border: 1px solid gray;}\n";
		$mail_text .= "table {width: 100%}\n";
		$mail_text .= "td {text-align: center;}\n";
	        $mail_text .= "</style>\n";
	        $mail_text .= "</head>\n<body>\n";
	        $mail_text .= "<table>\n";
	        
	        # table header
	        $mail_text .= "<tr>";
	        $mail_text .= "<th><b>Channel</b></th>";
	        $mail_text .= "<th><b>Program</b></th>";
	        $mail_text .= "<th><b>Description</b></th>";	        
	        $mail_text .= "<th><b>Match</b></th>";
	        $mail_text .= "<th><b>Streams</b></th>";
	        $mail_text .= "</tr>\n";
	        
	        # a row for each found program
	        foreach ($programSave as $program) {
	                $mail_text .= "\n<tr>";	 

	                # add channel information
	                $mail_text .= "<td>".  $program['channel']['id']. "<br>\n<b>". $program['channel']['name']."</b>\n";
	                if (isset($program['channel']['VPS'])) {
	                        $mail_text .= "<br>\nVPS: ".$program['channel']['VPS'];
                        }
	                $mail_text .= "</td>\n";
	                
	                # add program information
	                $mail_text .= "<td>";
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
                                $mail_text .= "<br>\n<a href=\"".$config['global']['vdradmin-am']['connect']."/vdradmin.pl?";
                                $mail_text .= "aktion=timer_new_form";
                                $mail_text .= "&epg_id=".$program['info']['eventID'];
                                $mail_text .= "&channel_id=".$program['channel']['id'];
                                $mail_text .= "&referer=".base64_encode("./vdradmin.pl?aktion=timer_list");
                                $mail_text .= "\">Link to vdradmin-am</a>";
                        }
                        $mail_text .= "</td>\n";
                        
                        # add description
                        $mail_text .= "<td>";
                        if (isset($program['info']['short'])) {
                                $mail_text .= $program['info']['short']."<br>\n";
                        }
                        if (isset($program['info']['description'])) {
                                $mail_text .= $program['info']['description'];
                        }
                        $mail_text .="</td>\n";
                            
	                # add matches
	                $mail_text .= "<td><ul>";
	                foreach (array_keys($program['match']) as $key) {
	                        $mail_text .= "<li>".$key.": ".$program['match'][$key]."</li>";
                        }
                        $mail_text .= "</ul></td>\n";
                        
	                # add streams (optional, may be not set)
	                $mail_text .= "<td>";
                        if (isset($program['streams'])) {
                                $mail_text .= "<ul>";
                                foreach ($program['streams'] as $stream) {
        	                        $mail_text .=  "<li>".$stream ."</li>";
                                }
                                $mail_text .= "</ul>";
	                }
	                $mail_text .= "</td></tr>\n";
	        }
      
	        $mail_text .="\n</table></body></html>\n";

		# Hint: most mailserver have a limit of 990 characters per line.
		$mail_text = wordwrap($mail_text,990);

		# add mail header
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
	# reduce indices (not leave an empty index back)
	$cache=array_merge($cache); 
	# save cache
	file_put_contents(getenv('HOME')."/.epgnotify.cache",serialize($cache));

} else {
  # write a mail if epg.file does not exists
  mail($config['mail_address'], "epgnotify error, no epg data found", "Dear User,\n Unfortunately the epg data file ".$config['global']['epgfile']." as specified in ".getenv('HOME')."/.epgnotify.ini"." could not be opened for reading. Please check if the file exists and is readable.");
}

?>
