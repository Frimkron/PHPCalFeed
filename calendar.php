<?php

// TODO: icalendar timezones
// TODO: icalendar recurring events
// TODO: fix html output layout for 6-week months
// TODO: icalendar over-escaped files?
// TODO: update readme
// TODO: facebook input
//		feasible using oath?
// TODO: wordpress api
//		most common use case?
// TODO: eventbrite input
//		api requires oauth?
// TODO: yaml input
// TODO: yaml output
// TODO: atom output
// TODO: yaml output
// TODO: textpattern api
// TODO: sql database input
// TODO: web-based UI for config
// TODO: web-based UI input
// TODO: other useful input formats
// TODO: icalendar feed name and link - see google example
// TODO: icalendar prodid standard?
// TODO: icalendar disallows zero events
// TODO: responsive design for html
// TODO: more css examples
// TODO: microformat shouldn't have multiple events for day-spanning event
// TODO: browser cache headers



abstract class InputFormat {

	/*	name
		description
		url
		events	
			name (required)
			year (required)
			month (required)
			day (required)
			hour
			minute
			duration (days,hours,minutes)
			description
			url			
		recurring-events
			name (required)
			recurrence (required)
			hour
			minute
			duration (days,hours,minutes)
			description
			url				*/

	public abstract function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config);
	
	public abstract function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config);
	
	protected function file_read_due($filename,$cachedtime,$expiretime){
		return $cachedtime <= filemtime($filename) || $cachedtime <= $expiretime;
	}
	
	protected function url_read_due($cachedtime,$expiretime){
		return $cachedtime <= $expiretime;
	}
}

class NoInput extends InputFormat {

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "none") return FALSE;
		return $this->get_empty_data();
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		$result = write_config($scriptname,array("format"=>"none"));
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
				&& strtolower($cal["properties"]["calscale"][0]["values"][0]) != "gregorian"){
			return "ICalendar: Non-gregorian calendar not supported";
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
						&& strlen($vevent["properties"]["description"][0]["values"][0])>0){
					$eventobj->description = $this->concat_prop_values($vevent["properties"]["description"]);
				}
				
				if(isset($vevent["properties"]["url"])){
					$eventobj->url = $vevent["properties"]["url"][0]["values"][0];
				}			
				
				if(isset($vevent["properties"]["rrule"])){
					// TODO: recurrence
					continue; //ignore for now
				}else{				
					// non-recurring
					if(!isset($vevent["properties"]["dtstart"])){
						continue; // ignore if no start time
					}
					$starttime = $this->extract_datetime($vevent["properties"]["dtstart"][0]);
					if(is_string($starttime)) return $starttime;
					$eventobj->year = $starttime["year"];
					$eventobj->month = $starttime["month"];
					$eventobj->day = $starttime["day"];
					$eventobj->hour = $starttime["hour"];
					$eventobj->minute = $starttime["minute"];
				}
				
				if(isset($vevent["properties"]["duration"])){
					// duration specified
					$duration = $this->extract_duration($vevent["properties"]["duration"][0]);
					if(is_string($duration)) return $duration;
					$eventobj->duration = $duration;
					
				}elseif(isset($vevent["properties"]["dtstart"])){
					$dtstart = $vevent["properties"]["dtstart"][0];
					if(isset($vevent["properties"]["dtend"])){
						// start and end specified
						$starttime = $this->extract_datetime($dtstart);
						if(is_string($starttime)) return $starttime;
						$startcal = new DateTime();
						$startcal->setDate($starttime["year"],$starttime["month"],$starttime["day"]);
						$startcal->setTime($starttime["hour"],$starttime["minute"]);
						$endtime = $this->extract_datetime($vevent["properties"]["dtend"][0]);
						if(is_string($endtime)) return $endtime;
						$endcal = new DateTime();
						$endcal->setDate($endtime["year"],$endtime["month"],$endtime["day"]);
						$endcal->setTime($endtime["hour"],$endtime["minute"]);
						$diff = $startcal->diff($endcal);
						$eventobj->duration = array( "days" => $diff->y*365 + $diff->m*31 + $diff->d, //close enough :/
								"hours" => $diff->h, "minutes" => $diff->i );					
					}elseif(isset($dtstart["parameters"]["value"]) && strtolower($dtstart["parameters"]["value"])=="date"){
						// start specified as date
						$eventobj->duration = array("days"=>1,"hours"=>0,"minutes"=>0);
					}else{
						// start specified as datetime
						$eventobj->duration = array("days"=>0,"hours"=>0,"minutes"=>0);
					}
				}else{
					return "ICalendar: Cannot determine duration for '".$eventobj->name."'";
				}
				
				if(isset($eventobj->recurrence)){
					array_push($calobj->{"recurring-events"},$eventobj);
				}else{
					array_push($calobj->events,$eventobj);
				}
			}
		}
		return $calobj;
	}

	private function concat_prop_values($properties){
		$result = "";
		foreach($properties as $prop){
			foreach($prop["values"] as $value){
				$result .= $value;
			}
		}
		return $result;
	}

	private function extract_datetime($property){
		if(isset($property["parameters"]["value"]) && strtolower($property["parameters"]["value"])=="date"){
			# date only
			$matches = array();
			if(!preg_match("/^(\d{4})(\d{2})(\d{2})$/", trim($property["values"][0]), $matches)){
				return "ICalendar: Invalid date ".$property["values"][0];
			}
			return array( "year"=>$matches[1], "month"=>$matches[2], "day"=>$matches[3], "hour"=>0, "minute"=>0 );
		}else{
			# date and time
			$matches = array();
			if(!preg_match("/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})\d{2}(Z)?$/",
					trim($property["values"][0]), $matches)){
				return "ICalendar: Invalid date-time ".$property["values"][0];
			}
			$isutc = sizeof($matches)>=7 && $matches[6]=="Z"; //TODO - convert timezone
			return array( "year"=>$matches[1], "month"=>$matches[2], "day"=>$matches[3], 
						"hour"=>$matches[4], "minute"=>$matches[5] );
		}
	}
	
	private function extract_duration($property){
		$matches = array();
		if(!preg_match("/^[+-]?P(\d+W)?(\d+D)?(?:T(\d+H)?(\d+M)?(\d+S)?)?$/",trim($property["values"][0]), $matches)){
			return "ICalendar: Invalid duration ".$property["values"][0];
		}
		$weeks = sizeof($matches)>=2 && $matches[1]!="" ? $matches[1] : 0;
		$days = sizeof($matches)>=3 && $matches[2]!="" ? $matches[2] : 0;
		$hours = sizeof($matches)>=4 && $matches[3]!="" ? $matches[3] : 0;
		$minutes = sizeof($matches)>=5 && $matches[4]!="" ? $matches[4] : 0;
		return array( "days"=>$weeks*7+$days, "hours"=>$hours, "minutes"=>$minutes );
	}
} 

