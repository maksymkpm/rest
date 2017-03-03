<?php
namespace rest;

/**
 * Main REST server class
 *
 * HOW TO USE:
 * rest\server::init('/home/vhost/public_html/api/v1.0');
 * rest\server::debug_mode_enable();
 * rest\server::set_supported_methods(request::HTTP_GET, request::HTTP_POST);
 *
 * // register filters
 * rest\router::register_filter('filter_name', function() {
 *		// filter logic
 * });
 *
 * // register routing rules
 * rest\router::add_controller('/', 'root')
 * 		->allowed_content_types(content_type::HTML)
 * 		->https_only('no')
 * 		->ip_blacklist('10.10.10.10', '20.20.20.*', '30.30.30.0/24', '40.40.40.1-40.40.40.6')
 * 		->ip_whitelist()
 * 		->filters('filter_1', 'filter_2', ...);
 *
 * rest\router::controller('root')->add_action('GET', '', 'index'); // controller_root::action_index()
 *
 * // run the server
 * $response = server::run();
 *
 * // additional headers
 * $response->set_headers('X-Debug-Memory', memory_get_peak_usage());
 *
 * // send headers and response
 * $response->send();
 *
 * @package rest
 */
abstract class Server {
	const CACHE_DIR = '/cache';
	const CONTROLLER_DIR = '/controller';

	/**
	 * List of HTTP methods supported by this server
	 * Default: all methods
	 *
	 * @type array
	 */
	private static $supportedMethods = [
		Request::HTTP_GET,
		Request::HTTP_POST,
		Request::HTTP_DELETE,
		Request::HTTP_PUT,
		Request::HTTP_OPTIONS,
	];

	/**
	 * @var string[]
	 */
	private static $allowedCrossDomains = [];

	/**
	 * Debug mode enabled (true) / disabled (false)
	 * @var bool
	 */
	private static $debugModeEnable;

	/**
	 * Root folder of the current version of the server
	 * @var string
	 */
	private static $serverRoot;

	/**
	 * Switch on debug mode
	 */
	public static function debugModeEnable() {
		self::$debugModeEnable = true;
	}

	/**
	 * Switch off debug mode
	 */
	public static function debugModeDisable() {
		self::$debugModeEnable = false;
	}

	/**
	 * Check if server is in debug mode
	 *
	 * @return bool
	 */
	public static function isDebugModeEnabled() {
		return self::$debugModeEnable;
	}

	/**
	 * Get path of the server root dir
	 * @return string
	 */
	public static function getServerRootDir() {
		return self::$serverRoot;
	}

