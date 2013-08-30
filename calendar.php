<?php

// TODO: caching to files
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
// TODO: other useful input formats
// TODO: better error handling

function make_html_fragment($dom,$data){

	// TODO: week formating
	// TODO: pagination
	// TODO: timezone
	// TODO: missing required attributes
	// TODO: optional attributes

	$elcontainer = $dom->createElement("div");
	$elcontainer->setAttribute("class","cal-container");

		$eltitle = $dom->createElement("h2");
		$eltitle->setAttribute("class","cal-title");
			$eltitlelink = $dom->createElement("a",$data->name);
			$eltitlelink->setAttribute("href",$data->url);
			$eltitle->appendChild($eltitlelink);
		$elcontainer->appendChild($eltitle);

		$eldescription = $dom->createElement("p",$data->description);
		$eldescription->setAttribute("class","cal-description");
		$elcontainer->appendChild($eldescription);

		$elevents = $dom->createElement("div");
		$elevents->setAttribute("class","cal-event-list");
		foreach($data->events as $item){
			$elevent = $dom->createElement("div");
			$elevent->setAttribute("class","cal-event");

				$eltitle = $dom->createElement("h3");
				$eltitle->setAttribute("class","cal-event-title");
					$eltitlelink = $dom->createElement("a",$item->name);
					$eltitlelink->setAttribute("href",$item->url);
					$eltitle->appendChild($eltitlelink);
				$elevent->appendChild($eltitle);

				$starttime = strtotime($item->{"start-time"});
				$endtime = strtotime($item->{"end-time"});
				$eltime = $dom->createElement("p",date("D d M Y, H:i",$starttime)." - ".date("D d M Y, H:i",$endtime));
				$eltime->setAttribute("class","cal-event-time");
				$elevent->appendChild($eltime);

				$eldescription = $dom->createElement("p",$item->description);
				$eldescription->setAttribute("class","cal-event-description");
				$elevent->appendChild($eldescription);

			$elevents->appendChild($elevent);
		}
		$elcontainer->appendChild($elevents);

	return $elcontainer;
}

function html_frag_output($data){
	$dom = new DOMDocument();
	$dom->appendChild( make_html_fragment($dom,$data) );
	echo( $dom->saveHTML() );
}

function html_full_output($data){
	header("Content-Type: text/html");
	// TODO: doctype
	$dom = new DOMDocument();

	$elhtml = $dom->createElement("html");
		$elhead = $dom->createElement("head");

			$eltitle = $dom->createElement("title",$data->name);
			$elhead->appendChild($eltitle);

			$elcss = $dom->createElement("link");
			$elcss->setAttribute("rel","stylesheet");
			$elcss->setAttribute("type","text/css");
			$elcss->setAttribute("href","calendar.css");
			$elhead->appendChild($elcss);

		$elhtml->appendChild($elhead);

		$elbody = $dom->createElement("body");

			$elfrag = make_html_fragment($dom,$data);
			$elbody->appendChild($elfrag);

		$elhtml->appendChild($elbody);
	$dom->appendChild($elhtml);

	echo( $dom->saveHTML() );
}

function json_output($data){
	header("Content-Type: application/json");
	echo( json_encode($data) );
}

function icalendar_output($data){
	header("Content-Type: text/calendar");
	// TODO: timezone
	// TODO: proper spec for escaping
	// TODO: spec for line wrapping
	// TODO: missing required attributes
	// TODO: optional attributes
	echo "BEGIN:VCALENDAR\n";
	echo "VERSION:2.0\n";
	foreach($data->events as $item){
		echo "BEGIN:VEVENT\n";
		$starttime = strtotime($item->{"start-time"});
		echo "DTSTART:".date("Ymd\THi\Z",$starttime)."\n";
		$endtime = strtotime($item->{"end-time"});
		echo "DTEND:".date("Ymd\YHi\Z",$endtime)."\n";
		echo "SUMMARY:".preg_replace("(:|;)","\\\\$0",$item->name)."\n";
		echo "DESCRIPTION:".preg_replace("(:|;)","\\\\$0",$item->description)."\n";
		echo "URL:".preg_replace("(:|;)","\\\\$0",$item->url)."\n";
		echo "END:VEVENT\n";
	}
	echo "END:VCALENDAR\n";
}

function rss_output($data){
	header("Content-Type: application/xml");
	// TODO: namespace
	// TODO: missing required attributes
	// TODO: optional attributes
	$dom = new DOMDocument();
	$elrss = $dom->createElement("rss");
	$elrss->setAttribute("version","2.0");

		$elchannel = $dom->createElement("channel");

			$eltitle = $dom->createElement("title",$data->name);
			$elchannel->appendChild($eltitle);

			$eldescription = $dom->createElement("description",$data->description);
			$elchannel->appendChild($eldescription);

			$ellink = $dom->createElement("link",$data->url);
			$elchannel->appendChild($ellink);

			foreach($data->events as $item){
				$elitem = $dom->createElement("item");

					$eltitle = $dom->createElement("title",$item->name);
					$elitem->appendChild($eltitle);

					$ellink = $dom->createElement("link",$item->url);

					$starttime = strtotime($item->{"start-time"});
					$endtime = strtotime($item->{"end-time"});
					$description =
							"<p>From ".date("D d M Y \a\\t H:i",$starttime)."</p>"
							."<p>Until ".date("D d M Y \a\\t H:i",$endtime)."</p>"
							."<p>".$item->description."</p>";
					$eldescription = $dom->createElement("description",$description);
					$elitem->appendChild($eldescription);

				$elchannel->appendChild($elitem);
			}

		$elrss->appendChild($elchannel);

	$dom->appendChild($elrss);
	echo( $dom->saveXML() );
}

$filename = "test.json";
$handle = @fopen($filename,"r");
if($handle===FALSE){
	die("Failed to open file");
}
$json = fread($handle,filesize($filename));
fclose($handle);

$data = json_decode($json);
if($data===NULL){
	die("Failed to parse json");
}

if(basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])){
	$format = array_key_exists("format",$_GET) ? $_GET["format"] : "";
}else{
	$format = "html-fragment";
}
switch($format){
	case "json":
		json_output($data);
		break;
	case "html-fragment":
		html_frag_output($data);
		break;
	case "icalendar":
		icalendar_output($data);
		break;
	case "rss":
		rss_output($data);
		break;
	default:
		html_full_output($data);
		break;
}

