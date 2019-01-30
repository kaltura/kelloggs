<?php

require_once(dirname(__file__) . '/../shared/DbWritesParser.php');

class BaseFilter
{
	protected $params;
	protected $responseFormat;

	protected static $primaryKeys;

	protected function __construct($params, $filter)
	{
		$this->responseFormat = isset($params['responseFormat']) ? $params['responseFormat'] : RESPONSE_FORMAT_JSON;
		if (!in_array($this->responseFormat, array(RESPONSE_FORMAT_RAW, RESPONSE_FORMAT_DOWNLOAD, RESPONSE_FORMAT_JSON)))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid responseFormat');
		}

		$this->params = $params;
	}

	protected static function getPrimaryKeysMap()
	{
		if (self::$primaryKeys)
		{
			return self::$primaryKeys;
		}

		$useCache = function_exists('apcu_fetch');
		if ($useCache)
		{
			self::$primaryKeys = apcu_fetch('primary_keys_map');
			if (self::$primaryKeys)
			{
				return self::$primaryKeys;
			}
		}

		self::$primaryKeys = DbWritesParser::getPrimaryKeysMap(K::get()->getProdPdo());
		if (!self::$primaryKeys)
		{
			return false;
		}

		if ($useCache)
		{
			apcu_store('primary_keys_map', self::$primaryKeys, 86400);
		}
		return self::$primaryKeys;
	}

	protected static function underscoresToLowerCamel($str)
	{
		$str = str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
		$str[0] = strtolower($str[0]);
		return $str;
	}

	protected static function filterFromColumn($str)
	{
		return self::underscoresToLowerCamel($str) . 'In';
	}

	protected static function parseDbTime($value)
	{
		$dateTime = new DateTime($value);
		return (int)$dateTime->format('U');
	}

	protected static function dbWritesCommands($table, $objectId, $row)
	{
		$toTime = isset($row['updated_at']) && $row['updated_at'] ? self::parseDbTime($row['updated_at']) + 300 : time();
		if (isset($row['deleted_at']) && $row['deleted_at'])
		{
			$toTime = max($toTime, self::parseDbTime($row['deleted_at']) + 300);
		}
		$fromTime = isset($row['created_at']) && $row['created_at'] ? self::parseDbTime($row['created_at']) - 300 : 0;
		$fromTime = max($fromTime, $toTime - 90 * 86400);
		$objectFilter = array(
			'type' => 'dbWritesFilter',
			'fromTime' => $fromTime,
			'toTime' => $toTime,
			'table' => $table,
			'objectId' => $objectId,
		);

		return array(
			array('label' => 'Search updates', 'action' => COMMAND_SEARCH, 'data' => $objectFilter),
			array('label' => 'Search updates in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $objectFilter),
		);
	}
}
