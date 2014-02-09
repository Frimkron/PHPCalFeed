<?php

// TODO: html links back to script are broken for include mode

// TODO: move heavy lifting to included file?
// TODO: events which started in the past but are still ongoing are excluded from feeds
// TODO: Filename in config for local files
// TODO: sql database input using pdo
// TODO: icalendar proper timezone construction
// TODO: CalDAV
// TODO: facebook input
// TODO: wordpress api
// TODO: eventbrite input
// TODO: yaml input
// TODO: yaml output
// TODO: atom output
// TODO: yaml output
// TODO: s-expression input
// TODO: textpattern api
// TODO: web-based UI for config
// TODO: web-based UI input
// TODO: microformat shouldn't have multiple events for day-spanning event
// TODO: browser cache headers
// TODO: need better way to handle incrementing from start time
// TODO: internationalisation


abstract class InputFormat {

	/*	name
		description
		url
		events (required)
			name (required)
			date (required) (year,month,day)
			time (hour, minute, second)
			duration (days,hours,minutes,seconds)
			description
			url			
		recurring-events
			name (required)
			recurrence (required,list) (start,[end],[count],week-start,rules)
			excluding (list) (start,[end],[count],week-start,rules)
			duration (days,hours,minutes,seconds)
			description
			url				*/

	public abstract function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config);
	
	public abstract function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config);
	
	protected function file_read_due($filepath,$cachedtime,$oldestinputok){
		return $cachedtime <= @filemtime($filepath) || $cachedtime <= $oldestinputok;
	}
	
	protected function url_read_due($cachedtime,$oldestinputok){
		return $cachedtime <= $oldestinputok;
	}
	
	protected function get_error_filename($scriptdir,$scriptname){
		return "$scriptname.error";
	}
	
	protected function check_cached_error($scriptdir,$scriptname){
		$errorfile = $this->get_error_filename($scriptdir,$scriptname);
		$errortime = @filemtime($errorfile);
		if($errortime===FALSE) return;
		if(time() - $errortime > 20*60){ // error file is valid for 20 minutes
			unlink($errorfile);
			return;
		}
		$error = @file_get_contents($errorfile);
		if($error===FALSE) return;
		return $error;		
	}
	
	protected function cache_error($scriptdir,$scriptname,$error){
		$errorfile = $this->get_error_filename($scriptdir,$scriptname);
		$handle = @fopen($errorfile,"w");
		if($handle===FALSE) return;
		fwrite($handle,$error);
		fclose($handle);
	}
}

class NoInput extends InputFormat {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "none") return FALSE;
		return $this->get_empty_data();
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		$result = write_config($scriptdir,$scriptname,array("format"=>"none"));
		if($result) return $result;
		return $this->get_empty_data();
	}
	
	private function get_empty_data(){
		$data = new stdClass();
		$data->events = array();
		$data->{"recurring-events"} = array();
		return $data;
	}

}

abstract class HtmlInputBase extends InputFormat {

	/*
		name xpath
		description xpath
		url xpath
		event xpath
			name xpath
			description xpath
			start datetime xpath
			start time xpath
			duration xpath
			end datetime xpath
			end time xpath
			url xpath
		(no recurring)
		Defaults to hcalendar spec
	*/
	
	protected function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	private function el_with_class_xp($elname,$classname){
		$classname = trim($classname);
		return $elname."[contains(concat(' ',normalize-space(@class),' '),' $classname ')]";
	}
	
	private function el_contents_with_class_xp($prefix,$classname){
		return $prefix.$this->el_with_class_xp("img",$classname)."/@alt"
			." | ".$prefix.$this->el_with_class_xp("area",$classname)."/@alt"
			." | ".$prefix.$this->el_with_class_xp("data",$classname)."/@value"
			." | ".$prefix.$this->el_with_class_xp("abbr",$classname)."/@title"
			." | ".$prefix.$this->el_with_class_xp("*",$classname)."//text()";
	}
	
	private function std_el_contents_with_class_xp($classname){
		return $this->el_contents_with_class_xp(".//".$this->el_with_class_xp("*",$classname)."//","value")
			." | ".$this->el_contents_with_class_xp(".//",$classname);	
	}
	
	private function anchor_contents_with_class_xp($classname){
		return $this->el_contents_with_class_xp(".//".$this->el_with_class_xp("*",$classname)."//","value")
			." | ".".//".$this->el_with_class_xp("a",$classname)."/@href"
			." | ".".//".$this->el_with_class_xp("link",$classname)."/@href"
			." | ".$this->el_contents_with_class_xp(".//",$classname);		
	}
	
	private function date_contents_with_class_xp($classname){
		return $this->el_contents_with_class_xp(".//".$this->el_with_class_xp("*",$classname)."//","value")
			." | ".".//".$this->el_with_class_xp("del",$classname)."/@datetime"
			." | ".".//".$this->el_with_class_xp("ins",$classname)."/@datetime"
			." | ".".//".$this->el_with_class_xp("time",$classname)."/@datetime"
			." | ".$this->el_contents_with_class_xp(".//",$classname);	
	}

	private function get_xpath_result($node,$context,$xpath){
		$results = $context->query($xpath,$node);
		if($results===FALSE) return "HTML: Bad XPath expression '$xpath'";
		return $results;
	}

	private function resolve_single_xpath($node,$context,$xpath){
		$results = $this->get_xpath_result($node,$context,$xpath);
		if(is_string($results)) return $results;
		foreach($results as $result){
			$text = $result->nodeValue;
			if(strlen(trim($text))==0) continue;
			return array( "text"=> $text );
		}
		return FALSE;
	}
	
	private function resolve_concat_xpath($node,$context,$xpath){
		$bits = array();
		$results = $this->get_xpath_result($node,$context,$xpath);
		if(is_string($results)) return $results;
		foreach($results as $result){
			$text = $result->nodeValue;
			if(strlen(trim($text))==0) continue;
			array_push($bits,trim($text));
		}
		if(sizeof($bits)==0) return FALSE;
		return array( "text" => implode(" ",$bits) );
	}
	
	private function resolve_duration_xpath($node,$context,$xpath){
		$results = $this->get_xpath_result($node,$context,$xpath);
		if(is_string($results)) return $results;
		foreach($results as $result){
			$text = $result->nodeValue;
			$duration = parse_duration($text);
			if($duration===FALSE) continue;
			return $duration;
		}
		return FALSE;
	}
	
	private function resolve_date_xpath($node,$context,$xpath){
		$date = NULL;
		$time = NULL;
		$gotspectime = FALSE;
		$results = $this->get_xpath_result($node,$context,$xpath);
		if(is_string($results)) return $results;
		foreach($results as $result){
			$text = $result->nodeValue;
			$parsed = parse_time($text);
			if($parsed!==FALSE){
				// time without date found - override existing time if it was part of 
				// a datetime as it may have been the default time
				if(!$gotspectime){
					$time = array( "hour"=>$parsed["hour"], "minute"=>$parsed["minute"],
						"day"=>$parsed["day"] );
					$gotspectime = TRUE;
					if($date!==NULL && $time!==NULL) break;
				}
			}else{
				$parsed = parse_datetime($text);
				if($parsed===FALSE) continue;
				if($date===NULL){
					$date = array( "year"=>$parsed["year"], "month"=>$parsed["month"], 
						"day"=>$parsed["day"] );
				}
				if($time===NULL){
					$time = array( "hour"=>$parsed["hour"], "minute"=>$parsed["minute"],
						"second"=>$parsed["second"] );
				}
				// don't break, as we might find a more specific time value
			}
		}
		if($date===NULL || $time===NULL) return FALSE;
		return array( "date"=>$date, "time"=>$time );
	}
	
	protected abstract function get_calendar_url($resource_name,$config);

	protected function stream_to_event_data($filehandle,$resource_name,$config){
		$xp_cal_summary = "/html/head/title//text()";
		$xp_cal_description = "/html/head/meta[@name='description']/@content";
		$xp_cal_url = NULL;
		$xp_event = "//".$this->el_with_class_xp("*","vevent"); // defines context element for event properties
		$xp_ev_summary = $this->std_el_contents_with_class_xp("summary");
		$xp_ev_description = $this->std_el_contents_with_class_xp("description");
		$xp_ev_url = $this->anchor_contents_with_class_xp("url");
		$xp_ev_dtstart = $this->date_contents_with_class_xp("dtstart");
		$xp_ev_dtend = $this->date_contents_with_class_xp("dtend");
		$xp_ev_duration = $this->std_el_contents_with_class_xp("duration");
		if(isset($config["html-markers"])){
			$markers = $config["html-markers"];
			if(isset($markers["cal-name-class"])) $xp_cal_summary = $this->std_el_contents_with_class_xp($markers["cal-name-class"]);
			if(isset($markers["cal-name-xpath"])) $xp_cal_summary = $markers["cal-name-xpath"];
			if(isset($markers["cal-description-class"])) $xp_cal_description = $this->std_el_contents_with_class_xp($markers["cal-description-class"]);
			if(isset($markers["cal-description-xpath"])) $xp_cal_description = $markers["cal-description-xpath"];
			if(isset($markers["cal-url-class"])) $xp_cal_url = $this->std_el_contents_with_class_xp($markers["cal-url-class"]);
			if(isset($markers["cal-url-xpath"])) $cap_cal_xpath = $markers["cal-url-xpath"];
			if(isset($markers["cal-event-class"])) $xp_event = "//".$this->el_with_class_xp("*",$markers["cal-event-class"]);
			if(isset($markers["cal-event-xpath"])) $xp_event = $markers["cal-event-xpath"];
			if(isset($markers["ev-name-class"])) $xp_ev_summary = $this->std_el_contents_with_class_xp($markers["ev-name-class"]);
			if(isset($markers["ev-name-xpath"])) $xp_ev_summary = $markers["ev-name-xpath"];
			if(isset($markers["ev-description-class"])) $xp_ev_description = $this->std_el_contents_with_class_xp($markers["ev-description-class"]);
			if(isset($markers["ev-description-xpath"])) $xp_ev_description = $markers["ev-description-xpath"];
			if(isset($markers["ev-url-class"])) $xp_ev_url = $this->anchor_contents_with_class_xp($markers["ev-url-class"]);
			if(isset($markers["ev-url-xpath"])) $xp_ev_url = $markers["ev-url-xpath"];
			if(isset($markers["ev-start-class"])) $xp_ev_dtstart = $this->date_contents_with_class_xp($markers["ev-start-class"]);
			if(isset($markers["ev-start-xpath"])) $xp_ev_dtstart = $markers["ev-start-xpath"];
			if(isset($markers["ev-end-class"])) $xp_ev_dtend = $this->date_contents_with_class_xp($markers["ev-end-class"]);
			if(isset($markers["ev-end-xpath"])) $xp_ev_dtend = $markers["ev-end-xpath"];
			if(isset($markers["ev-duration-class"])) $xp_ev_duration = $this->std_el_contents_with_class_xp($markers["ev-duration-class"]);
			if(isset($markers["ev-duration-xpath"])) $xp_ev_duration = $markers["ev-duration-xpath"];
		}
		$html = stream_get_contents($filehandle);
		if($html===FALSE) return "HTML: Failed to read stream";
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		if($doc===FALSE) return "HTML: Failed to parse page";
		$xpath = new DOMXPath($doc);
		$data = new stdClass();
		$calname = $this->resolve_concat_xpath($doc->documentElement,$xpath,$xp_cal_summary);
		if(is_string($calname)) return $calname;
		if($calname!==FALSE) $data->name = $calname["text"];
		$caldesc = $this->resolve_concat_xpath($doc->documentElement,$xpath,$xp_cal_description);
		if(is_string($caldesc)) return $caldesc;
		if($caldesc!==FALSE) $data->description = $caldesc["text"];
		if($xp_cal_url!==NULL){
			$calurl = $this->resolve_single_xpath($doc->documentElement,$xpath,$xp_cal_url);
			if(is_string($calurl)) return $calurl;
			if($calurl!==FALSE) $data->url = $calurl["text"];
		}else{
			$data->url = $this->get_calendar_url($resource_name,$config);
		}
		$event_els = $this->get_xpath_result($doc->documentElement,$xpath,$xp_event);
		if(is_string($event_els)) return $event_els;
		$data->events = array();
		foreach($event_els as $event_el){
			$event = new stdClass();
			$name = $this->resolve_concat_xpath($event_el,$xpath,$xp_ev_summary);
			if(is_string($name)) return $name;
			if($name===FALSE) continue;
			$event->name = $name["text"];
			$description = $this->resolve_concat_xpath($event_el,$xpath,$xp_ev_description);
			if(is_string($description)) return $description;
			if($description!==FALSE) $event->description = $description["text"];
			$url = $this->resolve_single_xpath($event_el,$xpath,$xp_ev_url);
			if(is_string($url)) return $url;
			if($url!==FALSE) $event->url = $url["text"];
			$start = $this->resolve_date_xpath($event_el,$xpath,$xp_ev_dtstart);
			if(is_string($start)) return $start;
			if($start===FALSE) continue;
			$event->date = $start["date"];
			$event->time = $start["time"];
			$end = $this->resolve_date_xpath($event_el,$xpath,$xp_ev_dtend);
			if(is_string($end)) return $end;
			if($end!==FALSE){
				$event->duration = calc_approx_diff($event->date,$event->time,$end["date"],$end["time"]);
			}else{
				$duration = $this->resolve_duration_xpath($event_el,$xpath,$xp_ev_duration);
				if(is_string($duration)) return $duration;
				if($duration!==FALSE){
					$event->duration = $duration;
				}else{
					$event->duration = array( "days"=>1, "hours"=>0, "minutes"=>0, "seconds"=>0 );
				}
			}
			array_push($data->events,$event);
		}
		$data->{"recurring-events"} = array();
		return $data;
	}

}

class LocalHtmlInput extends HtmlInputBase {
	
	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "html-local") return FALSE;
		if(!$this->is_available()) return FALSE;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!$this->is_available()) return FALSE;
		if(!file_exists($this->get_filepath($scriptdir,$scriptname))) return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"html-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-master.html";
	}
	
	protected function get_calendar_url($filepath,$config){
		return "http://".$_SERVER["HTTP_HOST"].dirname($_SERVER["REQUEST_URI"])."/".basename($filepath);
	}

	private function do_input($filepath){
		$handle = @fopen($filepath,"r");
		if($handle===FALSE) return "HTML: failed to open '$filepath'";
		$result = $this->stream_to_event_data($handle,$filepath,$config);
		fclose($handle);
		return $result;
	}

	private function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if(!$this->file_read_due($filepath,$cachedtime,$oldestinputok)) return FALSE;		
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($filepath);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);
		return $result;
	}
}

class RemoteHtmlInput extends HtmlInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "html-remote") return FALSE;
		if(!$this->is_available()) return FALSE;
		if(!isset($config["url"])) return "HTML: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"],$config);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".htm" && strtolower(substr($config["url"],-5,5)) != ".html") return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"html-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"],$config);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	protected function get_calendar_url($url,$config){
		return $url;
	}
	
	private function do_input($url){
		$handle = http_request($url);
		if(is_string($handle)) return "HTML: $handle";
		$result = $this->stream_to_event_data($handle,$url,$config);
		fclose($handle);
	}
	
	public function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$url,$config){
		if(!$this->url_read_due($cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($url);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);
		return $result;
	}
}

abstract class ICalendarInputBase extends InputFormat {

	protected function feed_to_event_data($filehandle){
		$parser = new ICalendarParser();
		$cal = $parser->parse($filehandle);
		if(is_string($cal)) return "ICalendar: Invalid ICalendar feed: $cal";
	
		$calobj = new stdClass();
		$calobj->events = array();
		$calobj->{"recurring-events"} = array();
		if($cal["name"]!="VCALENDAR"){
			return "ICalendar: Expected VCALENDAR component but found".$cal["name"];
		}
		if(isset($cal["properties"]["calscale"]) 
				&& strtolower($cal["properties"]["calscale"][0]["value"][0]) != "gregorian"){
			return "ICalendar: Non-gregorian calendar not supported";
		}
		if(isset($cal["properties"]["x-wr-calname"])){
			$calobj->name = $cal["properties"]["x-wr-calname"][0]["value"][0];
		}
		if(isset($cal["properties"]["x-wr-caldesc"])){
			$calobj->description = $this->concat_prop_values($cal["properties"]["x-wr-caldesc"]);
		}
		if(isset($cal["properties"]["x-original-url"])){
			$calobj->url = $cal["properties"]["x-original-url"][0]["value"][0];
		}
		if(isset($cal["components"]["VEVENT"])){
			foreach($cal["components"]["VEVENT"] as $vevent){
				$eventobj = new stdClass();
				
				if(isset($vevent["properties"]["summary"])){
					$eventobj->name = $this->concat_prop_values($vevent["properties"]["summary"]);
				}else{
					$eventobj->name = "Unnamed event";
				}
				
				if(isset($vevent["properties"]["description"]) 
						&& strlen($vevent["properties"]["description"][0]["value"][0])>0){
					$eventobj->description = $this->concat_prop_values($vevent["properties"]["description"]);
				}
				
				if(isset($vevent["properties"]["url"])){
					$eventobj->url = $vevent["properties"]["url"][0]["value"];
				}			
				
				if(!isset($vevent["properties"]["dtstart"])){
					continue; // ignore if no start time
				}
				$starttimes = $this->convert_datetime($vevent["properties"]["dtstart"][0]);
				if(is_string($starttimes)) return $starttimes;
				$starttime = $starttimes[0];
				$eventobj->date = array( "year"=>$starttime["year"], "month"=>$starttime["month"], "day"=>$starttime["day"] );
				$eventobj->time = array( "hour"=>$starttime["hour"], "minute"=>$starttime["minute"], "second"=>$starttime["second"] );
				
				if(isset($vevent["properties"]["duration"])){
					// duration specified
					$duration = $this->convert_duration($vevent["properties"]["duration"][0]);
					if(is_string($duration)) return $duration;
					$eventobj->duration = $duration;
					
				}elseif(isset($vevent["properties"]["dtstart"])){
					$dtstart = $vevent["properties"]["dtstart"][0];
					if(isset($vevent["properties"]["dtend"])){
						// start and end specified
						$starttimes = $this->convert_datetime($dtstart);
						if(is_string($starttimes)) return $starttimes;
						$starttime = $starttimes[0];
						$endtimes = $this->convert_datetime($vevent["properties"]["dtend"][0]);
						if(is_string($endtimes)) return $endtimes;
						$endtime = $endtimes[0];
						$eventobj->duration = calc_approx_diff(
							array( "year"=>$starttime["year"], "month"=>$starttime["month"], "day"=>$starttime["day"] ),
							array( "hour"=>$starttime["hour"], "minute"=>$starttime["minute"], "second"=>$starttime["second"] ),
							array( "year"=>$endtime["year"], "month"=>$endtime["month"], "day"=>$endtime["day"] ),
							array( "hour"=>$endtime["hour"], "minute"=>$endtime["minute"], "second"=>$endtime["second"] )
						);							
					}elseif(isset($dtstart["parameters"]["value"]) && strtolower($dtstart["parameters"]["value"])=="date"){
						// start specified as date
						$eventobj->duration = array("days"=>1,"hours"=>0,"minutes"=>0, "seconds"=>0);
					}else{
						// start specified as datetime
						$eventobj->duration = array("days"=>0,"hours"=>0,"minutes"=>0, "seconds"=>0);
					}
				}else{
					return "ICalendar: Cannot determine duration for '".$eventobj->name."'";
				}
		
				array_push($calobj->events,$eventobj);
		
				$rrules = isset($vevent["properties"]["rrule"]) ? $vevent["properties"]["rrule"] : NULL;
				$exrules = isset($vevent["properties"]["exrule"]) ? $vevent["properties"]["exrule"] : NULL;
				$rdates = isset($vevent["properties"]["rdate"]) ? $vevent["properties"]["rdate"] : NULL;
				$exdates = isset($vevent["properties"]["exdate"]) ? $vevent["properties"]["exdate"] : NULL;
				
				if($rrules!==NULL || $exrules!==NULL || $rdates!==NULL || $exdates!==NULL){				
					$convrecur = $this->convert_recurrence($rrules,$exrules,$rdates,$exdates,$starttime);
					if(is_string($convrecur)) return $convrecur;
					$eventobj->recurrence = $convrecur["include"];
					$eventobj->excluding = $convrecur["exclude"];
					array_push($calobj->{"recurring-events"},$eventobj);
				}
			}
		}
		return $calobj;
	}

