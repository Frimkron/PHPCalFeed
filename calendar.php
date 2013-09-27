<?php

// TODO: html format can't be dynamic view if cached to filesystem
//		use javascript?
//		any kind of html 5 component for hidden panels?
//		static implementation can't be limited in timespan
//		can't cache current day marker
//		static file updated once per day
// TODO: recurring events present caching issue - when are more events added? Daily?
// TODO: recurring events
//		date: ( [ ([-]nth|every x[+y]) year ][ ([-]nth|every x[+y]) month ][ ([-]nth|every x[+y]) week ] ([-]nth|every x[+y]) day  )+
//		time: hour minute
//		duration: [ x days ][ x hours ][ x minutes ]
//		week x day y != xth yday of month
//		every 1 months 15th day = 15th of each month
//		every 1 months 2nd week 1st day = monday of the 2nd week of each month (ambiguous - second monday or start of week 2?)
//		[                                ] year
//		[     ][     ][      ] ... [     ] month
//		 ][  ][  ][  ][  ][  ]      ][  ][ week   <-- not aligned
//		|||||||||||||||||||||||||||||||||| day
//		Day part is compulsory - ending with larger denomination doesnt specify single date
//		( ( day 2 ) of week ) of month
//		( day 256 ) of year
//		( ( day 15 ) of month 3 ) of year
//		( ( 2nd day ) of every 2 weeks ) of every 3 years
//		( ( 2nd day ) every week ) every year
//		( 2nd ( 3nd day ) every week ) every month
//		2nd ( ( 3rd day ) of every 2 weeks ) of month
//		S -> ( TY | NY | TM | NM | TW | NW | TD | ND )
//		N -> [0-9]+ 
//		T -> ( N ('th'|'rd'|'nd') ( 'to' 'last' )? | 'last' )
//		TD -> T 'day'
//		ND -> 'every' ( N 'days' | 'day' )
//		TW -> T ( TD | ND ) 'of' 'week'
//		NW -> ( TD | ND ) 'every' ( N 'weeks' | 'week' )
//		TM -> T ( TW | NW | TD | ND  ) 'of' 'month'
//		NM -> ( TW | NW | TD | ND  ) 'every' ( N 'months' | 'month' )
//		TY -> T ( TM | NM | TW | NW | TD | ND ) 'of' 'year' 
//		NY -> ( TM | NM | TW | NW | TD | ND ) 'every' ( N 'years' | 'year' )
//		
//		2nd (every day) of week --> 2nd day in history
//		(2nd day) every week --> every Tuesday
//		(every day) every 2 weeks --> daily on alternate weeks
//		(every 2 days) every week --> every Mon, Wed, Fri, Sun
//		every 2 days --> alternate days
//		2nd (every 2 days) of week --> the first Wednesday in history
//		(every 2 days) every 2 weeks --> Mon, Wed, Fri, Sun on alternate weeks
//		2nd (2nd (2nd day) of week) of month --> Second Tuesday of the second month in history
//		(2nd (2nd day) of week) every month --> second Tuesday every month
//		(2nd to last (2nd day) of week) every month -> second to last Tuesday each month
//      
// TODO: error page should have 500 status
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
// TODO: browser cache headers


function input_json_if_necessary($scriptname,$updated){
	$filename = $scriptname."-master.json";
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

	public abstract function write_file_if_possible($scriptname,$data);

	public abstract function attempt_handle_include($scriptname,$output_formats);

	public abstract function attempt_handle_by_name($name,$scriptname,$output_formats);
	
	public abstract function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats);

	protected abstract function get_filename($scriptname);
	
	protected abstract function output($scriptname);

	protected function handle($scriptname,$output_formats){
		$filename = $this->get_filename($scriptname);
		$error = update_cached_if_necessary($scriptname,$filename,$output_formats);
		if($error) return $error;
		$error = $this->output($scriptname);
		if($error) return $error;
	}

}

abstract class HtmlOutputBase extends OutputFormat {

