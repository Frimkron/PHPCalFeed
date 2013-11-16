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
		$name = $this->currentline["value"];
		$props = array();
		$comps = array();
		$this->next_content_line();
		while(TRUE){
			if($this->currentline === FALSE) return FALSE;
			$comp = $this->parse_component();
			if($comp!==FALSE){
				array_push($comps,$comp);
				$this->next_content_line();
			}			
			if($this->currentline["name"]=="end" && $this->currentline["value"]==$name){
				$this->next_content_line();
				break;
			}
			array_push($props,$this->currentline);
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
	
	// TODO: content value should be an array of values, where non-escaped comma is separator
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
			array_push($params,$param);
		}
		$c = $this->expect($line,$pos,":",NULL);
		if($c===FALSE){
			//echo "no colon";
			return FALSE;
		}
		$value = $this->parse_value($line,$pos);
		if($value===FALSE){
			//echo "no value";
			return FALSE;
		}
		return array("name"=>$name, "params"=>$params, "value"=>$value);
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
	
	private function parse_value($line,&$pos){
		$buffer = "";
		while(TRUE){
			$c = $this->expect($line,$pos,NULL,NULL);
			if($c===FALSE) break;
			$buffer .= $c;
		}
		return $buffer;
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

$handle = @fopen("example.ics","r");
$parser = new ICalendarParser();
$cal = $parser->parse($handle);
echo serialize($cal);
fclose($handle);

