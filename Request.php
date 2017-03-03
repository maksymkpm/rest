<?php
namespace rest;

/**
 * Covers request properties and data.
 *
 * @package rest
 *
 * @property-read string method
 * @property-read array headers
 * @property-read string uri
 * @property-read string $basePath
 * @property-read string version
 * @property-read bool $isHttps
 * @property-read array $contentTypes
 * @property-read string $userIP
 */
class Request {
	const HTTP_GET = 'GET';
	const HTTP_POST = 'POST';
	const HTTP_PUT = 'PUT';
	const HTTP_DELETE = 'DELETE';
	const HTTP_OPTIONS = 'OPTIONS';

	private $httpMethod;
	private $headers;
	private $dataGet;
	private $dataPost;
	private $dataFiles;
	private $isHttps;
	private $uri;
	private $contentTypes;
	private $basePath;
	private $version;
	private $userIP;

	/**
	 * Singleton for the request object
	 * @var
	 */
	private static $instance;

	/**
	 * Get current request instance
	 *
	 * @return Request
	 */
	public static function instance() {
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check if $method is a valid HTTP method for current version of the server.
	 * @see Server::setSupportedMethods()
	 *
	 * @param string $method
	 *
	 * @return bool
	 */
	public static function isHttpMethodValid($method) {
		return in_array($method, Server::getSupportedMethods());
	}

	/**
	 * Detect type of string as a single IPv4 or mask and type of mask.
	 *
	 * @param $ip
	 * @return false|string
	 *        string
	 *            "wildcard" - wildcard mask: '111.111.111.*', '111.111.*.*'
	 *            "mask" - simple mask: '111.111.111.0/24'
	 *            "section" - IP range: '111.111.111.99-111.111.111.222'
	 *            "single" - simple IPv4: '111.111.111.111'
	 *        false - if type did not match
	 */
	public static function getIpType($ip) {
		// try to detect wildcard
		if (strpos($ip, '*') !== false) {
			if (ip2long(str_replace('*', '1', $ip))) {
				return 'wildcard';
			}

			return false;
		}

		// try to detect network mask
		if (strpos($ip, '/')) {
			$tmp = explode('/', $ip);

			if (ip2long($tmp[0]) && $tmp[1] >= 1 && $tmp[1] <= 32) {
				return 'mask';
			}

			return false;
		}

		// try to detect IP range
		if (strpos($ip, '-')) {
			$tmp = explode('-', $ip);

			if (ip2long($tmp[0]) && ip2long($tmp[1])) {
				return 'section';
			}

			return false;
		}

		// try to detect single IP
		if (ip2long($ip)) {
			return 'single';
		}

		return false;
	}

	/**
	 * Check if a given IP address is in a given network
	 *
	 * @param $ip
	 * 		Valid IP address
	 * @param $network
	 * 		Valid IP address or network mask. It can be mask, wildcard, section or single IP
	 * 		wildcard: 192.168.0.*
	 * 		section: 192.168.0.0-192.168.0.255
	 * 		mask: 192.168.0.0/24
	 *
	 * @return bool
	 */
	public static function ipIsInNetwork($ip, $network) {
		$ipNumber = ip2long($ip);

		if (!$ipNumber) {
			return false;
		}

		switch (self::getIpType($network)) {
			case 'single':
				return ($ip == $network);
			case 'wildcard':
				$ipParts = explode('.', $ip);
				$networkParts = explode('.', $network);

				if (count($ipParts) != 4 || count($networkParts) != 4) {
					return false;
				}

				for ($i = 0; $i < 4; $i++) {
					if ($networkParts[$i] == '*') {
						$ipParts[$i] = '*';
					}
				}

				return (implode('.', $ipParts) == implode('.', $networkParts));
			case 'mask':
				list($baseIP, $mask) = explode('/', $network);

				if (($ipNumber & ~((1 << (32 - $mask)) - 1) ) == ip2long($baseIP)) {
					return true;
				}

				return false;
			case 'section':
				$border = explode('-', $network);

				return (ip2long($border[0]) <= $ipNumber && $ipNumber <= ip2long($border[1]));
			default:
				return false;
		}
	}

	/**
	 * Constructor is private to prevent creating a new instance of the class via the 'new' operator from outside this class
	 */
	private function __construct() {
		$this->isHttps = $this->checkRequestSecure();
		$this->headers = $this->getHeaders();
		$this->httpMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : self::HTTP_GET;
		$this->contentTypes = $this->getContentTypes(array(ContentType::TEXT));
		$this->userIP = $_SERVER['REMOTE_ADDR'];

		$this->processUri();

		$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : null);

		switch ($content_type) {
			case ContentType::JSON:
				$this->dataPost = json_decode(file_get_contents("php://input"), true);

				break;
			case ContentType::XML:
				$xml = simplexml_load_string(file_get_contents("php://input"));

				if ($xml != false) {
					$json = json_encode($xml);
					$this->dataPost = json_decode($json, true);
				}

				break;
			default:
				if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'PUT') {
					parse_str(file_get_contents("php://input"), $this->dataPost);
				} else {
					$this->dataPost = empty($_POST) ? array() : $_POST;
				}
		}

