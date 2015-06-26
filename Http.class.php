<?php
/**
* HTTP Class
* 
* Позволяет легко выполнять GET и POST запросы, хранить 
* и устанавливать cookies, следовать редиректам и 
* возвращать данные в необходимой кодировке. Так же имеется 
* встроенная функция для исправления относительных ссылок 
* в абсолютные.
* 
* @author Jeck (http://jeck.ru)
*/
class Http {

	public $user_agent = 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/22.0.1201.0 Safari/537.1';
	
	public $cookies = array();
	public $referer = '';
	public $timeout = 10;
	
	public $externalIp;
	
	public $proxy = '';
	public $proxy_type = '';
	
	public $current_url;
	
	// Режим следования по редиректу (true - включен, false - выключен)
	public $follow_location =  true;
	
	// Кодировка по умолчанию
	public $default_encoding = 'utf-8';
	
	// Кодировка страницы 
	public $encoding = null;
	
	public $info;
	
	private $ch;
	private $result;
	private $result_headers;
	private $result_body;
	private $location = '';
	
	public function get($url, $encoding='utf-8', $header=false) {
		$this->init($url, $header);
		$this->setDefaults();
		$this->exec();
		
		if ($this->follow_location && !empty($this->location)) {
			return $this->get(self::fixUrl($url,$this->location), $encoding);
		} else {
			return $this->processEncoding($this->result_body, $encoding);
		}
	}

	
	public function post($url, $postdata, $encoding='utf-8', $header=false) {
		$this->init($url, $header);
		$this->setPostFields($postdata);
		$this->setDefaults();
		$this->exec();
		
		if ($this->follow_location && !empty($this->location)) {
			return $this->get(self::fixUrl($url, $this->location), $encoding);
		} else {
			return $this->processEncoding($this->result_body, $encoding);
		}
	}
	
	
	
	
	/**
		Возвращает заголовки последнего запроса
	*/
	public function getHeaders() {
		return $this->result_headers;
	}
	
	/**
		Выполняет начальную инициализацию запроса 
	*/
	private function init($url, $header=false) {
		$this->current_url = $url;
		$this->ch = curl_init($url);
		if($header)
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, $header);
		