class LocalICalendarInput extends ICalendarInputBase {

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "icalendar-local") return FALSE;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!file_exists($this->get_filename($scriptname))) return FALSE;
		$result = write_config($scriptname,array("format"=>"icalendar-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function get_filename($scriptname){
		return "$scriptname-master.ics";
	}

	private function input_if_necessary($scriptname,$cachedtime,$expiretime){
		$filename = $this->get_filename($scriptname);
		if(!$this->file_read_due($filename,$cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($filename,"r");
		if($handle === FALSE){
			return "ICalendar: File '$filename' not found";
		}
		$data = $this->feed_to_event_data($handle);
		if(is_string($data)) return "ICalendar: $data";
		fclose($handle);
		return $data;
	}
}

class RemoteICalendarInput extends ICalendarInputBase {
	
	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "icalendar-remote") return FALSE;
		if(!isset($config["url"])) return "ICalendar: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".ics") return FALSE;
		$result = write_config($scriptname,array("format"=>"icalendar-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function input_if_necessary($scriptname,$cachedtime,$expiretime,$url){
		if(!$this->url_read_due($cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($url,"r");
		if($handle === FALSE){
			return "ICalendar: Could not open '$url'";
		}
		$result = $this->feed_to_event_data($handle);
		fclose($handle);
		return $result;
	}
	
}

abstract class CsvInputBase extends InputFormat {

	protected function stream_to_event_data($handle){
	
		$header = fgetcsv($handle);
		if($header === FALSE){
			return "CSV: Missing header row";
		}
		if(sizeof($header)==0){
			return "CSV: No columns in header row";
		}
		$colmap = array();
		foreach($header as $index => $label){
			$colmap[strtolower(trim($label))] = $index;
		}
		foreach(array("name","date") as $req_key){
			if(!array_key_exists($req_key,$colmap)){
				return "CSV: Required column '$req_key' not found in header";
			}
		}
		$data = new stdClass();
		$data->events= array();
		$data->{"recurring-events"} = array();
		
		while( ($row = fgetcsv($handle)) !== FALSE ){
			if(sizeof($row)==0 || (sizeof($row)==1 && $row[0]==NULL)) continue; # ignore blank lines
			while(sizeof($row) < sizeof($colmap)) array_push($row,"");
		
			$event = new stdClass();
			
			$ename = trim($row[$colmap["name"]]);
			if(strlen($ename)==0) return "CSV: Missing 'name' value";
			$event->name = $ename;
			
			$edate = trim($row[$colmap["date"]]);
			if(strlen($edate)==0) return "CSV: Missing 'date' value";
			if(preg_match("/^\d{4}-\d{2}-\d{2}$/",$edate)){
				$bits = explode("-",$edate);
				$event->year = $bits[0];
				$event->month = $bits[1];
				$event->day = $bits[2];
			}else{
				$parser = new RecurrenceParser();
				$edate = $parser->parse(strtolower($edate));
				if($edate===FALSE) return "CSV: Invalid date format - expected 'yyyy-mm-dd' or recurrence syntax";
				$event->recurrence = $edate;
			}
			
			$etime = "";
			if(array_key_exists("time",$colmap)){
				$etime = trim($row[$colmap["time"]]);
			}
			if(strlen($etime)==0) $etime = "00:00";
			if(!preg_match("/^\d{2}:\d{2}$/",$etime)) return "CSV: Invalid time format - expected 'hh:mm'";
			$bits = explode(":",$etime);
			$event->hour = $bits[0];
			$event->minute = $bits[1];
			
			$eduration = "";
			if(array_key_exists("duration",$colmap)){
				$eduration = strtolower(trim($row[$colmap["duration"]]));
			}
			if(strlen($eduration)==0) $eduration = "1d";
			$eduration = parse_duration($eduration);
			if($eduration === FALSE) return "CSV: Invalid duration format - expected '[0h][0m][0s]'";
			$event->duration = $eduration;
			
			if(array_key_exists("description",$colmap)){
				$event->description = trim($row[$colmap["description"]]);
			}
			
			if(array_key_exists("url",$colmap)){
				$event->url = trim($row[$colmap["url"]]);
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

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "csv-local") return FALSE;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!file_exists($this->get_filename($scriptname))) return FALSE;
		$result = write_config($scriptname,array("format"=>"csv-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	private function get_filename($scriptname){
		return "$scriptname-master.csv";
	}
	
	private function input_if_necessary($scriptname,$cachedtime,$expiretime){
		$filename = $this->get_filename($scriptname);
		if(!$this->file_read_due($filename,$cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($filename,"r");
		if($handle === FALSE){
			return "CSV: File '$filename' not found";
		}		
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		
		return $result;
	}

}

class RemoteCsvInput extends CsvInputBase {

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "csv-remote") return FALSE;
		if(!isset($config["url"])) return "CSV: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".csv") return FALSE;
		$result = write_config($scriptname,array("format"=>"csv-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function input_if_necessary($scriptname,$cachedtime,$expiretime,$url){
		if(!$this->url_read_due($cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($url,"r");
		if($handle === FALSE){
			return "CSV: Could not open '$url'";
		}
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		return $result;
	}

}

abstract class JsonInputBase extends InputFormat {

	protected function is_available(){
		return extension_loaded("mbstring") && extension_loaded("json");
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
				if(!isset($item->name)){
					return "JSON: Missing event name";
				}
				$date_pattern = "/^\d{4}-\d{2}-\d{2}$/";
				if(!isset($item->date)){
					return "JSON: Missing event date";
				}
				if(!preg_match("/^\d{4}-\d{2}-\d{2}$/",$item->date)){
					return "JSON: Invalid date format - expected \"yyyy-mm-dd\"";
				}
				$bits = explode("-",$item->date);
				$item->year = $bits[0];
				$item->month = $bits[1];
				$item->day = $bits[2];
				unset($item->date);			
				if(!isset($item->time)){
					$item->time = "00:00";
				}
				if(!preg_match("/^\d{2}:\d{2}$/",$item->time)){
					return "JSON: Invalid time format - expected \"hh:mm\"";
				}
				$bits = explode(":",$item->time);
				$item->hour = $bits[0];
				$item->minute = $bits[1];
				unset($item->time);
				if(!isset($item->duration)){
					$item->duration = "1d";
				}
				$result = parse_duration(strtolower($item->duration));
				if($result===FALSE){
					return "JSON: Invalid duration - expected \"[0d][0h][0m]\"";
				}
				$item->duration = $result;				
			}
		}
		if(isset($data->{"recurring-events"})){
			if(!is_array($data->{"recurring-events"})){
				return "JSON: Expected recurring-events to be array";
			}
			foreach($data->{"recurring-events"} as $item){
				if(!isset($item->name)){
					return "JSON: Missing recurring-event name";
				}
				if(!isset($item->time)){
					$item->time = "00:00";
				}
				if(!preg_match("/^\d{2}:\d{2}$/",$item->time)){
					return "JSON: Invalid time format - expected \"hh:mm\"";
				}
				$bits = explode(":",$item->time);
				$item->hour = $bits[0];
				$item->minute = $bits[1];
				unset($item->time);
				if(!isset($item->duration)){
					$item->duration = "1d";
				}
				$result = parse_duration(strtolower($item->duration));
				if($result===FALSE){
					return "JSON: Invalid duration - expected \"[0d][0h][0m]\"";
				}
				$item->duration = $result;
				if(!isset($item->recurrence)){
					return "JSON: Missing event recurrence";
				}
				$parser = new RecurrenceParser();
				$result = $parser->parse(strtolower($item->recurrence));
				if($result===FALSE){
					return "JSON: Invalid event recurrence syntax";
				}
				$item->recurrence = $result;
			}
		}
		return $data;
	}

}

class LocalJsonInput extends JsonInputBase {

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "json-local") return FALSE;
		if(!$this->is_available()) return FALSE;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!$this->is_available()) return FALSE;
		if(!file_exists($this->get_filename($scriptname))) return FALSE;
		$result = write_config($scriptname,array("format"=>"json-local"));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime);
		if($result === FALSE) return TRUE;
		return $result;
	}

	private function get_filename($scriptname){
		return "$scriptname-master.json";
	}
	
	private function input_if_necessary($scriptname,$cachedtime,$expiretime){
		$filename = $this->get_filename($scriptname);
		if(!$this->file_read_due($filename,$cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($filename,"r");
		if($handle===FALSE){
			return "JSON: File '$filename' not found";
		}
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		return $result;
	}
}

class RemoteJsonInput extends JsonInputBase {

	public function attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config){
		if($formatname != "json-remote") return FALSE;
		if(!isset($config["url"])) return "JSON: missing config parameter: 'url'";
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config){
		if(!isset($config["url"])) return FALSE;
		if(strtolower(substr($config["url"],-4,4)) != ".json") return FALSE;
		$result = write_config($scriptname,array("format"=>"json-remote","url"=>$config["url"]));
		if($result) return $result;
		$result = $this->input_if_necessary($scriptname,$cachedtime,$expiretime,$config["url"]);
		if($result === FALSE) return TRUE;
		return $result;
	}
	
	public function input_if_necessary($scriptname,$cachedtime,$expiretime,$url){
		if(!$this->url_read_due($cachedtime,$expiretime)) return FALSE;
		
		$handle = @fopen($url,"r");
		if($handle === FALSE){
			return "JSON: Could not open '$url'";
		}
		$result = $this->stream_to_event_data($handle);
		fclose($handle);
		return $result;
	}
}

abstract class OutputFormat {

	public abstract function write_file_if_possible($scriptname,$data);

	public abstract function attempt_handle_include($scriptname,$output_formats,$input_formats);

	public abstract function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats);
	
	public abstract function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats);

	protected abstract function get_filename($scriptname);
	
	protected abstract function output($scriptname);

	protected function handle($scriptname,$output_formats,$input_formats){
		$filename = $this->get_filename($scriptname);
		$error = update_cached_if_necessary($scriptname,$filename,$output_formats,$input_formats);
		if($error) return $error;
		$error = $this->output($scriptname);
		if($error) return $error;
	}

}

abstract class HtmlOutputBase extends OutputFormat {

	const SHOW_MONTHS = 3;

	protected function make_html_fragment($doc,$data){
	
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
	
			if(isset($data->description)){
				$eldescription = $doc->createElement("p");
				$txdescription = $doc->createElement($data->description);
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
	
			for($plusmonths=0; $plusmonths<self::SHOW_MONTHS; $plusmonths++){

				$currentevents = array();
	
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
						$cal->set_hour(0);
						$cal->set_minute(0);
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

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="html") return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("text/html"))) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}

	protected function get_filename($name){
		return "$name.html";
	}

	public function write_file_if_possible($scriptname,$data){
	
		$dom = new DOMImplementation();
	
		$doctype = $dom->createDocumentType("html","","");
	
		$doc = $dom->createDocument(NULL,NULL,$doctype);
	
		$elhtml = $doc->createElement("html");
			$elhead = $doc->createElement("head");
	
				$eltitle = $doc->createElement("title");
				$txtitle = $doc->createTextNode(isset($data->name) ? $data->name : "Calendar");
				$eltitle->appendChild($txtitle);
				$elhead->appendChild($eltitle);
	
				$elcss = $doc->createElement("link");
				$elcss->setAttribute("rel","stylesheet");
				$elcss->setAttribute("type","text/css");
				$elcss->setAttribute("href",$scriptname.".css");
				$elhead->appendChild($elcss);
				
				$elhcal = $doc->createElement("link");
				$elhcal->setAttribute("rel","profile");
				$elhcal->setAttribute("href","http://microformats.org/profile/hcalendar");
				$elhead->appendChild($elhcal);
	
			$elhtml->appendChild($elhead);
	
			$elbody = $doc->createElement("body");
			$elbody->setAttribute("style","background-color: rgb(100,200,200);");
	
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

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		$result = $this->handle($scriptname,$output_formats,$input_formats);
		// echo rather than return, to avoid 500 response from include
		if($result) echo $result;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="html-frag") return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		return FALSE;
	}

	protected function get_filename($scriptname){
		return "$scriptname-frag.html";	
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

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="json") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("application/json","text/json"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}

	protected function get_filename($scriptname){
		return $scriptname.".json";	
	}
	
	public function write_file_if_possible($scriptname,$data){
		if(!$this->is_available()) return;
		
		$filename = $this->get_filename($scriptname);
		$handle = @fopen($filename,"w");
		if($handle === FALSE){
			return "Failed to open ".$filename." for writing";
		}
		$this->write_to_stream($handle,$data);
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

class JsonpOutput extends JsonOutputBase {

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="jsonp") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("application/javascript","text/javascript"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}

	protected function get_filename($scriptname){
		return $scriptname.".js";	
	}
	
	public function write_file_if_possible($scriptname,$data){
		if(!$this->is_available()) return;
		
		$filename = $this->get_filename($scriptname);
		$handle = @fopen($filename,"w");
		if($handle === FALSE){
			return "Failed to open ".$filename." for writing";
		}
		fwrite($handle,"calendar_data(");
		$this->write_to_stream($handle,$data);
		fwrite($handle,")");
		fclose($handle);
	}
	
	public function output($scriptname){
		header("Content-Type: application/javascript; charset=".character_encoding_of_output());
		$filename = $this->get_filename($scriptname);
		if( @readfile($filename) === FALSE ){
			return "Error reading ".$filename;
		}
	}
}

class ICalendarOutput extends OutputFormat {

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="icalendar") return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("text/calendar"))) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}

	protected function get_filename($name){
		return $name.".ics";
	}
	
	private function wrap($text){
		return preg_replace("/[^\n\r]{75}/","$0\r\n ",$text);
	}
	
	private function escape_value($text){
		return str_replace(
			array("\n", "\r", "\\",  ","  ),
			array("\\n","\\r","\\\\","\\,"),
			$text);
	}
	
	public function write_file_if_possible($scriptname,$data){
		$filename = $this->get_filename($scriptname);
		$handle = fopen($filename,"w");
		if($handle === FALSE){
			return "Failed to open ".$filename." for writing";
		}
		fwrite($handle,"BEGIN:VCALENDAR\r\n");
		fwrite($handle,"VERSION:2.0\r\n");
		foreach($data->events as $item){
			fwrite($handle,"BEGIN:VEVENT\r\n");
			fwrite($handle,"DTSTART:".gmdate("Ymd\THis\Z",$item->{"start-time"})."\r\n");
			fwrite($handle,"DTEND:".gmdate("Ymd\THis\Z",$item->{"end-time"})."\r\n");
			fwrite($handle,$this->wrap("SUMMARY:".$this->escape_value($item->name))."\r\n");
			if(isset($item->description)){
				fwrite($handle,$this->wrap("DESCRIPTION:".$this->escape_value($item->description))."\r\n");
			}
			if(isset($item->url)){
				fwrite($handle,$this->wrap("URL:".$this->escape_value($item->url))."\r\n");
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

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="rss") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("application/rss+xml","application/rss"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
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
					$txlink = $doc->createElement($data->url);
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

	public function attempt_handle_include($scriptname,$output_formats,$input_formats){
		return FALSE;	
	}
	
	public function attempt_handle_by_name($name,$scriptname,$output_formats,$input_formats){
		if($name!="xml") return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
	}
	
	public function attempt_handle_by_mime_type($mimetype,$scriptname,$output_formats,$input_formats){
		if(!in_array($mimetype,array("text/xml","application/xml"))) return FALSE;
		if(!$this->is_available()) return FALSE;
		return $this->handle($scriptname,$output_formats,$input_formats);
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
	// D -> '[0-9]{4}-[0-9]{2}-[0-9]{2}'
	// N -> '[0-9]+'		
	
	public function parse($input){
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
		$retval = new StdClass();
		$retval->type = $type;
		$retval->frequency = $freq;
		$retval->start = $start;
		$retval->day = $day;
		$retval->week = $week;
		$retval->month = $month;
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
			if($result===FALSE) return FALSE;
			for($i=0; $i<2; $i++){
				$result = $this->parse_digit($input,$pos);
				if($result===FALSE) return FALSE;
				$pos = $result["pos"];
				$buffer .= $result["value"];
			}
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

	private $filehandle;
	private $currentline;
	private $linebuffer = "";

	public function parse($filehandle){
		$this->filehandle = $filehandle;
		for($i=0; $i<2; $i++){
			$result = $this->next_content_line();
			if(is_string($result)) return $result;
		}
		$result = $this->parse_component();
		if(is_string($result)) return $result;
		if($this->currentline !== FALSE) return "Expected end of file";
		return $result;
	}
	
	private function parse_component(){
		if($this->currentline===FALSE || $this->currentline["name"] != "begin"){
			return "Expected BEGIN property";
		}
		$name = $this->currentline["values"][0];
		$props = array();
		$comps = array();
		$result = $this->next_content_line();
		if(is_string($result)) return $result;
		while(TRUE){
			if($this->currentline === FALSE) return "Unexpected end of file";
			if($this->currentline["name"]=="begin"){
				$result = $this->parse_component();
				if(is_string($result)) return $result;	
				if(!isset($comps[$result["name"]])) $comps[$result["name"]] = array();
				array_push($comps[$result["name"]],$result);
			}elseif($this->currentline["name"]=="end"){
				if($this->currentline["values"][0]!=$name){
					return "Unexpected end of ".$this->currentline["values"][0]." component";
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
				$this->linebuffer .= $fline;
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
		if($name===FALSE) return "Expected property name";
		$params = array();
		while(TRUE){
			$param = $this->parse_param($line,$pos);
			if(is_string($param)) break;
			$params[$param["name"]] = $param["value"];
		}
		$c = $this->expect($line,$pos,":",NULL);
		if($c===FALSE) return "Expected colon";
		$values = $this->parse_values($line,$pos);
		if(is_string($values)) return $values;
		return array("name"=>$name, "parameters"=>$params, "values"=>$values);
	}
	
	private function parse_param($line,&$pos){
		$c = $this->expect($line,$pos,";",NULL);
		if($c===FALSE) return "Expected semicolon";
		$name = $this->parse_name($line,$pos);
		if($name===FALSE) return "Expected param name";
		$c = $this->expect($line,$pos,"=",NULL);
		if($c===FALSE) return "Expected equals";
		$value = $this->parse_param_value($line,$pos);
		if($value===FALSE) return "Expected param value";
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
	
	private function parse_values($line,&$pos){
		$vals = array();
		$buffer = "";
		$escape = FALSE;
		while(TRUE){
			$curr = $this->current_char($line,$pos);
			if($curr===FALSE) break;
			if($escape){
				switch($curr){
					case ";": case ",": case "\\": $buffer .= $curr; break;
					case "n": case "N": $buffer .= "\n"; break;
					default: $buffer .= "\\".$curr; break;
				}
				$escape = FALSE;
			}else{
				if($curr=="\\"){
					$escape = TRUE;
				}elseif($curr==","){
					array_push($vals,$buffer);
					$buffer = "";
				}else{
					$buffer .= $curr;
				}
			}	
			$this->next_char($line,$pos);		
		}
		array_push($vals,$buffer);
		return $vals;
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
	
}


class Calendar {
	
	public function __construct($timeoryear,$month=-1,$day=-1){
		if($month==-1 && $day==-1){
			$this->time = $timeoryear;
		}else{
			$this->time = strtotime("$timeoryear-$month-$day");
		}
	}
	
	public function get_day_of_week(){
		return date("N",$this->time);
	}

	public function get_year(){
		return date("Y",$this->time);
	}
	
	public function set_year($num){
		$this->time = strtotime(date("$num-n-j H:i",$this->time));
	}
	
	public function get_month(){
		return date("n",$this->time);
	}
	
	public function set_month($num){
		$this->time = strtotime(date("Y-$num-j H:i",$this->time));
	}
	
	public function get_day(){
		return date("j",$this->time);
	}
	
	public function set_day($num){
		$this->time = strtotime(date("Y-n-$num H:i",$this->time));		
	}
	
	public function get_hour(){
		return date("h",$this->time);
	}
	
	public function set_hour($num){
		$this->time = strtotime(date("Y-n-j $num:i",$this->time));
	}
	
	public function get_minute(){
		return date("i",$this->time);
	}
	
	public function set_minute($num){
		$this->time = strtotime(date("Y-n-j H:$num",$this->time));
	}
	
	public function inc_days($num){
		$inc = $num>=0 ? " + ".$num." days" : " - ".abs($num)." days";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function inc_weeks($num){
		$inc = $num>=0 ? " + ".$num." weeks" : " - ".abs($num)." weeks";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function inc_months($num){
		$inc = $num>=0 ? " + ".$num." months" : " - ".abs($num)." months";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function inc_years($num){
		$inc = $num>=0 ? " + ".$num." years" : " - ".abs($num)." years";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function inc_hours($num){
		$inc = $num>=0 ? " + ".$num." hours" : " - ".abs($num)." hours";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function inc_minutes($num){
		$inc = $num>=0 ? " + ".$num." minutes" : " - ".abs($num)." minutes";
		$this->time = strtotime(date("Y-n-j H:i",$this->time).$inc);
	}
	
	public function get_month_name(){
		return date("F",$this->time);
	}
}

function parse_duration($input){
	if(strlen(trim($input))==0) return FALSE;
	$matches = array();
	if(!preg_match("/^\s*(?:(\d+)d)?\s*(?:(\d+)h)?\s*(?:(\d+)m)?\s*$/",$input,$matches)) return FALSE;
	return array(
		"days"		=> (sizeof($matches) > 1 && $matches[1]) ? $matches[1] : 0,
		"hours"		=> (sizeof($matches) > 2 && $matches[2]) ? $matches[2] : 0,
		"minutes"	=> (sizeof($matches) > 3 && $matches[3]) ? $matches[3] : 0
	);
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
	$endtime = $cal->time;
	$event->{"start-time"} = $starttime;
	$event->{"end-time"} = $endtime;
	return $event;
}

function generate_events($data){

	$startthres = time();
	$endthres = time() + 2*365*24*60*60;
	$events = array();
	
	$max_recurring = 50;
	foreach($data->{"recurring-events"} as $item){
		if(sizeof($events) >= $max_recurring) break;
		$rec = $item->recurrence;
		$cal = new Calendar($rec->start);
		$cal->set_hour($item->hour);
		$cal->set_minute($item->minute);
		// TODO: don't increment all the way from start to current day
		// daily
		if($rec->type == "daily"){
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->inc_days($rec->frequency);
			}
		}
		// weekly, (nth last) day of week
		else if($rec->type == "weekly"){
			if($rec->day < 0) $rec->day = 7 + ($rec->day + 1);
			while($cal->get_day_of_week() != $rec->day){
				$cal->inc_days(1);
			}
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->inc_weeks($rec->frequency);
			}
		}
		// monthly, day of month
		else if($rec->type == "monthly" && $rec->week == 0 && $rec->day > 0){
			$cal->set_day($rec->day);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->inc_months($rec->frequency);
				$cal->set_day($rec->day);
			}
		}
		// monthly, nth last day of month
		else if($rec->type == "monthly" && $rec->week == 0 && $rec->day < 0){
			$cal->set_day(1);
			$cal->inc_months(1);
			$cal->inc_days($rec->day);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->inc_months($rec->frequency + 1);
				$cal->inc_days($rec->day);
			}
		}
		// monthly, nth xday of month
		else if($rec->type == "monthly" && $rec->week != 0 && $rec->week > 0){
			$cal->set_day(1);
			while($cal->get_day_of_week() != $rec->day){
				$cal->inc_days(1);
			}
			$cal->inc_weeks($rec->week - 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->inc_months($rec->frequency);
				while($cal->get_day_of_week() != $rec->day){
					$cal->inc_days(1);
				}
				$cal->inc_weeks($rec->week - 1);
			}
		}
		// montly, nth last xday of month
		else if($rec->type == "monthly" && $rec->week != 0 && $rec->week < 0){
			$cal->set_day(1);
			$cal->inc_months(1);
			do {
				$cal->inc_days(-1);
			}while($cal->get_day_of_week() != $rec->day);
			$cal->inc_weeks($rec->week + 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->inc_months($rec->frequency + 1);
				do {
					$cal->inc_days(-1);
				}while($cal->get_day_of_week() != $rec->day);
				$cal->inc_weeks($rec->week + 1);
			}
		}
		// yearly, day of year
		else if($rec->type == "yearly" && $rec->week == 0 && $rec->month == 0 && $rec->day > 0){
			$cal->set_month(1);
			$cal->set_day(1);
			$cal->inc_days($rec->day - 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_month(1);
				$cal->set_day(1);
				$cal->inc_years($rec->frequency);
				$cal->inc_days($rec->day - 1);
			}
		}
		// yearly, nth last day of year
		else if($rec->type == "yearly" && $rec->week == 0 && $rec->month == 0 && $rec->day < 0){
			$cal->set_day(1);
			$cal->set_month(12);
			$cal->set_day(31);
			$cal->inc_days($rec->day + 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->set_month(12);
				$cal->set_day(31);
				$cal->inc_years($rec->frequency);
				$cal->inc_days($rec->day + 1);
			}
		}
		// yearly, nth xday of year
		else if($rec->type == "yearly" && $rec->week != 0 && $rec->month == 0 && $rec->week > 0){
			$cal->set_month(1);
			$cal->set_day(1);
			while($cal->get_day_of_week() != $rec->day){
				$cal->inc_days(1);
			}
			$cal->inc_weeks($rec->week - 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_month(1);
				$cal->set_day(1);
				$cal->inc_years($rec->frequency);
				while($cal->get_day_of_week() != $rec->day){
					$cal->inc_days(1);
				}
				$cal->inc_weeks($rec->week - 1);
			}
		}
		// yearly, nth last xday of year
		else if($rec->type == "yearly" && $rec->week != 0 && $rec->month == 0 && $rec->week < 0){
			$cal->set_month(12);
			$cal->set_day(31);
			while($cal->get_day_of_week() != $rec->day){
				$cal->inc_days(-1);
			}
			$cal->inc_weeks($rec->week + 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_month(12);
				$cal->set_day(31);
				$cal->inc_years($rec->frequency);
				while($cal->get_day_of_week() != $rec->day){
					$cal->inc_days(-1);
				}
				$cal->inc_weeks($rec->week + 1);
			}
		}
		// yearly, nth day of month
		else if($rec->type == "yearly" && $rec->week == 0 && $rec->month != 0 && $rec->day > 0){
			$cal->set_month($rec->month);
			$cal->set_day($rec->day);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->inc_years($rec->frequency);
				$cal->set_month($rec->month);
				$cal->set_day($rec->day);
			}
		}
		// yearly nth last day of month
		else if($rec->type == "yearly" && $rec->week == 0 && $rec->month != 0 && $rec->day < 0){
			$cal->set_day(1);
			$cal->set_month($rec->month);
			$cal->inc_months(1);
			$cal->inc_days($rec->day);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->set_month($rec->month);
				$cal->inc_years($rec->frequency);
				$cal->inc_months(1);
				$cal->inc_days($rec->day);
			}
		}
		// yearly, nth xday of month
		else if($rec->type == "yearly" && $rec->week != 0 && $rec->month != 0 && $rec->week > 0){
			$cal->set_day(1);
			$cal->set_month($rec->month);
			while($cal->get_day_of_week() != $rec->day){
				$cal->inc_days(1);
			}
			$cal->inc_weeks($rec->week - 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_month($rec->month);
				$cal->set_day(1);
				$cal->inc_years($rec->frequency);
				while($cal->get_day_of_week() != $rec->day){
					$cal->inc_days(1);
				}
				$cal->inc_weeks($rec->week - 1);
			}
		}
		// yearly, nth last xday of month
		else if($rec->type == "yearly" && $rec->week !=0 && $rec->month != 0 && $rec->week < 0){
			$cal->set_day(1);
			$cal->set_month($rec->month);
			$cal->inc_months(1);
			do {
				$cal->inc_days(-1);
			}while($cal->get_day_of_week() != $rec->day);
			$cal->inc_weeks($rec->week + 1);
			while($cal->time < $endthres && sizeof($events) < $max_recurring){
				if($cal->time >= max($startthres,$rec->start)){
					array_push($events,make_event($item, $cal->time, $item->duration));
				}
				$cal->set_day(1);
				$cal->set_month($rec->month);
				$cal->inc_years($rec->frequency);
				$cal->inc_months(1);
				do{
					$cal->inc_days(-1);
				}while($cal->get_day_of_week() != $rec->day);
				$cal->inc_weeks($rec->week + 1);
			}
		}
	}
	
	// fixed events
	foreach($data->events as $item){
		$itemtime = strtotime($item->year."-".$item->month."-".$item->day
				." ".$item->hour.":".$item->minute);
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

function get_config_filename($scriptname){
	return "$scriptname-config.php";
}

function write_config($scriptname,$config){
	$filename = get_config_filename($scriptname);
	$handle = fopen($filename,"w");
	if($handle === FALSE){
		return "Failed to open '$filename' for writing";
	}
	fwrite($handle,"<?php\n");
	fwrite($handle,"return array(\n");
	foreach($config as $key=>$value){
		fwrite($handle,"\t'$key' => '$value',\n");
	}
	fwrite($handle,");");	
	fclose($handle);
}

function read_input_if_necessary($scriptname,$input_formats,$cachedtime,$expiretime){
	$filename = get_config_filename($scriptname);
	if(file_exists($filename)){
		$config = include $filename;
	}else{
		$config = array();
	}
	if(isset($config["format"])){	
		$formatname = $config["format"];
		foreach($input_formats as $format){
			$result = $format->attempt_handle_by_name($scriptname,$formatname,$cachedtime,$expiretime,$config);
			if(is_string($result)) return $result; # error
			if($result === TRUE) return FALSE; # handled, not modified
			if($result !== FALSE) return $result; # handled, data
		}
		return "Failed to read input for format '$formatname'";
	}else{
		foreach($input_formats as $format){
			$result = $format->attempt_handle_by_discovery($scriptname,$cachedtime,$expiretime,$config);
			if(is_string($result)) return $result; # error;
			if($result === TRUE) return FALSE; # handled, not modified
			if($result !== FALSE) return $result; # handled, data
		}
		return "Failed to read input in any format";
	}
}

function update_cached_if_necessary($scriptname,$filename,$output_formats,$input_formats){
	if(file_exists($filename)){
		$cachedtime = filemtime($filename);
		if($cachedtime === FALSE){
			return "Failed to determine last modified time for ".$filename;
		}
	}else{
		$cachedtime = 0;
	}
	$expiretime = time()-24*60*60;
	$data = read_input_if_necessary($scriptname,$input_formats,$cachedtime,$expiretime);	
	if(is_string($data)) return $data; // error
	if($data===FALSE) return;          // not modified
	
	$data->events = generate_events($data);
	foreach($output_formats as $format){
		$error = $format->write_file_if_possible($scriptname,$data);
		if($error) return $error;
	}
}

function attempt_handle($scriptname,$output_formats,$input_formats){

	// included from another script
	if(basename(__FILE__) != basename($_SERVER["SCRIPT_FILENAME"])){
		foreach($output_formats as $format){
			$result = $format->attempt_handle_include($scriptname,$output_formats,$input_formats);
			if($result===FALSE) continue; // wasn't handled
			if($result) return $result;   // handled, got error
			return;                       // handled, all done
		}
	}
	// format parameter specified
	if(array_key_exists("format",$_GET)){
		$formatname = $_GET["format"];
		foreach($output_formats as $format){
			$result = $format->attempt_handle_by_name($formatname,$scriptname,$output_formats,$input_formats);
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
			$result = $format->attempt_handle_by_mime_type($accept,$scriptname,$output_formats,$input_formats);
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
	new JsonpOutput(),
	new ICalendarOutput(),
	new RssOutput(),
	new XmlOutput()
);

$input_formats = array(
	new RemoteICalendarInput(),
	new RemoteJsonInput(),
	new RemoteCsvInput(),
	new LocalICalendarInput(),
	new LocalJsonInput(),
	new LocalCsvInput(),
	new NoInput()
);

$result = attempt_handle(basename(__FILE__,".php"),$output_formats,$input_formats);
if($result===FALSE){
	header("HTTP/1.0 406 Not Acceptable");	
}elseif($result){
	header("HTTP/1.0 500 Internal Server Error");
	die($result);
}
