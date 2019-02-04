<?php

require_once(dirname(__file__) . '/BaseFilter.php');

class ObjectListFilter extends BaseFilter
{
	protected static $tableMap = array(
		'flavor_asset' => array(
			'required' => array('idIn', 'entryIdIn'),
			'order' => 'int_id',
		),
		'batch_job_sep' => array(
			'required' => array('idIn', 'objectIdIn', 'entryIdIn'),
			'order' => 'id',
		),
		'file_sync' => array(
			'required' => array('idIn', 'objectIdIn'),
			'order' => 'id',
		),
		'metadata' => array(
			'required' => array('idIn', 'objectIdIn'),
			'order' => 'id',
		),
	);

	protected $table;
	protected $columnMap;
	protected $sql;
	protected $values;

	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter);

		if (!isset($filter['table']))
		{
			dieError(ERROR_BAD_REQUEST, 'Missing table');
		}
		$this->table = $table = $filter['table'];

		if (!isset(self::$tableMap[$table]))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid table');
		}

		$this->columnMap = self::getTableColumns($this->table);

		$tableMap = self::$tableMap[$table];

		$found = false;
		$requiredFields = $tableMap['required'];
		foreach ($requiredFields as $inputField)
		{
			if (isset($filter[$inputField]) && $filter[$inputField])
			{
				$found = true;
				break;
			}
		}
		if (!$found)
		{
			dieError(ERROR_BAD_REQUEST, 'Must filter on one of ' . implode(',', $requiredFields));
		}

		$conditions = array();

		$values = array();
		foreach ($this->columnMap as $dbField => $ignore)
		{
			$inputField = self::filterFromColumn($dbField);
			if (!isset($filter[$inputField]) || !$filter[$inputField])
			{
				continue;
			}

			$curValues = array_map('trim', explode(',', $filter[$inputField]));
			$conditions[$dbField] = "$dbField IN (" . rtrim(str_repeat('?,', count($curValues)), ',') . ')';
			$values = array_merge($values, $curValues);
		}


		$this->sql = "SELECT * FROM $table WHERE " . implode(' AND ', $conditions);
		if (isset($tableMap['order']))
		{
			$order = $tableMap['order'];
			$this->sql .= " ORDER BY $order DESC";
		}
		$this->sql .= ' LIMIT 1000';
		$this->values = array_combine(range(1, count($values)), $values);		// change to 1-based
	}

	protected static function getTableColumns($table)
	{
		$cacheKey = false;
		if (function_exists('apcu_fetch'))
		{
			$cacheKey = 'table_columns_' . $table;
			$result = apcu_fetch($cacheKey);
			if ($result)
			{
				return $result;
			}
		}

		$stmt = K::get()->getKelloggsPdo()->queryRetry('DESCRIBE ' . $table);
		if (!$stmt)
		{
			return false;
		}

		$result = array();
		$columnDefs = $stmt->fetchall(PDO::FETCH_ASSOC);
		foreach ($columnDefs as $columnDef)
		{
			$fieldName = $columnDef['Field'];
			$fieldType = $columnDef['Type'];
			$result[$fieldName] = $fieldType;
		}

		if ($cacheKey)
		{
			apcu_store($cacheKey, $result, 86400);
		}

		return $result;
	}

	protected function getTopLevelCommands()
	{
		return array(
			array('label' => 'Copy SQL statement', 'action' => COMMAND_COPY, 'data' => PdoWrapper::formatStatement($this->sql, $this->values)),
		);
	}

	protected function getResponseHeader()
	{
		$columns = array();
		foreach ($this->columnMap as $fieldName => $fieldType)
		{
			$fieldType = $fieldType == 'datetime' ? 'timestamp' : 'text';
			$columns[] = array('label' => ucfirst($fieldName), 'name' => $fieldName, 'type' => $fieldType);
		}

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands(),
			'metadata' => array(), 
		);
	}

	protected function doMain()
	{
		// TODO: support additional response formats

		$header = $this->getResponseHeader();
		echo json_encode($header) . "\n";

		$stmt = K::get()->getKelloggsPdo()->executeStatement($this->sql, $this->values, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);
		if (!$rows)
		{
			dieError(ERROR_NO_RESULTS, 'No rows matched the search filter');
		}

		$primaryKeys = self::getPrimaryKeysMap();
		$primaryKey = isset($primaryKeys[$this->table]) ? strtolower($primaryKeys[$this->table][0]) : null;

		foreach ($rows as $row)
		{
			$commands = array();
			if ($primaryKey)
			{
				$objectId = $row[$primaryKey];
				$commands = array_merge($commands, 
					self::objectInfoCommands(
						$this->table, 
						$objectId),
					self::dbWritesCommands(
						$this->table, 
						$row[$primaryKey], 
						$row));
			}

			foreach ($this->columnMap as $fieldName => $fieldType)
			{
				if ($fieldType == 'datetime' && $row[$fieldName])
				{
					$row[$fieldName] = self::parseDbTime($row[$fieldName]);
				}
			}

			$row['commands'] = $commands;
			echo json_encode($row) . "\n";
		}
	}

	public static function main($params, $filter)
	{
		$obj = new ObjectListFilter($params, $filter);
		$obj->doMain();
	}
}
