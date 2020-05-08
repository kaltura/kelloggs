<?php

require_once(dirname(__file__) . '/AccessLogFilter.php');

define('PATTERN_API_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_API_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

class ApiAccessLogFilter extends AccessLogFilter
{
	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter, 16, true);
	}

	protected function getGrepCommand()
	{
		parent::doGetGrepCommand(array(LOG_TYPE_API_ACCESS), PATTERN_API_ACCESS, TIME_FORMAT_API_ACCESS);
	}

	public static function main($params, $filter)
	{
		$obj = new ApiAccessLogFilter($params, $filter);
		$obj->doMain();
	}
}
