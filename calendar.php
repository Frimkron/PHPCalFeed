<?php

// TODO: handle overwriting of input file
// TODO: yaml input
// TODO: recurring events
// TODO: xml output
// TODO: xml schema
// TODO: proper css
// TODO: more css examples
// TODO: atom format
// TODO: yaml output
// TODO: google calendar input
// TODO: csv input
// TODO: wordpress api
// TODO: other useful input formats


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

	// TODO: week formating
	// TODO: pagination
	// TODO: timezone

	$elcontainer = $doc->createElement("div");
	$elcontainer->setAttribute("class","cal-container");

		if(isset($data->name)){
			$eltitle = $doc->createElement("h2");
			$eltitle->setAttribute("class","cal-title");
				$eltitlelink = $doc->createElement("a",$data->name);
				$eltitlelink->setAttribute("href",$data->url);
				$eltitle->appendChild($eltitlelink);
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

				$endtime = strtotime($item->{"end-time"});
				$eltime = $doc->createElement("p",date("D d M Y, H:i",$item->{"start-time"})." - ".date("D d M Y, H:i",$item->{"end-time"}));
				$eltime->setAttribute("class","cal-event-time");
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
	header("Content-Type: text/html");
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

function filename_json($name){
	return $name.".json";
}

function write_json($name,$data){
	// TODO: date formatting
	// TODO: timezone
	$filename = filename_json($name);
	$handle = @fopen($filename,"w");
	if($handle === FALSE){
		return "Failed to open ".$filename." for writing";
	}
	fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
	fclose($handle);
}

function output_json($name){
	header("Content-Type: application/json");
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

function filename_icalendar($name){
	return $name.".ical";
}

function icalendar_wrap($text){
	return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
}

function write_icalendar($name,$data){
	// TODO: feed name and link
	// TODO: timezone
	$filename = filename_icalendar($name);
	$handle = fopen($filename,"w");
	if($handle === FALSE){
		return "Failed to open ".$filename." for writing";
	}
	fwrite($handle,"BEGIN:VCALENDAR\r\n");
	fwrite($handle,"VERSION:2.0\r\n");
	foreach($data->events as $item){
		fwrite($handle,"BEGIN:VEVENT\r\n");
		fwrite($handle,"DTSTART:".date("Ymd\THi\Z",$item->{"start-time"})."\r\n");
		fwrite($handle,"DTEND:".date("Ymd\YHi\Z",$item->{"end-time"})."\r\n");
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
	header("Content-Type: text/calendar");
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

function filename_rss($name){
	return $name.".rss";
}

function write_rss($name,$data){

	$doc = new DOMDocument();

	$elrss = $doc->createElement("rss");
	$elrss->setAttribute("version","2.0");

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
					}

					$description =
							"<p>From ".date("D d M Y \a\\t H:i",$item->{"start-time"})."</p>"
							."<p>Until ".date("D d M Y \a\\t H:i",$item->{"end-time"})."</p>";
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
	header("Content-Type: application/rss+xml");
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

function update_cached_if_necessary($name,$filename){
	if(file_exists($filename)){
		$updated = filemtime($filename);
		if($updated === FALSE){
			return "Failed to determine last modified time for ".$filename;
		}
	}else{
		$updated = 0;
	}
	$data = input_json_if_necessary($name,$updated);
	if(is_string($data)) return $data;
	if(is_object($data)) {
		$error = write_html_frag($name,$data);
		if($error) return $error;
		$error = write_json($name,$data);
		if($error) return $error;
		$error = write_html_full($name,$data);
		if($error) return $error;
		$error = write_icalendar($name,$data);
		if($error) return $error;
		$error = write_rss($name,$data);
		if($error) return $error;
	}
}


$name = basename(__FILE__,".php");

if(basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])){
	$format = array_key_exists("format",$_GET) ? $_GET["format"] : "";
}else{
	$format = "html-fragment";
}

switch($format){
	case "json":
		$error = handle_json($name);
		break;
	case "html-fragment":
		$error = handle_html_frag($name);
		break;
	case "icalendar":
		$error = handle_icalendar($name);
		break;
	case "rss":
		$error = handle_rss($name);
		break;
	default:
		$error = handle_html_full($name);
		break;
}

if($error) die($error);