	private function int_param($name,$min,$max,$default){
		if(!isset($_GET[$name])) return $default;
		if(!is_int($_GET[$name])) return $default;
		if($_GET[$name] < $min) return $default;
		if($_GET[$name] > $max) return $default;
		return $_GET[$name];
	}
	
	private function make_cal_url($year,$month){
		$url = parse_url($_SERVER["REQUEST_URI"]);
		$params = array();
		foreach(explode("&",$url["query"]) as $nameval){
			$bits = explode("=",$nameval);
			$params[$bits[0]] = $bits[1];		
		}
		$params["calyr"] = $year;
		$params["calmn"] = $month;
		$toimplode = array();
		foreach($params as $name=>$val){
			array_push($toimplode,$name."=".$val);
		}
		return $url["path"]."?".implode("&",$toimplode);
	}

	protected function make_html_fragment($doc,$data){
	
		$nowinfo = getdate();
		$year = $this->int_param("calyr",0,9999,$nowinfo["year"]);
		$month = $this->int_param("calmn",1,12,$nowinfo["mon"]);		
		$monthname = getdate(strtotime($year."-".$month."-1"))["month"];
		
		$lastinfo = getdate(strtotime($year."-".$month."-1 - 1 month"));
		$lastyear = $lastinfo["year"];
		$lastmonth = $lastinfo["mon"];
		$lastmonthname = $lastinfo["month"];
		
		$nextinfo = getdate(strtotime($year."-".$month."-1 + 1 month"));
		$nextyear = $nextinfo["year"];
		$nextmonth = $nextinfo["mon"];
		$nextmonthname = $nextinfo["month"];
		
		$todayyear = $nowinfo["year"];
		$todaymonth = $nowinfo["mon"];
		$todaymonthname = $nowinfo["month"];
			
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
	
			$elcalendar = $doc->createElement("table");
			$elcalendar->setAttribute("class","cal-calendar");
			
				$elthead = $doc->createElement("thead");
				
					$elrow = $doc->createElement("tr");
					$elrow->setAttribute("class","cal-header");
					
						$elmonthtitle = $doc->createElement("th",$monthname." ".$year); //TODO
						$elmonthtitle->setAttribute("class","cal-month-title");
						$elmonthtitle->setAttribute("colspan","7");
						$elrow->appendChild($elmonthtitle);
																	
					$elthead->appendChild($elrow);
					
					$elrow = $doc->createElement("tr");
					$elrow->setAttribute("class","cal-header");
					
						foreach(array("Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday") as $dayname){
							$eldaytitle = $doc->createElement("th",$dayname);
							$eldaytitle->setAttribute("class","cal-day-title");
							$elrow->appendChild($eldaytitle);
						}
					
					$elthead->appendChild($elrow);
				
				$elcalendar->appendChild($elthead);
				
				$eltbody = $doc->createElement("tbody");
				$eltbody->setAttribute("class","cal-weeks");
				
					// TODO
					$weeks = array(
						array( array("date"=>30), array("date"=>31), array("date"=>1), array("date"=>2), array("date"=>3), array("date"=>4), array("date"=>5) ),
						array( array("date"=>6), array("date"=>7), array("date"=>8), array("date"=>9), array("date"=>10), array("date"=>11), array("date"=>12) ),
						array( array("date"=>13), array("date"=>14), array("date"=>15), array("date"=>16), array("date"=>17), array("date"=>18), array("date"=>19) ),
						array( array("date"=>20), array("date"=>21), array("date"=>22), array("date"=>23), array("date"=>24), array("date"=>25), array("date"=>26) ),
						array( array("date"=>27), array("date"=>28), array("date"=>29), array("date"=>30), array("date"=>31), array("date"=>1), array("date"=>2) ),
					);
				
					foreach($weeks as $week){
						
						$elweek = $doc->createElement("tr");
						$elweek->setAttribute("class","cal-week");
						
							foreach($week as $day){
							
								$elday = $doc->createElement("td");
								$elday->setAttribute("class","cal-day");
								
									$eldate = $doc->createElement("div",$day["date"]);
									$eldate->setAttribute("class","cal-date");									
									$elday->appendChild($eldate);
								
								$elweek->appendChild($elday);
							}
						
						$eltbody->appendChild($elweek);
						
					}
				
				$elcalendar->appendChild($eltbody);
			
			$elcontainer->appendChild($elcalendar);
			
			$elbacklink = $doc->createElement("a", $lastmonthname." ".$lastyear);
			$elbacklink->setAttribute("class","cal-nav-link cal-back-link");
			$elbacklink->setAttribute("href",$this->make_cal_url($lastyear,$lastmonth));
			$elcontainer->appendChild($elbacklink);
			
			$eltodaylink = $doc->createElement("a", "Today");
			$eltodaylink->setAttribute("class","cal-nav-link cal-today-link");
			$eltodaylink->setAttribute("href",$this->make_cal_url($todayyear,$todaymonth));
			$elcontainer->appendChild($eltodaylink);
			
			$elforwardlink = $doc->createElement("a", $nextmonthname." ".$nextyear);
			$elforwardlink->setAttribute("class","cal-nav-link cal-forward-link");
			$elforwardlink->setAttribute("href",$this->make_cal_url($nextyear,$nextmonth));
			$elcontainer->appendChild($elforwardlink);
	
		return $elcontainer;
	}
}