		// Если запрос с использованием SSL - отключаем проверку сертификата
		$scheme = parse_url($url,PHP_URL_SCHEME);
		$scheme = strtolower($scheme);
		if ($scheme == 'https') {
			curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
		}
	}
	
	/**
		Запускает обработку запроса
	*/
	private function exec() {
		// Установливает параметры необходимые для правильной обработки данных
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
		
		// Выполняем запрос и получаем информацию
		$this->result = @curl_exec($this->ch);
		$this->info = curl_getinfo($this->ch);
		
	//	echo $this->current_url, ' - ', $this->info['total_time'], PHP_EOL;flush();
		
		//var_dump(curl_getinfo($this->ch));
		$this->processHeaders();
		$this->processBody();
		$this->correctEncoding();
	}
	
	/**
		Получает заголовки запроса и обрабатывает их содержимое
	*/
	private function processHeaders() {
		$this->location = '';
		$this->referer = $this->info['url'];
		$this->result_headers = substr($this->result,0,$this->info['header_size']);
		$headers = explode("\r\n",$this->result_headers);

		foreach ($headers as $header) {
			if (strpos($header,":") !== false) {
				list($key,$value) = explode(":",$header,2);
				$key = trim($key);
				$key = strtolower($key);
				switch ($key) {
					case 'set-cookie':
						$this->processSetCookie($value);
					break;
					case 'content-type':
						$this->processContentType($value);
					break;
					case 'location':
						$this->processLocation($value);
					break;
				}
			}
		}
	}
	
	/**
		Обрабатывает заголовок set-cookie
	*/
	private function processSetCookie($string) {
		$parts = explode(';', $string);
		$domain = '.';
		$cookies = array();
		$expires = false;
		
		foreach ($parts as $part) {
			
			$value = '';

			if (strpos($part, '=') !== false)
			{
				list($key, $value) = explode('=', $part, 2);
				$key = trim($key);
				$value = trim($value);
			}
			else 
				$key = trim($part);
				
			if (strtolower($key) == 'domain') 
				$domain = $value;
			
			if (strtolower($key) == 'expires')
				if ($time = strtotime($value))
					$expires = (boolean) time() > $time;
					
			if (!in_array(strtolower($key), array('domain', 'expires', 'path', 'secure', 'comment', 'secure', 'httponly'))) 
				$cookies[$key] = $value;
			
		}
		foreach ($cookies as $key => $value) {
			if ($expires) {
				unset($this->cookies[$domain][$key]);
			} else {
				$this->cookies[$domain][$key] = $value;
			}
		}
	}
	
	/**
		Обрабатывает заголовок content-type
	*/
	private function processContentType($string) {
		$pos = strpos($string,'charset');
		if ($pos !== false) {
			$endpos = strpos($string,';',$pos);
			if ($endpos === false) {
				$charset = substr($string,$pos);
			} else {
				$length = $endpos - $pos;
				$charset = substr($string,$pos,$length);
			}
			list(,$this->encoding) = explode("=",$charset,2);
			$this->encoding = strtoupper($this->encoding);
		}
	}
	
	/**
		Обрабатывает заголовок location
	*/
	private function processLocation($string) {
		$this->location = trim($string);
	}
	
	/**
		Получает тело страницы
	*/
	private function processBody() {
		$this->result_body = substr($this->result,$this->info['header_size']);
		// Определяем кодировку по meta тегам
		if ($this->encoding == NULL)
			if (preg_match("#<meta\b[^<>]*?\bcontent=['\"]?text/html;\s*charset=([^>\s\"']+)['\"]?#is", $this->result_body, $match)) 
				$this->encoding = strtoupper($match[1]);
	}

	/**
		Возвращает тело страницы в нужной кодировке
	*/
	private function processEncoding($body,$encoding) {

		if ($encoding !== null && !empty($this->encoding) && strtoupper($this->encoding) !== strtoupper( $encoding )) {
			return @iconv($this->encoding, $encoding.'//IGNORE', $body);
		}
		return $body;
	}
	
	/**
		Устанавливает начальные параметры заданные в свойствах
	*/
	private function setDefaults() {
		$this->encoding = null;
		$this->setUserAgent();
		$this->setReferer();
		$this->setCookies();
		$this->setProxy();
		$this->setTimeout();
		$this->setExternalIp();
	}
	
	/**
		Устанавливает UserAgent для curl
	*/
	private function setUserAgent() {
		if (!empty($this->user_agent)) {
			curl_setopt($this->ch, CURLOPT_USERAGENT, $this->user_agent);
		}
	}

	/**
		Устанавливает Referer для curl
	*/	
	private function setReferer() {
		if (!empty($this->referer))
			curl_setopt($this->ch, CURLOPT_REFERER, $this->referer);

	}
	
	/**
		Преобразуем cookies в строку и устанавливаем их для curl
	*/	
	private function setCookies() {
		if (is_array($this->cookies)) {
			$cookie_string = '';
			$currentHost = parse_url($this->current_url, PHP_URL_HOST);
			foreach ($this->cookies as $cookieDomain => $cookies) {
                if ($currentHost{0} !== '.') {
                    $currentHost = '.'.$currentHost;
                }
				if ($cookieDomain == '.' || preg_match('/'.preg_quote($cookieDomain, '/').'$/i', $currentHost)) {
					foreach ($cookies as $key => $value) {
						$cookie_string .= $key.'='.$value.'; ';
					}
				}
			}
			if (strlen($cookie_string) > 0) {
				$cookie_string = substr($cookie_string, 0, -2);
				
				curl_setopt($this->ch, CURLOPT_COOKIE, $cookie_string);
			}
		}
	}
	
	/**
		Устанавливаем  postdata  в качестве post_fields
	*/	
	private function setPostFields($postdata) {
		curl_setopt($this->ch, CURLOPT_POST, true);
		if(is_array($postdata))
		{
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($postdata, '', '&'));
		} else {
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postdata);
		}
	}
	
	/**
		Устанавливаем proxy если необходимо
	*/	
	private function setProxy() {
		if (!empty($this->proxy)) {
			curl_setopt($this->ch, CURLOPT_PROXY, $this->proxy);
			curl_setopt($this->ch, CURLOPT_PROXYTYPE, $this->proxy_type);
		}
	}
	
	/**
		Устанавливает таймаут соединения
	*/	
	private function setTimeout() {
		if ($this->timeout > 0) {
			curl_setopt($this->ch, CURLOPT_TIMEOUT, $this->timeout);
		}
	}
	/**
		Устанавливает внешний IP интерфейс
	*/	
	private function setExternalIp() {
		if (!is_null($this->externalIp)) {
			curl_setopt($this->ch, CURLOPT_INTERFACE, $this->externalIp);
		}
	}
	
	/**
		Корректирует кодировку если она задана в виде не подходящим для curl
	*/
	private function correctEncoding() {
			if($this->encoding == null)
				$this->encoding = $this->default_encoding;
	}
	
	/**
		Преобразовывает относительные URL в абсолютные по базе
	*/
	final static function fixUrl($baseUrl, $url) {
		$baseParts = parse_url($baseUrl);
		if (preg_match('/^\/\//', $url)) {
			$url = $baseParts['scheme'].':'.$url;
		}
		$urlParts = parse_url($url);
		if (isset($urlParts['scheme'])) {
			return self::buidUrl($urlParts);
		}
		$parts = $baseParts;
		if (!isset($baseParts['path'])) {
			$baseParts['path'] = '';
		}
		unset($parts['fragment']);
		if (isset($urlParts['path'])) {
			unset($parts['query']);
			if (strpos($urlParts['path'], '/') === 0) {
				$parts['path'] = $urlParts['path'];
			} else if (isset($urlParts['path']) && !empty($urlParts['path'])) {
				$basePath = explode('/', $baseParts['path']);
				array_pop($basePath);
				$urlPath = explode('/', $urlParts['path']);
				$lastSegment = count($urlPath) - 1;
				foreach ($urlPath as $key => $pathSegment) {
					if ($pathSegment == '.') {
						continue;
					} else if ($pathSegment == '..') {
						if (count($basePath) > 0) {
							array_pop($basePath);
						}
					} else if (empty($pathSegment) && $key != $lastSegment) {
						$basePath = array();
					} else {
						array_push($basePath, $pathSegment);
					}
				}
				$basePath = implode('/', $basePath);
				if (strpos($basePath, '/') !== 0) {
					$basePath = '/'.$basePath;
				}
				$parts['path'] = $basePath;
			}
		}
		if (isset($urlParts['query'])) {
			$parts['query'] = $urlParts['query'];
		}
		if (isset($urlParts['fragment'])) {
			$parts['fragment'] = $urlParts['fragment'];
		}
		return self::buidUrl($parts);
	}

	final static function buidUrl($parts) {
		$url  = (isset($parts['scheme']) ? $parts['scheme'].'://' : 'http://').
				(isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':' . $parts['pass'] : '') .'@' : '').
				(isset($parts['host']) ? $parts['host'] : '').
				(isset($parts['port']) ? ':' . $parts['port'] : '').
				(isset($parts['path']) ? $parts['path'] : '/').
				(isset($parts['query']) ? '?' . $parts['query'] : '').
				(isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
		return $url;
	}
}

?>