	/**
	 * Get path to the cache directory
	 * @return string
	 */
	public static function getCacheDir() {
		return self::$serverRoot . trim(self::CACHE_DIR, '/') . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get path to the controllers directory
	 * @return string
	 */
	public static function getControllerDir() {
		return self::$serverRoot . trim(self::CONTROLLER_DIR, '/') . DIRECTORY_SEPARATOR;
	}

	/**
	 * Set server root folder and initialise error handlers
	 *
	 * @param $serverRoot
	 *
	 * @throws \ErrorException
	 *		- if cache dir does not exist and can't be created
	 *		- if cache dir is not writable
	 *		- if controller dir does not exist
	 */
	public static function init($serverRoot) {
		// Setup error handlers and autoloader for controllers
		set_exception_handler([__CLASS__, 'exceptionHandler']);
		set_error_handler([__CLASS__, 'errorHandler']);
		register_shutdown_function([__CLASS__, 'shutdownHandler']);
		//spl_autoload_register([__CLASS__, 'classLoader']);

		// Set root folder
		if (!is_dir($serverRoot)) {
			throw new \ErrorException('$server_root value is not a valid directory!');
		}

		self::$serverRoot = rtrim($serverRoot, '/\\') . DIRECTORY_SEPARATOR;

		// Check cache directory status and try to create it if it doesn't exist
		$directory = self::getCacheDir();

		if (!is_dir($directory)) {
			@mkdir($directory, 02777);
			@chmod($directory, 02777);
		}

		if (!is_dir($directory)) {
			throw new \ErrorException("The cache directory '{$directory}' does not exist and could not be created!");
		}

		if (!is_writable($directory)) {
			throw new \ErrorException("The cache directory '{$directory}' is not writable!");
		}

		// Check directory with controllers
		$directory = self::getControllerDir();

		if (!is_dir($directory)) {
			throw new \ErrorException("The controller directory '{$directory}' does not exist!");
		}

		// Setup REST server to accept UTF-8
		mb_internal_encoding('UTF-8');
		mb_http_output('UTF-8');
		mb_http_input('UTF-8');
		mb_regex_encoding('UTF-8');
	}

	/**
	 * Run the server
	 *
	 * @return Response
	 *
	 * @throws \Exception
	 * 		- if provided directory does not exist
	 * 		- if cache directory does not exist
	 */
	public static function run() {
		$request = Request::instance();

		// check HTTP method for request is acceptable
		if (!in_array($request->method, self::$supportedMethods)) {
			Response::errorMethod();
		}

		// Cross-Origin Request Support
		if (!empty($request->headers['Origin']) && (in_array($request->headers['Origin'], self::$allowedCrossDomains, true) || in_array('*', self::$allowedCrossDomains, true))) {
			header('Access-Control-Allow-Origin: ' . $request->headers['Origin']);
			header('Vary: ' . (empty($request->headers['Vary']) ? 'Origin' : $request->headers['Vary'] . ', Origin'));

			// If this is an OPTIONS request - set headers and reply immediately
			if ($request->method === Request::HTTP_OPTIONS) {
				header('Access-Control-Allow-Methods: ' . implode(', ', self::$supportedMethods));

				if (!empty($request->headers['Access-Control-Request-Headers'])) {
					header('Access-Control-Allow-Headers: ' .  $request->headers['Access-Control-Request-Headers']);
				}

				header('Access-Control-Max-Age: 86400');

				exit(0);
			}
		}

		// try to find the route
		$route = Router::findRoute($request->method(), $request->uri());

		// $route has structure like:
		// ['controller', 'action', 'parameters', 'secure', 'content_types', 'black_ip_list', 'white_ip_list', 'filters']

		if ($route === false) {
			Response::errorNotFound();
		}

		#region Check request for allowed agent IP
		$agentIp = Request::instance()->userIp();

		if (!empty($route['black_ip_list'])) {
			foreach ($route['black_ip_list'] as $network) {
				if (Request::ipIsInNetwork($agentIp, $network)) {
					Response::errorForbidden("The IP address '{$agentIp}' is in black list for the action: {$route['controller']}@{$route['action']}.");
				}
			}
		} else if (!empty($route['white_ip_list'])) {
			$allowed = false;

			foreach ($route['white_ip_list'] as $network) {
				if (Request::ipIsInNetwork($agentIp, $network)) {
					$allowed = true;

					break;
				}
			}

			if (!$allowed) {
				Response::errorForbidden("The IP address '{$agentIp}' is not in white list for the action: {$route['controller']}@{$route['action']}.");
			}
		}
		#endregion

		#region Check request for allow non-secure connection
		if ($route['secure'] && !$request->isHttps()) {
			Response::errorForbidden("URI '{$request->uri()}' can be called only through a secure connection!");
		}
		#endregion

		#region Check request for allowed content types and select the most wishful
		$contentType = false;

		foreach ($request->contentTypes() as $requestedType) {
			if ($requestedType == '*/*' || $requestedType == 'text/plain') {
				$contentType = $route['content_types'][0];

				break;
			}

			if (in_array($requestedType, $route['content_types'])) {
				$contentType = $requestedType;

				break;
			}
		}

		if (!$contentType) {
			Response::errorContentType($route['content_types']);
		}
		#endregion

		// run filters
		foreach ($route['filters'] as $filter) {
			Router::executeFilter($filter);
		}

		// create controller and execute the action
		$response = Controller::create($route['controller'])->execute($route['action'], $route['parameters'], $contentType);

		return $response;
	}

	/**
	 * Setup the list of the supported HTTP methods.
	 * Call this method only once, before running the server with run() method.
	 *
	 * @param string|array $httpMethods
	 *
	 * @throws \InvalidArgumentException
	 *            - if HTTP method is unsupported
	 */
	public static function setSupportedMethods($httpMethods) {
		if (!is_array($httpMethods)) {
			$httpMethods = func_get_args();
		}

		self::$supportedMethods = [];

		foreach ($httpMethods as $method) {
			switch ($method) {
				case Request::HTTP_GET:
				case Request::HTTP_POST:
				case Request::HTTP_PUT:
				case Request::HTTP_DELETE:
				case Request::HTTP_OPTIONS:
					self::$supportedMethods[] = $method;
					break;
				default:
					throw new \InvalidArgumentException("Invalid HTTP method: '{$method}'.");
			}
		}
	}

	/**
	 * Setup the list of allowed domains for the cross-domain requests
	 *
	 * @param $domainNames
	 *
	 * @throws \InvalidArgumentException
	 * 				- if the domain name is empty or not a string
	 */
	public static function allowCrossDomains($domainNames) {
		if (!is_array($domainNames)) {
			$domainNames = func_get_args();
		}

		self::$allowedCrossDomains = array();

		// if allowing from all * - skip other
		if (in_array('*', $domainNames, true)) {
			self::$allowedCrossDomains = ['*'];

			return;
		}

		foreach ($domainNames as $domainName) {
			if (empty($domainName) || !is_string($domainName)) {
				throw new \InvalidArgumentException('The domain name must be a string.');
			}

			self::$allowedCrossDomains[] = $domainName;
		}
	}

	/**
	 * Get list of HTTP Methods supported by the server
	 *
	 * @return array
	 */
	public static function getSupportedMethods() {
		return self::$supportedMethods;
	}

	/**
	 * Get base URI without host name
	 *
	 * @return string
	 */
	public static function baseUri() {
		return Request::instance()->basePath() . Request::instance()->version();
	}

	/**
	 * Build URI (base URI + relative URI) without hostname
	 *
	 * @param $relativeUri
	 *
	 * @return string
	 */
	public static function uri($relativeUri) {
		return self::baseUri() . '/' . ltrim($relativeUri, '/');
	}

	/**
	 * Loader for controllers. Process only classes with prefix "controller_".
	 *
	 * @param $className
	 *
	 * @throws \Exception
	 * 		- if file with controller does not exist
	 * 		- if file with controller does not contain a controller class
	 */
	public static function classLoader($className) {
		if (class_exists($className)) {
			return;
		}

		if (strpos($className, 'controller') === 0) {
			$controllerName = substr($className, 10);
			$file = self::getControllerDir() . $controllerName . '.php';

			if (!is_file($file)) {
				throw new \Exception("File with controller '{$controllerName}' is not found. It must be in '{$file}'.");
			}

			require_once($file);

			if (!class_exists($className)) {
				throw new \Exception("File '{$file}' does not contain controller class with name '{$className}'.");
			}
		}
	}

	#region Error and Exception handlers: call response::error_500()
	/**
	 * Catch fatal errors which are not handled by error_handler()
	 */
	public static function shutdownHandler() {
		$error = error_get_last();

		if ($error) {
			self::exceptionHandler(new \ErrorException($error['message'], $error['type'], 0, $error['file'], $error['line']));

			// kill script to avoid looping
			exit(1);
		}
	}

	/**
	 * Catch PHP errors and convert them to ErrorException so there is only one point for error processing
	 *
	 * @param $errorCode
	 * @param $message
	 * @param null $file
	 * @param null $line
	 *
	 * @return bool
	 *
	 * @throws \ErrorException
	 */
	public static function errorHandler($errorCode, $message, $file = null, $line = null) {
		if (error_reporting() & $errorCode) {
			// convert PHP error to Error Exception
			throw new \ErrorException($message, $errorCode, 0, $file, $line);
		}

		// do not call internal PHP error handler
		return true;
	}

	/**
	 * One point for caching all error types
	 *
	 * @param \Exception $exception
	 */
	public static function exceptionHandler($exception) {
		$message = sprintf('%s; %s[%s]', $exception->getMessage(), $exception->getFile(), $exception->getLine());

		Response::error500($message);
	}
	#endregion
}
