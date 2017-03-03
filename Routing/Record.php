<?php
namespace rest\routing;

use \rest\ContentType;
use \rest\Request;
use \rest\Router;

/**
 * Base class for routing records
 */
abstract class Record {
	// prevent setting up properties twice and prevent development mistakes in writing routing table
	private $isSetAllowedContentTypes = false;
	private $isSetHttpsOnly = false;
	private $isSetBlackIpList = false;
	private $isSetWhiteIpList = false;
	private $isSetFilters = false;

	/**
	 * List of allowed content types
	 * @var array
	 */
	protected $allowedContentTypes;

	/**
	 * Allow only secure requests (or non-secure also)
	 * @var bool
	 */
	protected $httpsOnly;

	/**
	 * Blacklist of IP addresses
	 * @var array
	 */
	protected $blackIPList;

	/**
	 * Whitelist of IP addresses
	 * @var array
	 */
	protected $whiteIPList;

	/**
	 * List of filters
	 * @var array
	 */
	protected $filters;

	/**
	 * Sets the allowed response content types.
	 * Any controller can have its own allowed content type list, otherwise defaults to server allowed list.
	 * Any action can have its own allowed content type list, otherwise defaults to controller allowed list.
	 *
	 * @param array|string $contentTypes
	 *
	 * @throws \BadFunctionCallException
	 *      - if allowed content types have already been set
	 *
	 * @throws \InvalidArgumentException
	 *      - if content_type is invalid
	 *
	 * @see ContentType::check_content_type
	 *
	 * @return $this
	 */
	public function allowedContentTypes($contentTypes) {
		if ($this->isSetAllowedContentTypes) {
			throw new \BadFunctionCallException('You are trying to set allowed content type twice for the same route item.');
		}

		$this->isSetAllowedContentTypes = true;

		if (!is_array($contentTypes)) {
			$contentTypes = func_get_args();
		}

		$this->allowedContentTypes = [];

		foreach ($contentTypes as $contentType) {
			if (!ContentType::isValid($contentType)) {
				throw new \InvalidArgumentException("Invalid content type provided: '{$contentType}'");
			}

			if (!in_array($contentType, $this->allowedContentTypes)) {
				$this->allowedContentTypes[] = $contentType;
			}
		}

		return $this;
	}

	/**
	 * Allows only secure connections or not
	 *
	 * @param bool $isSecure - true,1,yes,on = TRUE, else FALSE
	 *
	 * @throws \BadFunctionCallException
	 *      - if HTTPS rules have already been set
	 *
	 * @return $this
	 */
	public function httpsOnly($isSecure) {
		if ($this->isSetHttpsOnly) {
			throw new \BadFunctionCallException('You are trying to set HTTPS rule twice for the same route item.');
		}

		$this->isSetHttpsOnly = true;

		$this->httpsOnly = filter_var($isSecure, FILTER_VALIDATE_BOOLEAN);

		return $this;
	}

	/**
	 * Filter list
	 *
	 * @param mixed $filters
	 *
	 * @throws \BadFunctionCallException
	 *      - if filters have already been set
	 *
	 * @throws \InvalidArgumentException
	 *      - if filter with given name does not exist
	 *
	 * @return $this
	 */
	public function filters($filters = []) {
		if ($this->isSetFilters) {
			throw new \BadFunctionCallException('You are trying to set filters twice for the same route item.');
		}

		$this->isSetFilters = true;

		if (!is_array($filters)) {
			$filters = func_get_args();
		}

		$this->filters = [];

		foreach ($filters as $filter) {
			if (!Router::isFilterExists($filter)) {
				throw new \InvalidArgumentException("Invalid filter name provided: '{$filter}'");
			}

			if (!in_array($filter, $this->filters)) {
				$this->filters[] = $filter;
			}
		}

		return $this;
	}

	/**
	 * Set black list of IP addresses. Omit parameters for an empty list.
	 *
	 * NOTE: you cannot set both of black and white list at the same time
	 *
	 * @param string|array $ips
	 * 		Valid IP address or network mask. It can be wildcard, section or CIDR
	 * 		wildcard: 192.168.0.*
	 * 		section: 192.168.0.0-192.168.0.255
	 * 		CIDR: 192.168.0.0/24
	 *
	 * @throws \BadFunctionCallException
	 *      - if blacklist has already been set
	 *      - if both blacklist and whitelist are set at the same time
	 *
	 * @return $this
	 */
	public function ipBlacklist($ips = []) {
		if ($this->isSetBlackIpList) {
			throw new \BadFunctionCallException('You are trying to set black IP list twice for the same route item.');
		}

		$this->isSetBlackIpList = true;

		if (!is_array($ips)) {
			$ips = func_get_args();
		}

		if (empty($ips)) {
			$this->blackIPList = [];

			return $this;
		}

		if (!empty($this->whiteIPList)) {
			throw new \BadFunctionCallException('You cannot set both of black and white list at the same time. Maybe you forgot to clean white list.');
		}

		self::setIpList($ips, true);

		return $this;
	}

	/**
	 * Set white list of IP addresses. Omit parameters for an empty list.
	 *
	 * NOTE: you cannot set both of black and white list at the same time
	 *
	 * @param string|array $ips
	 * 		Valid IP address or network mask. It can be wildcard, section or CIDR
	 * 		wildcard: 192.168.0.*
	 * 		section: 192.168.0.0-192.168.0.255
	 * 		CIDR: 192.168.0.0/24
	 *
	 * @throws \BadFunctionCallException
	 *      - if whitelist has already been set
	 *      - if both blacklist and whitelist are set at the same time
	 *
	 * @return $this
	 */
	public function ipWhitelist($ips = []) {
		if ($this->isSetWhiteIpList) {
			throw new \BadFunctionCallException('You are trying to set white IP list twice for the same route item.');
		}

		$this->isSetWhiteIpList = true;

		if (!is_array($ips)) {
			$ips = func_get_args();
		}

		if (!empty($ips)) {
			if (!empty($this->blackIPList)) {
				throw new \BadFunctionCallException('You cannot set both of black and white list at the same time. Maybe you forgot to clean black list.');
			}

			self::setIpList($ips, false);
		} else {
			$this->whiteIPList = [];
		}

		return $this;
	}

	/**
	 * Add IPs to a specific list
	 * this function is useful, allow to set network mask as '192.168.0.*' and '192.168.0.0/24'
	 *
	 * @param array $ipList
	 * @param boolean $black
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException
	 *      - if IP list not an array
	 *      - if IP address is not a string
	 *      - if IP address is invalid
	 */
	protected function setIpList(array $ipList, $black) {
		if ($black) {
			$this->blackIPList = [];
		} else {
			$this->whiteIPList = [];
		}

		foreach ($ipList as $ip) {
			if (!is_string($ip)) {
				throw new \InvalidArgumentException('IP address must be a string, but ' . gettype($ip) . ' supplied.');
			}

			if (!Request::getIpType($ip)) {
				throw new \InvalidArgumentException("IP address is invalid: '{$ip}'");
			}

			if ($black) {
				$this->blackIPList[] = $ip;
			} else {
				$this->whiteIPList[] = $ip;
			}
		}
	}
}