		$this->dataGet = empty($_GET) ? array() : $_GET;
		$this->dataFiles = empty($_FILES) ? array() : $_FILES;

		// prevent using global variables
		unset($_POST, $_GET, $_FILES);
	}

	private function __clone() {}
	private function __sleep() {}
	private function __wakeup() {}

	/**
	 * Returns true if request is through secure HTTPS connection
	 *
	 * @return bool
	 */
	public function isHttps() {
		return $this->isHttps;
	}

	/**
	 * Returns requested URI (without base path)
	 * @return string
	 */
	public function uri() {
		return $this->uri;
	}

	/**
	 * Returns server base path
	 * @return string
	 */
	public function basePath() {
		return $this->basePath;
	}

	/**
	 * Returns requested version
	 * @return string
	 */
	public function version() {
		return $this->version;
	}

	/**
	 * Returns HTTP method of the request
	 * @return string
	 */
	public function method() {
		return $this->httpMethod;
	}

	/**
	 * Returns requested headers
	 * @return string
	 */
	public function headers() {
		return $this->headers;
	}

	/**
	 * Returns requested content types, ordered by quality
	 * @return array
	 */
	public function contentTypes() {
		return $this->contentTypes;
	}

	/**
	 * Returns remote client IP
	 * @return string
	 */
	public function userIp() {
		return $this->userIP;
	}

	/**
	 * Getter
	 *
	 * @param $name
	 *
	 * @return mixed
	 */
	public function __get($name) {
		switch ($name) {
			case 'isHttps':
			case 'uri':
			case 'basePath':
			case 'version':
			case 'method':
			case 'headers':
			case 'content_types':
			case 'userIp':
				return $this->$name();
			default:
				throw new \InvalidArgumentException("Property '{$name}' does not exist in class " . get_class($this));
		}
	}

	/**
	 * Magic method for working empty()
	 * @param $name
	 *
	 * @return bool
	 */
	public function __isset($name) {
		switch ($name) {
			case 'isHttps':
			case 'uri':
			case 'basePath':
			case 'version':
			case 'method':
			case 'headers':
			case 'contentTypes':
			case 'userIp':
				return true;
			default:
				throw new \InvalidArgumentException("Property '{$name}' does not exist in class " . get_class($this));
		}
	}

	/**
	 * Get one or all $_GET parameters
	 *
	 * @param string $name - if null return full $_GET array
	 * @param mixed $defaultValue
	 *
	 * @return mixed
	 */
	public function dataGet($name = null, $defaultValue = null) {
		if (empty($name)) {
			return $this->dataGet;
		}

		if (isset($this->dataGet[$name])) {
			return $this->dataGet[$name];
		}

		return $defaultValue;
	}

	/**
	 * Get one or all $_POST parameters
	 *
	 * @param string $name - if null return full $_POST array
	 * @param mixed $defaultValue
	 *
	 * @return mixed
	 */
	public function dataPost($name = null, $defaultValue = null) {
		if (empty($name)) {
			return $this->dataPost;
		}

		if (isset($this->dataPost[$name])) {
			return $this->dataPost[$name];
		}

		return $defaultValue;
	}

	/**
	 * Get one or all $_FILES parameters
	 *
	 * @param string $name - if null return full $_FILES array
	 * @param mixed $defaultValue
	 *
	 * @return mixed
	 */
	public function dataFiles($name = null, $defaultValue = null) {
		if (empty($name)) {
			return $this->dataFiles;
		}

		if (isset($this->dataFiles[$name])) {
			return $this->dataFiles[$name];
		}

		return $defaultValue;
	}

	/**
	 * Check if request is secure (https)
	 *
	 * @return bool
	 */
	private function checkRequestSecure() {
		// FILTER_VALIDATE_BOOLEAN - Returns TRUE for "1", "true", "on" and "yes". Returns FALSE otherwise.
		if (!empty($_SERVER['HTTPS']) && filter_var($_SERVER['HTTPS'], FILTER_VALIDATE_BOOLEAN)) {
			return true;
		}

		// possible load balancer option
		if (!empty($_SERVER['HTTP_USESSL']) && filter_var($_SERVER['HTTP_USESSL'], FILTER_VALIDATE_BOOLEAN)) {
			return true;
		}

		// one more option of load balancer
		if ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') ||
			(!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && filter_var($_SERVER['HTTP_X_FORWARDED_SSL'], FILTER_VALIDATE_BOOLEAN))
		) {
			return true;
		}

		// not guaranty SSL connection, check server settings
		return ($_SERVER['SERVER_PORT'] == 443);
	}

	/**
	 * Return a list of accepted content types in quality order.
	 * If accept request header is not set, returns empty array.
	 *
	 * @param array $defaultContentTypes
	 * @return array
	 */
	private function getContentTypes(array $defaultContentTypes = []) {
		$header = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';

		if (empty($header)) {
			return $defaultContentTypes;
		}

		$contentTypes = array();
		$types = explode(',', $header);

		foreach ($types as $type) {
			$parts = explode(';', $type);
			$type = trim(array_shift($parts));
			$quality = 1.0;

			foreach ($parts as $part) {
				if (strpos($part, '=') === false) {
					continue;
				}

				list ($key, $value) = explode('=', trim($part));

				if ($key === 'q') {
					$quality = (float)trim($value);
				}
			}

			$contentTypes[$type] = $quality;
		}

		arsort($contentTypes);

		return array_keys($contentTypes);
	}

	/**
	 * Return a list of requested headers
	 *
	 * @return array
	 */
	private function getHeaders() {
		$headers = array();

		foreach ($_SERVER as $key => $value) {
			if (strpos($key, 'HTTP_') === 0) {
				// HTTP_X_REQUESTED_WITH -> X-Requested-With
				// HTTP_CONTENT_TYPE -> Content-Type
				$header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
				$headers[$header] = $value;
			}
		}

		return $headers;
	}

	/**
	 * Get url information.
	 * NOTE: base path has leading "/" and closing "/", or can be just "/"
	 * 		uri is trimmed with "/"
	 *
	 * @throws \Exception
	 *      - if cannot get URI from: PATH_INFO, REQUEST_URI, PHP_SELF
	 */
	private function processUri() {
		if (!empty($_SERVER['PATH_INFO'])) {
			$uri = $_SERVER['PATH_INFO'];
		} else if (!empty($_SERVER['REQUEST_URI'])) {
			$uri = $_SERVER['REQUEST_URI'];
		} else if (!empty($_SERVER['PHP_SELF'])) {
			$uri = $_SERVER['PHP_SELF'];

			// delete index.php from uri
			if (strpos($uri, '/index.php') !== false) {
				$uri = substr_replace($uri, '', strpos($uri, '/index.php'), strlen('/index.php'));
			}
		} else {
			throw new \Exception('Cannot find URI from: PATH_INFO, REQUEST_URI, PHP_SELF');
		}

		// delete query part
		if (strpos($uri, '?') !== false) {
			$uri = substr($uri, 0, strpos($uri, '?'));
		}

		// if we are not in the root, try to determine the base path
		if (!empty($_SERVER['SCRIPT_NAME'])) {
			$indexPosition = strpos($_SERVER['SCRIPT_NAME'], 'index.php');

			if ($indexPosition !== false) {
				$this->basePath = substr($_SERVER['SCRIPT_NAME'], 0, $indexPosition);
			}
		}

		// delete base path from uri
		if (empty($this->basePath)) {
			$this->basePath = '/';
		} else if ($this->basePath != '/' && (strpos($uri, $this->basePath) === 0)){
			$uri = substr($uri, strlen($this->basePath));
		}

		$uri = trim($uri, '/');

		if (preg_match('|^\/?(\d{1,3}\.\d{1,3})(\/(.+)?)?$|', $uri, $matches)) {
			$this->uri = isset($matches[3]) ? $matches[3] : '';
			$this->version = $matches[1];
		} else {
			$this->uri = $uri;
			$this->version = '';
		}
	}
}
