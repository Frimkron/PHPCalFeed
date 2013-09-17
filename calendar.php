<?php

// TODO: improve class structure - tda
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

abstract class OutputFormat {

	public function handle($name,$output_formats){
		$filename = $this->get_filename($name);
		$error = update_cached_if_necessary($name,$filename,$output_formats);
		if($error) return $error;
		$error = $this->output($name);
		if($error) return $error;
	}

}

abstract class HtmlOutputBase extends OutputFormat {

	public function is_available(){
		return TRUE;
	}
	
	public function get_mime_types(){
		return array("text/html");
	}

	protected function make_html_fragment($doc,$data){
	
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
}

class HtmlFullOutput extends HtmlOutputBase {

	public function get_filename($name){
		return $name.".html";
	}

	public function write_file($name,$data){
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
	
				$elfrag = $this->make_html_fragment($doc,$data);
				$elbody->appendChild($elfrag);
	
			$elhtml->appendChild($elbody);
		$doc->appendChild($elhtml);
	
		$filename = $this->get_filename($name);
		$doc->formatOutput = TRUE;
		if( @$doc->saveHTMLFile($filename) === FALSE){
			return "Failed to write ".$filename;
		}
	}
	
	public function output($name){
		$ctypes = $this->get_mime_types();
		header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}
}

class HtmlFragOutput extends HtmlOutputBase {

	public function get_filename($name){
		return $name."-frag.html";	
	}
	
	public function write_file($name,$data){
		$doc = new DOMDocument();
		$doc->appendChild( $this->make_html_fragment($doc,$data) );
		$doc->formatOutput = TRUE;
 		$doc->saveHTMLFile($this->get_filename($name));
	}
	
	public function output($name){
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}
}

class JsonOutput extends OutputFormat {

	public function is_available(){
		return extension_loaded("mbstring") && extension_loaded("json");
	}

	public function get_mime_types(){
		return array("application/json","text/json");
	}

	public function get_filename($name){
		return $name.".json";	
	}
	
	public function write_file($name,$data){
		$data = unserialize(serialize($data)); //deep copy
		foreach($data->events as $item){
			$item->{"start-time"} = date("c",$item->{"start-time"});
			$item->{"end-time"} = date("c",$item->{"end-time"});
		}
		$filename = $this->get_filename($name);
		$handle = @fopen($filename,"w");
		if($handle === FALSE){
			return "Failed to open ".$filename." for writing";
		}
		fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
		fclose($handle);
	}
	
	public function output($name){
		$ctypes = $this->get_mime_types();
		header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}	
}

class ICalendarOutput extends OutputFormat {

	public function is_available(){
		return TRUE;
	}

	public function get_mime_types(){
		return array("text/calendar");
	}

	public function get_filename($name){
		return $name.".ical";
	}
	
	private function wrap($text){
		return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
	}
	
	public function write_file($name,$data){
		$filename = $this->get_filename($name);
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
			fwrite($handle,$this->wrap("SUMMARY:".$item->name)."\r\n");
			if(isset($item->description)){
				fwrite($handle,$this->wrap("DESCRIPTION:".$item->description)."\r\n");
			}
			if(isset($item->url)){
				fwrite($handle,$this->wrap("URL:".$item->url)."\r\n");
			}
			fwrite($handle,"END:VEVENT\r\n");
		}
		fwrite($handle,"END:VCALENDAR\r\n");
		fclose($handle);
	}
	
	public function output($name){
		$ctypes = $this->get_mime_types();
		header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE){
			return "Error reading ".$filename;
		}
	}	
}

class RssOutput extends OutputFormat {

	public function is_available(){
		return TRUE;
	}
	
	public function get_mime_types(){
		return array("application/rss+xml","application/rss");
	}

	public function get_filename($name){
		return $name.".rss";
	}
	
	public function write_file($name,$data){
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
	
		$filename = $this->get_filename($name);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filename) === FALSE ){
			return "Failed to write ".$filename;
		}
	}
	
	public function output($name){
		$ctypes = $this->get_mime_types();
		header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}	
}

class XmlOutput extends OutputFormat {

	public function is_available(){
		return TRUE;
	}
	
	public function get_mime_types(){
		return array("application/xml","text/xml");
	}
		
	public function get_filename($name){
		return $name.".xml";
	}
	
	public function write_file($name,$data){

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
		
		$filename = $this->get_filename($name);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filename) === FALSE ){
			return "Failed to write ".$filename;
		}
	}	
	
	public function output($name){
		$ctypes = $this->get_mime_types();
		header("Content-Type: ".$ctypes[0]."; charset=".character_encoding_of_output());
		$filename = $this->get_filename($name);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;	
		}
	}	
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

function update_cached_if_necessary($name,$filename,$output_formats){
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
		foreach($output_formats as $fname=>$format){
			if($format->is_available()){
				$error = $format->write_file($name,$data);
				if($error) return $error;
			}
		}
	}
}

function establish_output_format($output_formats,$default,$on_include){
	// accessed directly
	if(basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])){
		// format parameter specified
		if(array_key_exists("format",$_GET)){
			$param = $_GET["format"];
			if(array_key_exists($param,$output_formats) && $output_formats[$param]->is_available()){
				return $output_formats[$param];
			}else{
				return $default;
			}
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
				foreach($output_formats as $name=>$format){
					if($format->is_available() && in_array($accept,$format->get_mime_types())){
						return $format;
					}
				}
			}
			return $default;
		}
	// included from another script
	}else{
		return $on_include;
	}	
}


$output_formats = array(
	"html"		=> new HtmlFullOutput(),
	"html-frag"	=> new HtmlFragOutput(),
	"json"		=> new JsonOutput(),
	"icalendar"	=> new ICalendarOutput(),
	"rss"		=> new RssOutput(),
	"xml"		=> new XmlOutput()
);

$name = basename(__FILE__,".php");

$outformat = establish_output_format($output_formats,$output_formats["html"],$output_formats["html-frag"]);
if(!$outformat->is_available()) die("Fell back on unavailable format");

$error = $outformat->handle($name,$output_formats);
if($error) die($error);
