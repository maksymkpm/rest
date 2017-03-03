<?php
namespace rest;

/**
 * Contains a list of all allowed content types and validation method
 */
abstract class ContentType {
	const JSON = 'application/json';
	const XML = 'application/xml';

	const TEXT = 'text/plain';
	const HTML = 'text/html';

	const JPG = 'image/jpeg';
	const PNG = 'image/png';
	const GIF = 'image/gif';

	const PDF = 'application/pdf';
	const EXCEL = 'application/vnd.xls';
	const WORD = 'application/ms-word';
	const CSV = 'text/csv';

	const BINARY = 'application/octet-stream';

	private static $contentTypes;

	/**
	 * Check if content type string is a valid and allowed content type
	 *
	 * @param $contentType
	 *
	 * @return bool
	 */
	public static function isValid($contentType) {
		if (!isset(self::$contentTypes)) {
			// build a list of all allowed content types from constants of the class
			$class = new \ReflectionClass(__CLASS__);
			self::$contentTypes = $class->getConstants();
		}

		return in_array($contentType, self::$contentTypes);
	}
}
