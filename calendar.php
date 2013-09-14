<?php

// TODO: check for json and mbstring modules for json input/output
// TODO: proper character encoding
//		if mbstring enabled:
//			detect encoding of input file
//			convert data to utf8
//			output utf8 data
//		else:
//			assume windows-1252 encoding
//			output windows-1252 data
// TODO: apply polymorphism
// TODO: output events in time order
// TODO: don't output events in past
// TODO: recurring events
// TODO: proper html output with navigation
// TODO: proper css
// TODO: input discovery
// TODO: input preference storage in config php
// TODO: yaml input
// TODO: more css examples
// TODO: jsonp output (application/javascript)
// TODO: atom format
// TODO: yaml output
// TODO: google calendar input
// TODO: csv input
// TODO: wordpress api
// TODO: textpattern api
// TODO: sql database input
// TODO: web-based UI for config
// TODO: web-based UI input
// TODO: facebook input
// TODO: eventbrite input
// TODO: other useful input formats
// TODO: icalendar feed name and link?
// TODO: icalendar prodid standard?
// TODO: icalendar disallows zero events

function input_json_if_necessary($name,$updated){
	$filename = $name."-master.json";
	if(filemtime($filename) <= $updated){
		return FALSE;
	}
	$handle = @fopen($filename,"r");
	if($handle===FALSE){
		return "JSON: File ".$filename." not found";
	}
	$json = fread($handle,filesize($filename));
	fclose($handle);
	
	$json = do_character_encoding($json);
	$data = json_decode($json);
	if($data===NULL){
		return "JSON: Error in syntax";
	}
	if(!is_object($data)){
		return "JSON: Expected root object";
	}
	if(!isset($data->events)){
		return "JSON: Missing calendar events array";
	}
	if(!is_array($data->events)){
		return "JSON: Expected events array";
	}
	foreach($data->events as $item){
		if(!isset($item->name)){
			return "JSON: Missing event name";
		}
		$date_pattern = "/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\+\d{2}$/";
		if(!isset($item->{"start-time"})){
			return "JSON: Missing event start time";
		}
		if(!preg_match($date_pattern,$item->{"start-time"})){
			return "JSON: Invalid start date format - expected \"yyyy-mm-ddThh:mm+zz\"";
		}
		$item->{"start-time"} = strtotime($item->{"start-time"});
		if(!isset($item->{"end-time"})){
			return "JSON: Missing event end time";
		}
		if(!preg_match($date_pattern,$item->{"end-time"})){
			return "JSON: Invalid end date format - expected \"yyyy-mm-ddThh:mm+zz\"";
		}
		$item->{"end-time"} = strtotime($item->{"end-time"});
	}
	return $data;
}

function make_html_fragment($doc,$data){

	$elcontainer = $doc->createElement("div");
	$elcontainer->setAttribute("class","cal-container");

		if(isset($data->name)){
			$eltitle = $doc->createElement("h2");
			$eltitle->setAttribute("class","cal-title");
				if(isset($data->url)){
					$eltitlelink = $doc->createElement("a",$data->name);
					$eltitlelink->setAttribute("href",$data->url);
					$eltitle->appendChild($eltitlelink);
				}else{
					$txtitle = $doc->createTextNode($data->name);
					$eltitle->appendChild($txtitle);
				}
			$elcontainer->appendChild($eltitle);
		}

		if(isset($data->description)){
			$eldescription = $doc->createElement("p",$data->description);
			$eldescription->setAttribute("class","cal-description");
			$elcontainer->appendChild($eldescription);
		}

		$elevents = $doc->createElement("div");
		$elevents->setAttribute("class","cal-event-list");
		foreach($data->events as $item){
			$elevent = $doc->createElement("div");
			$elevent->setAttribute("class","cal-event");

				$eltitle = $doc->createElement("h3");
				$eltitle->setAttribute("class","cal-event-title");
				if(isset($item->url)){
					$eltitlelink = $doc->createElement("a",$item->name);
					$eltitlelink->setAttribute("href",$item->url);
					$eltitle->appendChild($eltitlelink);
				}else{
					$titletext = $doc->createTextNode($item->name);
					$eltitle->appendChild($titletext);
				}
				$elevent->appendChild($eltitle);

				$eltime = $doc->createElement("p");
				$eltime->setAttribute("class","cal-event-time");

					$elstartdatetime = $doc->createElement("div");
					$elstartdatetime->setAttribute("class","cal-start-time");
						
						$txfrom = $doc->createTextNode("From ");
						$elstartdatetime->appendChild($txfrom);
						
						$elstarttime = $doc->createElement("span",date("H:i T",$item->{"start-time"}));
						$elstarttime->setAttribute("class","cal-time");
						$elstartdatetime->appendChild($elstarttime);
						
						$txon = $doc->createTextNode(" on ");
						$elstartdatetime->appendChild($txon);
						
						$elstartdate = $doc->createElement("span",date("D d M Y",$item->{"start-time"}));
						$elstartdate->setAttribute("class","cal-date");
						$elstartdatetime->appendChild($elstartdate);
					
					$eltime->appendChild($elstartdatetime);
						
					$elenddatetime = $doc->createElement("div");
					$elenddatetime->setAttribute("class","cal-end-time");
						
						$txuntil = $doc->createTextNode(" Until ");
						$elenddatetime->appendChild($txuntil);
						
						$elendtime = $doc->createElement("span",date("H:i T",$item->{"end-time"}));
						$elendtime->setAttribute("class","cal-time");
						$elenddatetime->appendChild($elendtime);
						
						$txon = $doc->createTextNode(" on ");
						$elenddatetime->appendChild($txon);
						
						$elenddate = $doc->createElement("span",date("D d M Y",$item->{"end-time"}));
						$elenddate->setAttribute("class","cal-date");
						$elenddatetime->appendChild($elenddate);
												
					$eltime->appendChild($elenddatetime);
										
				$elevent->appendChild($eltime);

				if(isset($item->description)){
					$eldescription = $doc->createElement("p",$item->description);
					$eldescription->setAttribute("class","cal-event-description");
					$elevent->appendChild($eldescription);
				}

			$elevents->appendChild($elevent);
		}
		$elcontainer->appendChild($elevents);

	return $elcontainer;
}