	private function concat_prop_values($properties){
		$result = "";
		foreach($properties as $prop){
			foreach($prop["value"] as $value){
				$result .= $value;
			}
		}
		return $result;
	}

	private function convert_datetime($property){
		if(isset($property["parameters"]["value"]) && strtolower($property["parameters"]["value"])=="date"){
			# date only
			$result = array();
			foreach($property["value"] as $date){
				array_push($result, array( "year"=>$date["year"], "month"=>$date["month"], "day"=>$date["day"],
						"hour"=>0, "minute"=>0, "second"=>0));
			}
			return $result;
		}else{
			# date and time
			$result = array();
			foreach($property["value"] as $value){
				$datetime = $this->convert_datetime_value($value,$property["parameters"]);
				if(is_string($datetime)) return $datetime;
				array_push($result,$datetime);
			}
			return $result;
		}
	}
	
	private function convert_datetime_value($value,$parameters){
		if($value["isutc"]){
			$timezone = new DateTimeZone("UTC");		
		}elseif(isset($parameters["tzid"])){
			$tzid = $parameters["tzid"];				
			try {
				$timezone = new DateTimeZone($tzid);
			}catch(Exception $e){
				return "ICalendar: timezone '$tzid' unknown and timezone construction not implemented";
			}
		}else{
			$newdate = new DateTime();
			$timezone = $newdate->getTimezone();
		}	
		$cal = new DateTime($value["year"]."-".$value["month"]."-".$value["day"]
				." ".$value["hour"].":".$value["minute"].":".$value["second"], $timezone);
		$cal = new DateTime("@".$cal->getTimestamp());
		return array( "year"=>(int)$cal->format("Y"), "month"=>(int)$cal->format("n"), "day"=>(int)$cal->format("j"), 
			"hour"=>(int)$cal->format("G"), "minute"=>(int)$cal->format("i"), "second"=>(int)$cal->format("s") );
	}
	
	private function convert_duration($property){
		$dur = $property["value"][0];
		return array( "days"=>$dur["weeks"]*7+$dur["days"], "hours"=>$dur["hours"], 
			"minutes"=>$dur["minutes"], "seconds"=>$dur["seconds"] );
	}

	private function convert_recur_rule($rrule,$starttime){
	
		if(!isset($rrule["freq"])) return "ICalendar: RRULE without FREQ parameter";
			
		$result = array( "start"=>$starttime, "rules"=>array(), "week-start"=>1 );
		
		if(isset($rrule["until"])){		
			$result["end"] = $this->convert_datetime_value($rrule["until"],array());
		}
		if(isset($rrule["count"])){
			$result["count"] = $rrule["count"] - 1; // internal format doesnt include dtstart occurence
		}
		if(isset($rrule["wkst"])){
			switch($rrule["wkst"]){
				case "mo": $daynum = 1; break;
				case "tu": $daynum = 2; break;
				case "we": $daynum = 3; break;
				case "th": $daynum = 4; break;
				case "fr": $daynum = 5; break;
				case "sa": $daynum = 6; break;
				case "su": $daynum = 7; break;
				default: return "ICalendar: invalid WKST value '".$rrule["wkst"]."'";
			}
			$result["week-start"] = $daynum;
		}
		if(isset($rrule["interval"])){
			switch($rrule["freq"]){
				case "yearly":		$rulename="year-ival";		break;
				case "monthly": 	$rulename="month-ival";		break;
				case "weekly": 		$rulename="week-ival";		break;
				case "daily": 		$rulename="day-ival";		break;
				case "hourly": 		$rulename="hour-ival";		break;
				case "minutely": 	$rulename="minute-ival";	break;
				case "secondly": 	$rulename="second-ival";	break;
				default: return "ICalendar: Unknown FREQ value '".$rrule["freq"]."'";
			}
			$result["rules"][$rulename] = $rrule["interval"];
		}
		if(isset($rrule["bysecond"])){
			$result["rules"]["minute-second"] = $rrule["bysecond"];
		}
		if(isset($rrule["byminute"])){
			$result["rules"]["hour-minute"] = $rrule["byminute"];
		}
		if(isset($rrule["byhour"])){
			$result["rules"]["day-hour"] = $rrule["byhour"];
		}
		if(isset($rrule["byday"])){		
			$rulebits = array();
			foreach($rrule["byday"] as $byday){
				switch($byday["day"]){
					case "mo": $daynum = 1; break;
					case "tu": $daynum = 2; break;
					case "we": $daynum = 3; break;
					case "th": $daynum = 4; break;
					case "fr": $daynum = 5; break;
					case "sa": $daynum = 6; break;
					case "su": $daynum = 7; break;
					default: return "ICalendar: invalid day value '".$byday["day"]."'";
				}				
				$rulebit = array( "day"=>$daynum );
				if(isset($byday["number"])){ 
					$rulebit["number"] = $byday["number"];
				}
				array_push($rulebits,$rulebit);
			}
			if($rrule["freq"]=="monthly" || ($rrule["freq"]=="yearly" && isset($rrule["bymonth"]))){
				$result["rules"]["month-week-day"] = $rulebits;
			}else{
				$result["rules"]["year-week-day"] = $rulebits;
			}
		}
		if(isset($rrule["bymonthday"])){
			$result["rules"]["month-day"] = $rrule["bymonthday"];
		}
		if(isset($rrule["byyearday"])){
			$result["rules"]["year-day"] = $rrule["byyearday"];
		}
		if(isset($rrule["byweekno"])){
			$result["rules"]["year-week"] = $rrule["byweekno"];
		}
		if(isset($rrule["bymonth"])){
			$result["rules"]["year-month"] = $rrule["bymonth"];
		}
		if(isset($rrule["bysetpos"])){
			$poses = $rrule["bysetpos"];
			switch($rrule["freq"]){
				case "yearly":   $result["rules"]["year-match"] = $poses;   break;
				case "monthly":  $result["rules"]["month-match"] = $poses;  break;
				case "weekly":   $result["rules"]["week-match"] = $poses;   break;
				case "daily":    $result["rules"]["day-match"] = $poses;    break;
				case "hourly":   $result["rules"]["hour-match"] = $poses;   break;
				case "minutely": $result["rules"]["minute-match"] = $poses; break;
				case "secondly": $result["rules"]["second-match"] = $poses; break;
				default: return "ICalendar: invalid FREQ value '".$rrule["freq"]."'";
			}
		}
		
		// fill in remaining rules from dtstart
		switch($rrule["freq"]){
			case "yearly":
				if(!isset($result["rules"]["year-month"]) && !isset($result["rules"]["year-week"])
						&& !isset($result["rules"]["year-day"]) && !isset($result["rules"]["year-week-day"])
						&& !isset($result["rules"]["month-day"]) && !isset($result["rules"]["month-week-day"])
						&& !isset($result["rules"]["day-hour"]) && !isset($result["rules"]["hour-minute"]) 
						&& !isset($result["rules"]["minute-second"])){			
					$result["rules"]["year-month"] = array( $starttime["month"] );
				}
				// fall through
			case "monthly":
				if(!isset($result["rules"]["year-week"]) && !isset($result["rules"]["year-day"])
						&& !isset($result["rules"]["year-week-day"]) && !isset($result["rules"]["month-day"])
						&& !isset($result["rules"]["month-week-day"]) && !isset($result["rules"]["day-hour"]) 
						&& !isset($result["rules"]["hour-minute"]) && !isset($result["rules"]["minute-second"])){			
					$result["rules"]["month-day"] = array( $starttime["day"] );
				}
				// fall through
			case "weekly":
				if(!isset($result["rules"]["year-day"]) && !isset($result["rules"]["year-week-day"]) 
						&& !isset($result["rules"]["month-day"]) && !isset($result["rules"]["month-week-day"]) 
						&& !isset($result["rules"]["day-hour"]) && !isset($result["rules"]["hour-minute"]) 
						&& !isset($result["rules"]["minute-second"])){
					$cal = new DateTime($starttime["year"]."-".$starttime["month"]."-".$starttime["day"]
						." ".$starttime["hour"].":".$starttime["minute"].":".$starttime["second"]);
					$dow = $cal->format("N");
					$result["rules"]["year-week-day"] = array( array("day"=>$dow) );
				}
				// fall through
			case "daily":    
				if(!isset($result["rules"]["day-hour"]) && !isset($result["rules"]["hour-minute"]) 
						&& !isset($result["rules"]["minute-second"])){
					$result["rules"]["day-hour"] = array( $starttime["hour"] ); 
				}
				// fall through
			case "hourly":   
				if(!isset($result["rules"]["hour-minute"]) && !isset($result["rules"]["minute-second"])){
					$result["rules"]["hour-minute"] = array( $starttime["minute"] ); 
				}
				// fall through
			case "minutely": 
				if(!isset($result["rules"]["minute-second"])){
					$result["rules"]["minute-second"] = array( $starttime["second"] ); 
				}
				// fall through
			case "secondly": 				
				break; // nothing
			default: return "ICalendar: invalid FREQ value '".$rrule["freq"]."'";
		}
		
		return $result;
	}
	
	private function convert_recurrence($rruleprops,$exruleprops,$rdateprops,$exdateprops,$starttime){
		if($rruleprops===NULL && $rdateprops===NULL) return "ICalendar: Must specify RRULE or RDATE";
		
		$results = array( "include"=>array(), "exclude"=>array() );
		if($rruleprops!==NULL){
			foreach($rruleprops as $rruleprop){
				foreach($rruleprop["value"] as $rrule){
					$result = $this->convert_recur_rule($rrule,$starttime);
					if(is_string($result)) return $result;
					array_push($results["include"],$result);
				}
			}
		}
		if($exruleprops!==NULL){
			foreach($exruleprops as $exruleprop){
				foreach($exruleprop["value"] as $exrule){
					$result = $this->convert_recur_rule($exrule,$starttime);
					if(is_string($result)) return $result;
					array_push($results["exclude"],$result);
				}
			}
		}
		if($rdateprops!==NULL){
			foreach($rdateprops as $rdateprop){
				$rdtimes = $this->convert_datetime($rdateprop);
				if(is_string($rdtimes)) return $rdtimes;
				foreach($rdtimes as $rdtime){
					$result = array( "start"=>$starttime, "rules"=>array(
							"year"=>array($rdtime["year"]), "year-month"=>array($rdtime["month"]), 
							"month-day"=>array($rdtime["day"]),"day-hour"=>array($rdtime["hour"]), 
							"hour-minute"=>array($rdtime["minute"]), "minute-second"=>array($rdtime["second"])
						), "week-start"=>1 );
					array_push($results["include"],$result);
				}
			}
		}
		if($exdateprops!==NULL){
			foreach($exdateprops as $exdateprop){
				$edtimes = $this->convert_datetime($exdateprop);
				if(is_string($edtimes)) return $edtimes;
				foreach($edtimes as $edtime){
					$result = array( "start"=>$starttime, "rules"=>array(
							"year"=>array($edtime["year"]), "year-month"=>array($edtime["month"]), 
							"month-day"=>array($edtime["day"]),"day-hour"=>array($edtime["hour"]), 
							"hour-minute"=>array($edtime["minute"]), "minute-second"=>array($edtime["second"])
						), "week-start"=>1 );
					array_push($results["exclude"],$result);
				}
			}
		}
		return $results;		
	}
} 

class LocalICalendarInput extends ICalendarInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "icalendar-local") return FALSE;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!file_exists($this->get_filepath($scriptdir,$scriptname))) return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"icalendar-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-master.ics";
	}

	private function do_input($filepath){
		$handle = @fopen($filepath,"r");
		if($handle === FALSE) return "ICalendar: Failed to open '$filepath'";
		$data = $this->feed_to_event_data($handle);
		fclose($handle);
		return $data;
	}

	private function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if(!$this->file_read_due($filepath,$cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$data = $this->do_input($filepath);
		if(is_string($data)) $this->cache_error($scriptdir,$scriptname,$data);		
		return $data;
	}
}

class RemoteICalendarInput extends ICalendarInputBase {
	
	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "icalendar-remote") return FALSE;
		if(!isset($config["url"])) return "ICalendar: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".ics"
				&& strtolower(substr($config["url"],-5,5))  != ".ical"
				&& strtolower(substr($config["url"],-10,10)) != ".icalendar")
			return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"icalendar-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function do_input($url){
		$handle = http_request($url);
		if(is_string($handle)) return "ICalendar: $handle";		
		$result = $this->feed_to_event_data($handle);
		fclose($handle);
		return $result;
	}
	
	private function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$url){
		if(!$this->url_read_due($cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($url);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);		
		return $result;
	}
	
}

abstract class CsvInputBase extends InputFormat {

	protected $COL_PATTERNS = array(
		"name" 			=> "/^\\s*(name|title|summary|subject)\\s*$/i",
		"description"	=> "/^\\s*(desc(ription)?)\\s*$/i",
		"url"			=> "/^\\s*(url|uri|href|link|website)\\s*$/i",
		"start-date" 	=> "/^\\s*(date|start\\W*date|dtstart|start)\\s*$/i",
		"start-time" 	=> "/^\\s*(time|start\\W*time)\\s*$/i",
		"end-date" 		=> "/^\\s*(end\\W*date|dtend|end)\\s*$/i",
		"end-time" 		=> "/^\\s*(end\\W*time)\\s*$/i",
		"duration" 		=> "/^\\s*(dur(ation)?|length)\\s*$/i",
	);

	protected function stream_to_event_data($handle,$delimiter){
	
		$header = @fgetcsv($handle,0,$delimiter);
		if($header === FALSE){
			return "CSV: Failed to read header row";
		}
		if(sizeof($header)==0){
			return "CSV: No columns in header row";
		}
		$colmap = array();
		foreach($header as $index => $label){
			foreach($this->COL_PATTERNS as $col => $pattern){
				if(preg_match($pattern,$label)){
					$colmap[$col] = $index;
					break;
				}
			}
		}
		foreach(array("name","start-date") as $req_key){
			if(!array_key_exists($req_key,$colmap)){				
				return "CSV: Required column '$req_key' not found in header";
			}
		}
		$data = new stdClass();
		$data->events= array();
		$data->{"recurring-events"} = array();
		
		while( ($row = fgetcsv($handle,0,$delimiter)) !== FALSE ){
			if(sizeof($row)==0 || (sizeof($row)==1 && $row[0]==NULL)) continue; # ignore blank lines
			while(sizeof($row) < sizeof($colmap)) array_push($row,"");
		
			$event = new stdClass();
			
			$namestr = trim($row[$colmap["name"]]);
			if(strlen($namestr)==0) return "CSV: Missing 'name' value";
			$event->name = $namestr;

			$starttime = array( "hour"=>0, "minute"=>0, "second"=>0 );
			if(array_key_exists("start-time",$colmap)){					
				$starttstr = trim($row[$colmap["start-time"]]);
				if(strlen($starttstr) > 0){
					$parsed = parse_time($starttstr);
					if($parsed===FALSE) return "CSV: Invalid start time format - expected 'hh:mm' or similar";
					$starttime = $parsed;
				}
			}
			
			$startdate = NULL;
			$startdstr = trim($row[$colmap["start-date"]]);
			if(strlen($startdstr)==0) return "CSV: Missing 'start-date' value";
			$parsed = parse_datetime($startdstr);
			if($parsed!==FALSE){
				$startdate = $parsed;			
				$event->date = array( "year"=>$startdate["year"], "month"=>$startdate["month"], 
						"day"=>$startdate["day"] );
				if($starttime["hour"]!=0 || $starttime["minute"]!=0 || $starttime["second"]!=0){
					$event->time = $starttime;
				}else{
					$event->time = array( "hour"=>$startdate["hour"], "minute"=>$startdate["minute"],
							"second"=>$startdate["second"] );
				}
			}else{
				$parser = new RecurrenceParser();
				$recur = $parser->parse(strtolower($startdstr),$starttime);
				if($recur===FALSE){
					return "CSV: Invalid start date format - expected date ('yyyy-mm-dd' or similar), or recurrence syntax";
				}
				$event->recurrence = array( $recur );
			}
			
			$endtime = NULL;
			if(array_key_exists("end-time",$colmap)){
				$endtstr = trim($row[$colmap["end-time"]]);
				if(strlen($endtstr) > 0){
					$parsed = parse_time($endtstr);
					if($parsed===FALSE) return "CSV: Invalid end time format - expected 'hh:mm' or similar";
					$endtime = $parsed;
				}	
			}
			
			$enddate = NULL;
			if(array_key_exists("end-date",$colmap)){
				$enddstr = trim($row[$colmap["end-date"]]);
				if(strlen($enddstr) > 0){
					$parsed = parse_datetime($enddstr);
					if($parsed===FALSE) return "CSV: Invalid end date format - expected 'yyyy-mm-dd' or similar";
					$enddate = array( "year"=>$parsed["year"], "month"=>$parsed["month"], 
							"day"=>$parsed["day"] );
					if($endtime===NULL){
						$endtime = array( "hour"=>$parsed["hour"], "minute"=>$parsed["minute"], 
								"second"=>$parsed["second"] );
					}
				}
			}
			
			if($startdate !== NULL && ($enddate !== NULL || $endtime !== NULL)){
				
				$diffend_d = $enddate===NULL ? $startdate : $enddate;
				$diffend_t = $endtime; // must be non-null
				$event->duration = calc_approx_diff($startdate,$starttime,$diffend_d,$diffend_t);
			
			}else{
				$eduration = array( "days"=>1, "hours"=>0, "minutes"=>0, "seconds"=>0 );
				if(array_key_exists("duration",$colmap)){
					$durstr = strtolower(trim($row[$colmap["duration"]]));
					if(strlen($durstr) > 0){
						$eduration = parse_duration($durstr);
						if($eduration === FALSE) return "CSV: Invalid duration format - expected '0d 1h 30m' or similar'";
					}
				}
				$event->duration = $eduration;
			}
			
			if(array_key_exists("description",$colmap)){
				$descstr = trim($row[$colmap["description"]]);
				if(strlen($descstr) > 0){
					$event->description = $descstr;
				}
			}
			
			if(array_key_exists("url",$colmap)){
				$urlstr = trim($row[$colmap["url"]]);
				if(strlen($urlstr) > 0){
					$event->url = $urlstr;
				}
			}
			
			if(isset($event->recurrence)){
				array_push($data->{"recurring-events"},$event);
			}else{
				array_push($data->events,$event);
			}				
		}
		
		return $data;
	}

}