class HtmlFullOutput extends HtmlOutputBase {

	public function attempt_handle_include($scriptname,$output_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="html") return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		if(!in_array($mimetype,array("text/html"))) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}

	protected function get_filename($name){
		return $name.".html";
	}

	public function write_file_if_possible($scriptname,$data){
	
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
				$elcss->setAttribute("href",$scriptname.".css");
				$elhead->appendChild($elcss);
	
			$elhtml->appendChild($elhead);
	
			$elbody = $doc->createElement("body");
	
				$elfrag = $this->make_html_fragment($doc,$data);
				$elbody->appendChild($elfrag);
	
			$elhtml->appendChild($elbody);
		$doc->appendChild($elhtml);
	
		$filename = $this->get_filename($scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->saveHTMLFile($filename) === FALSE){
			return "Failed to write ".$filename;
		}
	}
		
	public function output($scriptname){
		header("Content-Type: text/html; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}
}

class HtmlFragOutput extends HtmlOutputBase {

	public function attempt_handle_include($scriptname,$output_formats){
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="html-frag") return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		return FALSE;
	}

	protected function get_filename($scriptname){
		return $scriptname."-frag.html";	
	}
	
	public function write_file_if_possible($scriptname,$data){
	
		$doc = new DOMDocument();
		$doc->appendChild( $this->make_html_fragment($doc,$data) );
		$doc->formatOutput = TRUE;
 		$doc->saveHTMLFile($this->get_filename($scriptname));
	}
	
	public function output($scriptname){
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}
}

class JsonOutput extends OutputFormat {

