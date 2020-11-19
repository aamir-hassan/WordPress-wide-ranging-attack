<?php
/*
Name: YouTube Video Downloader
Developer: MasuD RaNa
Dev Url: Tubesh.Com
FB Url: fb.com/masudrana2266
*/
function sig_js_decode($player_html){
	
	if(preg_match('/signature",([a-zA-Z0-9$]+)\(/', $player_html, $matches)){
		
		$func_name = $matches[1];		
		$func_name = preg_quote($func_name);
		
		if(preg_match('/'.$func_name.'=function\([a-z]+\){(.*?)}/', $player_html, $matches)){
			
			$js_code = $matches[1];
			
			if(preg_match_all('/([a-z0-9]{2})\.([a-z0-9]{2})\([^,]+,(\d+)\)/i', $js_code, $matches) != false){
				
				$obj_list = $matches[1];
				$func_list = $matches[2];
				
				preg_match_all('/('.implode('|', $func_list).'):function(.*?)\}/m', $player_html, $matches2,  PREG_SET_ORDER);
				
				$functions = array();
				
				foreach($matches2 as $m){
					
					if(strpos($m[2], 'splice') !== false){
						$functions[$m[1]] = 'splice';						
					} else if(strpos($m[2], 'a.length') !== false){
						$functions[$m[1]] = 'swap';
					} else if(strpos($m[2], 'reverse') !== false){
						$functions[$m[1]] = 'reverse';
					}
				}
				
				$instructions = array();
				
				foreach($matches[2] as $index => $name){
					$instructions[] = array($functions[$name], $matches[3][$index]);
				}
				
				return $instructions;
			}
		}
	}
	
	return false;
}



class YouTubeDownloader {
	
	private $storage_dir;
	private $cookie_dir;
	
	private $itag_info = array(
	
		18 => "MP4 360p",
		22 => "MP4 720p (HD)",
		37 => "MP4 1080",
		38 => "MP4 3072p",
		59 => "MP4 480p",
		78 => "MP4 480p",
		43 => "WebM 360p",
		17 => "3GP 144p"
	);
	
	function __construct(){
		$this->storage_dir = sys_get_temp_dir();
		$this->cookie_dir = sys_get_temp_dir();
	}
	
	function setStorageDir($dir){
		$this->storage_dir = $dir;
	}
	
	public function curl($url){
	
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:49.0) Gecko/20100101 Firefox/49.0');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}
	
	public static function head($url){
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		$result = curl_exec($ch);
		curl_close($ch);
		return http_parse_headers($result);
	}
	
	private function getInstructions($html){
		
		if(preg_match('@<script\s*src="([^"]+player[^"]+js)@', $html, $matches)){
			
			$player_url = $matches[1];
			
			if(strpos($player_url, '//') === 0){
				$player_url = 'http://'.substr($player_url, 2);
			} else if(strpos($player_url, '/') === 0){
				$player_url = 'http://www.youtube.com'.$player_url;
			}

			$file_path = $this->storage_dir.'/'.md5($player_url);
			
			if(file_exists($file_path)){
				
				$str = file_get_contents($file_path);
				return unserialize($str);
				
			} else {
				
				$js_code = $this->curl($player_url);
				$instructions = sig_js_decode($js_code);
				
				if($instructions){
					file_put_contents($file_path, serialize($instructions));
					return $instructions;
				}
			}
		}
		
		return false;
	}
	