class LocalCsvInput extends CsvInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "csv-local") return FALSE;
		$delimiter = isset($config["delimiter"]) ? $config["delimiter"] : ",";
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$delimiter);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!file_exists($this->get_filepath($scriptdir,$scriptname))) return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"csv-local", "delimiter"=>","));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,",");
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-master.csv";
	}
	
	private function do_input($filepath,$delimiter){
		$handle = @fopen($filepath,"r");
		if($handle === FALSE) return "CSV: Failed to open '$filepath'";
		$result = $this->stream_to_event_data($handle,$delimiter);
		fclose($handle);		
		return $result;
	}
	
	private function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$delimiter){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if(!$this->file_read_due($filepath,$cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($filepath,$delimiter);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);
		return $result;
	}

}

class RemoteCsvInput extends CsvInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "csv-remote") return FALSE;
		if(!isset($config["url"])) return "CSV: missing config parameter: 'url'";
		$delimiter = isset($config["delimiter"]) ? $config["delimiter"] : ",";
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"],$delimiter);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".csv") return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"csv-remote","url"=>$config["url"], "delimiter"=>","));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"],",");
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function do_input($url,$delimiter){
		$handle = http_request($url);
		if(is_string($handle)) return "CSV: $handle";
		$result = $this->stream_to_event_data($handle,$delimiter);
		fclose($handle);
		return $result;
	}
	
	public function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$url,$delimiter){
		if(!$this->url_read_due($cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($url,$delimiter);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);		
		return $result;
	}

}

abstract class JsonInputBase extends InputFormat {

	protected function is_available(){
		return extension_loaded("mbstring") && extension_loaded("json");
	}

	protected function convert_event_properties(&$item){
		if(!isset($item->name)){
			return "JSON: Missing event name";
		}
		if(isset($item->time)){
			$parsed = parse_time($item->time);
			if($parsed===FALSE) return "JSON: Invalid time format - expected \"hh:mm\" or similar";
			$item->time = $parsed;								
		}else{
			$item->time = array( "hour"=>0, "minute"=>0, "second"=>0 );
		}
		if(isset($item->duration)){
			$parsed = parse_duration($item->duration);
			if($parsed===FALSE) return "JSON: Invalid duration - expected \"0d 1h 30m\" or similar";
			$item->duration = $parsed;
		}else{
			$item->duration = array( "days"=>1, "hours"=>0, "minutes"=>0, "seconds"=>0 );
		}
	}

	protected function stream_to_event_data($handle){
	
		$json = stream_get_contents($handle);
		
		$json = do_character_encoding($json);
		$data = json_decode($json);
		if($data===NULL){
			return "JSON: Error in syntax";
		}
		if(!is_object($data)){
			return "JSON: Expected root object";
		}
		if(isset($data->events)){
			if(!is_array($data->events)){
				return "JSON: Expected events to be array";
			}
			foreach($data->events as $item){
				$result = $this->convert_event_properties($item);
				if(is_string($result)) return $result;
				
				if(!isset($item->date)) return "JSON: Missing event date";
				$parsed = parse_datetime($item->date);
				if($parsed===FALSE) return "JSON: Invalid date format - expected \"yyyy-mm-dd\" or similar";
				$item->date = array( "year"=>$parsed["year"], "month"=>$parsed["month"], 
						"day"=>$parsed["day"] );
				if($item->time["hour"]==0 && $item->time["minute"]==0 && $item->time["second"]==0){
					$item->time = array( "hour"=>$parsed["hour"], "minute"=>$parsed["minute"],
							"second"=>$parsed["second"] );
				}				
			}
		}
		if(isset($data->{"recurring-events"})){
			if(!is_array($data->{"recurring-events"})){
				return "JSON: Expected recurring-events to be array";
			}
			foreach($data->{"recurring-events"} as $item){
				$result = $this->convert_event_properties($item);
				if(is_string($result)) return $result;
				
				if(!isset($item->recurrence)){
					return "JSON: Missing event recurrence";
				}
				$parser = new RecurrenceParser();
				$result = $parser->parse(strtolower($item->recurrence,$item->time));
				if($result===FALSE){
					return "JSON: Invalid event recurrence syntax";
				}
				$item->recurrence = array( $result );
			}
		}
		return $data;
	}

}

class LocalJsonInput extends JsonInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "json-local") return FALSE;
		if(!$this->is_available()) return FALSE;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!$this->is_available()) return FALSE;
		if(!file_exists($this->get_filepath($scriptdir,$scriptname))) return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"json-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok);
		if($result === FALSE) return TRUE;
		return $result;
	}

	private function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-master.json";
	}
	
	private function do_input($filepath){
		$handle = @fopen($filepath,"r");
		if($handle===FALSE) return "JSON: Failed to open '$filepath'";
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		return $result;
	}
	
	private function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if(!$this->file_read_due($filepath,$cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($filepath);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);		
		return $result;
	}
}

class RemoteJsonInput extends JsonInputBase {

	public function attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config){
		if($formatname != "json-remote") return FALSE;
		if(!isset($config["url"])) return "JSON: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".json") return FALSE;
		$result = write_config($scriptdir,$scriptname,array("format"=>"json-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function do_input($url){
		$handle = http_request($url);
		if(is_string($handle)) return "JSON: $handle";
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		return $result;
	}
	
	public function input_if_necessary($scriptdir,$scriptname,$cachedtime,$oldestinputok,$url){
		if(!$this->url_read_due($cachedtime,$oldestinputok)) return FALSE;
		$error = $this->check_cached_error($scriptdir,$scriptname);
		if($error) return $error;
		$result = $this->do_input($url);
		if(is_string($result)) $this->cache_error($scriptdir,$scriptname,$result);
		return $result;
	}
}

abstract class OutputFormat {

	/*	name
		description
		url
		events	
			name (required)
			start-time (required)
			end-time (required)
			description
			url					*/

	public abstract function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats);

	public abstract function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params);

	public abstract function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params);
	
	public abstract function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params);

	public abstract function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname);

	protected abstract function get_filepath($scriptdir,$scriptname);
	
	protected abstract function output($scriptdir,$scriptname,$params);

	protected function handle($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$error = update_cached_if_necessary($scriptdir,$scriptname,$filepath,$output_formats,$input_formats);
		if($error) return $error;
		$error = $this->output($scriptdir,$scriptname,$params);
		if($error) return $error;
	}

}

abstract class HtmlOutputBase extends OutputFormat {

	const SHOW_MONTHS = 3;

	protected function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	protected function url_for_format($name,$scriptdir,$scriptname,$output_formats){
		foreach($output_formats as $format){
			$url = $format->attempt_protocolless_url_by_name($name,$scriptdir,$scriptname);
			if($url !== FALSE) return $url;
		}
		return FALSE;
	}

	protected function make_button_fragment($doc,$scriptdir,$scriptname,$output_formats){
	
		$urlical = $this->url_for_format("icalendar",$scriptdir,$scriptname,$output_formats);
	
		$fragment = $doc->createDocumentFragment();
	
			$white = $doc->createTextNode("\n");
			$fragment->appendChild($white);
	
			$comstart = $doc->createComment("========= Calendar button start =========");
			$fragment->appendChild($comstart);
	
			$white = $doc->createTextNode("\n");
			$fragment->appendChild($white);
	
			$elbutton = $doc->createElement("div");
			$elbutton->setAttribute("class","calsub-button");
			
				$white = $doc->createTextNode("\n    ");
				$elbutton->appendChild($white);
			
				$ellink = $doc->createElement("a");
				$ellink->setAttribute("class","calsub-link");
				$ellink->setAttribute("title","Subscribe to calendar");
				if($urlical!==FALSE) $ellink->setAttribute("href",$urlical);
				$elbutton->appendChild($ellink);
				
				$white = $doc->createTextNode("\n    ");
				$elbutton->appendChild($white);
				
				$elmenu = $doc->createElement("div");
				$elmenu->setAttribute("class","calsub-menu");
				
					if($urlical!==FALSE){
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
					
						$elitemical = $doc->createElement("a");
						$txitemical = $doc->createTextNode("Subscribe to iCalendar");
						$elitemical->appendChild($txitemical);
						$elitemical->setAttribute("class","calsub-item calsub-icalendar");
						$elitemical->setAttribute("href","webcal:$urlical");
						$elmenu->appendChild($elitemical);
						
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
						
						$elitemgoogle = $doc->createElement("a");
						$txitemgoogle = $doc->createTextNode("Subscribe with Google Calendar");
						$elitemgoogle->appendChild($txitemgoogle);
						$elitemgoogle->setAttribute("class","calsub-item calsub-google");
						$elitemgoogle->setAttribute("href","http://www.google.com/calendar/render?cid=".urlencode("http:$urlical"));
						$elmenu->appendChild($elitemgoogle);
						
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
						
						$elitemlive = $doc->createElement("a");
						$txitemlive = $doc->createTextNode("Subscribe with Microsoft Live");
						$elitemlive->appendChild($txitemlive);
						$elitemlive->setAttribute("class","calsub-item calsub-live");
						$elitemlive->setAttribute("href","http://calendar.live.com/calendar/calendar.aspx"
								."?rru=addsubscription&url=".urlencode("http:$urlical")."&name=".urlencode("Calendar"));
						$elmenu->appendChild($elitemlive);
					}
				
					$urlrss = $this->url_for_format("rss", $scriptdir,$scriptname, $output_formats);
					if($urlrss!==FALSE){			
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
					
						$elitemrss = $doc->createElement("a");
						$txitemrss = $doc->createTextNode("Subscribe to RSS");
						$elitemrss->appendChild($txitemrss);
						$elitemrss->setAttribute("class","calsub-item calsub-rss");
						$elitemrss->setAttribute("href","http:$urlrss");
						$elmenu->appendChild($elitemrss);
					}
					
					$urljson = $this->url_for_format("json", $scriptdir,$scriptname,$output_formats);
					if($urljson!==FALSE){
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
						
						$elitemjson = $doc->createElement("a");
						$txitemjson = $doc->createTextNode("Link to JSON data");
						$elitemjson->appendChild($txitemjson);
						$elitemjson->setAttribute("class","calsub-item calsub-json");
						$elitemjson->setAttribute("href","http:$urljson");
						$elitemjson->setAttribute("onclick","prompt('URL to copy and paste:',this.href); return false;");
						$elmenu->appendChild($elitemjson);
					}
					
					$urlxml = $this->url_for_format("xml", $scriptdir,$scriptname, $output_formats);
					if($urlxml!==FALSE){				
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
						
						$elitemxml = $doc->createElement("a");
						$txitemxml = $doc->createTextNode("Link to XML data");
						$elitemxml->appendChild($txitemxml);
						$elitemxml->setAttribute("class","calsub-item calsub-xml");
						$elitemxml->setAttribute("href","http:$urlxml");
						$elitemxml->setAttribute("onclick","prompt('URL to copy and paste:',this.href); return false;");
						$elmenu->appendChild($elitemxml);
					}
					
					$urlsexp = $this->url_for_format("s-exp", $scriptdir,$scriptname, $output_formats);
					if($urlsexp!==FALSE){
						$white = $doc->createTextNode("\n        ");
						$elmenu->appendChild($white);
					
						$elitemsexp = $doc->createElement("a");
						$txitemsexp = $doc->createTextNode("Link to S-Exp data");
						$elitemsexp->appendChild($txitemsexp);
						$elitemsexp->setAttribute("class","calsub-item calsub-sexp");
						$elitemsexp->setAttribute("href","http:$urlsexp");
						$elitemsexp->setAttribute("onclick","prompt('URL to copy and paste:',this.href); return false;");
						$elmenu->appendChild($elitemsexp);
					}
					
					$white = $doc->createTextNode("\n    ");
					$elmenu->appendChild($white);
					
				$elbutton->appendChild($elmenu);
				
				$white = $doc->createTextNode("\n");
				$elbutton->appendChild($white);
								
			$fragment->appendChild($elbutton);
			
			$white = $doc->createTextNode("\n");
			$fragment->appendChild($white);
			
			$comend = $doc->createComment("======== Calendar button end ========");
			$fragment->appendChild($comend);
			
			$white = $doc->createTextNode("\n");
			$fragment->appendChild($white);
		
		return $fragment;	
	}

	protected function make_calendar_fragment($doc,$scriptdir,$scriptname,$data,$output_formats){
	
		$time = time();
		$cal = new Calendar($time);
		$todayday = $cal->get_day();
		$todaymonth = $cal->get_month();
		$todayyear = $cal->get_year();
		
		$elcontainer = $doc->createElement("div");
		$elcontainer->setAttribute("class","cal-container vcalendar");
	
			if(isset($data->name)){
				$eltitle = $doc->createElement("h2");
				$eltitle->setAttribute("class","cal-title");
					if(isset($data->url)){
						$eltitlelink = $doc->createElement("a");
						$txtitle = $doc->createTextNode($data->name);
						$eltitlelink->appendChild($txtitle);
						$eltitlelink->setAttribute("href",$data->url);
						$eltitle->appendChild($eltitlelink);
					}else{
						$txtitle = $doc->createTextNode($data->name);
						$eltitle->appendChild($txtitle);
					}
				$elcontainer->appendChild($eltitle);
			}
	
			$elsubbutton = $this->make_button_fragment($doc,$scriptdir,$scriptname,$output_formats);
			$elcontainer->appendChild($elsubbutton);
	
			if(isset($data->description)){
				$eldescription = $doc->createElement("p");
				$txdescription = $doc->createTextNode($data->description);
				$eldescription->appendChild($txdescription);
				$eldescription->setAttribute("class","cal-description");
				$elcontainer->appendChild($eldescription);
			}
	
			for($plusmonths=0; $plusmonths<self::SHOW_MONTHS; $plusmonths++){
			
				$cal->time = $time;
				$cal->set_day(1);
				$cal->inc_months($plusmonths);
				$monthname = $cal->get_month_name();
				$yearname = $cal->get_year();
			
				$elcallink = $doc->createElement("a");
				$txcallink = $doc->createTextNode("$monthname $yearname");
				$elcallink->appendChild($txcallink);
				$elcallink->setAttribute("class","cal-nav-link");
				$elcallink->setAttribute("id","cal-$plusmonths-link");
				$elcallink->setAttribute("href","#cal-calendar-$plusmonths");
				$elcontainer->appendChild($elcallink);
				
				$cal->inc_months(1);
			}

			$currentevents = array();
	
			for($plusmonths=0; $plusmonths<self::SHOW_MONTHS; $plusmonths++){
	
				$cal->time = $time;
				$cal->set_day(1);
				$cal->inc_months($plusmonths);
				$monthname = $cal->get_month_name();
				$yearname = $cal->get_year();
				$currmonth = $cal->get_month();
				$curryear = $cal->get_year();
	
				while($cal->get_day_of_week() != 1){
					$cal->inc_days(-1);
				}
				
				$nextevent = 0;
				$weeks = array();
				while($cal->get_year() < $curryear 
						|| ($cal->get_year() == $curryear && $cal->get_month() <= $currmonth)){
					$week = array();
					for($i=0; $i<7; $i++){
						$cal->set_time(0,0,0);
						$starttime = $cal->time;
						$cal->inc_days(1);
						$endtime = $cal->time;
						$cal->inc_days(-1);	
					
						$daydata = array();
						$daydata["date"] = $cal->get_day();
						$daydata["outside"] = $cal->get_month() != $currmonth;
						$daydata["today"] = $cal->get_year()==$todayyear 
									&& $cal->get_month()==$todaymonth && $cal->get_day()==$todayday;
						$daydata["events"] = array();
						
						while($nextevent < sizeof($data->events)
								&& $data->events[$nextevent]->{"start-time"} < $starttime){
							$nextevent++;		
						}			
						while( $nextevent < sizeof($data->events)
								&& $data->events[$nextevent]->{"start-time"} >= $starttime
								&& $data->events[$nextevent]->{"start-time"} < $endtime ){
							array_push($currentevents,$data->events[$nextevent]);
							$nextevent++;
						}
						foreach($currentevents as $key=>$event){
							if($event->{"start-time"} > $endtime || $event->{"end-time"} <= $starttime) {
								unset($currentevents[$key]);
								continue;
							}
							$eventdata = array();
							$estart = max($starttime,$event->{"start-time"});
							$eend = min($endtime,$event->{"end-time"});
							$eventdata["start"] = date("H:i",$estart);
							$eventdata["startiso"] = date("Y-n-j\TH:i",$estart);
							$eventdata["end"] = date("H:i",$eend);
							$eventdata["endiso"] = date("Y-n-j\TH:i",$eend);
							$eventdata["name"] = $event->name;
							if(isset($event->url)) $eventdata["url"] = $event->url;
							array_push($daydata["events"],$eventdata);
						}
						array_push($week,$daydata);
						$cal->inc_days(1);
					}
					array_push($weeks,$week);
				}
		
				$elcalendar = $doc->createElement("table");
				$elcalendar->setAttribute("class","cal-calendar");
				$elcalendar->setAttribute("id","cal-calendar-$plusmonths");
				
					$elcaption = $doc->createElement("caption");
					$txcaption = $doc->createTextNode("$monthname $yearname");
					$elcaption->appendChild($txcaption);
					$elcalendar->appendChild($elcaption);
				
					$elthead = $doc->createElement("thead");
					
						$elrow = $doc->createElement("tr");
						
							foreach(array("Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday") as $dayname){
								$eldaytitle = $doc->createElement("th");
								$txdaytitle = $doc->createTextNode($dayname);
								$eldaytitle->appendChild($txdaytitle);
								$eldaytitle->setAttribute("class","cal-day-title");
								$elrow->appendChild($eldaytitle);
							}
						
						$elthead->appendChild($elrow);
					
					$elcalendar->appendChild($elthead);
					
					$eltbody = $doc->createElement("tbody");
					
						foreach($weeks as $week){
							
							$elweek = $doc->createElement("tr");
							$elweek->setAttribute("class","cal-week");
							
								foreach($week as $day){
								
									$elday = $doc->createElement("td");
									$cellclass = "cal-day";
									if($day["outside"]) $cellclass .= " cal-outside-day";
									if($day["today"]) $cellclass .= " cal-today";
									$elday->setAttribute("class",$cellclass);
									
										$eldate = $doc->createElement("div");
										$txdate = $doc->createTextNode($day["date"]);
										$eldate->appendChild($txdate);
										$eldate->setAttribute("class","cal-date");
										$elday->appendChild($eldate);
										
										if(sizeof($day["events"]) > 0){
											$elevents = $doc->createElement("div");
											$elevents->setAttribute("class","cal-events");
										
											foreach($day["events"] as $event){
											
												$elevent = $doc->createElement("div");
												$elevent->setAttribute("class","cal-event vevent");
												
													$ellabelwrapper = NULL;
													if(isset($event["url"])){
														$eleventurl = $doc->createElement("a");
														$eleventurl->setAttribute("href",$event["url"]);
														$eleventurl->setAttribute("class","url");
														$elevent->appendChild($eleventurl);
														$ellabelwrapper = $eleventurl;
													}else{
														$ellabelwrapper = $elevent;
													}
													
													$elevstart = $doc->createElement("abbr");
													$txevstart = $doc->createTextNode($event["start"]);
													$elevstart->appendChild($txevstart);
													$elevstart->setAttribute("class","dtstart");
													$elevstart->setAttribute("title",$event["startiso"]);
													$ellabelwrapper->appendChild($elevstart);
													
													$textevhyphen = $doc->createTextNode("-");
													$ellabelwrapper->appendChild($textevhyphen);
													
													$elevend = $doc->createElement("abbr");
													$txevend = $doc->createTextNode($event["end"]);
													$elevend->appendChild($txevend);
													$elevend->setAttribute("class","dtend");
													$elevend->setAttribute("title",$event["endiso"]);
													$ellabelwrapper->appendChild($elevend);
													
													$textevspace = $doc->createTextNode(" ");
													$ellabelwrapper->appendChild($textevspace);
													
													$elevname = $doc->createElement("span");
													$txevname = $doc->createTextNode($event["name"]);
													$elevname->appendChild($txevname);
													$elevname->setAttribute("class","summary");
													$ellabelwrapper->appendChild($elevname);
													
												$elevents->appendChild($elevent);
											}
											
											$elday->appendChild($elevents);
										}
									
									$elweek->appendChild($elday);
								}
							
							$eltbody->appendChild($elweek);						
						}
					
					$elcalendar->appendChild($eltbody);
					

				$elcontainer->appendChild($elcalendar);
				
			}
			
			$elprofilelink = $doc->createElement("a");
			$txprofilelink = $doc->createTextNode("hCalendar compatible");
			$elprofilelink->appendChild($txprofilelink);
			$elprofilelink->setAttribute("rel","profile");
			$elprofilelink->setAttribute("href","http://microformats.org/profile/hcalendar");
			$elprofilelink->setAttribute("class","cal-hcal-link");
			$elcontainer->appendChild($elprofilelink);
			
		return $elcontainer;
	}
}

