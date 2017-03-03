<?php
namespace rest;

/**
 * Collect response headers and data
 *
 * server::run() returns this object
 *
 * @see \rest\Server::run()
 *
 * @package rest
 */
class Response {
	const CREATED = 201;
	const ACCEPTED = 200;
	/**
	 * Response headers
	 * @type array
	 */
	private static $headers = [];

	/**
	 * Response content type
	 * @var string
	 */
	private $contentType;

	/**
	 * Response data
	 * @var array
	 */
	private $data;

	/**
	 * HTTP code of the response
	 * @var int
	 */
	private $responseCode = 200;

	/**
	 * Redirect
	 * @param string $url
	 * @param int $code
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException
	 *      - if $url is not a string or is empty
	 */
	public static function redirect($url, $code = 301){
		#region Check input data
		if (!is_string($url)) {
			throw new \InvalidArgumentException('URL must be a string!');
		}

		if (empty($url)) {
			throw new \InvalidArgumentException('URL cannot be an empty string!');
		}
		#endregion

		header('Location: ' . $url, true, $code);

		if (Server::isDebugModeEnabled()) {
			$debug_backtrace = debug_backtrace();

			header('X-Redirect-File: ' . $debug_backtrace[0]['file']);
			header('X-Redirect-Line: ' . $debug_backtrace[0]['line']);
		}

		exit();
	}

	#region Error methods
	/**
	 * 401 Unauthorized
	 * Use this error if request does not provide the required authorization
	 *
	 * @param string $url
	 */
	public static function errorUnauthorized($url = '') {
		if (!is_string($url)) {
			throw new \InvalidArgumentException('Parameter "url" must be a string!');
		}

		http_response_code(401);

		if (!empty($url)) {
			header('Content-Type: ' . ContentType::JSON);
			$message = json_encode(['redirect_url' => $url]);
		} else {
			header('Content-Type: ' . ContentType::HTML);

			if (Server::isDebugModeEnabled()) {
				$message = 'Debug Mode is ON. 401 Unauthorized';
			} else {
				$message = '401 Unauthorized';
			}
		}

		header('Content-Length: ' . strlen($message));
		echo $message;

		exit(1);
	}

	/**
	 * 403 Forbidden
	 * Use this error if request requires a secure connection (https) or responder is denied (for example by IP)
	 * @param string $debugMessage
	 */
	public static function errorForbidden($debugMessage = 'Forbidden') {
		if (!is_string($debugMessage)) {
			throw new \InvalidArgumentException('Parameter "debug_message" must be a string!');
		}

		self::error(403, $debugMessage);
	}

	/**
	 * 404 Not Found
	 */
	public static function errorNotFound() {
		self::error(404, 'Not Found');
	}

	/**
	 * 405 Method Not Allowed
	 * Use this error if the server does not allow requested HTTP method
	 */
	public static function errorMethod() {
		header('Allow: ' . implode(', ', Server::getSupportedMethods()));
		self::error(405, 'The HTTP method is not supported, please use one of: ' . implode(', ', Server::getSupportedMethods()));
	}

	/**
	 * 406 Not Acceptable
	 * Use this error if requested content type is not acceptable
	 *
	 * @param array $contentTypes
	 * 			allowed content types for the requested API method
	 */
	public static function errorContentType(array $contentTypes) {
		header('Accept: ' . implode(',', $contentTypes));
		self::error(406, 'Accept type is not allowed, please use one of: ' . implode(', ', $contentTypes));
	}

	/**
	 * 500 Internal Server Error
	 * @param string $debugMessage
	 */
	public static function error500($debugMessage = 'Internal Server Error') {
		if (!is_string($debugMessage)) {
			throw new \InvalidArgumentException('Parameter "debug_message" must be a string!');
		}

		self::error(500, $debugMessage);
	}

	/**
	 * Close connection with error code.
	 * In non-production mode, message will be printed
	 *
	 * @param int $code
	 * @param string $message
	 */
	private static function error($code, $message) {
		header('Content-Type: text/html', true, $code);

		if (Server::isDebugModeEnabled()) {
			$message = 'Debug Mode is ON. ' . $message;
			header('Content-Length: ' . strlen($message));

			echo $message;
		}

		exit(1);
	}
	#endregion

	/**
	 * Constructor
	 *
	 * @param $contentType
	 */
	public function __construct($contentType) {
		$this->contentType = $contentType;

		// we always use UTF-8
		self::$headers['Content-Type'] = $contentType . '; charset=utf-8';

		switch ($contentType) {
			case ContentType::JSON:
			case ContentType::XML:
				$this->data = [];
				break;
			default:
				$this->data = '';
		}
	}