	public function attempt_handle_include($scriptname,$output_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="json") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		if(!in_array($mimetype,array("application/json","text/json"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}

	private function is_available(){
		return extension_loaded("mbstring") && extension_loaded("json");
	}

	protected function get_filename($scriptname){
		return $scriptname.".json";	
	}
	
	public function write_file_if_possible($scriptname,$data){
		if(!$this->is_available()) return;
		
		$data = unserialize(serialize($data)); //deep copy
		foreach($data->events as $item){
			$item->{"start-time"} = date("c",$item->{"start-time"});
			$item->{"end-time"} = date("c",$item->{"end-time"});
		}
		$filename = $this->get_filename($scriptname);
		$handle = @fopen($filename,"w");
		if($handle === FALSE){
			return "Failed to open ".$filename." for writing";
		}
		fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
		fclose($handle);
	}
	
	public function output($scriptname){
		header("Content-Type: application/json; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}	
}

class ICalendarOutput extends OutputFormat {

	public function attempt_handle_include($scriptname,$output_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="icalendar") return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		if(!in_array($mimetype,array("text/calendar"))) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}

	protected function get_filename($name){
		return $name.".ical";
	}
	
	private function wrap($text){
		return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
	}
	
	public function write_file_if_possible($scriptname,$data){
		$filename = $this->get_filename($scriptname);
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
	
	public function output($scriptname){
		header("Content-Type: text/calendar; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE){
			return "Error reading ".$filename;
		}
	}	
}

class RssOutput extends OutputFormat {

	private function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	public function attempt_handle_include($scriptname,$output_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="rss") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		if(!in_array($mimetype,array("application/rss+xml","application/rss"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}

	protected function get_filename($scriptname){
		return $scriptname.".rss";
	}
	
	public function write_file_if_possible($scriptname,$data){
		if(!$this->is_available()) return;
		
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
	
		$filename = $this->get_filename($scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filename) === FALSE ){
			return "Failed to write ".$filename;
		}
	}
	
	public function output($scriptname){
		header("Content-Type: application/rss+xml; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}	
}

class XmlOutput extends OutputFormat {

	private function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	public function attempt_handle_include($scriptname,$output_formats){
		return FALSE;	
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats){
		if($name!="xml") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats){
		if(!in_array($mimetype,array("text/xml","application/xml"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats);
	}

	protected function get_filename($scriptname){
		return $scriptname.".xml";
	}
	
	public function write_file_if_possible($scriptname,$data){
		if(!$this->is_available()) return;

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
		
		$filename = $this->get_filename($scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filename) === FALSE ){
			return "Failed to write ".$filename;
		}
	}	
	
	public function output($scriptname){
		header("Content-Type: application/xml; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
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

function generate_events($data){
	$events = $data->events;
	// sort by date
	usort($events, function($a,$b){ 
		if($a->{"start-time"} > $b->{"start-time"}) return 1;
		elseif($a->{"start-time"} < $b->{"start-time"}) return -1;
		else return 0;
	});
	// filter past and future events
	$events = array_filter($events,function($item){
		return $item->{"start-time"} >= time() - 60*24*60*60 // 60 days ago
			&& $item->{"start-time"} < time() + 2*365*24*60*60; // 2 years from now
	});
	return $events;
}

function update_cached_if_necessary($scriptname,$filename,$output_formats){
	if(file_exists($filename)){
		$updated = filemtime($filename);
		if($updated === FALSE){
			return "Failed to determine last modified time for ".$filename;
		}
	}else{
		$updated = 0;
	}
	// TODO: alternative input format if json not available
	$data = input_json_if_necessary($scriptname,$updated);
	if(is_string($data)) return $data; // error
	if($data===FALSE) return;          // not modified
	
	$data->events = generate_events($data);
	foreach($output_formats as $format){
		$error = $format->write_file_if_possible($scriptname,$data);
		if($error) return $error;
	}
}

function attempt_handle($scriptname,$output_formats){

	// included from another script
	if(basename(__FILE__) != basename($_SERVER["SCRIPT_FILENAME"])){
		foreach($output_formats as $format){
			$result = $format->attempt_handle_include($scriptname,$output_formats);
			if($result===FALSE) continue; // wasn't handled
			if($result) return $result;   // handled, got error
			return;                       // handled, all done
		}
	}
	// format parameter specified
	if(array_key_exists("format",$_GET)){
		$formatname = $_GET["format"];
		foreach($output_formats as $format){
			$result = $format->attempt_handle_by_name($formatname,$scriptname,$output_formats);
			if($result===FALSE) continue; // wasn't handled
			if($result) return $result;   // handled, got error
			return;                       // handled, all done
		}			
	}
	// content negotiation
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
		foreach($output_formats as $format){
			$result = $format->attempt_handle_by_mime_type($accept,$scriptname,$output_formats);
			if($result===FALSE) continue; // wasn't handled
			if($result) return $result;   // handled, got error
			return;                       // handled, all done
		}
	}
	// exhausted ways to handle request
	return FALSE;
}


$output_formats = array(
	new HtmlFullOutput(),
	new HtmlFragOutput(),
	new JsonOutput(),
	new ICalendarOutput(),
	new RssOutput(),
	new XmlOutput()
);

$result = attempt_handle(basename(__FILE__,".php"),$output_formats);
if($result===FALSE){
	header("HTTP/1.0 406 Not Acceptable");	
}elseif($result){
	die($result);
}