class HtmlFullOutput extends HtmlOutputBase {

	const FORMAT_NAME = "html";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("text/html"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$name){
		return $scriptdir."/".$name.".html";
	}

	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
	
		$dom = new DOMImplementation();
	
		$doctype = $dom->createDocumentType("html","","");
	
		$doc = $dom->createDocument(NULL,NULL,$doctype);
	
		$elhtml = $doc->createElement("html");
			$elhead = $doc->createElement("head");
	
				$eltitle = $doc->createElement("title");
				$txtitle = $doc->createTextNode(isset($data->name) ? $data->name : "Calendar");
				$eltitle->appendChild($txtitle);
				$elhead->appendChild($eltitle);
	
				if(isset($data->description)){
					$eldesc = $doc->createElement("meta");
					$eldesc->setAttribute("name","description");
					$eldesc->setAttribute("content",$data->description);
					$elhead->appendChild($eldesc);
				}
				
				$elcssbutt = $doc->createElement("link");
				$elcssbutt->setAttribute("rel","stylesheet");
				$elcssbutt->setAttribute("type","text/css");
				$elcssbutt->setAttribute("href","$scriptname-sub.css");
				$elhead->appendChild($elcssbutt);
				
				$elcss = $doc->createElement("link");
				$elcss->setAttribute("rel","stylesheet");
				$elcss->setAttribute("type","text/css");
				$elcss->setAttribute("href","$scriptname-cal.css");
				$elhead->appendChild($elcss);
				
				$elhcal = $doc->createElement("link");
				$elhcal->setAttribute("rel","profile");
				$elhcal->setAttribute("href","http://microformats.org/profile/hcalendar");
				$elhead->appendChild($elhcal);
	
				$elvport = $doc->createElement("meta");
				$elvport->setAttribute("name","viewport");
				$elvport->setAttribute("content","width=device-width");
				$elhead->appendChild($elvport);
	
				$urlrss = $this->url_for_format("rss", $scriptdir,$scriptname, $output_formats);
				if($urlrss!==FALSE){
					$elrsslink = $doc->createElement("link");
					$elrsslink->setAttribute("rel","alternate");
					$elrsslink->setAttribute("type","application/rss+xml");
					$elrsslink->setAttribute("href","http:$urlrss");
					$elrsslink->setAttribute("title","Calendar RSS feed");
					$elhead->appendChild($elrsslink);
				}
				
				$urlical = $this->url_for_format("icalendar", $scriptdir,$scriptname, $output_formats);
				if($urlical!==FALSE){
					$elicallink = $doc->createElement("link");
					$elicallink->setAttribute("rel","alternate");
					$elicallink->setAttribute("type","text/calendar");
					$elicallink->setAttribute("href","http:$urlical");
					$elicallink->setAttribute("title", "Calendar iCalendar feed");
					$elhead->appendChild($elicallink);
				}
				
				$urljson = $this->url_for_format("json", $scriptdir,$scriptname, $output_formats);
				if($urljson!==FALSE){
					$eljsonlink = $doc->createElement("link");
					$eljsonlink->setAttribute("rel","alternate");
					$eljsonlink->setAttribute("type","application/json");
					$eljsonlink->setAttribute("href","http:$urljson");
					$eljsonlink->setAttribute("title","Calendar JSON feed");
					$elhead->appendChild($eljsonlink);
				}
				
				$urlxml = $this->url_for_format("xml", $scriptdir,$scriptname, $output_formats);
				if($urlxml!==FALSE){
					$elxmllink = $doc->createElement("link");
					$elxmllink->setAttribute("rel", "alternate");
					$elxmllink->setAttribute("type","application/xml");
					$elxmllink->setAttribute("href","http:$urlxml");
					$elxmllink->setAttribute("title","Calendar XML feed");
					$elhead->appendChild($elxmllink);
				}
				
				$urlsexp = $this->url_for_format("s-exp", $scriptdir,$scriptname, $output_formats);
				if($urlsexp!==FALSE){
					$elsexplink = $doc->createElement("link");
					$elsexplink->setAttribute("rel","alternate");
					$elsexplink->setAttribute("type","application/x-lisp");
					$elsexplink->setAttribute("href","http:$urlsexp");
					$elsexplink->setAttribute("title","Calendar S-Expression feed");
					$elhead->appendChild($elsexplink);
				}
	
			$elhtml->appendChild($elhead);
	
			$elbody = $doc->createElement("body");
			$elbody->setAttribute("style","background-color: rgb(100,200,200);");
	
				$elfrag = $this->make_calendar_fragment($doc,$scriptdir,$scriptname,$data,$output_formats);
				$elbody->appendChild($elfrag);
	
			$elhtml->appendChild($elbody);
		$doc->appendChild($elhtml);
	
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->saveHTMLFile($filepath) === FALSE){
			return "Failed to write ".$filepath;
		}
	}
		
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: text/html; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class HtmlFragOutput extends HtmlOutputBase {

	const FORMAT_NAME = "html-frag";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!$this->is_available()) return FALSE;
		$result = $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
		// echo rather than return, to avoid 500 response from include
		if($result) echo $result;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-frag.html";	
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
	
		$doc = new DOMDocument();
		$doc->appendChild( $this->make_calendar_fragment($doc,$scriptdir,$scriptname,$data,$output_formats) );
		$doc->formatOutput = TRUE;
 		$doc->saveHTMLFile($this->get_filepath($scriptdir,$scriptname)); 
	}
	
	public function output($scriptdir,$scriptname,$params){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if(!$this->is_available()) return FALSE;
		if($name != self::FORMAT_NAME) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class HtmlButtonOutput extends HtmlOutputBase {

	const FORMAT_NAME = "html-button";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname."-button.html";
	}

	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		// output type is special - don't need to keep it up to date
		if(file_exists($filepath)) return; 
		
		$doc = new DOMDocument();
		$doc->appendChild( $this->make_button_fragment($doc,$scriptdir,$scriptname,$output_formats) );
		$doc->formatOutput = TRUE;
 		$doc->saveHTMLFile($filepath); 
	}
	
	protected function output($scriptdir,$scriptname,$params){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}		
	}

	protected function handle($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		// output type is special - its never out of date and needn't trigger generation of 
		// other outputs, only itself
		if(!file_exists($filepath)){
			$this->write_file_if_possible($scriptdir,$scriptname,NULL,$output_formats);
		}
		$error = $this->output($scriptdir,$scriptname,$params);
		if($error) return $error;
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if(!$this->is_available()) return FALSE;
		if($name != self::FORMAT_NAME) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

abstract class JsonOutputBase extends OutputFormat {

	protected function is_available(){
		return extension_loaded("mbstring") && extension_loaded("json");
	}
	
	protected function write_to_stream($handle,$data){
		$data = unserialize(serialize($data)); //deep copy
		foreach($data->events as $item){
			$item->{"start-time"} = date("c",$item->{"start-time"});
			$item->{"end-time"} = date("c",$item->{"end-time"});
		}
		fwrite($handle,json_encode($data,JSON_PRETTY_PRINT));
	}

}

class JsonOutput extends JsonOutputBase {

	const FORMAT_NAME = "json";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("application/json","text/json"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname.".json";	
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
		
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$handle = @fopen($filepath,"w");
		if($handle === FALSE){
			return "Failed to open ".$filepath." for writing";
		}
		$this->write_to_stream($handle,$data);
		fclose($handle);
	}
	
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: application/json; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}
	}	
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class JsonPOutput extends JsonOutputBase {

	const FORMAT_NAME = "jsonp";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("application/javascript","text/javascript"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname.".json";	
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
		
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$handle = @fopen($filepath,"w");
		if($handle === FALSE){
			return "Failed to open ".$filepath." for writing";
		}
		$this->write_to_stream($handle,$data);
		fclose($handle);
	}
	
	public function output($scriptdir,$scriptname,$params){
		if(!isset($params["callback"]) || strlen(trim($params["callback"]))==0){
			$callback = "receive_calendar_data";
		}else{
			$callback = trim($params["callback"]);
		}
		header("Content-Type: application/javascript; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		echo "$callback(";
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}
		echo ");";
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class SExpressionOutput extends OutputFormat {

	const FORMAT_NAME = "s-exp";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;	
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("application/x-lisp","text/x-script.lisp"))) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	protected function get_filepath($scriptdir,$name){
		return $scriptdir."/".$name.".lsp";
	}

	private function escape($text){
		return str_replace(
			array("\n", "\r", "\""  ),
			array("\\n","\\r","\\\""),
			str_replace("\\","\\\\",$text));
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$handle = @fopen($filepath,"w");
		if($handle === FALSE){
			return "Failed to open file $filepath for writing";
		}
		fwrite($handle,"(calendar");
		if(isset($data->name)){
			fwrite($handle,"\n\t(name \"".$this->escape($data->name)."\")");
		}
		if(isset($data->description)){
			fwrite($handle,"\n\t(description \"".$this->escape($data->description)."\")");
		}
		if(isset($data->url)){
			fwrite($handle,"\n\t(url \"".$this->escape($data->url)."\")");
		}
		fwrite($handle,"\n\t(events (");
		foreach($data->events as $event){
			fwrite($handle,"\n\t\t(event");
			fwrite($handle,"\n\t\t\t(name \"".$this->escape($event->name)."\")");
			if(isset($event->description)){
				fwrite($handle,"\n\t\t\t(description \"".$this->escape($event->description)."\")");
			}
			if(isset($event->url)){
				fwrite($handle,"\n\t\t\t(url \"".$this->escape($event->url)."\")");
			}
			fwrite($handle,"\n\t\t\t(start-time \"".date("c",$event->{"start-time"})."\")");
			fwrite($handle,"\n\t\t\t(end-time \"".date("c",$event->{"end-time"})."\")");
			fwrite($handle,")");
		}
		fwrite($handle, ")))\n");
		fclose($handle);
	}	
	
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: application/x-lisp; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE){
			return "Error reading $filepath";
		}
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class ICalendarOutput extends OutputFormat {

	const FORMAT_NAME = "icalendar";

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("text/calendar"))) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname.".ics";
	}
	
	private function wrap($text){
		return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
	}
	
	private function escape_text($text){
		return str_replace(
				array("\n", "\r", ",",  ";"  ),
				array("\\n","\\r","\\,","\\;"),
				str_replace("\\","\\\\",$text));
	}
	
	private function escape_url($text){
		return str_replace(
			array("\n", "\r"),
			array("","",),
			$text);
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$handle = @fopen($filepath,"w");
		if($handle === FALSE){
			return "Failed to open ".$filepath." for writing";
		}
		fwrite($handle,"BEGIN:VCALENDAR\r\n");
		fwrite($handle,"VERSION:2.0\r\n");
		fwrite($handle,"PRODID:-//Mark Frimston//PHPCalFeed//EN\r\n");
		fwrite($handle,"CALSCALE:GREGORIAN\r\n");
		fwrite($handle,"METHOD:PUBLISH\r\n");
		if(isset($data->name)){
			fwrite($handle,$this->wrap("X-WR-CALNAME:".$this->escape_text($data->name))."\r\n");
		}
		if(isset($data->description)){
			fwrite($handle,$this->wrap("X-WR-CALDESC:".$this->escape_text($data->description))."\r\n");
		}
		if(isset($data->url)){
			fwrite($handle,$this->wrap("X-ORIGINAL-URL:".$this->escape_url($data->url))."\r\n");
		}
		foreach($data->events as $item){
			fwrite($handle,"BEGIN:VEVENT\r\n");
			fwrite($handle,"DTSTART:".gmdate("Ymd\THis\Z",$item->{"start-time"})."\r\n");
			fwrite($handle,"DTEND:".gmdate("Ymd\THis\Z",$item->{"end-time"})."\r\n");
			fwrite($handle,$this->wrap("SUMMARY:".$this->escape_text($item->name))."\r\n");
			if(isset($item->description)){
				fwrite($handle,$this->wrap("DESCRIPTION:".$this->escape_text($item->description))."\r\n");
			}
			if(isset($item->url)){
				fwrite($handle,$this->wrap("URL:".$this->escape_url($item->url))."\r\n");
			}
			fwrite($handle,"END:VEVENT\r\n");
		}
		fwrite($handle,"END:VCALENDAR\r\n");
		fclose($handle);
	}
	
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: text/calendar; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE){
			return "Error reading ".$filepath;
		}
	}	
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class RssOutput extends OutputFormat {

	const FORMAT_NAME = "rss";

	private function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("application/rss+xml","application/rss"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname.".rss";
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;
		
		$doc = new DOMDocument();
	
		$elrss = $doc->createElement("rss");
		$elrss->setAttribute("version","2.0");
		$doc->encoding = character_encoding_of_output();
	
			$elchannel = $doc->createElement("channel");
	
				if(isset($data->name)){
					$eltitle = $doc->createElement("title");
					$txtitle = $doc->createTextNode($data->name);
					$eltitle->appendChild($txtitle);
					$elchannel->appendChild($eltitle);
				}
				if(isset($data->description)){
					$eldescription = $doc->createElement("description");
					$txdescription = $doc->createTextNode($data->description);
					$eldescription->appendChild($txdescription);
					$elchannel->appendChild($eldescription);
				}
				if(isset($data->url)){
					$ellink = $doc->createElement("link");
					$txlink = $doc->createTextNode($data->url);
					$ellink->appendChild($txlink);
					$elchannel->appendChild($ellink);
				}
	
				foreach($data->events as $item){
					$elitem = $doc->createElement("item");
	
						$eltitle = $doc->createElement("title");
						$txtitle = $doc->createTextNode($item->name);
						$eltitle->appendChild($txtitle);
						$elitem->appendChild($eltitle);
	
						if(isset($item->url)){
							$ellink = $doc->createElement("link");
							$txlink = $doc->createTextNode($item->url);
							$ellink->appendChild($txlink);
							$elitem->appendChild($ellink);
						}
	
						$description =
								"<p>From ".date("H:i T \o\\n D d M Y",$item->{"start-time"})."</p>"
								."<p>Until ".date("H:i T \o\\n D d M Y",$item->{"end-time"})."</p>";
						if(isset($item->description)){
							$description .= "<p>".$item->description."</p>";
						}
						$eldescription = $doc->createElement("description");
						$txdescription = $doc->createTextNode($description);
						$eldescription->appendChild($txdescription);
						$elitem->appendChild($eldescription);
	
					$elchannel->appendChild($elitem);
				}
	
			$elrss->appendChild($elchannel);
	
		$doc->appendChild($elrss);
	
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filepath) === FALSE ){
			return "Failed to write ".$filepath;
		}
	}
	
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: application/rss+xml; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;
		}
	}	
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}
}

class XmlOutput extends OutputFormat {

	const FORMAT_NAME = "xml";

	private function is_available(){
		return extension_loaded("libxml") && extension_loaded("dom");
	}