	public function stream($id){
		
		$links = $this->getDownloadLinks($id, "mp4");
		
		if(count($links) == 0){
			die("no url found!");
		}
		
		$url = $links[0]['url'];
		
		$headers = array(
			'User-Agent: Mozilla/5.0 (Windows NT 6.3; WOW64; rv:49.0) Gecko/20100101 Firefox/49.0'
		);
		
		if(isset($_SERVER['HTTP_RANGE'])){
			$headers[] = 'Range: '.$_SERVER['HTTP_RANGE'];
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
		curl_setopt($ch, CURLOPT_HEADER, 0);
	
		$headers = '';
		$headers_sent = false;
		$success = false;
		
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $data) use (&$headers, &$headers_sent){
			
			$headers .= $data;
			
			if(preg_match('@HTTP\/\d\.\d\s(\d+)@', $data, $matches)){
				$status_code = $matches[1];
				
				if($status_code == 200 || $status_code == 206){
					$headers_sent = true;
					header(rtrim($data));
				}
				
			} else {
				
				$forward = array('content-type', 'content-length', 'accept-ranges', 'content-range');
				
				$parts = explode(':', $data, 2);
				
				if($headers_sent && count($parts) == 2 && in_array(trim(strtolower($parts[0])), $forward)){
					header(rtrim($data));
				}
			}
			
			return strlen($data);
		});
		
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$headers_sent){
			
			if($headers_sent){
				echo $data;
				flush();
			}
			
			return strlen($data);
		});
		
		$ret = @curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);
		return true;
	}
	
	public function extractId($str){
		
		if(preg_match('/[a-z0-9_-]{11}/i', $str, $matches)){
			return $matches[0];
		}
		
		return false;
	}
	
	private function selectFirst($links, $selector){
		
		$result = array();
		$formats = preg_split('/\s*,\s*/', $selector);
		
		foreach($formats as $f){
			
			foreach($links as $l){
				
				if(stripos($l['format'], $f) !== false || $f == 'any'){
					$result[] = $l;
				}
			}
		}
		
		return $result;
	}

	public function getDownloadLinks($id, $selector = false){
		
		$result = array();
		$instructions = array();
		
		if(strpos($id, '<div id="player') !== false){
			$html = $id;
		} else {
			$video_id = $this->extractId($id);
			
			if(!$video_id){
				return false;
			}
			
			$html = $this->curl("https://www.youtube.com/watch?v={$video_id}");
		}
	
		if(strpos($html, 'player-age-gate-content') !== false){
			// nothing you can do folks...
			return false;
		}
		
		if(preg_match('@url_encoded_fmt_stream_map["\']:\s*["\']([^"\'\s]*)@', $html, $matches)){
			
			$parts = explode(",", $matches[1]);
			
			foreach($parts as $p){
				$query = str_replace('\u0026', '&', $p);
				parse_str($query, $arr);
				
				$url = $arr['url'];
				
				if(isset($arr['sig'])){
					$url = $url.'&signature='.$arr['sig'];
				
				} else if(isset($arr['signature'])){
					$url = $url.'&signature='.$arr['signature'];
				
				} else if(isset($arr['s'])){
					
					if(count($instructions) == 0){
						$instructions = (array)$this->getInstructions($html);
					}
					
					$dec = $this->sig_decipher($arr['s'], $instructions);
					$url = $url.'&signature='.$dec;
				}
				
				$url = preg_replace('@(\/\/)[^\.]+(\.googlevideo\.com)@', '$1redirector$2', $url);
				
				$itag = $arr['itag'];
				$format = isset($this->itag_info[$itag]) ? $this->itag_info[$itag] : 'Unknown';
				
				$result[$itag] = array(
					'url' => $url,
					'format' => $format
				);
			}
		}
		
		if($selector){
			return $this->selectFirst($result, $selector);
		}
		
		return $result;
	}
	
	private function sig_decipher($signature, $instructions){
		
		foreach($instructions as $opt){
			
			$command = $opt[0];
			$value = $opt[1];
			
			if($command == 'swap'){
				
				$temp = $signature[0];
				$signature[0] = $signature[$value % strlen($signature)];
				$signature[$value] = $temp;
				
			} else if($command == 'splice'){
				$signature = substr($signature, $value);
			} else if($command == 'reverse'){
				$signature = strrev($signature);
			}
		}
		
		return trim($signature);
	}
}


?>