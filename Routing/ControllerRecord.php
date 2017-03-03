<?php
namespace rest\routing;

use rest\Request;
use rest\Router;

/**
 * Class defining a controller in the routing table
 */
class ControllerRecord extends Record {
	/**
	 * List of action records
	 * @var ActionRecord[]
	 */
	private $actions = [];

	/**
	 * URI prefix of the controller. All URIs starting with this text will try to find their actions in this controller
	 * @var string
	 */
	private $uriPrefix;

	/**
	 * @param $uriPrefix
	 * @param array $allowedContentTypes
	 * @param $httpsOnly
	 * @param array $whiteIpList
	 * @param array $blackIpList
	 */
	public function __construct($uriPrefix, array $allowedContentTypes, $httpsOnly, array $whiteIpList, array $blackIpList) {
		$this->uriPrefix = $uriPrefix;
		$this->allowedContentTypes = $allowedContentTypes;
		$this->httpsOnly = $httpsOnly;
		$this->whiteIPList = $whiteIpList;
		$this->blackIPList = $blackIpList;
		$this->filters = [];
	}

	/**
	 * Add new action to the controller
	 *
	 * @param string $http_method
	 * @param string $uri
	 * @param string $action_name
	 *
	 * @throws \LogicException
	 *		- if compilation is already completed
	 *
	 * @throws \InvalidArgumentException
	 *		- if $http_method is unsupported
	 *		- if Action name is not a string or is empty
	 *		- if Action has already been added to routing map
	 *
	 * @return ActionRecord
	 */
	public function addAction($http_method, $uri, $action_name) {
		if (Router::isInitialized()) {
			throw new \LogicException('Router is already completed! You cannot add new record after finalize the router.');
		}

		#region Check input data
		if (!Request::isHttpMethodValid($http_method)) {
			throw new \InvalidArgumentException("Invalid HTTP method: '{$http_method}''!");
		}

		if (!is_string($action_name)) {
			throw new \InvalidArgumentException("Action name must be a string!");
		}

		if (empty($action_name)) {
			throw new \InvalidArgumentException("Action name cannot be empty!");
		}

		if (isset($this->actions[$action_name])) {
			throw new \InvalidArgumentException("Action with name {$action_name} already exists in the controller!");
		}
		#endregion

		// create action record with default values from the controller
		$action = new ActionRecord(
			strtoupper($http_method),
			trim($uri, '/ '),
			$this->allowedContentTypes,
			$this->httpsOnly,
			$this->whiteIPList,
			$this->blackIPList,
			$this->filters
		);

		$this->actions[$action_name] = $action;

		return $action;
	}

	/**
	 * Get controller record as array
	 *
	 * array(
	 * 		'prefix' => string, // URI prefix of the controller
	 *		'actions' => array(  // list of the actions
	 * 			'action_name' => array(...),
	 * 			...
	 * 		),
	 * )
	 *
	 * @see ActionRecord::toArray
	 *
	 * @return array
	 */
	public function toArray() {
		$result = array(
			'prefix' => $this->uriPrefix,
			'actions' => [],
		);

		foreach ($this->actions as $name => $action) {
			$result['actions'][$name] = $action->toArray();
		}

		return $result;
	}
}