	public function attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$params){
		return FALSE;	
	}
	
	public function attempt_handle_by_name($name,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if($name!=self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptdir,$scriptname,$output_formats,$input_formats,$params){
		if(!in_array($mimetype,array("text/xml","application/xml"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptdir,$scriptname,$output_formats,$input_formats,$params);
	}

	protected function get_filepath($scriptdir,$scriptname){
		return $scriptdir."/".$scriptname.".xml";
	}
	
	public function write_file_if_possible($scriptdir,$scriptname,$data,$output_formats){
		if(!$this->is_available()) return;

		$namespace = "http://markfrimston.co.uk/calendar_schema";
		$schemaurl = "http://".$_SERVER["HTTP_HOST"].dirname($_SERVER["REQUEST_URI"])."/".$scriptname.".xsd";

		$dom = new DOMImplementation();
		
		$doc = $dom->createDocument();
		$doc->encoding = character_encoding_of_output();
			
			$elcalendar = $doc->createElement("calendar");
			$elcalendar->setAttributeNS("http://www.w3.org/2001/XMLSchema-instance",
					"xsi:schemaLocation", "$namespace $schemaurl");
				
				if(isset($data->name)){
					$elname = $doc->createElement("name");
					$txname = $doc->createTextNode($data->name);
					$elname->appendChild($txname);
					$elcalendar->appendChild($elname);
				}
				if(isset($data->description)){
					$eldescription = $doc->createElement("description");
					$txdescription = $doc->createTextNode($data->description);
					$eldescription->appendChild($txdescription);
					$elcalendar->appendChild($eldescription);
				}
				if(isset($data->url)){
					$elurl = $doc->createElement("url");
					$txurl = $doc->createTextNode($data->url);
					$elurl->appendChild($txurl);
					$elcalendar->appendChild($elurl);
				}
				
				foreach($data->events as $item){
				
					$elevent = $doc->createElement("event");
				
						$elname = $doc->createElement("name");
						$txname = $doc->createTextNode($item->name);
						$elname->appendChild($txname);
						$elevent->appendChild($elname);
						
						$elstarttime = $doc->createElement("start-time");
						$txstarttime = $doc->createTextNode(date("c",$item->{"start-time"}));
						$elstarttime->appendChild($txstarttime);
						$elevent->appendChild($elstarttime);
						
						$elendtime = $doc->createElement("end-time");
						$txendtime = $doc->createTextNode(date("c",$item->{"end-time"}));
						$elendtime->appendChild($txendtime);
						$elevent->appendChild($elendtime);
						
						if(isset($item->description)){
							$eldescription = $doc->createElement("description");
							$txdescription = $doc->createTextNode($item->description);
							$eldescription->appendChild($txdescription);
							$elevent->appendChild($eldescription);
						}
						if(isset($item->url)){
							$elurl = $doc->createElement("url");
							$txurl = $doc->createTextNode($item->url);
							$elurl->appendChild($txurl);
							$elevent->appendChild($elurl);
						}
				
					$elcalendar->appendChild($elevent);
				}
			
			$doc->appendChild($elcalendar);
	
		$doc->createAttributeNS($namespace,"xmlns");
		
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		$doc->formatOutput = TRUE;
		if( @$doc->save($filepath) === FALSE ){
			return "Failed to write ".$filepath;
		}
	}	
	
	public function output($scriptdir,$scriptname,$params){
		header("Content-Type: application/xml; charset=".character_encoding_of_output());
		$filepath = $this->get_filepath($scriptdir,$scriptname);
		if( @readfile($filepath) === FALSE ){
			return "Error reading ".$filepath;	
		}
	}
	
	public function attempt_protocolless_url_by_name($name,$scriptdir,$scriptname){
		if($name != self::FORMAT_NAME) return FALSE;
		if(!$this->is_available()) return FALSE;
		return "//".$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"]."?format=".self::FORMAT_NAME;
	}	
}


class RecurrenceParser {

	// S -> Ed | Ew | Em | Ey
	// Ed -> 'every' N 'days' 'starting' D | 'daily'
	// Ew -> 'every' N 'weeks' Ow 'starting' D | 'weekly' Ow
	// Em -> 'every' N 'months' Om 'starting' D | 'monthly' Om 
	// Ey -> 'every' N 'years' Oy 'starting' D | 'yearly' Oy
	// Ow -> 'on' ( Ntd | Wn )
	// Om -> 'on' ( Nt | Ntd | Nw )
	// Oy -> 'on' ( Ntd | Nw | Md | Mw )
	// Md -> ( Nt 'of'? | Ntd 'of' ) Mn
	// Mw -> Nw 'of' Mn
	// Ntd -> Nl 'day'
	// Nw -> Nl Wn
	// Nl -> Nt ('to' 'last')? | 'last'
	// Nt -> N ('th'|'st'|'nd'|'rd')
	// Wn -> 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun'
	// Mn -> 'jan' | 'feb' | 'mar' | 'apr' | 'may' | 'jun' | 'jul' | 'aug' | 'sep' | 'oct' | 'nov' | 'dec'
	// D -> '[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}'
	// N -> '[0-9]+'		
	
	public function parse($input,$time){
		$pos = 0;
		$result = $this->parse_EdOrEwOrEmOrEy($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$type = $result["type"];
		$freq = $result["frequency"];
		$start = $result["start"];
		$day = $result["day"];
		$week = $result["week"];
		$month = $result["month"];
		if($this->expect_end($input,$pos)===FALSE) return FALSE;

		$startdt = new DateTime("@".$start);
		$retval = array( 
			"start" => array(
				"year"=>(int)$startdt->format("Y"),
				"month"=>(int)$startdt->format("n"),
				"day"=>(int)$startdt->format("j"),
				"hour"=>(int)$startdt->format("G"),
				"minute"=>(int)$startdt->format("i"),
				"second"=>(int)$startdt->format("s")
			),
			"week-start" => 1,
			"rules" => array(
				"day-hour" => array( $time["hour"] ),
				"hour-minute" => array( $time["minute"] ),
				"minute-second" => array( $time["second"] )
			)
		);
		switch($type){
			case "daily":	
				$retval["rules"]["day-ival"] = $freq;	
				break;
			case "weekly":	
				$retval["rules"]["week-ival"] = $freq; 	
				$retval["rules"]["year-week-day"] = array( array( "day"=>$day ) );
				break;
			case "monthly":	
				$retval["rules"]["month-ival"] = $freq;	
				if($week != 0){
					$retval["rules"]["month-week-day"] = array( array( "day"=>$day, "number"=>$week ) );
				}else{
					$retval["rules"]["month-day"] = array( $day );
				}
				break;
			case "yearly":	
				$retval["rules"]["year-ival"] = $freq;	
				if($week != 0 && $month!= 0){
					$retval["rules"]["month-week-day"] = array( array( "day"=>$day, "number"=>$week ) );
					$retval["rules"]["year-month"] = array( $month );
				}elseif($month!=0){
					$retval["rules"]["month-day"] = array( $day );
					$retval["rules"]["year-month"] = array( $month );
				}elseif($week!=0){
					$retval["rules"]["year-week-day"] = array( array( "day"=>$day, "number"=>$week ) );
				}else{
					$retval["rules"]["year-day"] = array( $day );
				}
				break;
		}
		return $retval;
	}
	
	private function parse_EdOrEwOrEmOrEy($input,$pos){
		$result = $this->parse_Ed($input,$pos);
		if( $result !== FALSE ) return array( "pos"=>$result["pos"], "type"=>"daily", "frequency"=>$result["n"], 
				"start"=>$result["start"], "day"=>0, "week"=>0, "month"=>0 );
		$result = $this->parse_Ew($input,$pos);
		if( $result !== FALSE ) return array( "pos"=>$result["pos"], "type"=>"weekly", "frequency"=>$result["n"], 
				"start"=>$result["start"], "day"=>$result["day"], "week"=>0, "month"=>0 );
		$result = $this->parse_Em($input,$pos);
		if( $result !== FALSE ) return array( "pos"=>$result["pos"], "type"=>"monthly", "frequency"=>$result["n"], 
				"start"=>$result["start"], "day"=>$result["day"], "week"=>$result["week"], "month"=>0 );
		$result = $this->parse_Ey($input,$pos);
		if( $result !== FALSE ) return array( "pos"=>$result["pos"], "type"=>"yearly", "frequency"=>$result["n"], 
				"start"=>$result["start"], "day"=>$result["day"], "week"=>$result["week"], "month"=>$result["month"] );
		return FALSE;
	}
	
	private function parse_Ed($input,$pos){
		$result = $this->parse_evNDays($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>$result["n"], "start"=>$result["start"] );
		$result = $this->parse_daily($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>1, "start"=>0 );
		return FALSE;
	}
	
	private function parse_evNDays($input,$pos){
		foreach(array("e","v","e","r","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_N($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];		
		$n = $result["value"];
		foreach(array("d","a","y","s",  "s","t","a","r","t","i","n","g") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_D($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$start = $result["date"];
		return array( "pos"=>$pos, "n"=>$n, "start"=>$start );
	}
	
	private function parse_daily($input,$pos){
		foreach(array("d","a","i","l","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_Ew($input,$pos){
		$result = $this->parse_evNWeeks($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>$result["n"], "start"=>$result["start"], "day"=>$result["day"] );
		$result = $this->parse_weekly($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>1, "start"=>0, "day"=>$result["day"] );
		return FALSE;
	}
	
	private function parse_Ow($input,$pos){
		foreach(array("o","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_NtdOrWn($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		return array( "pos"=>$pos, "day"=>$day );
	}
	
	private function parse_evNWeeks($input,$pos){
		foreach(array("e","v","e","r","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_N($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["value"];
		foreach(array("w","e","e","k","s") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_Ow($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		foreach(array("s","t","a","r","t","i","n","g") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}		
		$result = $this->parse_D($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$start = $result["date"];
		return array( "pos"=>$pos, "n"=>$n, "start"=>$start, "day"=>$day );
	}
	
	private function parse_weekly($input,$pos){
		foreach(array("w","e","e","k","l","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_Ow($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		return array( "pos"=>$pos, "day"=>$day );
	}
	
	private function parse_NtdOrWn($input,$pos){
		$result = $this->parse_Ntd($input,$pos);
		if($result!==FALSE) return array("pos"=>$result["pos"], "day"=>$result["day"] );
		$result = $this->parse_Wn($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"] );
		return FALSE;
	}
	
	private function parse_Ntd($input,$pos){
		$result = $this->parse_Nl($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["n"];
		foreach(array("d","a","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos, "day"=>$n );
	}
	
	private function parse_Nl($input,$pos){
		$result = $this->parse_NtToLast($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>$result["n"] );
		$result = $this->parse_last($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>-1 );
		return FALSE;
	}
	
	private function parse_NtToLast($input,$pos){
		$result = $this->parse_Nt($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["n"];
		$result = $this->parse_toLast($input,$pos);
		if($result!==FALSE){
			$pos = $result["pos"];
			$n *= -1;
		}
		return array( "pos"=>$pos, "n"=>$n );
	}
	
	private function parse_Nt($input,$pos){
		$result = $this->parse_N($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["value"];
		$result = $this->parse_thOrStOrNdOrRd($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		return array( "pos"=>$pos, "n"=>$n );
	}
	
	private function parse_toLast($input,$pos){
		foreach(array("t","o","l","a","s","t") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}	
		return array( "pos"=>$pos );
	}
	
	private function parse_last($input,$pos){
		foreach(array("l","a","s","t") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_thOrStOrNdOrRd($input,$pos){
		$result = $this->parse_th($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"] );
		$result = $this->parse_st($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"] );
		$result = $this->parse_nd($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"] );
		$result = $this->parse_rd($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"] );
		return FALSE;
	}
	
	private function parse_th($input,$pos){
		foreach(array("t","h") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_st($input,$pos){
		foreach(array("s","t") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_nd($input,$pos){
		foreach(array("n","d") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_rd($input,$pos){
		foreach(array("r","d") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_Wn($input,$pos){
		$result = $this->parse_mon($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>1 );
		$result = $this->parse_tue($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>2 );
		$result = $this->parse_wed($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>3 );
		$result = $this->parse_thu($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>4 );
		$result = $this->parse_fri($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>5 );
		$result = $this->parse_sat($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>6 );
		$result = $this->parse_sun($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>7 );
		return FALSE;
	}
	
	private function parse_mon($input,$pos){
		foreach(array("m","o","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_tue($input,$pos){
		foreach(array("t","u","e") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_wed($input,$pos){
		foreach(array("w","e","d") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_thu($input,$pos){
		foreach(array("t","h","u") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_fri($input,$pos){
		foreach(array("f","r","i") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_sat($input,$pos){
		foreach(array("s","a","t") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_sun($input,$pos){
		foreach(array("s","u","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_Em($input,$pos){
		$result = $this->parse_evNMonths($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>$result["n"], "start"=>$result["start"], 
				"day"=>$result["day"], "week"=>$result["week"] );
		$result = $this->parse_monthly($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>1, "start"=>0,
				"day"=>$result["day"], "week"=>$result["week"] );
		return FALSE;
	}
	
	private function parse_Om($input,$pos){
		foreach(array("o","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_NtOrNtdOrNw($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week );
	}
	
	private function parse_evNMonths($input,$pos){
		foreach(array("e","v","e","r","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_N($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["value"];
		foreach(array("m","o","n","t","h","s") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_Om($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		foreach(array("s","t","a","r","t","i","n","g") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_D($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$start = $result["date"];
		return array( "pos"=>$pos, "n"=>$n, "start"=>$start, "day"=>$day, "week"=>$week );
	}
	
	private function parse_monthly($input,$pos){
		foreach(array("m","o","n","t","h","l","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_Om($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week );
	}
	
	private function parse_NtOrNtdOrNw($input,$pos){
		$result = $this->parse_Nw($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>$result["week"] );
		$result = $this->parse_Ntd($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>0 );
		$result = $this->parse_Nt($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["n"], "week"=>0 );
		return FALSE;	
	}
	
	private function parse_Nw($input,$pos){
		$result = $this->parse_Nl($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$week = $result["n"];
		$result = $this->parse_Wn($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week );
	}
	
	private function parse_Ey($input,$pos){
		$result = $this->parse_evNYears($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>$result["n"], "start"=>$result["start"],
					"day"=>$result["day"], "week"=>$result["week"], "month"=>$result["month"] );
		$result = $this->parse_yearly($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "n"=>1, "start"=>0,
					"day"=>$result["day"], "week"=>$result["week"], "month"=>$result["month"] );
		return FALSE;
	}
	
	private function parse_Oy($input,$pos){
		foreach(array("o","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_NtdOrNwOrMdOrMw($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		$month = $result["month"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week, "month"=>$month );
	}
	
	private function parse_evNYears($input,$pos){
		foreach(array("e","v","e","r","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		$result = $this->parse_N($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["value"];
		foreach(array("y","e","a","r","s") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;		
		}
		$result = $this->parse_Oy($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		$month = $result["month"];
		foreach(array("s","t","a","r","t","i","n","g") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;		
		}
		$result = $this->parse_D($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$start = $result["date"];
		return array( "pos"=>$pos, "n"=>$n, "start"=>$start, "day"=>$day, "week"=>$week, "month"=>$month );
	}
	
	private function parse_yearly($input,$pos){
		foreach(array("y","e","a","r","l","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;		
		}
		$result = $this->parse_Oy($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$week = $result["week"];
		$month = $result["month"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week, "month"=>$month );
	}
	
	private function parse_NtdOrNwOrMdOrMw($input,$pos){
		$result = $this->parse_Mw($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>$result["week"], "month"=>$result["month"] );
		$result = $this->parse_Md($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>0, "month"=>$result["month"] );
		$result = $this->parse_Nw($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>$result["week"], "month"=>0 );		
		$result = $this->parse_Ntd($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"], "week"=>0, "month"=>0 );
		return FALSE;
	}
	
	private function parse_Md($input,$pos){
		$result = $this->parse_NtOfOrNtdOf($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$result = $this->parse_Mn($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$month = $result["month"];
		return array( "pos"=>$pos, "day"=>$day, "month"=>$month );
	}
	
	private function parse_Mw($input,$pos){
		$result = $this->parse_Nw($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$week = $result["week"];
		$day = $result["day"];
		foreach(array("o","f") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;		
		}
		$result = $this->parse_Mn($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$month = $result["month"];
		return array( "pos"=>$pos, "day"=>$day, "week"=>$week, "month"=>$month );
	}
	
	private function parse_NtOfOrNtdOf($input,$pos){
		$result = $this->parse_NtdOf($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["day"] );
		$result = $this->parse_NtOf($input,$pos);
		if($result!==FALSE) return array( "pos"=>$result["pos"], "day"=>$result["n"] );
		return FALSE;
	}
	
	private function parse_NtOf($input,$pos){
		$result = $this->parse_Nt($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$n = $result["n"];
		$result = $this->parse_of($input,$pos);
		if($result!==FALSE) $pos = $result["pos"];
		return array( "pos"=>$pos, "n"=>$n );
	}
	
	private function parse_NtdOf($input,$pos){
		$result = $this->parse_Ntd($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$day = $result["day"];
		$result = $this->parse_of($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		return array( "pos"=>$pos, "day"=>$day );
	}
	
	private function parse_of($input,$pos){
		foreach(array("o","f") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;			
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_Mn($input,$pos){
		$result = $this->parse_jan($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>1 );
		$result = $this->parse_feb($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>2 );
		$result = $this->parse_mar($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>3 );
		$result = $this->parse_apr($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>4 );
		$result = $this->parse_may($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>5 );
		$result = $this->parse_jun($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>6 );
		$result = $this->parse_jul($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>7 );
		$result = $this->parse_aug($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>8 );
		$result = $this->parse_sep($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>9 );
		$result = $this->parse_oct($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>10 );
		$result = $this->parse_nov($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>11 );
		$result = $this->parse_dec($input,$pos);
		if($result!==FALSE) return array( "pos"=> $result["pos"], "month"=>12 );
		return FALSE;
	}
	
	private function parse_jan($input,$pos){
		foreach(array("j","a","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_feb($input,$pos){
		foreach(array("f","e","b") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_mar($input,$pos){
		foreach(array("m","a","r") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );		
	}
	
	private function parse_apr($input,$pos){
		foreach(array("a","p","r") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}		
		return array( "pos"=>$pos );
	}
	
	private function parse_may($input,$pos){
		foreach(array("m","a","y") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_jun($input,$pos){
		foreach(array("j","u","n") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_jul($input,$pos){
		foreach(array("j","u","l") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_aug($input,$pos){
		foreach(array("a","u","g") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_sep($input,$pos){
		foreach(array("s","e","p") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_oct($input,$pos){
		foreach(array("o","c","t") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_nov($input,$pos){
		foreach(array("n","o","v") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_dec($input,$pos){
		foreach(array("d","e","c") as $c){
			if($this->expect($input,$pos,$c)===FALSE) return FALSE;
		}
		return array( "pos"=>$pos );
	}
	
	private function parse_N($input,$pos){
		$buffer = "";
		$result = $this->parse_digit($input,$pos);
		if($result===FALSE) return FALSE;
		$pos = $result["pos"];
		$buffer .= $result["value"];
		while(TRUE){
			$result = $this->parse_digit($input,$pos);
			if($result===FALSE) return array("pos"=>$pos,"value"=>(int)$buffer);
			$pos = $result["pos"];
			$buffer .= $result["value"];		
		}		
	}
	
	private function parse_D($input,$pos){
		$buffer = "";
		for($i=0; $i<4; $i++){
			$result = $this->parse_digit($input,$pos);
			if($result===FALSE) return FALSE;
			$pos = $result["pos"];
			$buffer .= $result["value"];
		}
		for($j=0; $j<2; $j++){
			$result = $this->expect($input,$pos,"-");
			$buffer .= "-";
			if($result===FALSE) return FALSE;
			$result = $this->parse_digit($input,$pos);
			if($result===FALSE) return FALSE;
			$pos = $result["pos"];
			$buffer .= $result["value"];
			$result = $this->parse_digit($input,$pos);
			if($result===FALSE) continue; // second digit is optional
			$pos = $result["pos"];
			$buffer .= $result["value"];
		}
		$date = strtotime($buffer);
		return array( "pos"=>$pos, "date"=>$date );
	}
	
	private function parse_digit($input,$pos){
		$result = $this->expect($input,$pos,"0123456789");
		if($result===FALSE) return FALSE;
		return array( "pos"=>$pos, "value"=>$result );
	}
	
	private function expect($input,&$pos,$chars){
		$result = $this->next($input,$pos);
		if($result===FALSE || strstr($chars,$result)===FALSE) return FALSE;
		return $result;
	}
	
	private function expect_end($input,&$pos){
		$result = $this->next($input,$pos);
		return $result===FALSE;
	}
	
	private function next($input,&$pos){
		do {
			if($pos >= strlen($input)) return FALSE;
			$retval = substr($input,$pos,1);
			$pos ++;
		} while( strlen(trim($retval))==0 );
		return $retval;
	}		
}

class ICalendarParser {

	private $PROP_TYPES = array(
		"calscale"			=>	"text",
		"method"			=>	"text",
		"prodid"			=>	"text",
		"version"			=>	"text",
		
		"begin"				=>	"raw",
		"end"				=>	"raw",
	
		"attach"			=>	"uri",
		"categories"		=>	"text",
		"class"				=>	"text",
		"comment"			=>	"text",
		"description"		=>	"text",
		"geo"				=>	"raw",
		"location"			=>	"text",
		"percent-complete"	=>	"integer",
		"priority"			=>	"integer",
		"resources"			=>	"text",
		"status"			=>	"text",
		"summary"			=>	"text",
		
		"completed"			=>	"date-time",
		"dtend"				=>	"date-time",
		"due"				=>	"date-time",
		"dtstart"			=>	"date-time",
		"duration"			=>	"duration",
		"freebusy"			=>	"period",
		"transp"			=>	"text",
		
		"tzid"				=>	"text",
		"tzname"			=>	"text",
		"tzoffsetfrom"		=>	"utc-offset",
		"tzoffsetto"		=>	"utc-offset",
		"tzurl"				=>	"uri",
		
		"attendee"			=>	"cal-address",
		"contact"			=>	"text",
		"organizer"			=>	"cal-address",
		"recurrence-id"		=>	"date-time",
		"related-to"		=>	"text",
		"url"				=>	"uri",
		"uid"				=>	"text",
		
		"exdate"			=>	"date-time",
		"exrule"			=>	"recur",
		"rdate"				=>	"date-time",
		"rrule"				=>	"recur",
		
		"action"			=>	"text",
		"repeat"			=>	"integer",
		"trigger"			=>	"duration",
		
		"created"			=>	"date-time",
		"dtstamp"			=>	"date-time",
		"last-modified"		=>	"date-time",
		"sequence"			=>	"integer",
		
		"request-status"	=>	"text"
	);

	private $filehandle;
	private $currentline;
	private $currentlinenum = -1;
	private $linebuffer = "";

	public function parse($filehandle){
		$this->filehandle = $filehandle;
		for($i=0; $i<2; $i++){
			$result = $this->next_content_line();
			if(is_string($result)) return $result;
		}
		$result = $this->parse_component();
		if(is_string($result)) return $result;
		if($this->currentline !== FALSE) return $this->error("Expected end of file");
		return $result;
	}
	
	private function parse_component(){
		if($this->currentline===FALSE || $this->currentline["name"] != "begin"){
			return $this->error("Expected BEGIN property");
		}
		$name = $this->currentline["value"];
		$props = array();
		$comps = array();
		$result = $this->next_content_line();
		if(is_string($result)) return $result;
		while(TRUE){
			if($this->currentline === FALSE) return $this->error("Unexpected end of file");
			if($this->currentline["name"]=="begin"){
				$result = $this->parse_component();
				if(is_string($result)) return $result;	
				if(!isset($comps[$result["name"]])) $comps[$result["name"]] = array();
				array_push($comps[$result["name"]],$result);
			}elseif($this->currentline["name"]=="end"){
				if($this->currentline["value"]!=$name){
					return $this->error("Unexpected end of ".$this->currentline["value"]." component");
				}
				$result = $this->next_content_line();
				if(is_string($result)) return $result;
				break;
			}else{
				if(!isset($props[$this->currentline["name"]])) $props[$this->currentline["name"]] = array();
				array_push($props[$this->currentline["name"]],$this->currentline);
				$result = $this->next_content_line();
				if(is_string($result)) return $result;
			}
		}
		return array("name"=>$name,"properties"=>$props,"components"=>$comps);
	}
	
	private function next_content_line(){
		if($this->linebuffer === FALSE){
			$this->currentline = FALSE;
			return;
		}
		while(TRUE){
			$fline = fgets($this->filehandle);
			$this->currentlinenum += 1;
			if($fline === FALSE){
				if(strlen($this->linebuffer)>0){
					$result = $this->parse_content_line($this->linebuffer);
					if(is_string($result)) return $result;
					$this->currentline = $result;
				}
				$this->linebuffer = FALSE;
				break;
			}
			$fline = preg_replace("/[\n\r]/","",$fline);
			if(strlen(trim($fline))==0){
				// skip blank line
			}elseif(substr($fline,0,1)==" "){
				$this->linebuffer .= substr($fline,1);
			}else{
				if(strlen($this->linebuffer)>0){
					$result = $this->parse_content_line($this->linebuffer);
					if(is_string($result)) return $result;
					$this->currentline = $result;
				}
				$this->linebuffer = $fline;
				break;
			}
		}
	}
	
	private function parse_content_line($line){
		$pos = 0;
		$name = $this->parse_name($line,$pos);
		if($name===FALSE) return $this->error("Expected property name");
		$params = array();
		while(TRUE){
			$param = $this->parse_param($line,$pos);
			if(is_string($param)) break;
			$params[$param["name"]] = $param["value"];
		}
		$c = $this->expect($line,$pos,":",NULL);
		if($c===FALSE) return $this->error("Expected colon");
		if(!isset($this->PROP_TYPES[$name]) && substr($name,0,2)!="x-"){
			return $this->error("Unknown property '$name'");
		}
		if(isset($params["value"])){
			$type = strtolower($params["value"]);
		}elseif(isset($this->PROP_TYPES[$name])){
			$type = $this->PROP_TYPES[$name];
		}else{
			$type = "text";
		}
		switch($type){
			case "text":		$value = $this->parse_texts($line,$pos); 		break;
			case "boolean":		$value = $this->parse_boolean($line,$pos); 		break;
			case "date":		$value = $this->parse_dates($line,$pos);		break;
			case "date-time":	$value = $this->parse_datetimes($line,$pos);	break;
			case "duration":	$value = $this->parse_durations($line,$pos);	break;
			case "integer":
			case "float":		$value = $this->parse_numbers($line,$pos);		break;
			case "period":		$value = $this->parse_periods($line,$pos);		break;
			case "recur":		$value = $this->parse_recurs($line,$pos);		break;
			case "time":		$value = $this->parse_times($line,$pos);		break;
			case "utc-offset":	$value = $this->parse_utc_offset($line,$pos);	break;
			case "raw":
			case "binary":
			case "cal-address":
			case "uri":			$value = $this->parse_raw($line,$pos); 			break;
			default:			return $this->error("Unknown type '$type'");
		}
		if($value===FALSE) return $this->error("Invalid $type value");
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || strpos(" \t",$curr)===FALSE) break;
			$this->next_char($line,$pos);
		}
		if($this->current_char($line,$pos)!==FALSE) return $this->error("Expected end of property value");
		return array("name"=>$name, "parameters"=>$params, "value"=>$value);
	}
	
	private function parse_param($line,&$pos){
		$c = $this->expect($line,$pos,";",NULL);
		if($c===FALSE) return $this->error("Expected semicolon");
		$name = $this->parse_name($line,$pos);
		if($name===FALSE) return $this->error("Expected param name");
		$c = $this->expect($line,$pos,"=",NULL);
		if($c===FALSE) return $this->error("Expected equals");
		$value = $this->parse_param_value($line,$pos);
		if($value===FALSE) return $this->error("Expected param value");
		return array("name"=>$name,"value"=>$value);
	}
	
	private function parse_param_value($line,&$pos){
		$disallowed = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0a\x0b\x0c\x0d\x0e\x0f"
			."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f\"";
		$buffer = "";
		if($this->current_char($line,$pos)=='"'){
			# quoted string
			$c = $this->expect($line,$pos,'"',NULL);
			if($c===FALSE) return FALSE;
			while(TRUE){
				$c = $this->expect($line,$pos,NULL,$disallowed);
				if($c===FALSE) break;
				$buffer .= $c;
			}
			$c = $this->expect($line,$pos,'"',NULL);
			if($c===FALSE) return FALSE;
		}else{
			# unquoted content
			while(TRUE){
				$c = $this->expect($line,$pos,NULL,$disallowed.';:,');
				if($c===FALSE) break;
				$buffer .= $c;
			}
		}
		return $buffer;
	}
	
	private function parse_raw($line,&$pos){
		$buffer = "";
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE) break;
			$buffer .= $curr;
			$this->next_char($line,$pos);
		}
		return $buffer;
	}
	
	private function parse_texts($line,&$pos){
		$values = array();
		$val = $this->parse_text($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_text($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;						
	}
	
	private function parse_text($line,&$pos){
		$buffer = "";
		$escape = FALSE;
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE) break;
			if($escape){
				if($curr==";" || $curr=="," || $curr=="\\"){
					$buffer .= $curr;
				}elseif($curr=="n" || $curr=="N"){
					$buffer .= "\n";
				}else{
					$buffer .= "\\".$curr;
				}
				$escape = FALSE;
			}else{
				if($curr=="\\"){
					$escape = TRUE;
				}elseif($curr=="," || $curr==";"){
					break;
				}else{
					$buffer .= $curr;
				}
			}
			$this->next_char($line,$pos);
		}
		return $buffer;
	}
	
	private function parse_boolean($line,&$pos){
		if($this->current_char($line,$pos) == "T"){
			foreach(array("T","R","U","E") as $c){
				$result = $this->expect($line,$pos,$c,NULL);
				if($result===FALSE) return $result;
			}
			return 1;
		}else{
			foreach(array("F","A","L","S","E") as $c){
				$result = $this->expect($line,$pos,$c,NULL);
				if($result===FALSE) return $result;
			}
			return 0;
		}
	}
	
	private function parse_dates($line,&$pos){
		$values = array();
		$val = $this->parse_date($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_date($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_date($line,&$pos){
		$numchars = "0123456789";
		$buffer = "";
		for($i=0;$i<4;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$year = (int)$buffer;
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$month = (int)$buffer;
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$day = (int)$buffer;
		return array("year"=>$year, "month"=>$month, "day"=>$day);
	}
	
	private function parse_datetimes($line,&$pos){
		$values = array();
		$val = $this->parse_datetime($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_datetime($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_datetime($line,&$pos){
		$date = $this->parse_date($line,$pos);
		if($date===FALSE) return FALSE;
		$c = $this->expect($line,$pos,"T",NULL);
		if($c===FALSE) return FALSE;
		$time = $this->parse_time($line,$pos);
		if($time===FALSE) return FALSE;
		return array("year"=>$date["year"], "month"=>$date["month"], "day"=>$date["day"],
			"hour"=>$time["hour"], "minute"=>$time["minute"], "second"=>$time["second"],
			"isutc"=>$time["isutc"]);
	}
	
	private function parse_times($line,&$pos){
		$values = array();
		$val = $this->parse_time($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_time($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_time($line,&$pos){
		$numchars = "0123456789";
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$hour = (int)$buffer;
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$minute = (int)$buffer;
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$second = (int)$buffer;
		$buffer = "";
		$isutc = FALSE;
		$curr = $this->current_char($line,$pos);
		if($curr!==FALSE && $curr=="Z"){
			$isutc = TRUE;
			$this->next_char($line,$pos);
		}
		return array("hour"=>$hour, "minute"=>$minute, "second"=>$second, "isutc"=>$isutc);
	}
	
	private function parse_durations($line,&$pos){
		$values = array();
		$val = $this->parse_duration($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_duration($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_duration($line,&$pos){
		$numchars = '0123456789';
		$buffer = '-"-~-3-^-~0-/-';
		$positive = TRUE;
		$weeks = 0;
		$days = 0;
		$hours = 0;
		$minutes = 0;
		$seconds = 0;
		
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		if($curr=="+"){
			$positive = TRUE;
			$this->next_char($line,$pos);
		}elseif($curr=="-"){
			$positive = FALSE;
			$this->next_char($line,$pos);
		}
		
		$c = $this->expect($line,$pos,"P",NULL);
		if($c===FALSE) return FALSE;
		
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		if(strpos($numchars,$curr)===FALSE) goto t_check;
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$curr = $this->current_char($line,$pos);
		
		w_check:
		if($curr===FALSE || $curr!="W") goto d_check;
		$weeks  = (int)$val;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE || strpos($numchars,$curr)===FALSE) goto t_check;		
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$curr = $this->current_char($line,$pos);
		
		d_check:		
		if($curr===FALSE || $curr!="D") return FALSE;
		$days = (int)$val;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);
		
		t_check:
		if($curr===FALSE || $curr!="T") goto end;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$curr = $this->current_Char($line,$pos);
		
		h_check:
		if($curr===FALSE || $curr!="H") goto m_check;
		$hours = (int)$val;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE || strpos($numchars,$curr)===FALSE) goto end;
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$curr = $this->current_char($line,$pos);
		
		m_check:
		if($curr===FALSE || $curr!="M") goto s_check;
		$minutes = (int)$val;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE || strpos($numchars,$curr)===FALSE) goto end;
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$curr = $this->current_char($line,$pos);
		
		s_check:
		if($curr===FALSE || $curr!="S") return FALSE;
		$seconds = (int)$val;
		$this->next_char($line,$pos);
		$curr = $this->current_char($line,$pos);

		end:
		return array( "positive"=>$positive, "weeks"=>$weeks, "days"=>$days, 
						"hours"=>$hours, "minutes"=>$minutes, "seconds"=>$seconds );
	}
	
	private function parse_numbers($line,&$pos){
		$values = array();
		$val = $this->parse_number($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_number($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_number($line,&$pos){
		$numchars = "0123456789";
		$buffer = "";
		$curr = $this->current_char($line,$pos);
		if($curr=="+" || $curr=="-"){
			$buffer .= $curr;
			$this->next_char($line,$pos);
		}
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		$buffer .= $val;
		$curr = $this->current_char($line,$pos);
		if($curr!==FALSE && $curr=="."){
			$buffer .= $curr;
			$this->next_char($line,$pos);
			$val = $this->parse_digits($line,$pos);
			if($val===FALSE) return FALSE;
			$buffer .= $val;
		}
		return (float)$buffer;
	}
	
	private function parse_digits($line,&$pos){
		$numchars = "0123456789";
		$buffer = "";
		$c = $this->expect($line,$pos,$numchars,NULL);
		if($c===FALSE) return FALSE;
		$buffer .= $c;
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || strpos($numchars,$curr)===FALSE) break;
			$buffer .= $curr;
			$this->next_char($line,$pos);
		}
		return $buffer;
	}
	
	private function parse_periods($line,&$pos){
		$values = array();
		$val = $this->parse_period($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_period($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_period($line,&$pos){
		$val = $this->parse_datetime($line,$pos);
		if($val===FALSE) return FALSE;
		$start = $val;
		$c = $this->expect($line,$pos,"/",NULL);
		if($c===FALSE) return FALSE;
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		if($curr=="+" || $curr=="-" || $curr=="P"){
			$val = $this->parse_duration($line,$pos);
			if($val===FALSE) return FALSE;
			return array( "start"=>$start, "duration"=>$val );
		}else{
			$val = $this->parse_datetime($line,$pos);
			if($val===FALSE) return FALSE;
			return array( "start"=>$start, "end"=>$val );
		}
		return array();
	}
	
	private function parse_recurs($line,&$pos){
		$values = array();
		$val = $this->parse_recur($line,$pos);
		if($val===FALSE) return FALSE;
		array_push($values,$val);
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=",") break;
			$this->next_char($line,$pos);
			$val = $this->parse_recur($line,$pos);
			if($val===FALSE) return FALSE;
			array_push($values,$val);
		}
		return $values;
	}
	
	private function parse_recur($line,&$pos){
		$values = array();
		$val = $this->parse_recur_param($line,$pos);
		if($val===FALSE) return FALSE;
		$values[$val["name"]] = $val["value"];
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE || $curr!=";") break;
			$this->next_char($line,$pos);
			$val = $this->parse_recur_param($line,$pos);
			if($val===FALSE) return FALSE;
			$values[$val["name"]] = $val["value"];
		}
		return $values;
	}
	
	private function parse_recur_param($line,&$pos){
		$val = $this->parse_name($line,$pos);
		if($val===FALSE) return FALSE;
		$name = $val;
		$c = $this->expect($line,$pos,"=",NULL);
		if($c===FALSE) return FALSE;
		if(substr($name,0,2)=="x-"){
			$val = $this->parse_text($line,$pos);
			if($val===FALSE) return FALSE;
		}elseif($name=="freq"){
			$val = $this->parse_name($line,$pos);
			if($val===FALSE) return FALSE;
			if($val!="secondly" && $val!="minutely" && $val!="hourly" && $val!="daily"
					&& $val!="weekly" && $val!="monthly" && $val!="yearly"){
				return FALSE;		
			}			
		}elseif($name=="until"){
			$val = $this->parse_date($line,$pos);
			if($val===FALSE) return FALSE;
			$date = $val;
			$curr = $this->current_char($line,$pos);
			if($curr!==FALSE && $curr=="T"){
				$this->next_char($line,$pos);
				$val = $this->parse_time($line,$pos);
				if($val===FALSE) return FALSE;
				$time = $val;
				$val = array("year"=>$date["year"], "month"=>$date["month"], "day"=>$date["day"],
								"hour"=>$time["hour"], "minute"=>$time["minute"], "second"=>$time["second"],
								"isutc"=>$time["isutc"]);
			}else{
				$val = $date;
			}
		}elseif($name=="count" || $name=="interval"){
			$val = $this->parse_digits($line,$pos);
			if($val===FALSE) return FALSE;
			$val = (int)$val;
		}elseif($name=="wkst"){
			$val = $this->parse_name($line,$pos);
			if($val===FALSE) return FALSE;
			if($val!="su" && $val!="mo" && $val!="tu" && $val!="we" && $val!="th" && $val!="fr" && $val!="sa"){
				return FALSE;
			}
		}elseif($name=="byday"){
			$val = array();
			$v = $this->parse_recur_day_num($line,$pos);
			if($v===FALSE) return FALSE;
			array_push($val,$v);
			while(TRUE){
				$curr = $this->current_char($line,$pos);
				if($curr===FALSE || $curr!=",") break;
				$this->next_char($line,$pos);
				$v = $this->parse_recur_day_num($line,$pos);
				if($v===FALSE) return FALSE;
				array_push($val,$v);
			}
		}elseif($name=="bymonthday" || $name=="byyearday" 
				|| $name=="byweekno" || $name=="bysetpos"){
			$val = array();
			$v = $this->parse_recur_week_num($line,$pos);
			if($v===FALSE) return FALSE;
			array_push($val,$v);
			while(TRUE){
				$curr = $this->current_char($line,$pos);
				if($curr===FALSE || $curr!=",") break;
				$this->next_char($line,$pos);
				$v = $this->parse_recur_week_num($line,$pos);
				if($v===FALSE) return FALSE;
				array_push($val,$v);
			}
		}elseif($name=="bysecond" || $name=="byminute" || $name=="byhour" 
				|| $name=="bymonth" ){
			$val = array();
			$v = $this->parse_digits($line,$pos);
			if($v===FALSE) return FALSE;
			array_push($val,(int)$v);
			while(TRUE){
				$curr = $this->current_char($line,$pos);
				if($curr===FALSE || $curr!=",") break;
				$this->next_char($line,$pos);
				$v = $this->parse_digits($line,$pos);
				if($v===FALSE) return FALSE;
				array_push($val,(int)$v);
			}
		}else{
			return FALSE;
		}
		return array("name"=>$name, "value"=>$val);
	}
	
	private function parse_recur_day_num($line,&$pos){
		$numchars = "0123456789";
		$sign = 1;
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		if($curr=="+" || $curr=="-"){
			$sign = $curr=="+" ? 1 : -1;
			$this->next_char($line,$pos);
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE) return FALSE;
			if(strpos($numchars,$curr)===FALSE) return FALSE;
		}
		$number = NULL;
		if(strpos($numchars,$curr)!==FALSE){
			$val = $this->parse_digits($line,$pos);
			if($val===FALSE) return FALSE;
			$number = (int)$val * $sign;
		}
		$val = $this->parse_name($line,$pos);
		if($val===FALSE) return FALSE;
		if($val!="mo" && $val!="tu" && $val!="we" && $val!="th" && $val!="fr" && $val!="sa" && $val!="su"){
			return FALSE;
		}
		$retval = array("day"=>$val);
		if($number!==NULL) $retval["number"] = $number;
		return $retval;
	}
	
	private function parse_recur_week_num($line,&$pos){
		$sign = 1;
		$curr = $this->current_char($line,$pos);
		if($curr===FALSE) return FALSE;
		if($curr=="+" || $curr=="-"){
			$sign = $curr=="+" ? 1 : -1;
			$this->next_char($line,$pos);
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE) return FALSE;
		}
		$val = $this->parse_digits($line,$pos);
		if($val===FALSE) return FALSE;
		return (int)$val * $sign;
	}
	
	private function parse_utc_offset($line,&$pos){
		$numchars = "0123456789";
		$buffer = "";
		$c = $this->expect($line,$pos,"+-",NULL);
		if($c===FALSE) return FALSE;
		$positive = $c=="+";
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$hour = (int)$buffer;
		$buffer = "";
		for($i=0;$i<2;$i++){
			$c = $this->expect($line,$pos,$numchars,NULL);
			if($c===FALSE) return FALSE;
			$buffer .= $c;
		}
		$minute = (int)$buffer;
		$buffer = "";
		$curr = $this->current_char($line,$pos);
		if($curr!==FALSE && strpos($numchars,$curr)!==FALSE){
			for($i=0;$i<2;$i++){
				$c = $this->expect($line,$pos,$numchars,NULL);
				if($c===FALSE) return FALSE;
				$buffer .= $c;
			}
			$second = (int)$buffer;
		}else{
			$second = 0;
		}
		return array("positive"=>$positive, "hour"=>$hour, "minute"=>$minute, "second"=>$second);
	}
	
	private function parse_name($line,&$pos){
		$allowed = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-";
		$buffer = "";
		$c = $this->expect($line,$pos,$allowed,NULL);
		if($c===FALSE) return FALSE;
		$buffer .= $c;
		while(TRUE){
			$c = $this->expect($line,$pos,$allowed,NULL);
			if($c===FALSE) break;
			$buffer .= $c;
		}
		return strtolower($buffer);		
	}
	
	private function expect($line,&$pos,$allowed,$disallowed){
		$curr = $this->current_char($line,$pos);
		if($curr === FALSE) return FALSE;
		if($disallowed!==NULL && strpos($disallowed,$curr)!==FALSE){
			return FALSE;
		}
		if($allowed!==NULL && strpos($allowed,$curr)===FALSE){
			return FALSE;
		}
		$this->next_char($line,$pos);
		return $curr;
	}
	
	private function current_char($line,$pos){
		return $pos<strlen($line) ? $line[$pos] : FALSE;
	}
	
	private function next_char($line,&$pos){
		if($pos<strlen($line)){
			$pos += 1;
		}
	}
	
	private function error($message){
		$lineno = $this->currentlinenum;
		return "$message (line $lineno)";
	}
	
}


class Calendar {
	
	public function __construct($timeoryear,$month=-1,$day=-1,$hour=0,$min=0,$sec=0){
		if($month==-1 && $day==-1){
			$this->time = (int)$timeoryear;
		}else{
			$this->time = strtotime("$timeoryear-$month-$day $hour:$min:$sec");
		}
	}
	
	public function set_date($year,$month,$day){
		$this->time = strtotime(date("$year-$month-$day H:i:s",$this->time));
	}
	
	public function set_time($hour,$minute,$second){
		$this->time = strtotime(date("Y-n-j $hour:$minute:$second",$this->time));
	}
	
	public function get_year(){
		return date("Y",$this->time);
	}
	
	public function set_year($num){
		$this->time = strtotime(date("$num-n-j H:i:s",$this->time));
	}
	
	public function get_month(){
		return date("n",$this->time);
	}
	
	public function set_month($num){
		$this->time = strtotime(date("Y-$num-j H:i:s",$this->time));
	}
	
	public function get_day(){
		return date("j",$this->time);
	}
	
	public function set_day($num){
		$this->time = strtotime(date("Y-n-$num H:i:s",$this->time));		
	}
	
	public function get_hour(){
		return date("H",$this->time);
	}
	
	public function set_hour($num){
		$this->time = strtotime(date("Y-n-j $num:i:s",$this->time));
	}
	
	public function get_minute(){
		return date("i",$this->time);
	}
	
	public function set_minute($num){
		$this->time = strtotime(date("Y-n-j H:$num:s",$this->time));
	}
	
	public function get_second(){
		return date("s",$this->time);
	}
	
	public function set_second($num){
		$this->time = strtotime(date("Y-n-j H:i:$num",$this->time));
	}
	
	public function inc_days($num){
		$inc = $num>=0 ? " + ".$num." days" : " - ".abs($num)." days";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_weeks($num){
		$inc = $num>=0 ? " + ".$num." weeks" : " - ".abs($num)." weeks";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_months($num){
		$inc = $num>=0 ? " + ".$num." months" : " - ".abs($num)." months";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_years($num){
		$inc = $num>=0 ? " + ".$num." years" : " - ".abs($num)." years";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_hours($num){
		$inc = $num>=0 ? " + ".$num." hours" : " - ".abs($num)." hours";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_minutes($num){
		$inc = $num>=0 ? " + ".$num." minutes" : " - ".abs($num)." minutes";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function inc_seconds($num){
		$inc = $num>=0 ? " + ".$num." seconds" : " - ".abs($num)." seconds";
		$this->time = strtotime(date("Y-n-j H:i:s",$this->time).$inc);
	}
	
	public function get_month_name(){
		return date("F",$this->time);
	}
	
	public function get_day_of_week(){
		return date("N",$this->time);
	}
	
	public function get_day_of_year(){
		return (int)date("z",$this->time) + 1;
	}

	public function get_week_of_year($weekstart){
		$wk = $this->raw_week_of_current_year($weekstart);
		$weeks = $this->get_weeks_in_year($weekstart);
		if($wk > $weeks) $wk -= $weeks;
		if($wk < 1){
			$tempcal = new Calendar($this->time);
			$tempcal->set_day(1);
			$tempcal->set_month(1);
			$tempcal->inc_years(-1);
			$weeksprev = $tempcal->get_weeks_in_year($weekstart);
			$wk = $weeksprev - $wk;
		}
		return $wk;
	}
	
	// return week of year where < 1 is in previous year and > weeks is in next year
	private function raw_week_of_current_year($weekstart){
		$doy = (int)$this->get_day_of_year();
		$ys_woff = $this->get_year_start_week_offset($weekstart);
		$yw_start = $ys_woff<=3 ? -$ys_woff : 7-$ys_woff; // offset of first week
		return floor((($doy-1) - $yw_start) / 7) + 1;
	}
	
	// the year that the current week is classed as
	public function get_year_of_week($weekstart){
		$wk = $this->raw_week_of_current_year($weekstart);
		$weeks = $this->get_weeks_in_year($weekstart);
		if($wk < 1) 
			return (int)$this->get_year() - 1;
		elseif($wk > $weeks) 
			return (int)$this->get_year() + 1;
		else 
			return $this->get_year();
	}

	// 0-based index into week that jan 1 is
	private function get_year_start_week_offset($weekstart){
		$dow = (int)$this->get_day_of_week();
		$doy = (int)$this->get_day_of_year();
		$ys_dow = (($dow-1 + 7 - (($doy-1) % 7)) % 7) + 1; // day of week on jan 1
		return ($ys_dow - $weekstart) % 7; 
	}

	public function get_days_in_month(){
		return (int)date("t",$this->time);
	}
	
	public function get_days_in_year(){
		return (bool)date("L",$this->time) ? 366 : 365;
	}
	
	public function get_weeks_in_year($weekstart){
		$ys_woff = $this->get_year_start_week_offset($weekstart);
		$days = $this->get_days_in_year() + ($ys_woff>=4 ? -(7-$ys_woff) : $ys_woff);
		$full_weeks = floor($days / 7);
		$days_left = $days % 7;
		return $full_weeks + ($days_left>=4 ? 1 : 0);
	}
	
	public function get_xdays_in_year($dayofweek,$uptoinc=999){
		return $this->get_xdays_in_period($dayofweek,min($this->get_days_in_year(),$uptoinc),
			$this->get_day_of_year());
	}
	
	public function get_xdays_in_month($dayofweek,$uptoinc=999){
		return $this->get_xdays_in_period($dayofweek,min((int)$this->get_days_in_month(),$uptoinc),
			(int)$this->get_day());
	}
	
	private function get_xdays_in_period($xday,$plength,$pday){
		$dow = (int)$this->get_day_of_week();
		$sdow = (($dow-1 + 7 - (($pday-1) % 7)) % 7) + 1; // day of week at period start
		$fxoff = $xday>=$sdow ? $xday-$sdow : 7-($sdow-$xday); // 0-based offset of first xday
		return ceil(($plength - $fxoff) / 7);
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

function make_event($eventinfo,$starttime,$duration){
	$event = new stdClass();
	$event->name = $eventinfo->name;
	if(isset($eventinfo->description)) $event->description = $eventinfo->description;
	if(isset($eventinfo->url)) $event->url = $eventinfo->url;
	$cal = new Calendar($starttime);
	$cal->inc_days($duration["days"]);
	$cal->inc_hours($duration["hours"]);
	$cal->inc_minutes($duration["minutes"]);
	$cal->inc_seconds($duration["seconds"]);
	$endtime = $cal->time;
	$event->{"start-time"} = $starttime;
	$event->{"end-time"} = $endtime;
	return $event;
}

function generate_events($data){

	$events = array();

	// global event window
	$cal = new Calendar(time());
	$cal->set_time(0,0,0);
	$startthres = $cal->time;
	$endthres = $startthres + 2*365*24*60*60;
	
	// recurring events
	if(isset($data->{"recurring-events"})){
		$max_recurring = 1000;
		foreach($data->{"recurring-events"} as $item){
			$dates = array();
			foreach($item->recurrence as $rec){
				$maxdates = round($max_recurring/sizeof($data->{"recurring-events"})/sizeof($item->recurrence));
				$recdates = array();
				evaluate_recurrence($rec,$startthres,$endthres,$maxdates,function($timestamp) use (&$recdates){
					array_push($recdates,$timestamp);
				});
				$dates = array_unique(array_merge($dates,$recdates));
			}
			if(isset($item->excluding)){
				foreach($item->excluding as $exc){
					evaluate_recurrence($exc,$startthres,$endthres,999999,function($timestamp) use (&$dates){
						$found = array_search($timestamp,$dates);
						if($found!==FALSE) unset($dates[$found]);
					});
				}
			}
			foreach($dates as $date){
				array_push($events,make_event($item, $date, $item->duration));
			}
		}
	}
	
	// fixed events
	foreach($data->events as $item){
		$itemtime = strtotime($item->date["year"]."-".$item->date["month"]."-".$item->date["day"]
				." ".$item->time["hour"].":".$item->time["minute"].":".$item->time["second"]);
		if($itemtime >= $startthres && $itemtime < $endthres){
			array_push($events,make_event($item, $itemtime, $item->duration));
		}
	}
	
	// sort by date
	usort($events, function($a,$b){ 
		if($a->{"start-time"} > $b->{"start-time"}) return 1;
		elseif($a->{"start-time"} < $b->{"start-time"}) return -1;
		else return 0;
	});
	
	return $events;
}

// evaluates recurrence descriptor and invokes the 'withdate' callback with the timestamp
// of each event occurence found.
function evaluate_recurrence($rec,$startthres,$endthres,$maxdates,$withdate){

	if(isset($rec["rules"]["day-hour"])){
		$checkhours = array();
		foreach($rec["rules"]["day-hour"] as $hour) array_push($checkhours,$hour>=0 ? $hour : 24+$hour);
	}else{
		$checkhours = array();
		for($i=0;$i<24;$i++) array_push($checkhours,$i);
	}
	if(isset($rec["rules"]["hour-minute"])){
		$checkmins = array();
		foreach($rec["rules"]["hour-minute"] as $min) array_push($checkmins,$min>=0 ? $min : 60+$min);
	}else{
		$checkmins = array();
		for($i=0;$i<60;$i++) array_push($checkmins,$i);
	}
	// doesnt handle leap second, but oh well
	if(isset($rec["rules"]["minute-second"])){
		$checksecs = array();
		foreach($rec["rules"]["minute-second"] as $sec) array_push($checksecs,$sec>=0 ? $sec : 60+$sec);
	}else{
		$checksecs = array();
		for($i=0;$i<60;$i++) array_push($checksecs,$i);
	}
	$start = $rec["start"];
	$cal = new Calendar($start["year"],$start["month"],$start["day"],
		$start["hour"],$start["minute"],$start["second"]);
	$startstamp = $cal->time;
	if(isset($rec["end"])){
		$end = $rec["end"];
		$endcal = new Calendar($end["year"],$end["month"],$end["day"],
			$end["hour"],$end["minute"],$end["second"]);
		$endstamp = $endcal->time;
	}
	if(isset($rec["count"])){
		$count = $rec["count"];
	}
	$weekstart = $rec["week-start"];
	$cal->set_second(0);
	$cal->set_minute(0);
	$cal->set_hour(0);
	$cal->set_day(1);
	$cal->set_month(1);
	while($cal->get_day_of_week() != $weekstart) $cal->inc_days(-1);
	$yearcount = 0;
	$lastyear = $cal->get_year();
	$lastyearofweek = $cal->get_year_of_week($weekstart);
	$monthcount = 0;
	$lastmonth = $cal->get_month();
	$weekcount = 0;
	$lastweek = $cal->get_week_of_year($weekstart);
	$daycount = 0;
	$monthxdaynums = array();
	for($i=1;$i<=7;$i++) $monthxdaynums[$i] = $cal->get_xdays_in_month($i,$cal->get_day());
	$yearxdaynums = array();
	for($i=1;$i<=7;$i++) $yearxdaynums[$i] = $cal->get_xdays_in_year($i,$cal->get_day_of_year());		
	$secmatchcounts = array( (int)$cal->get_year() => array() );
	$minmatchcounts = array( (int)$cal->get_year() => array() );
	$hourmatchcounts = array( (int)$cal->get_year() => array() );
	$daymatchcounts = array( (int)$cal->get_year() => array() );
	$weekmatchcounts = array( (int)$cal->get_year_of_week($weekstart) => array() );
	$monthmatchcounts = array( (int)$cal->get_year() => array() );
	$yearmatchcounts = array( (int)$cal->get_year() => array() );
	$potentialdates = array( (int)$cal->get_year() => array() );
	$datecount = 0;
	$returnedcount = 0;
	while(TRUE){
		foreach($checkhours as $hour){
			foreach($checkmins as $minute){
				foreach($checksecs as $second){
					$cal->set_hour($hour);
					$cal->set_minute($minute);
					$cal->set_second($second);
					if($cal->time > $endthres) break 4;

					if(isset($rec["rules"]["year"])){
						$matched = FALSE;
						foreach($rec["rules"]["year"] as $yr){
							if($cal->get_year() == $yr){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					
					if(isset($rec["rules"]["year-month"])){
						$matched = FALSE;
						foreach($rec["rules"]["year-month"] as $mo){
							if($mo < 0) $mo = 12+1+$mo;								
							if($cal->get_month() == $mo){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["year-week"])){
						$matched = FALSE;
						foreach($rec["rules"]["year-week"] as $yw){							
							if($yw < 0) $yw = $cal->get_weeks_in_year($weekstart)+1+$yw;
							if($cal->get_week_of_year($weekstart) == $yw){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["year-day"])){
						$matched = FALSE;
						foreach($rec["rules"]["year-day"] as $dy){
							if($dy < 0) $dy = $cal->get_days_in_year()+1+$dy;
							if($cal->get_day_of_year() == $dy){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["year-week-day"])){
						$matched = FALSE;
						foreach($rec["rules"]["year-week-day"] as $ywd){
							$ywdd = $ywd["day"];
							if($ywdd < 0) $ywdd = 7+1+$ywdd;
							if($cal->get_day_of_week() == $ywdd){
								if(isset($ywd["number"])){
									$ywdn = $ywd["number"];
									if($ywdn < 0) $ywdn = $cal->get_xdays_in_year($ywdd)+1+$ywdn;
									if($yearxdaynums[$ywdd] == $ywdn){
										$matched = TRUE;
									}
								}else{
									$matched = TRUE;
								}
								if($matched) break;
							}
						}	
						if(!$matched) continue;					
					}
					if(isset($rec["rules"]["month-day"])){
						$matched = FALSE;
						foreach($rec["rules"]["month-day"] as $dy){
							if($dy < 0) $dy = $cal->get_days_in_month()+1+$dy;
							if($cal->get_day() == $dy){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["month-week-day"])){						
						$matched = FALSE;
						foreach($rec["rules"]["month-week-day"] as $mwd){
							$mwdd = $mwd["day"];
							if($mwdd < 0) $mwdd = 7+1+$mwdd;
							if($cal->get_day_of_week() == $mwdd){
								if(isset($mwd["number"])){
									$mwdn = $mwd["number"];
									if($mwdn < 0) $mwdn = $cal->get_xdays_in_month($mwdd)+1+$mwdn;
									if($monthxdaynums[$mwdd] == $mwdn){
										$matched = TRUE;
									}
								}else{
									$matched = TRUE;
								}
								if($matched) break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["day-hour"])){
						$matched = FALSE;
						foreach($rec["rules"]["day-hour"] as $hr){
							if($hr < 0) $hr = 23+1+$hr;
							if($cal->get_hour() == $hr){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["hour-minute"])){
						$matched = FALSE;
						foreach($rec["rules"]["hour-minute"] as $min){
							if($min < 0) $min = 59+1+$min;
							if($cal->get_minute() == $min){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["minute-second"])){
						$matched = FALSE;
						foreach($rec["rules"]["minute-second"] as $sec){
							if($sec < 0) $sec = 59+1+$sec;
							if($cal->get_second() == $sec){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["year-ival"])){
						if($yearcount % $rec["rules"]["year-ival"] != 0){
							continue;
						}
					}						
					if(isset($rec["rules"]["month-ival"])){
						if($monthcount % $rec["rules"]["month-ival"] != 0){
							continue;
						}							
					}	
					if(isset($rec["rules"]["week-ival"])){
						if($weekcount % $rec["rules"]["week-ival"] != 0){
							continue;
						}							
					}					
					if(isset($rec["rules"]["day-ival"])){
						if($daycount % $rec["rules"]["day-ival"] != 0){
							continue;
						}
					}
					if(isset($rec["rules"]["hour-ival"])){
						if(($daycount*24+$hour) % $rec["rules"]["hour-ival"] != 0){
							continue;
						}
					}
					if(isset($rec["rules"]["minute-ival"])){
						if(($daycount*24*60 + $hour*60 + $minute) % $rec["rules"]["minute-ival"] != 0){
							continue;
						}
					}
					if(isset($rec["rules"]["second-ival"])){
						if(($daycount*24*60*60 + $hour*60*60 + $minute*60 + $second) % $rec["rules"]["second-ival"] != 0){
							continue;
						}
					}
					$date = array( "timestamp"=>$cal->time );
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_year();
					$date["yearmatch"] = inc_and_return_match_count($yearmatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_month();
					$date["monthmatch"] = inc_and_return_match_count($monthmatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year_of_week($weekstart);
					$nkey = (int)$cal->get_week_of_year($weekstart);
					$date["weekmatch"] = inc_and_return_match_count($weekmatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_month()."-".(int)$cal->get_day();
					$date["daymatch"] = inc_and_return_match_count($daymatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_month()."-".(int)$cal->get_day()."-".(int)$cal->get_hour();
					$date["hourmatch"] = inc_and_return_match_count($hourmatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_month()."-".(int)$cal->get_day()."-".(int)$cal->get_hour()
							."-".(int)$cal->get_minute();
					$date["minmatch"] = inc_and_return_match_count($minmatchcounts,$ykey,$nkey);
					$ykey = (int)$cal->get_year();
					$nkey = (int)$cal->get_month()."-".(int)$cal->get_day()
							."-".(int)$cal->get_hour()."-".(int)$cal->get_minute()."-".(int)$cal->get_second();
					$date["secmatch"] = inc_and_return_match_count($secmatchcounts,$ykey,$nkey);
					
					// if before start date, increment match counts but don't include date
					if($cal->time > $startstamp){
						$key = (int)$cal->get_year();
						if(!isset($potentialdates[$key])) $potentialdates[$key] = array();
						array_push($potentialdates[$key], $date);
					}
				}
			}
		}
		$allperiodsended = FALSE;
		$cal->inc_days(1);
		// only increment day/week/month/year counts once at start date
		if($cal->time > $startstamp) $daycount ++;
		if($cal->get_week_of_year($weekstart) != $lastweek){
			if($cal->time > $startstamp) $weekcount ++;
			$lastweek = $cal->get_week_of_year($weekstart);
		}
		if($cal->get_month() != $lastmonth){
			if($cal->time > $startstamp) $monthcount ++;
			$lastmonth = $cal->get_month();
			for($i=1;$i<=7;$i++) $monthxdaynums[$i] = 0;
		}
		if($cal->get_year() != $lastyear){
			// if year has changed to be same as week's year, last week is accounted for
			if($cal->get_year_of_week($weekstart) == $cal->get_year()) $allperiodsended = TRUE;
			if($cal->time > $startstamp) $yearcount ++;
			$lastyear = $cal->get_year();
			for($i=1;$i<=7;$i++) $yearxdaynums[$i] = 0;
		}
		if($cal->get_year_of_week($weekstart) != $lastyearofweek){
			// if week's year has changed to be same as year, last week is accounted for
			if($cal->get_year() == $cal->get_year_of_week($weekstart)) $allperiodsended = TRUE;
			$lastyearofweek = $cal->get_year_of_week($weekstart);
		}
		if($allperiodsended){
			if(isset($potentialdates[(int)$cal->get_year()-1])){
				foreach($potentialdates[(int)$cal->get_year()-1] as $date){
					if(isset($end) && $date["timestamp"] > $endstamp) break 2;
					if(isset($count) && $datecount >= $count) break 2;
					if($returnedcount >= $maxdates) break 2;
					
					$tempcal = new Calendar($date["timestamp"]);
					
					if(isset($rec["rules"]["year-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_year();
						foreach($rec["rules"]["year-match"] as $ym){
							if($ym < 0) $ym = $yearmatchcounts[$ykey][$nkey]+1+$ym;
							if($date["yearmatch"] == $ym){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["month-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_month();
						foreach($rec["rules"]["month-match"] as $mm){
							if($mm < 0) $mm = $monthmatchcounts[$ykey][$nkey]+1+$mm;
							if($date["monthmatch"] == $mm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["week-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_week_of_year($weekstart);
						foreach($rec["rules"]["week-match"] as $wm){
							if($wm < 0) $wm = $weekmatchcounts[$ykey][$nkey]+1+$wm;
							if($date["weekmatch"] == $wm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["day-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_month()."-".$tempcal->get_day();
						foreach($rec["rules"]["day-match"] as $dm){
							if($dm < 0) $dm = $daymatchcounts[$ykey][$nkey]+1+$dm;
							if($date["daymatch"] == $dm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["hour-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_month()."-".(int)$tempcal->get_day()."-".(int)$tempcal->get_hour();
						foreach($rec["rules"]["hour-match"] as $hm){
							if($hm < 0) $hm = $hourmatchcounts[$ykey][$nkey]+1+$hm;
							if($date["hourmatch"] == $hm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["minute-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_month()."-".(int)$tempcal->get_day()
								."-".(int)$tempcal->get_hour()."-".(int)$tempcal->get_minute();
						foreach($rec["rules"]["minute-match"] as $mm){
							if($mm < 0) $mm = $minmatchcounts[$ykey][$nkey]+1+$mm;
							if($date["minmatch"] == $mm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					if(isset($rec["rules"]["second-match"])){
						$matched = FALSE;
						$ykey = (int)$tempcal->get_year();
						$nkey = (int)$tempcal->get_month()."-".(int)$tempcal->get_day()
							."-".(int)$tempcal->get_hour()."-".(int)$tempcal->get_minute()."-".(int)$tempcal->get_second();
						foreach($rec["rules"]["second-match"] as $sm){
							if($sm < 0) $sm = $secmatchcounts[$ykey][$nkey]+1+$sm;
							if($date["secmatch"] == $sm){
								$matched = TRUE;
								break;
							}
						}
						if(!$matched) continue;
					}
					$datecount ++;
					if($date["timestamp"] >= $startthres && $date["timestamp"] < $endthres){
						$withdate($date["timestamp"]);
						$returnedcount ++;
					}
				}
			}
			// discard evaluated counts and potential dates
			unset($secmatchcounts[(int)$cal->get_year()-1]);
			unset($minmatchcounts[(int)$cal->get_year()-1]);
			unset($hourmatchcounts[(int)$cal->get_year()-1]);
			unset($daymatchcounts[(int)$cal->get_year()-1]);
			unset($weekmatchcounts[(int)$cal->get_year_of_week($weekstart)-1]);
			unset($monthmatchcounts[(int)$cal->get_year()-1]);
			unset($yearmatchcounts[(int)$cal->get_year()-1]);
			unset($potentialdates[(int)$cal->get_year()-1]);
		}
		// increment xday counts even before we reach start time
		$monthxdaynums[$cal->get_day_of_week()] ++;
		$yearxdaynums[$cal->get_day_of_week()] ++;
	} // end while	
}

function inc_and_return_match_count(&$countarray,$yearkey,$unitkey){
	if(!isset($countarray[$yearkey])) $countarray[$yearkey] = array();
	if(!isset($countarray[$yearkey][$unitkey])) $countarray[$yearkey][$unitkey] = 0;
	return ++$countarray[$yearkey][$unitkey];
}

// Gives an approximation of the duration between the two given datetimes, where 
// each date is array( "year"=>y, "month"=>m, "day"=>d ),
// each time is array( "hour"=>h, "minute"=>m, "second"=>s ),
// and returned duration is array( "days"=>d, "hours"=>h, "minutes"=>m, "seconds"=>s )
// Not accurate for periods over 1 day
function calc_approx_diff($startdate,$starttime,$enddate,$endtime){
	$start_dt = new DateTime();
	$start_dt->setDate($startdate["year"],$startdate["month"],$startdate["day"]);
	$start_dt->setTime($starttime["hour"],$starttime["minute"],$starttime["second"]);
	$end_dt = new DateTime();
	$end_dt->setDate($enddate["year"],$enddate["month"],$enddate["day"]);
	$end_dt->setTime($endtime["hour"],$endtime["minute"],$endtime["second"]);
	$diff = $start_dt->diff($end_dt);
	return approx_duration($diff->y,$diff->m,0,$diff->d,$diff->h,$diff->i,$diff->s);
}

// returns duration as array( "days"=>d, "hours"=>h, "minutes"=>m, "seconds"=>s ),
// but not accurate for numbers of years or months.
function approx_duration($years,$months,$weeks,$days,$hours,$minutes,$seconds){
	return array( "days"=>round($years*365.25 + $months*30.5 + $weeks*7 + $days),
					"hours"=>$hours, "minutes"=>$minutes, "seconds"=>$seconds );
}

$T_FRAC = "\\.[0-9]+";
$T_hh = "(0?[1-9]|1[0-2])";
$T_HH = "([01][0-9]|2[0-4])";
$T_MERID = "[AaPp]\\.?[Mm]\\.?";
$T_MM = "[0-5][0-9]";
$T_ll = "[0-9][0-9]";
$T_SPACE = "[ \\t]";
$T_TZ = "(\\(?[A-Za-z]{1,6}\\)?|[A-Z][a-z]+([_\/][A-Z][a-z]+)+)";
$T_TZC = "(GMT)?[+-]$T_hh:?($T_MM)?";
$TIME_PATTERN = "(".implode("|",array(
	$T_hh."(".$T_SPACE.")?".$T_MERID,
	$T_hh."[.:]".$T_MM."(".$T_SPACE.")?".$T_MERID,
	$T_hh."[.:]".$T_MM."[.:]".$T_ll."(".$T_SPACE.")?".$T_MERID,
	$T_hh.":".$T_MM.":".$T_ll."[.:][0-9]+".$T_MERID,
	"[Tt]?".$T_HH."[.:]".$T_MM,
	"[Tt]?".$T_HH.$T_MM,
	"[Tt]?".$T_HH."[.:]".$T_MM."[.:]".$T_ll,
	"[Tt]?".$T_HH.$T_MM.$T_ll,
	"[Tt]?".$T_HH."[.:]".$T_MM."[.:]".$T_ll."(".$T_SPACE.")?(".$T_TZC."|".$T_TZ.")",
	"[Tt]?".$T_HH."[.:]".$T_MM."[.:]".$T_ll.$T_FRAC,
	"(".$T_TZ."|".$T_TZC.")"
)).")";

// Parse a date and time from a string representation, allowing a wide variety of formats. 
// Returns array( "year"=>y, "month"=>m, "day"=>d, "hour"=>h, "minute"=>m, "second"=>s )
// or FALSE if no date or time found. 
function parse_datetime($datestr){
	$parsed = strtotime(trim($datestr));
	if($parsed===FALSE) return FALSE;
	$cal = new Calendar($parsed);
	return array( "year"=>(int)$cal->get_year(), "month"=>(int)$cal->get_month(), "day"=>(int)$cal->get_day(),
		"hour"=>(int)$cal->get_hour(), "minute"=>(int)$cal->get_minute(), "second"=>(int)$cal->get_second() );
}

// Parse a time from a string representation, allowing a wide variety of formats.
// Returns array( "hour"=>h, "minute"=>m, "second"=>s ) or FALSE if no time found.
// In particular, will return FALSE if string is a full date in addition to time.
function parse_time($timestr){
	global $TIME_PATTERN;
	$timestr = trim($timestr);
	if(!preg_match("/^\\s*".$TIME_PATTERN."\\s*$/",$timestr)) return FALSE;
	$parsed = strtotime($timestr);
	if($parsed===FALSE) return FALSE;
	$cal = new Calendar($parsed);
	return array( "hour"=>(int)$cal->get_hour(), "minute"=>(int)$cal->get_minute(), 
			"second"=>(int)$cal->get_second() );
}

$DURATION_PATTERN = "(?:".implode("|",array(
	// P 1Yr 4 days t 8m
	"[Pp]?" . "\\s*" . "(?:(\\d+)\\s*[Yy](?:(?:ea)?rs?)?)?" . "\\s*" . "(?:(\\d+)\\s*[Mm](?:(?:on)?ths?)?)?"
		. "\\s*" . "(?:(\\d+)\\s*[Ww](?:(?:ee)?ks?)?)?" . "\\s*" . "(?:(\\d+)\\s*[Dd](?:a?ys?)?)?" 
		. "\\s*" . "[Tt]?" . "\\s*" . "(?:(\\d+)\\s*[Hh](?:(?:ou)?rs?)?)?" 
		. "\\s*" . "(?:(\\d+)\\s*[Mm](?:in(?:ute)?s?)?)?" . "\\s*" . "(?:(\\d+)\\s*[Ss](?:sec(?:ond)?s?)?)?",
	// 0000-01-00 t 09:20
	"[Pp]?" . "(\\d+)-(\\d+)-(\\d+)" . "\\s*" . "(?:" . "[Tt ]" . "\\s*" . "(\\d{1,2}):(\\d{2})(?::(\\d{2}))?" . ")",
	// p 00:02:00
	"[Pp]?" . "\\s*" . "[Tt]?" . "(\\d{1,2}):(\\d{2})(?::(\\d{2}))?"
)).")";

// Parse a duration from a string representation, allowing a wide variety for formats. Returns 
// array( "days"=>d, "hours"=>h, "minutes"=>m, "seconds"=>s ) or FALSE if no duration found. 
// Result is an approximation for units larger than a day.
function parse_duration($durstr){
	global $DURATION_PATTERN;
	$matches = array();
	if(!preg_match("/^\\s*".$DURATION_PATTERN."\\s*$/",trim($durstr),$matches)) return FALSE;
	if(sizeof($matches) == 1) return FALSE;
	if(sizeof($matches) <= 1+7){
		// words format
		$years = 0;   if(sizeof($matches) > 1+0 && $matches[1+0] != "") $years = (int)$matches[1+0];
		$months = 0;  if(sizeof($matches) > 1+1 && $matches[1+1] != "") $months = (int)$matches[1+1];
		$weeks = 0;   if(sizeof($matches) > 1+2 && $matches[1+2] != "") $weeks = (int)$matches[1+2];
		$days = 0;    if(sizeof($matches) > 1+3 && $matches[1+3] != "") $days = (int)$matches[1+3];
		$hours = 0;   if(sizeof($matches) > 1+4 && $matches[1+4] != "") $hours = (int)$matches[1+4];
		$minutes = 0; if(sizeof($matches) > 1+5 && $matches[1+5] != "") $minutes = (int)$matches[1+5];
		$seconds = 0; if(sizeof($matches) > 1+6 && $matches[1+6] != "") $seconds = (int)$matches[1+6];				
		return approx_duration($years,$months,$weeks,$days,$hours,$minutes,$seconds);				
	}elseif(sizeof($matches) <= 1+7+6){
		// iso datetime format
		$years = 0;   if(sizeof($matches) > 1+7+0 && $matches[1+7+0] != "") $years = (int)$matches[1+7+0];
		$months = 0;  if(sizeof($matches) > 1+7+1 && $matches[1+7+1] != "") $months = (int)$matches[1+7+1];
		$days = 0;    if(sizeof($matches) > 1+7+2 && $matches[1+7+2] != "") $days = (int)$matches[1+7+2];
		$hours = 0;   if(sizeof($matches) > 1+7+3 && $matches[1+7+3] != "") $hours = (int)$matches[1+7+3];
		$minutes = 0; if(sizeof($matches) > 1+7+4 && $matches[1+7+4] != "") $minutes = (int)$matches[1+7+4];
		$seconds = 0; if(sizeof($matches) > 1+7+5 && $matches[1+7+5] != "") $seconds = (int)$matches[1+7+5];
		return approx_duration($years,$months,0,$days,$hours,$minutes,$seconds);
	}else{
		// iso time only format
		$hours = 0;   if(sizeof($matches) > 1+7+6+0 && $matches[1+7+6+0] != "") $hours = (int)$matches[1+7+6+0];
		$minutes = 0; if(sizeof($matches) > 1+7+6+1 && $matches[1+7+6+1] != "") $minutes = (int)$matches[1+7+6+1];
		$seconds = 0; if(sizeof($matches) > 1+7+6+2 && $matches[1+7+6+2] != "") $seconds = (int)$matches[1+7+6+2];
		return approx_duration(0,0,0,0,$hours,$minutes,$seconds);
	}
}

// Makes request to given url. Returns a file handle for the response on success, or 
// an error string on failure.
function http_request($url){
	if(extension_loaded("curl")){
		// use cURL, which gives us more info
		$handle = tmpfile();
		if($handle===FALSE) return "Failed to create temp file for HTTP response";
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_FILE, $handle);
		$success = curl_exec($curl);
		if($success===FALSE){
			fclose($handle);
			return "HTTP request failed: ".curl_error($curl);
		}
		$status = curl_getinfo($curl,CURLINFO_HTTP_CODE);
		if($status < 200 || $status >= 300){
			fclose($handle);
			return "Got HTTP status $status";
		}
		curl_close($curl);
		fseek($handle,0);
		return $handle;
	}else{
		// fall back to fopen
		$handle = @fopen($url);
		if($handle===FALSE) return "HTTP request failed";
		return $handle;
	}
}

function get_config_filepath($scriptdir,$scriptname){
	return $scriptdir."/".$scriptname."-config.php";
}

function write_config($scriptdir,$scriptname,$config){
	$filepath = get_config_filepath($scriptdir,$scriptname);
	$handle = fopen($filepath,"w");
	if($handle === FALSE){
		return "Failed to open '$filepath' for writing";
	}
	fwrite($handle,"<?php\n");
	fwrite($handle,"return array(\n");
	foreach($config as $key=>$value){
		fwrite($handle,"\t'$key' => '$value',\n");
	}
	fwrite($handle,");");	
	fclose($handle);
}

function debug_dump($var){
	foreach(explode("\n",var_export($var,TRUE)) as $line) error_log($line);
}

function read_input_if_necessary($scriptdir,$scriptname,$input_formats,$cachedtime,$oldestinputok){
	$filepath = get_config_filepath($scriptdir,$scriptname);
	if(file_exists($filepath)){
		$config = include $filepath;
	}else{
		$config = array();
	}
	if(isset($config["format"])){	
		$formatname = $config["format"];
		foreach($input_formats as $format){
			$result = $format->attempt_handle_by_name($scriptdir,$scriptname,$formatname,$cachedtime,$oldestinputok,$config);
			if(is_string($result)) return $result; # error
			if($result === TRUE) return FALSE; # handled, not modified
			if($result !== FALSE) return $result; # handled, data
		}
		return "Failed to read input for format '$formatname'";
	}else{
		foreach($input_formats as $format){
			$result = $format->attempt_handle_by_discovery($scriptdir,$scriptname,$cachedtime,$oldestinputok,$config);
			if(is_string($result)) return $result; # error;
			if($result === TRUE) return FALSE; # handled, not modified
			if($result !== FALSE) return $result; # handled, data
		}
		return "Failed to read input in any format";
	}
}

function update_cached_if_necessary($scriptdir,$scriptname,$filepath,$output_formats,$input_formats){
	if(file_exists($filepath)){
		$cachedtime = @filemtime($filepath);
		if($cachedtime === FALSE){
			return "Failed to determine last modified time for ".$filepath;
		}
	}else{
		$cachedtime = 0;
	}
	// cached input is out of date if not created today
	$cal = new Calendar(time());	
	$cal->set_time(0,0,0);
	$oldestinputok = $cal->time;	
	$data = read_input_if_necessary($scriptdir,$scriptname,$input_formats,$cachedtime,$oldestinputok);	
	if(is_string($data)) return $data; // error
	if($data===FALSE) return;          // not modified
	
	$data->events = generate_events($data);
	unset($data->{"recurring-events"});
	foreach($output_formats as $format){
		$error = $format->write_file_if_possible($scriptdir,$scriptname,$data,$output_formats);
		if($error) return $error;
	}
}

function attempt_handle($scriptdir,$scriptname,$output_formats,$input_formats){

	// check PHP version
	$required = "5.3";
	if(!version_compare(PHP_VERSION,$required,"ge")) return "PHP version $required or later required";

	// included from another script
	if(basename(__FILE__) != basename($_SERVER["SCRIPT_FILENAME"])){
		foreach($output_formats as $format){
			$result = $format->attempt_handle_include($scriptdir,$scriptname,$output_formats,$input_formats,$_GET);
			if($result===FALSE) continue; // wasn't handled
			if($result) return $result;   // handled, got error
			return;                       // handled, all done
		}
	}
	// format parameter specified
	if(array_key_exists("format",$_GET)){
		$formatname = $_GET["format"];
		foreach($output_formats as $format){
			$result = $format->attempt_handle_by_name($formatname,$scriptdir,$scriptname,$output_formats,$input_formats,$_GET);
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
			$result = $format->attempt_handle_by_mime_type($accept,$scriptdir,$scriptname,$output_formats,$input_formats,$_GET);
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
	new HtmlButtonOutput(),
	new JsonOutput(),
	new JsonPOutput(),
	new ICalendarOutput(),
	new RssOutput(),
	new XmlOutput(),
	new SExpressionOutput()
);

$input_formats = array(
	new RemoteICalendarInput(),
	new RemoteJsonInput(),
	new RemoteCsvInput(),
	new RemoteHtmlInput(),
	new LocalICalendarInput(),
	new LocalJsonInput(),
	new LocalCsvInput(),
	new LocalHtmlInput(),
	new NoInput()
);

/*$result = attempt_handle(dirname(__FILE__),basename(__FILE__,".php"),$output_formats,$input_formats);
if($result===FALSE){
	header("HTTP/1.0 406 Not Acceptable");	
}elseif($result){
	header("HTTP/1.0 500 Internal Server Error");
	die($result);
}
*/

var_dump($_SERVER);
