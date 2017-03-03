<?php
namespace rest;

use \rest\routing\ControllerRecord;

/**
 * Contains routing map and allows to build routing table
 *
 * @package rest
 */
class Router extends routing\Record {
	const CACHE_FILE_NAME = 'routing_table.cache';
	const CACHE_LIFETIME = 3600;

	/**
	 * Registered routing table
	 *
*@var ControllerRecord[]
	 */
	private static $map = [];

	/**
	 * Compiled routing table as plane array
	 * @var array
	 */
	private static $cachedMap;

	/**
	 * If we are trying to load from cache, we must save cache after compilation for the next time
	 * @see Router::try_load_from_cache()
	 * @var bool
	 */
	private static $saveCache = false;

	/**
	 * Registered filters
	 * @var array
	 */
	private static $registeredFilters = [];

	/**
	 * Instance of the class for keeping default routing settings
	 * @var Router
	 */
	private static $instance;

	/**
	 * Get current router instance
	 * We are using singleton because we need to use base class non-static logic.
	 * For public usage we provide static functions because it requires less code to be written
	 *
	 * @return Router
	 */
	private static function instance() {
		if (!isset(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor is private to prevent creating a new instance of the class via the 'new' operator from outside this class
	 */
	private function __construct() {
		// set default values
		$this->allowedContentTypes = [ContentType::JSON];
		$this->httpsOnly = false;
		$this->whiteIPList = [];
		$this->blackIPList = [];
	}

	private function __clone() {}
	private function __sleep() {}
	private function __wakeup() {}

	/**
	 * Check if router already completed building routing table.
	 * You cannot add controller record or action record, if router compilation has already completed.
	 *
	 * @return bool
	 */
	public static function isInitialized() {
		return isset(self::$cachedMap);
	}

	/**
	 * Try load compiled routing table from cache and return TRUE if successful.
	 *
	 * @return bool
	 */
	public static function loadFromCache() {
		if (Server::isDebugModeEnabled()) {
			// in debug mode the server never uses caching
			return false;
		}

		// If we are trying to load routing from cache, we must save cache after compilation for the next time
		self::$saveCache = true;

		if (self::isInitialized()) {
			// prevent calling this method a second time
			return true;
		}

		$fileName = Server::getCacheDir() . DIRECTORY_SEPARATOR . self::CACHE_FILE_NAME;

		if (!is_file($fileName) || !is_readable($fileName)) {
			return false;
		}

		if (time() > (filemtime($fileName) + self::CACHE_LIFETIME)) {
			// cache file is expired, delete it and re-render routing table
			unlink($fileName);

			return false;
		}

		$content = file_get_contents($fileName);

		if ($content) {
			$map = @unserialize($content);

			if (!empty($map) && is_array($map)) {
				self::$cachedMap = $map;

				return true;
			}
		}

		// cache file contains wrong data
		unlink($fileName);

		return false;
	}

	/**
	 * Set default list of allowed content types. Controllers and actions are able to rewrite this value.
	 *
	 * @param mixed $contentTypes
	 * 		you can provide data several ways:
	 *		as list of function properties:  default_allowed_content_types('type_1','type_2',..)
	 * 		as array of properties: default_allowed_content_types(array('type_1','type_2',..))
	 *
	 */
	public static function setAllowedContentTypes($contentTypes) {
		if (!is_array($contentTypes)) {
			$contentTypes = func_get_args();
		}

		self::instance()->allowedContentTypes($contentTypes);
	}

	/**
	 * Set default rule for unsecured requests. Controllers and actions are able to rewrite this value.
	 *
	 * @param mixed $httpsOnly
	 * 		1, 'true', 'on', 'yes' - TRUE
	 * 		0, 'false', 'off', 'no' - FALSE
	 */
	public static function setHttpsOnly($httpsOnly) {
		self::instance()->httpsOnly($httpsOnly);
	}

	/**
	 * Set default white list of IP addresses. Omit parameters for an empty list.
	 * Controllers and actions can rewrite this property.
	 *
	 * NOTE: you cannot set both of black and white list at the same time
	 *
	 * @param string|array $ips
	 * 		Valid IP address or network mask.
	 * 		wildcard: 192.168.0.*
	 * 		section: 192.168.0.0-192.168.0.255
	 * 		mask: 192.168.0.0/24
	 *
	 * @throws \BadFunctionCallException
	 *      - if set both of black and white list at the same time
	 * @throws \InvalidArgumentException
	 *      - IP list not array
	 *      - IP address is invalid
	 *      - IP address must be a string
	 */
	public static function setIpWhitelist($ips = []) {
		if (!is_array($ips)) {
			$ips = func_get_args();
		}

		self::instance()->ipWhitelist($ips);
	}

	/**
	 * Set default white list of IP addresses. Omit parameters for an empty list.
	 * Controllers and actions can rewrite this property.
	 *
	 * NOTE: you cannot set both of black and white list at the same time
	 *
	 * @param string|array $ips
	 * 		Valid IP address or network mask.
	 * 		wildcard: 192.168.0.*
	 * 		section: 192.168.0.0-192.168.0.255
	 * 		mask: 192.168.0.0/24
	 *
	 * @throws \BadFunctionCallException
	 *      - if set both of black and white list at the same time
	 * @throws \InvalidArgumentException
	 *      - IP list not array
	 *      - IP address is invalid
	 *      - IP address must be a string
	 */
	public static function setIpBlacklist($ips = []) {
		if (!is_array($ips)) {
			$ips = func_get_args();
		}

		self::instance()->ipBlacklist($ips);
	}

	/**
	 * Add a controller
	 *
	 * @param string $uriPrefix
	 * @param string $controllerName
	 *
	 * @throws \LogicException
	 *		- if compilation is already completed
	 *
	 * @throws \InvalidArgumentException
	 *		- if Controller URI is not a string
	 *		- if Controller URI contains invalid characters
	 *		- if Controller name is not a string or is empty
	 *		- if Controller has already been added to routing map
	 *
	 * @return ControllerRecord
	 */
	public static function addController($uriPrefix, $controllerName) {
		if (self::isInitialized()) {
			throw new \LogicException('Router is already completed! You cannot add new record after finalize the router.');
		}

		#region Check input data
		if (!is_string($uriPrefix)) {
			throw new \InvalidArgumentException('URI must be a string, "' . gettype($uriPrefix) . '" was given.');
		}

		$uriPrefix = trim($uriPrefix, '/ ');

		if (!empty($uriPrefix) && !preg_match('/^([a-zA-Z0-9-_]+\/?)+$/', $uriPrefix)) {
			throw new \InvalidArgumentException('Controller URI must contain only latin letters, numbers, and "/_-", and cannot contain two or more "/" in sequence. "' . $uriPrefix . '" was given.');
		}

		if (!is_string($controllerName)) {
			throw new \InvalidArgumentException('controller_name must be a string, ' . gettype($controllerName) . ' was given!');
		}

		if (empty($controllerName)) {
			throw new \InvalidArgumentException('controller_name cannot be an empty string!');
		}

		if (isset(self::$map[$controllerName])) {
			throw new \InvalidArgumentException("Routing with controller name '{$controllerName}' already exists!");
		}
		#endregion

		self::$map[$controllerName] = new ControllerRecord(
			$uriPrefix,
			self::instance()->allowedContentTypes,
			self::instance()->httpsOnly,
			self::instance()->whiteIPList,
			self::instance()->blackIPList
		);

		return self::$map[$controllerName];
	}

	/**
	 * Get routing record for controller by name
	 *
	 * @param string $controllerName
	 *
	 * @throws \InvalidArgumentException
	 *		- if Controller name is not a string or is empty
	 *		- if Controller with given name does not exist in routing map
	 *
	 * @return ControllerRecord
	 */
	public static function controller($controllerName) {
		#region Check input data
		if (!is_string($controllerName)) {
			throw new \InvalidArgumentException('controller_name must be a string!');
		}

		if (empty($controllerName)) {
			throw new \InvalidArgumentException('controller_name cannot be an empty string!');
		}

		if (!isset(self::$map[$controllerName])) {
			throw new \InvalidArgumentException("Routing record with controller name '{$controllerName}' does not exist!");
		}
		#endregion

		return self::$map[$controllerName];
	}

	/**
	 * Register a filter function
	 *
	 * @param string $filterName
	 * @param callable $filterFunction
	 *
	 * @throws \InvalidArgumentException
	 * 		- if filter name is not a string
	 * 		- if a filter with the given name already exists
	 * 		- if given function is not callable
	 */
	public static function registerFilter($filterName, $filterFunction) {
		#region Check input data
		if (!is_string($filterName)) {
			throw new \InvalidArgumentException('Filter name must be a string!');
		}

		if (isset(self::$registeredFilters[$filterName])) {
			throw new \InvalidArgumentException("Filter with name '{$filterName}', already exists.");
		}

		if (!is_callable($filterFunction)) {
			throw new \InvalidArgumentException("Function '{$filterFunction}', is not callable.");
		}
		#endregion

		self::$registeredFilters[$filterName] = $filterFunction;
	}

	/**
	 * Check if a filter exists with the given name
	 *
	 * @param string $filterName
	 *
	 * @return bool
	 */
	public static function isFilterExists($filterName) {
		return isset(self::$registeredFilters[$filterName]);
	}

	/**
	 * Execute filter by name
	 *
	 * @param $filterName
	 *
	 * @throws \InvalidArgumentException
	 * 		- if a filter with the given name does not exist
	 */
	public static function executeFilter($filterName) {
		if (!self::isFilterExists($filterName)) {
			throw new \InvalidArgumentException("Filter with name '{$filterName}' is not registered!");
		}

		$filter = self::$registeredFilters[$filterName];

		$filter();
	}

	/**
	 * Based on URI, try to find the action and return array with data:
	 * array(
	 * 		'controller' => string, // controller_name
	 * 		'action' => string, // action_name
	 * 		'parameters' => string[], // values of the request parameters
	 * 		'secure' => bool, // allow only HTTPS
	 * 		'content_types' => string[], // list of allowed content types
	 * 		'black_ip_list' => string[],
	 * 		'white_ip_list' => string[],
	 * 		'filters' => string[], // list of filters for this action
	 * )
	 *
	 * @param $httpMethod
	 * 		Requested HTTP method
	 * @param $uri
	 * 		Requested URI
	 *
	 * @throws \InvalidArgumentException
	 * 		- if variable URI part does not exist for the controller+action
	 *
	 * @return array|false
	 */
	public static function findRoute($httpMethod, $uri) {
		// URI is trimmed with "/", root request has empty string URI
		// controller prefix is trimmed with "/", prefix for the root controller is empty string
		// action URI is trimmed with "/", root controller default action has empty string URI
		$httpMethod = strtoupper($httpMethod);
		$map = self::getRoutingTable();

		foreach ($map as $controllerName => $controller) {
			if (empty($uri) && !empty($controller['prefix'])) {
				// empty URI but not root controller
				continue;
			}

			if (empty($controller['prefix']) || $uri == $controller['prefix'] || strpos($uri, $controller['prefix'] . '/') === 0) {
				// URI begins from controller prefix, try to find the action in this controller
				$actionUri = $controller['prefix'] !== '' ? substr($uri, strlen($controller['prefix']) + 1) : $uri;

				foreach ($controller['actions'] as $actionName => $action) {
					if ($httpMethod != $action['method']) {
						continue;
					}

					$parameters = []; // will contain parameters from URI

					if (strpos($action['uri'], '<') === false) {
						// action without parameters, we need strong equivalence
						if ($action['uri'] != $actionUri) {
							continue;
						}
					} else {
						// action with parameters, split URI on parts and check parts one by one
						$actionParts = explode('/', $action['uri']);
						$uriParts = explode('/', $actionUri);
						$actionPartsCount = count($actionParts);

						if ($actionPartsCount != count($uriParts)) {
							// uri and action have different amount of parts
							continue;
						}

						// check uri parts with action parts one by one
						for ($i = 0; $i < $actionPartsCount; $i++) {
							$actionPart = $actionParts[$i];
							$uriPart = $uriParts[$i];

							if ($actionPart[0] != '<') {
								// constant part
								if ($actionPart != $uriPart) {
									// next action
									continue 2;
								}
							} else {
								// variable part
								$partName = trim($actionPart, '<>');

								if (!isset($action['parameters'][$partName])) {
									throw new \InvalidArgumentException("Variable URI part with name '{$partName}' is not set for the controller@action: '{$controllerName}@{$actionName}'.");
								}

								$partRegex = $action['parameters'][$partName];
								$uriPart = urldecode($uriPart);

								if (!preg_match("/^{$partRegex}$/", $uriPart)) {
									// next action
									continue 2;
								}

								$parameters[] = $uriPart; // collect parameters from URI
							}
						}
					}

					// action pass all checks
					return [
						'controller' => $controllerName,
						'action' => $actionName,
						'parameters' => $parameters,
						'secure' => $action['https_only'],
						'content_types' => $action['content_types'],
						'white_ip_list' => $action['white_ip_list'],
						'black_ip_list' => $action['black_ip_list'],
						'filters' => $action['filters'],
					];
				}
			}
		}

		return false;
	}

	/**
	 * Compile routing table to array and save to cache
	 *
	 * @return array
	 */
	private static function getRoutingTable() {
		if (!isset(self::$cachedMap)) {
			self::$cachedMap = [];

			foreach (self::$map as $name => $controller) {
				self::$cachedMap[$name] = $controller->toArray();
			}

			if (self::$saveCache) {
				// save routing table to cache, because tried to load it unsuccessfully
				$map = serialize(self::$cachedMap);

				if ($map) {
					$fileName = Server::getCacheDir() . DIRECTORY_SEPARATOR . self::CACHE_FILE_NAME;
					file_put_contents($fileName, $map);
				}
			}
		}

		return self::$cachedMap;
	}

	/**
	 * Cross check routing table. Debug self diagnostic.
	 * Return array with errors or TRUE is success.
	 *
	 * @return true|string[]
	 */
	public static function diagnostic() {
		$map = self::getRoutingTable();

		$errors = [];

		foreach ($map as $controllerName => $controller) {
			foreach ($controller['actions'] as $actionName => $action) {
				$result = self::diagnosticAction($controllerName, $actionName, $controller);

				if ($result !== true) {
					$errors[] = $result;
				}
			}
		}

		return empty($errors) ? true : $errors;
	}

	/**
	 * Check one action with others
	 *
	 * @param $checkControllerName
	 * @param $checkActionName
	 * @param $checkController
	 *
	 * @return true|string
	 */
	private static function diagnosticAction($checkControllerName, $checkActionName, $checkController) {
		$map = self::getRoutingTable();

		$checkAction = $checkController['actions'][$checkActionName];
		$checkUri = (empty($checkController['prefix']) ? '' : $checkController['prefix'] . '/') . $checkAction['uri'];

		foreach ($map as $controllerName => $controller) {
			foreach ($controller['actions'] as $actionName => $action) {
				if ($controllerName == $checkControllerName && $checkActionName == $actionName) {
					continue;
				}

				$actionUri = (empty($controller['prefix']) ? '' : $controller['prefix'] . '/') . $action['uri'];

				if (strpos($checkUri, '<') !== false) {
					// variable uri allowed
					$checkParts = explode('/', $checkUri);
					$actionParts = explode('/', $actionUri);

					$count = count($checkParts);

					if ($count != count($actionParts)) {
						continue;
					}

					for ($i = 0; $i < $count; $i++) {
						$checkPart = $checkParts[$i];
						$actionPart = $actionParts[$i];

						if ($checkPart[0] == '<' || $actionPart[0] == '<') {
							// variable part can be equal to anything, check next part of URI
							continue;
						} else if ($checkPart != $actionPart) {
							// static parts are difference - routes are difference, go to next action
							continue 2;
						}
					}
				} else if ($checkUri != $actionUri) {
					continue;
				}

				// URIs are equal
				return "Cross routing: action '{$checkActionName}' in controller '{$checkControllerName}' is conflicted with action '{$actionName} in controller '{$controllerName}'" . PHP_EOL .
					$checkUri . PHP_EOL .
					$actionUri;
			}
		}

		return true;
	}
}
