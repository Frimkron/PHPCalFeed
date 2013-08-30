<?php

// TODO: cached file update time checking
// TODO: handle overwriting of input file
// TODO: better error handling - i.e. for file writing
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


function input_json($name){
	$filename = $name."-master.json";
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

function make_html_fragment($dom,$data){

	// TODO: week formating
	// TODO: pagination
	// TODO: timezone

	$elcontainer = $dom->createElement("div");
	$elcontainer->setAttribute("class","cal-container");

		if(isset($data->name)){
			$eltitle = $dom->createElement("h2");
			$eltitle->setAttribute("class","cal-title");
				$eltitlelink = $dom->createElement("a",$data->name);
				$eltitlelink->setAttribute("href",$data->url);
				$eltitle->appendChild($eltitlelink);
			$elcontainer->appendChild($eltitle);
		}

		if(isset($data->description)){
			$eldescription = $dom->createElement("p",$data->description);
			$eldescription->setAttribute("class","cal-description");
			$elcontainer->appendChild($eldescription);
		}

		$elevents = $dom->createElement("div");
		$elevents->setAttribute("class","cal-event-list");
		foreach($data->events as $item){
			$elevent = $dom->createElement("div");
			$elevent->setAttribute("class","cal-event");

				$eltitle = $dom->createElement("h3");
				$eltitle->setAttribute("class","cal-event-title");
				if(isset($item->url)){
					$eltitlelink = $dom->createElement("a",$item->name);
					$eltitlelink->setAttribute("href",$item->url);
					$eltitle->appendChild($eltitlelink);
				}else{
					$titletext = $dom->createTextNode($item->name);
					$eltitle->appendChild($titletext);
				}
				$elevent->appendChild($eltitle);

				$endtime = strtotime($item->{"end-time"});
				$eltime = $dom->createElement("p",date("D d M Y, H:i",$item->{"start-time"})." - ".date("D d M Y, H:i",$item->{"end-time"}));
				$eltime->setAttribute("class","cal-event-time");
				$elevent->appendChild($eltime);

				if(isset($item->description)){
					$eldescription = $dom->createElement("p",$item->description);
					$eldescription->setAttribute("class","cal-event-description");
					$elevent->appendChild($eldescription);
				}

			$elevents->appendChild($elevent);
		}
		$elcontainer->appendChild($elevents);

	return $elcontainer;
}

function write_html_frag($name,$data){
	$dom = new DOMDocument();
	$dom->appendChild( make_html_fragment($dom,$data) );
	$dom->formatOutput = TRUE;
	$dom->saveHTMLFile($name."-frag.html");
}

function output_html_frag($name){
	$filename = $name."-frag.html";
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function write_html_full($name,$data){
	// TODO: doctype
	$dom = new DOMDocument();

	$elhtml = $dom->createElement("html");
		$elhead = $dom->createElement("head");

			$eltitle = $dom->createElement("title",
				isset($data->name) ? $data->name : "Calendar");
			$elhead->appendChild($eltitle);

			$elcss = $dom->createElement("link");
			$elcss->setAttribute("rel","stylesheet");
			$elcss->setAttribute("type","text/css");
			$elcss->setAttribute("href",$name.".css");
			$elhead->appendChild($elcss);

		$elhtml->appendChild($elhead);

		$elbody = $dom->createElement("body");

			$elfrag = make_html_fragment($dom,$data);
			$elbody->appendChild($elfrag);

		$elhtml->appendChild($elbody);
	$dom->appendChild($elhtml);

	$dom->formatOutput = TRUE;
	$dom->saveHTMLFile($name.".html");
}

function output_html_full($name){
	header("Content-Type: text/html");
	$filename = $name.".html";
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function write_json($name,$data){
	// TODO: date formatting
	// TODO: timezone
	$handle = fopen($name.".json","w");
	fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
	fclose($handle);
}

function output_json($name){
	header("Content-Type: application/json");
	$filename = $name.".json";
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

function icalendar_wrap($text){
	return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
}

function write_icalendar($name,$data){
	// TODO: feed name and link
	// TODO: timezone
	$handle = fopen($name.".ical","w");
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
}

function output_icalendar($name){
	header("Content-Type: text/calendar");
	$filename = $name.".ical";
	if( @readfile($filename) === FALSE){
		return "Error reading ".$filename;
	}
}

function write_rss($name,$data){
	// TODO: namespace
	$dom = new DOMDocument();
	$elrss = $dom->createElement("rss");
	$elrss->setAttribute("version","2.0");

		$elchannel = $dom->createElement("channel");

			if(isset($data->name)){
				$eltitle = $dom->createElement("title",$data->name);
				$elchannel->appendChild($eltitle);
			}
			if(isset($data->description)){
				$eldescription = $dom->createElement("description",$data->description);
				$elchannel->appendChild($eldescription);
			}
			if(isset($data->url)){
				$ellink = $dom->createElement("link",$data->url);
				$elchannel->appendChild($ellink);
			}

			foreach($data->events as $item){
				$elitem = $dom->createElement("item");

					$eltitle = $dom->createElement("title",$item->name);
					$elitem->appendChild($eltitle);

					if(isset($item->url)){
						$ellink = $dom->createElement("link",$item->url);
					}

					$description =
							"<p>From ".date("D d M Y \a\\t H:i",$item->{"start-time"})."</p>"
							."<p>Until ".date("D d M Y \a\\t H:i",$item->{"end-time"})."</p>";
					if(isset($item->description)){
						$description .= "<p>".$item->description."</p>";
					}
					$eldescription = $dom->createElement("description",$description);
					$elitem->appendChild($eldescription);

				$elchannel->appendChild($elitem);
			}

		$elrss->appendChild($elchannel);

	$dom->appendChild($elrss);
	$dom->formatOutput = TRUE;
	$dom->save($name.".rss");
}

function output_rss($name){
	header("Content-Type: application/rss+xml");
	$filename = $name.".rss";
	if( @readfile($filename) === FALSE ){
		return "Error reading ".$filename;
	}
}

$name = basename(__FILE__,".php");
$data = input_json($name);
if(!is_object($data)){
	die($data);
}
write_json($name,$data);
write_html_frag($name,$data);
write_html_full($name,$data);
write_icalendar($name,$data);
write_rss($name,$data);

if(basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])){
	$format = array_key_exists("format",$_GET) ? $_GET["format"] : "";
}else{
	$format = "html-fragment";
}

switch($format){
	case "json":
		output_json($name);
		break;
	case "html-fragment":
		output_html_frag($name);
		break;
	case "icalendar":
		output_icalendar($name);
		break;
	case "rss":
		output_rss($name);
		break;
	default:
		output_html_full($name);
		break;
}