function available_html(){
	return TRUE;
}

function mimetypes_html(){
	return array("text/html");
}

function filename_html_frag($name){
	return $name."-frag.html";
}

function write_html_frag($name,$data){
	$doc = new DOMDocument();
	$doc->appendChild( make_html_fragment($doc,$data) );
	$doc->formatOutput = TRUE;
 	$doc->saveHTMLFile(filename_html_frag($name));
}

function output_html_frag($name){
	$filename = filename_html_frag($name);
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function handle_html_frag($name){
	$filename = filename_html_frag($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_html_frag($name);
	if($error) return $error;
}

function filename_html_full($name){
	return $name.".html";
}

function write_html_full($name,$data){

	$dom = new DOMImplementation();

	$doctype = $dom->createDocumentType("html","","");

	$doc = $dom->createDocument(NULL,NULL,$doctype);

	$elhtml = $doc->createElement("html");
		$elhead = $doc->createElement("head");

			$eltitle = $doc->createElement("title",
				isset($data->name) ? $data->name : "Calendar");
			$elhead->appendChild($eltitle);

			$elcss = $doc->createElement("link");
			$elcss->setAttribute("rel","stylesheet");
			$elcss->setAttribute("type","text/css");
			$elcss->setAttribute("href",$name.".css");
			$elhead->appendChild($elcss);

		$elhtml->appendChild($elhead);

		$elbody = $doc->createElement("body");

			$elfrag = make_html_fragment($doc,$data);
			$elbody->appendChild($elfrag);

		$elhtml->appendChild($elbody);
	$doc->appendChild($elhtml);

	$filename = filename_html_full($name);
	$doc->formatOutput = TRUE;
	if( @$doc->saveHTMLFile($filename) === FALSE){
		return "Failed to write ".$filename;
	}
}

function output_html_full($name){
	$ctypes = mimetypes_html();
	header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
	$filename = filename_html_full($name);
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function handle_html_full($name){
	$filename = filename_html_full($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_html_full($name);
	if($error) return $error;
}

function available_json(){
	return extension_loaded("mbstring") && extension_loaded("json");
}

function mimetypes_json(){
	return array("application/json","text/json");
}

function filename_json($name){
	return $name.".json";
}

function write_json($name,$data){
	$data = unserialize(serialize($data)); //deep copy
	foreach($data->events as $item){
		$item->{"start-time"} = date("c",$item->{"start-time"});
		$item->{"end-time"} = date("c",$item->{"end-time"});
	}
	$filename = filename_json($name);
	$handle = @fopen($filename,"w");
	if($handle === FALSE){
		return "Failed to open ".$filename." for writing";
	}
	fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
	fclose($handle);
}

function output_json($name){
	$ctypes = mimetypes_json();
	header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
	$filename = filename_json($name);
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function handle_json($name){
	$filename = filename_json($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_json($name);
	if($error) return $error;
}

function available_icalendar(){
	return TRUE;
}

function mimetypes_icalendar(){
	return array("text/calendar");
}

function filename_icalendar($name){
	return $name.".ical";
}

function icalendar_wrap($text){
	return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
}

function write_icalendar($name,$data){
	$filename = filename_icalendar($name);
	$handle = fopen($filename,"w");
	if($handle === FALSE){
		return "Failed to open ".$filename." for writing";
	}
	fwrite($handle,"BEGIN:VCALENDAR\r\n");
	fwrite($handle,"VERSION:2.0\r\n");
	fwrite($handle,"PRODID:Calendar Script\r\n");
	foreach($data->events as $item){
		fwrite($handle,"BEGIN:VEVENT\r\n");
		fwrite($handle,"DTSTART:".gmdate("Ymd\THis\Z",$item->{"start-time"})."\r\n");
		fwrite($handle,"DTEND:".gmdate("Ymd\THis\Z",$item->{"end-time"})."\r\n");
		fwrite($handle,icalendar_wrap("SUMMARY:".$item->name)."\r\n");
		if(isset($item->description)){
			fwrite($handle,icalendar_wrap("DESCRIPTION:".$item->description)."\r\n");
		}
		if(isset($item->url)){
			fwrite($handle,icalendar_wrap("URL:".$item->url)."\r\n");
		}
		fwrite($handle,"END:VEVENT\r\n");
	}
	fwrite($handle,"END:VCALENDAR\r\n");
	fclose($handle);
}

function output_icalendar($name){
	$ctypes = mimetypes_icalendar();
	header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
	$filename = filename_icalendar($name);
	if( @readfile($filename) === FALSE){
		return "Error reading ".$filename;
	}
}

function handle_icalendar($name){
	$filename = filename_icalendar($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_icalendar($name);
	if($error) return $error;
}

function available_rss(){
	return TRUE;
}

function mimetypes_rss(){
	return array("application/rss+xml","application/rss");
}

function filename_rss($name){
	return $name.".rss";
}

function write_rss($name,$data){

	$doc = new DOMDocument();

	$elrss = $doc->createElement("rss");
	$elrss->setAttribute("version","2.0");
	$doc->encoding = character_encoding_of_output();

		$elchannel = $doc->createElement("channel");

			if(isset($data->name)){
				$eltitle = $doc->createElement("title",$data->name);
				$elchannel->appendChild($eltitle);
			}
			if(isset($data->description)){
				$eldescription = $doc->createElement("description",$data->description);
				$elchannel->appendChild($eldescription);
			}
			if(isset($data->url)){
				$ellink = $doc->createElement("link",$data->url);
				$elchannel->appendChild($ellink);
			}

			foreach($data->events as $item){
				$elitem = $doc->createElement("item");

					$eltitle = $doc->createElement("title",$item->name);
					$elitem->appendChild($eltitle);

					if(isset($item->url)){
						$ellink = $doc->createElement("link",$item->url);
						$elitem->appendChild($ellink);
					}

					$description =
							"<p>From ".date("H:i T \o\\n D d M Y",$item->{"start-time"})."</p>"
							."<p>Until ".date("H:i T \o\\n D d M Y",$item->{"end-time"})."</p>";
					if(isset($item->description)){
						$description .= "<p>".$item->description."</p>";
					}
					$eldescription = $doc->createElement("description",$description);
					$elitem->appendChild($eldescription);

				$elchannel->appendChild($elitem);
			}

		$elrss->appendChild($elchannel);

	$doc->appendChild($elrss);

	$filename = filename_rss($name);
	$doc->formatOutput = TRUE;
	if( @$doc->save($filename) === FALSE ){
		return "Failed to write ".$filename;
	}
}

function output_rss($name){
	$ctypes = mimetypes_rss();
	header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
	$filename = filename_rss($name);
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function handle_rss($name){
	$filename = filename_rss($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_rss($name);
	if($error) return $error;
}

function available_xml(){
	return TRUE;
}

function mimetypes_xml(){
	return array("application/xml","text/xml");
}

function filename_xml($name){
	return $name.".xml";
}

function write_xml($name,$data){
	
	$namespace = "http://markfrimston.co.uk/calendar_schema";
	
	$dom = new DOMImplementation();
	
	$doc = $dom->createDocument();
	$doc->encoding = character_encoding_of_output();
		
		$elcalendar = $doc->createElement("calendar");
		$elcalendar->setAttributeNS("http://www.w3.org/2001/XMLSchema-instance",
				"xsi:schemaLocation", $namespace." calendar.xsd");
			
			if(isset($data->name)){
				$elname = $doc->createElement("name",$data->name);
				$elcalendar->appendChild($elname);
			}
			if(isset($data->description)){
				$eldescription = $doc->createElement("description",$data->description);
				$elcalendar->appendChild($eldescription);
			}
			if(isset($data->url)){
				$elurl = $doc->createElement("url",$data->url);
				$elcalendar->appendChild($elurl);
			}
			
			foreach($data->events as $item){
			
				$elevent = $doc->createElement("event");
			
					$elname = $doc->createElement("name",$item->name);
					$elevent->appendChild($elname);
					
					$elstarttime = $doc->createElement("start-time",date("c",$item->{"start-time"}));
					$elevent->appendChild($elstarttime);
					
					$elendtime = $doc->createElement("end-time",date("c",$item->{"end-time"}));
					$elevent->appendChild($elendtime);
					
					if(isset($item->description)){
						$eldescription = $doc->createElement("description",$item->description);
						$elevent->appendChild($eldescription);
					}
					if(isset($item->url)){
						$elurl = $doc->createElement("url",$item->url);
						$elevent->appendChild($elurl);
					}
			
				$elcalendar->appendChild($elevent);
			}
		
		$doc->appendChild($elcalendar);

	$doc->createAttributeNS($namespace,"xmlns");
	
	$filename = filename_xml($name);
	$doc->formatOutput = TRUE;
	if( @$doc->save($filename) === FALSE ){
		return "Failed to write ".$filename;
	}
}

function output_xml($name){
	$ctypes = mimetypes_xml();
	header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
	$filename = filename_xml($name);
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;	
	}
}

function handle_xml($name){
	$filename = filename_xml($name);
	$error = update_cached_if_necessary($name,$filename);
	if($error) return $error;
	$error = output_xml($name);
	if($error) return $error;
}

function character_encoding_of_output(){
	return extension_loaded("mbstring") ? "UTF-8" : "Windows-1252";
}

function do_character_encoding($rawtext){
	if(extension_loaded("mbstring")){
		$encoding = mb_detect_encoding($rawtext);
		$newencoding = character_encoding_of_output();
		if($encoding != $newencoding){ 
			$rawtext = mb_convert_encoding($rawtext,
				$encoding===FALSE ? "Windows-1252" : $encoding, $newencoding);
		}
	}
	// otherwise assume win-1252 encoding
	return $rawtext;
}

function update_cached_if_necessary($name,$filename){
	if(file_exists($filename)){
		$updated = filemtime($filename);
		if($updated === FALSE){
			return "Failed to determine last modified time for ".$filename;
		}
	}else{
		$updated = 0;
	}
	// TODO: alternative input format if json not available
	$data = input_json_if_necessary($name,$updated);
	if(is_string($data)) return $data;
	if(is_object($data)) {
		if(available_html()){
			$error = write_html_frag($name,$data);
			if($error) return $error;
		}
		if(available_json()){
			$error = write_json($name,$data);
			if($error) return $error;
		}
		if(available_html()){
			$error = write_html_full($name,$data);
			if($error) return $error;
		}
		if(available_icalendar()){
			$error = write_icalendar($name,$data);
			if($error) return $error;
		}
		if(available_rss()){
			$error = write_rss($name,$data);
			if($error) return $error;
		}
		if(available_xml()){
			$error = write_xml($name,$data);
			if($error) return $error;
		}
	}
}

function establish_output_format(){
	// accessed directly
	if(basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])){
		// format parameter specified
		if(array_key_exists("format",$_GET)){
			return $_GET["format"];
		// content negotiation
		}else{
			$acceptlist = array();
			foreach(explode(",",$_SERVER["HTTP_ACCEPT"]) as $accept){
				$accept = trim(strtolower($accept));
				if(strpos($accept,";q=")){
					$bits = explode(";q=",$accept);
					$accept = $bits[0];
					$quality = floatval($bits[1]);
				}else{
					$quality = 1.0;
				}
				$acceptlist[$accept] = $quality;
			}
			arsort($acceptlist);
			foreach($acceptlist as $accept => $quality){
				if(in_array($accept,mimetypes_json()))		return "json";
				if(in_array($accept,mimetypes_html()))		return "html";
				if(in_array($accept,mimetypes_icalendar()))	return "icalendar";
				if(in_array($accept,mimetypes_rss()))		return "rss";
				if(in_array($accept,mimetypes_xml()))		return "xml";
			}
			return "";
		}
	// included from another script
	}else{
		return "html-fragment";
	}	
}


$name = basename(__FILE__,".php");

$output_format = establish_output_format();

if($output_format=="json" && available_json()) 
	$error = handle_json($name);
	
elseif($output_format=="html-fragment" && available_html()) 	
	$error = handle_html_frag($name);
	
elseif($output_format=="icalendar" && available_icalendar())
	$error = handle_icalendar($name);
	
elseif($output_format=="rss" && available_rss())
	$error = handle_rss($name);
	
elseif($output_format=="xml" && available_xml())
	$error = handle_xml($name);
	
else
	$error = handle_html_full($name);

if($error) die($error);
