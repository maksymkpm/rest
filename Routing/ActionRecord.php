<?php

namespace rest\routing;

/**
 * Class defining an action in the routing table
 */
class ActionRecord extends Record {
	private $httpMethod;
	private $uri;
	private $parameters;

	/**
	 * @param $httpMethod
	 * @param $uri
	 * @param $allowedContentTypes
	 * @param $httpsOnly
	 * @param $whiteIPList
	 * @param $blackIPList
	 * @param $filters
	 */
	public function __construct($httpMethod, $uri, array $allowedContentTypes, $httpsOnly, array $whiteIPList, array $blackIPList, array $filters) {
		$this->httpMethod = $httpMethod;
		$this->uri = $uri;
		$this->allowedContentTypes = $allowedContentTypes;
		$this->httpsOnly = $httpsOnly;
		$this->whiteIPList = $whiteIPList;
		$this->blackIPList = $blackIPList;
		$this->filters = $filters;

		// set default pattern for parameters, if URI contains variable parts
		$this->parameters = [];
		$parts = explode('/', trim($uri, '/'));

		foreach ($parts as $part) {
			if (empty($part)) {
				continue;
			}

			if ($part[0] != '<') {
				continue;
			}

			$part = trim($part, '<>');
			$this->parameters[$part] = '[^\/]+';
		}
	}

	/**
	 * Describe URI parameter
	 *
	 * @param string $placeholder
	 * @param string $pattern
	 *
	 * @throws \InvalidArgumentException
	 * 		- if placeholder name is empty
	 * 		- if $pattern is invalid regex
	 * 		- if $placeholder is not found in URI
	 *
	 * @return $this
	 */
	public function parameter($placeholder, $pattern = '.+') {
		#region Check input data
		if (empty($placeholder) || !is_string($placeholder)) {
			throw new \InvalidArgumentException('The parameter name must be a not empty string.');
		}

		$pattern = str_replace('/', '\/', str_replace('\/', '/', $pattern));

		if (@preg_match("/{$pattern}/", null) === false) {
			throw new \InvalidArgumentException("The supplied regex pattern '{$pattern}' is invalid.");
		}

		if (strpos($this->uri, "<{$placeholder}>") === false) {
			throw new \InvalidArgumentException("The supplied parameter '<{$placeholder}>' was not found in the path pattern '{$this->uri}'.");
		}
		#endregion

		$this->parameters[$placeholder] = $pattern;

		return $this;
	}

	/**
	 * Get action record as an array
	 *
	 * array(
	 * 		'method' => string, GET | POST | PUT | DELETE
	 * 		'uri' => string, action URI
	 * 		'parameters' => array, properties regular expression
	 * 		'content_types' => array, list of allowed content types
	 * 		'https_only' => bool, allow unsecured connection or not
	 *		'white_ip_list' => array, list with white IP addresses or network masks
	 * 		'black_ip_list' => array, list with black IP addresses or network masks
	 * 		'filters' => array, list of active filters for this action
	 * )
	 *
	 * @return array
	 */
	public function toArray() {
		return [
			'method' => $this->httpMethod,
			'uri' => $this->uri,
			'parameters' => $this->parameters,
			'content_types' => $this->allowedContentTypes,
			'https_only' => $this->httpsOnly,
			'white_ip_list' => $this->whiteIPList,
			'black_ip_list' => $this->blackIPList,
			'filters' => $this->filters,
		];
	}
}