	/**
	 * 400 Bad Request
	 * Use inside the actions if the incoming GET or POST parameters are wrong
	 *
	 * @param array $validationErrors - list of validation errors
	 * 	[
	 * 		'property_name' => 'Text of validation error',
	 * 		...
	 * 	]
	 */
	public function errorBadRequest(array $validationErrors) {
		http_response_code(400);

		$this->data = $validationErrors;
		$this->send();

		exit(1);
	}

	/**
	 * Get response content type
	 *
	 * @return string
	 */
	public function contentType() {
		return $this->contentType;
	}

	/**
	 * Set response data
	 *
	 * @param mixed $responseData
	 *
	 * @throws \InvalidArgumentException
	 * 		- if $response_data is in an invalid format for the content type
	 */
	public function set($responseData) {
		if ($this->contentType == ContentType::JSON || $this->contentType == ContentType::XML) {
			// for JSON and XML the response must be an array
			if (!is_array($responseData)) {
				throw new \InvalidArgumentException("The response content type is '{$this->contentType}' and the response data must be an array.");
			}
		} else {
			// for other content types convert data to string
			if (is_array($responseData)) {
				$responseData = json_encode($responseData, JSON_UNESCAPED_SLASHES);
			}
		}

		$this->data = $responseData;
	}

	/**
	 * Set element of the response array by path
	 *
	 * @param string $path
	 * 		Dot separated path of the property into response array
	 * @param mixed $value
	 * 		Value of the property
	 *
	 * @throws \InvalidArgumentException
	 * 		- if content type is not JSON or XML
	 * 		- if $path is not a string or is empty
	 * 		- if $value is not scalar or array
	 * 		- if segment of $path is invalid
	 */
	public function setPath($path, $value) {
		// we can use this function only if content type allow response as array: JSON or XML
		if ($this->contentType != ContentType::JSON && $this->contentType != ContentType::XML) {
			throw new \InvalidArgumentException("The response content type is '{$this->contentType}' and it does not allow use array as the answer.");
		}

		#region Check input data
		if (!is_string($path)) {
			throw new \InvalidArgumentException('Path must be a string!');
		}

		if (empty($path)) {
			throw new \InvalidArgumentException('Path cannot be an empty string!');
		}

		if (!is_scalar($value) && !is_array($value)) {
			throw new \InvalidArgumentException('Value must be scalar or array.');
		}
		#endregion

		if (strpos($path, '.') === false) {
			// root element of the answer
			$segments = array($path);
		} else {
			$segments = explode('.', $path);
		}

		$data = &$this->data;
		$count = count($segments);

		for ($i = 0; $i < $count; $i++) {
			$segment = $segments[$i];

			if ($i == $count - 1) {
				// last segment
				// inform about changing already existed values
				trigger_error("Response data under path '{$path}' already set and will be rewritten.", E_USER_NOTICE);

				$data[$segment] = $value;
			} else {
				if (!isset($data[$segment])) {
					$data[$segment] = [];
				} else if (!is_array($data[$segment])) {
					throw new \InvalidArgumentException("Conflict setting path '{$path}', the segment of the path '{$segment}' is not an array.");
				}

				$data = &$data[$segment];
			}
		}
	}

	/**
	 * Set one or more headers
	 * NOTE: you cannot set Content-Type and Content-Length headers, exception will be thrown
	 *
	 * set_headers('Header-Name', 'Header value');
	 *
	 * set_headers(array(
	 * 		'Header-Name-1', 'Header Value #1',
	 * 		'Header-Name-2', 'Header Value #2',
	 * 		...
	 * ));
	 *
	 * @param array|string $data
	 * @param string|null $value
	 *
	 * @throws \InvalidArgumentException
	 *      - if $data is not an array but $value is null
	 *		- if Content-Type or Content-Length headers are attempted to be set
	 */
	public function setHeaders($data, $value = null) {
		if (is_string($data) && !is_null($value)) {
			// we get key => value pair
			$data = [$data => (string)$value];
		}

		if (!is_array($data)) {
			throw new \InvalidArgumentException('$data is not an array nor string.');
		}

		foreach ($data as $key => $value) {
			if (in_array(strtolower($key), ['content-type', 'content-length'])) {
				throw new \InvalidArgumentException('You cannot manually set "Content-Type" and "Content-Length" headers!');
			}

			self::$headers[$key] = $value;
		}
	}

	/**
	 * Set success response code, if you want answer with 2xx HTTP code
	 *
	 * @param int $code - Can be between 200 and 299
	 */
	public function setResponseCode($code) {
		$code = (int) $code;

		if (200 > $code || $code > 299) {
			throw new \InvalidArgumentException("HTTP success code can be 2xx only, your provide {$code}!");
		}

		if (headers_sent($file, $line)) {
			throw new \BadFunctionCallException("Headers already sent in {$file}[{$line}]!");
		}

		$this->responseCode = $code;
		http_response_code($code);
	}

