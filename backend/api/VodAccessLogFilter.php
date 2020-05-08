<?php

require_once(dirname(__file__) . '/AccessLogFilter.php');

define('PATTERN_VOD_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_VOD_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

class VodAccessLogFilter extends AccessLogFilter
{
	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter, 28, false);
	}

	protected function getGrepCommand()
	{
		parent::doGetGrepCommand(array(LOG_TYPE_VOD_ACCESS), PATTERN_VOD_ACCESS, TIME_FORMAT_VOD_ACCESS);
	}

	public static function main($params, $filter)
	{
		$obj = new VodAccessLogFilter($params, $filter);
		$obj->doMain();
	}
}
