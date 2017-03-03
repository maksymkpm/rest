<?php
namespace rest\components\auth;

use rest\Request;

interface TokenAuth {
	public function __construct(Request $request);

	public function getToken();
}