	/**
	 * Get HTTP response code
	 * @return int
	 */
	public function getResponseCode() {
		return $this->responseCode;
	}

	/**
	 * Render response as string, send headers and response to output
	 */
	public function send() {
		switch ($this->contentType) {
			case ContentType::JSON:
				$response = json_encode($this->data, JSON_UNESCAPED_SLASHES);
				break;
			case ContentType::XML:
				$response = self::renderXml('response', $this->data);
				break;
			default:
				$response = is_array($this->data) ? json_encode($this->data, JSON_UNESCAPED_SLASHES) : (string) $this->data;
				break;
		}

		header('Content-Type: ' . $this->contentType);
		header('Content-Length: ' . strlen($response));

		foreach (self::$headers as $header => $value) {
			header("{$header}: {$value}");
		}

		echo $response;
	}

	/**
	 * Convert an array with data to XML string
	 *
	 * @param string $rootName
	 * @param mixed $data
	 *
	 * @return string
	 * @throws \Exception
	 */
	private static function renderXml($rootName, $data) {
		if (!is_array($data)) {
			$data = [$data];
		}

		// we always use UTF-8
		$xml = new \DomDocument('1.0', 'UTF-8');
		$xml->formatOutput = true;

		$xml->appendChild(self::array2xml($xml, $rootName, $data));

		return $xml->saveXML();
	}

	/**
	 * Recursively convert array to XML
	 *
	 * @param \DomDocument $xml
	 * @param string $nodeName
	 * @param array $array
	 *
	 * @return \DomDocument
	 * @throws \Exception
	 * 		- if illegal character in attribute name
	 * 		- if illegal character in tag name
	 */
	private static function &array2xml($xml, $nodeName, $array = []) {
		$node = $xml->createElement($nodeName);

		if (is_array($array)) {
			// get the attributes first
			if (isset($array['@attributes'])) {
				foreach ($array['@attributes'] as $key => $value) {
					if (!self::isTagValid($key)) {
						throw new \Exception('[Array2XML] Illegal character in attribute name. attribute: ' . $key . ' in node: ' . $nodeName);
					}

					$node->setAttribute($key, self::valueToString($value));
				}

				//remove the key from the array once done.
				unset($array['@attributes']);
			}

			// check if it has a value stored in @value, if yes store the value and return
			// else check if its directly stored as string
			if (isset($array['@value'])) {
				$node->appendChild($xml->createTextNode(self::valueToString($array['@value'])));

				//remove the key from the array once done.
				unset($array['@value']);

				//return from recursion, as a node with a value cannot have child nodes.
				return $node;
			} else if (isset($array['@cdata'])) {
				$node->appendChild($xml->createCDATASection(self::valueToString($array['@cdata'])));

				//remove the key from the array once done.
				unset($array['@cdata']);

				//return from recursion, as a node with CDATA cannot have child nodes.
				return $node;
			}
		}

        //create subnodes using recursion
        if (is_array($array)) {
            // recurse to get the node for that key
            foreach ($array as $key => $value) {
                if (!self::isTagValid($key)) {
                    throw new \Exception('[Array2XML] Illegal character in tag name. tag: ' . $key . ' in node: ' . $nodeName);
                }

                if (is_array($value) && is_numeric(key($value))) {
                    // if the new array is numerically indexed, it means it is an array of nodes of the same kind
                    // it should follow the parent key name
                    foreach ($value as $k => $v) {
                        $node->appendChild(self::array2xml($xml, $key, $v));
                    }
                } else {
                    $node->appendChild(self::array2xml($xml, $key, $value));
                }

                //remove the key from the array once done.
                unset($array[$key]);
            }
        }

		// after we are done with all the keys in the array (if it is one)
		// we check if it has any text value, and if yes, append it.
		if (!is_array($array)) {
			$node->appendChild($xml->createTextNode(self::valueToString($array)));
		}

		return $node;
	}

	/**
	 * Get string representation of boolean value
	 *
	 * @param bool $value
	 *
	 * @return string
	 */
	private static function valueToString($value){
		//convert boolean to text value.
		$value = ($value === true) ? 'true' : (string) $value;
		$value = ($value === false) ? 'false' : (string) $value;

		return $value;
	}

	/**
	 * Check if the tag name or attribute name contains illegal characters
	 * @link http://www.w3.org/TR/xml/#sec-common-syn
	 *
	 * @param string $tag
	 *
	 * @return bool
	 */
	private static function isTagValid($tag){
		$pattern = '/^[a-z_]+[a-z0-9\:\-\.\_]*[^:]*$/i';

		return (preg_match($pattern, $tag, $matches) && $matches[0] == $tag);
	}
}
