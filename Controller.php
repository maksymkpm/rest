<?php
namespace rest;

/**
 * Base class for all controllers
 */
abstract class Controller {
	/**
	 * Prevent creation of more than one controller
	 * @var Controller
	 */
	private static $instance;

	/**
	 * Prevent running the action twice
	 * @var bool
	 */
	private static $hasRun;

	/**
	 * Time spent running the action
	 * @var float
	 */
	private $actionRunTime = 0;

	/**
	 * Request object
	 * @var Request
	 */
	protected $request;

	/**
	 * Response object
	 * @var Response
	 */
	protected $response;

	/**
	 * Called action
	 * @var string
	 */
	protected $action;

	/**
	 * Requested content type
	 * @var string
	 */
	protected $contentType;

	/**
	 * Create controller object
	 *
	 * @param $controllerName
	 *
	 * @return Controller
	 *
	 * @throws \BadMethodCallException
	 * 		- if controller has been already created
	 */
	public static function create($controllerName) {
		if (isset(self::$instance)) {
			throw new \BadMethodCallException('The controller has been already created!');
		}

		// server autoloader will find controller class file
		self::$instance = new $controllerName();

		return self::$instance;
	}

	/**
	 * Return current controller object
	 *
	 * @return Controller
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Execute the action
	 *
	 * @param string $action
	 * 		Action name
	 * @param array $parameters
	 * 		Array with request URL parameters
	 * @param string $contentType
	 * 		Wishful content type of the response
	 *
	 * @return array
	 *
	 * @throws \BadMethodCallException
	 *      - if action has already run
	 *      - if action method does not exist
	 *      - if action method is uncallable
	 *      - if validation for action does not exist
	 *      - if validation for action is uncallable
	 */
	public function execute($action, array $parameters, $contentType) {
		if (isset(self::$hasRun)) {
			throw new \BadMethodCallException('The action has already run!');
		}

		// prevent running the action twice
		self::$hasRun = true;

		$ucAction = str_replace(' ', '', ucwords(implode(' ', explode('_', $action))));

		$actionMethod = 'action' . $ucAction;
		$validationMethod = 'validate' . $ucAction;

		if (!method_exists($this, $actionMethod)) {
			throw new \BadMethodCallException("There isn't a method '{$actionMethod}' for action '{$action}' in the controller: " . get_class($this));
		}

		if (!is_callable(array($this, $actionMethod))) {
			throw new \BadMethodCallException("The method '{$actionMethod}' in the controller '" . get_class($this) . "' is private.");
		}

		$this->action = $ucAction;
		$this->contentType = $contentType;
		$this->request = Request::instance();
		$this->response = new Response($contentType);

		$start_time = microtime(true);

		$this->runBeforeAction();

		if (method_exists($this, $validationMethod)) {
			if (!is_callable(array($this, $validationMethod))) {
				throw new \BadMethodCallException("The validation method '{$validationMethod}' exists in the controller '" . get_class($this) . "', but it is private.");
			}

			// run the validation method for GET/POST values and request parameters
			call_user_func_array(array($this, $validationMethod), $parameters);
		}

		// run the action
		call_user_func_array(array($this, $actionMethod), $parameters);

		$this->runAfterAction();

		$this->actionRunTime = round(microtime(true) - $start_time, 3);

		return $this->response;
	}

	/**
	 * Time spent running the action
	 * @return float
	 *
	 * @throws \BadMethodCallException
	 * 		- if the action is not executed yet
	 */
	public function getActionRunTime() {
		return $this->actionRunTime;
	}

	/**
	 * Response with error
	 *
	 * @param array $errorList numeric array with list of errors or named ['<field_name>' => 'error',... ]
	 */
	protected function error(array $errorList) {
		$responseData = [
			'success' => false,
		];

		if (array_key_exists(0, $errorList)) {
			$responseData['error'] = $errorList;
		} else {
			$responseData['validation'] = $errorList;
		}

		$this->response->set($responseData);
	}

	/**
	 * The method to run before action is executed
	 */
	protected abstract function runBeforeAction();

	/**
	 * The method to run after action is executed
	 */
	protected abstract function runAfterAction();
}
