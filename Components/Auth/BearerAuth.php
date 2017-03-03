<?php
namespace rest\components\auth;

use rest\Request;

class BearerAuth implements TokenAuth {
	protected $request;

	public function __construct(Request $request) {
		$this->request = $request;
	}

	public function getToken(): ?string {
		$headers = $this->request->headers;

		if (isset($headers['Authorization']) && preg_match('/^Bearer\s+(.*?)$/', $headers['Authorization'], $matches)) {
			return $matches[1];
		}

		return null;
	}
}
