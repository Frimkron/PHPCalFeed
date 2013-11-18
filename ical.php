<?php

class ICalendarParser {

	private $filehandle;
	private $currentline;
	private $linebuffer = "";

	public function parse($filehandle){
		$this->filehandle = $filehandle;
		$this->next_content_line();
		$this->next_content_line();
		$cal = $this->parse_component();
		if($cal===FALSE) return FALSE;
		return $cal;
	}
	
	private function parse_component(){
		if($this->currentline===FALSE || $this->currentline["name"] != "begin"){
			return FALSE;
		}
		$name = $this->currentline["values"][0];
		$props = array();
		$comps = array();
		$this->next_content_line();
		while(TRUE){
			if($this->currentline === FALSE) return FALSE;
			$comp = $this->parse_component();
			if($comp!==FALSE){
				if(!isset($comps[$comp["name"]])) $comps[$comp["name"]] = array();
				array_push($comps[$comp["name"]],$comp);
			}			
			if($this->currentline["name"]=="end" && $this->currentline["values"][0]==$name){
				$this->next_content_line();
				break;
			}
			if(!isset($props[$this->currentline["name"]])) $props[$this->currentline["name"]] = array();
			array_push($props[$this->currentline["name"]],$this->currentline);
			$this->next_content_line();
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
					$this->currentline = $this->parse_content_line($this->linebuffer);
				}
				$this->linebuffer = FALSE;
				break;
			}elseif(substr($fline,0,1)==" "){
				$this->linebuffer .= substr($fline,1,-2);
			}else{
				if(strlen($this->linebuffer)>0){
					$this->currentline = $this->parse_content_line($this->linebuffer);
				}
				$this->linebuffer = substr($fline,0,-2);
				break;
			}
		}
	}
	
	private function parse_content_line($line){
		$pos = 0;
		$name = $this->parse_name($line,$pos);
		if($name===FALSE) {
			//echo "no name";
			return FALSE;
		}
		$params = array();
		while(TRUE){
			$param = $this->parse_param($line,$pos);
			if($param===FALSE) break;
			$params[$param["name"]] = $param["value"];
		}
		$c = $this->expect($line,$pos,":",NULL);
		if($c===FALSE){
			//echo "no colon";
			return FALSE;
		}
		$values = $this->parse_values($line,$pos);
		if($values===FALSE){
			//echo "no values";
			return FALSE;
		}
		return array("name"=>$name, "parameters"=>$params, "values"=>$values);
	}
	
	private function parse_param($line,&$pos){
		//echo "parsing param at $pos";
		$c = $this->expect($line,$pos,";",NULL);
		if($c===FALSE) return FALSE;
		$name = $this->parse_name($line,$pos);
		if($name===FALSE) return FALSE;
		$c = $this->expect($line,$pos,"=",NULL);
		if($c===FALSE) return FALSE;
		$value = $this->parse_param_value($line,$pos);
		if($value===FALSE) return FALSE;
		return array("name"=>$name,"value"=>$value);
	}
	
	private function parse_param_value($line,&$pos){
		//echo "parsing param value at $pos";
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
		//echo "parsing name at $pos";
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
		//echo "expecting $allowed and not $disallowed, got $curr";
		if($curr === FALSE) return FALSE;
		if($disallowed!==NULL && strpos($disallowed,$curr)!==FALSE){
			//echo "$curr was disallowed";
			return FALSE;
		}
		if($allowed!==NULL && strpos($allowed,$curr)===FALSE){
			//echo "$curr was not allowed";
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

function echo_component($comp,$indent){
	for($i=0;$i<$indent;$i++) echo "\t";
	echo $comp["name"]."\n";
	foreach($comp["properties"] as $propname=>$props){
		for($i=0;$i<$indent+1;$i++) echo "\t";
		echo $propname."[";
		foreach($props as $prop){
			echo "(";
			foreach($prop["params"] as $paramname=>$params){
				echo $paramname."=[";
				foreach($params as $param){
					echo $param["value"]."|";
				}
			}
			echo ") : ";
			foreach($prop["values"] as $value){
				echo $value."|";
			}
		}
		echo "]\n";
	}
	foreach($comp["components"] as $compname=>$comps){
		for($i=0;$i<$indent+1;$i++) echo "\t";
		echo $compname."\n";
		foreach($comps as $inner){
			echo_component($inner,$indent+2);
		}
	}
}

function extract_datetime($property){
	if(isset($dtstart["parameters"]["value"]) && strtolower($dtstart["parameters"]["value"])=="date"){
		# date only
		$matches = array();
		if(!preg_match("/^(\d{4})(\d{2})(\d{2})$/", trim($dtstart["values"][0]), $matches)){
			return "Invalid date ".$dtstart["values"][0];
		}
		return array( "year"=>$matches[1], "month"=>$matches[2], "day"=>$matches[3], "hour"=>0, "minute"=>0 );
	}else{
		# date and time
		$matches = array();
		if(!preg_match("/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})\d{2}(Z)?$/",
				trim($dtstart["values"][0]), $matches)){
			return "Invalid date-time ".$dtstart["values"][0];
		}
		$isutc = sizeof($matches)>=7 && $matches[6]=="Z"; //TODO - convert timezone
		return array( "year"=>$matches[1], "month"=>$matches[2], "day"=>$matches[3], 
					"hour"=>$matches[4], "minute"=>$matches[5] );
	}
}

function cal_to_event_data($cal){
	$calobj = new stdClass();
	$calobj->events = array();
	$calobj->{"recurring-events"} = array();
	if($cal["name"]!="VCALENDAR"){
		return "Expected VCALENDAR component but found".$cal["name"];
	}
	if(isset($cal["properties"]["calscale"]) 
			&& strtolower($cal["properties"]["calscale"][0]["values"][0]) != "gregorian"){
		return "Non-gregorian calendar not supported";
	}
	if(isset($cal["components"]["VEVENT"])){
		foreach($cal["components"]["VEVENT"] as $vevent){
			$eventobj = new stdClass();
			if(isset($vevent["properties"]["summary"])){
				$eventobj->name = $vevent["properties"]["summary"][0]["values"][0];
			}else{
				$eventobj->name = "Unnamed event";
			}
			if(isset($vevent["properties"]["description"]) 
					&& strlen($vevent["properties"]["description"][0]["values"][0])>0){
				$eventobj->description = $vevent["properties"]["description"][0]["values"][0];
			}
			// TODO: URL?
			if(isset($vevent["properties"]["rrule"])){
				// TODO: recurrence
				continue; //ignore for now
			}else{				
				if(!isset($vevent["properties"]["dtstart"])) continue; // ignore if no start time
				$starttime = extract_datetime($vevent["properties"]["dtstart"][0]);
				$eventobj->year = $starttime["year"];
				$eventobj->month = $starttime["month"];
				$eventobj->day = $startime["day"];
				$eventobj->hour = $starttime["hour"];
				$eventobj->minute = $starttime["minute"];
			}
			
			if(isset($vevent["properties"]["dtend"])){
				$endtime = extract_datetime($vevent["properties"]["dtend"][0]);
				// TODO: date difference
			}elseif(isset($vevent["properties"]["duration"])){
				// TODO: extract duration			
			}elseif(isset($vevent["properties"]["dtstart"])){
				$dtstart = $vevent["properties"]["dtstart"][0];
				if(isset($dtstart["parameters"]["value"]) && strtolower($dtstart["parameters"]["value"])=="date"){
					$eventobj->duration = array("days"=>1,"hours"=>0,"minutes"=>0);
				}else{
					$eventobj->duration = array("days"=>0,"hours"=>0,"minutes"=>0);
				}
			}else{
				return "Cannot determine duration for '".$eventobj->name."'";
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

$handle = @fopen("example.ics","r");
$parser = new ICalendarParser();
$cal = $parser->parse($handle);
if($cal===FALSE){
	echo "Failed to parse";
}else{
	//echo_component($cal,0);
	$caldata = cal_to_event_data($cal);
	if(is_string($caldata)){
		echo "ERROR: $caldata\n";
	}else{
		foreach($caldata->events as $event){
			echo $event->name." @ ".$event->hour.":".$event->minute." on ".$event->year."-".$event->month."-".$event->day."\n";
		}
	}
}
fclose($handle);

