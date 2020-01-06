<?php

require_once(dirname(__file__) . '/BaseFilter.php');

class ObjectInfoFilter extends BaseFilter
{
	protected $table;
	protected $objectId;

	protected static $relatedObjectsMap = array(
		'entry' => array(
			'flavor_asset' => array(
				'objectIdColumn' => 'entry_id',
				'groupBy' => array('type'),
			),
			'batch_job_sep' => array(
				'objectIdColumn' => 'entry_id',
				'groupBy' => array('job_type'),
			),
			'metadata' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '1',
				)
			),
			'file_sync' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '1',
				)
			),
		),
		'ui_conf' => array(
			'file_sync' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '2',
				)
			),
		),
		'flavor_asset' => array(
			'batch_job_sep' => array(
				'objectIdColumn' => 'object_id',
				'groupBy' => array('job_type'),
			),
			'file_sync' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '4',
				)
			),
		),
		'metadata' => array(
			'file_sync' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '5',
				)
			),
		),
		'metadata_profile' => array(
			'file_sync' => array(
				'objectIdColumn' => 'object_id',
				'conditions' => array(
					'object_type' => '6',
				)
			),
		),
		'conversion_profile_2' => array(
			'flavor_params_conversion_profile' => array(
				'objectIdColumn' => 'conversion_profile_id',
			),
		),
	);

	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter);

		if (!isset($filter['table']))
		{
			dieError(ERROR_BAD_REQUEST, 'Missing table');
		}
		$this->table = $filter['table'];

		$primaryKeys = self::getPrimaryKeysMap();
		if (!isset($primaryKeys[$this->table]))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid table');
		}

		if (!isset($filter['objectId']))
		{
			dieError(ERROR_BAD_REQUEST, 'Missing objectId');
		}
		$this->objectId = $filter['objectId'];
	}

	protected function getTopLevelCommands($row)
	{
		return self::dbWritesCommands(
			$this->table, 
			$this->objectId, 
			$row);
	}

	protected function getResponseHeader($row)
	{
		$columns = array(
			array('label' => 'Column', 'name' => 'column', 'type' => 'text'),
			array('label' => 'Value', 'name' => 'body', 'type' => 'richText'),
		);

		$metadata = array(
			'Table' => $this->table,
			'Object Id' => $this->objectId,
		);

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands($row),
			'metadata' => self::formatMetadata($metadata), 
		);
	}

	protected function outputRelatedObjects($table, $params)
	{
		$objectIdColumn = $params['objectIdColumn'];
		$objectIdFilter = self::filterFromColumn($objectIdColumn);

		$selectColumns = array('COUNT(1)');

		$primaryKeys = self::getPrimaryKeysMap();
		$primaryKey = null;
		if (isset($primaryKeys[$table]))
		{
			$primaryKey = $primaryKeys[$table][0];
			$selectColumns[] = $primaryKey;
		}

		$groupBy = '';
		if (isset($params['groupBy']))
		{
			$selectColumns = array_merge($selectColumns, $params['groupBy']);
			$groupBy = ' GROUP BY ' . implode(',', $params['groupBy']);
		}
		$selectColumns = implode(',', $selectColumns);

		$sql = "SELECT $selectColumns FROM $table WHERE $objectIdColumn = ?";
		$values = array(
			1 => $this->mainId
		);

		$extraFilters = array();
		if (isset($params['conditions']))
		{
			foreach ($params['conditions'] as $key => $value)
			{
				$sql .= " AND $key = $value";
				$extraFilters[self::filterFromColumn($key)] = $value;
			}
		}
		$sql .= $groupBy;

		$stmt = K::get()->getProdPdo()->executeStatement($sql, $values, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);

		$block = array();
		foreach ($rows as $row)
		{
			$count = $row['COUNT(1)'];
			if (!$count)
			{
				continue;
			}

			unset($row['COUNT(1)']);

			if ($primaryKey)
			{
				$objectId = $row[$primaryKey];
				unset($row[$primaryKey]);
			}

			$line = "$count related objects";
			$groupBy = array();
			foreach ($row as $key => $value)
			{
				$groupBy[] = "$key=$value";
			}

			if ($groupBy)
			{
				$line .= ' with ' . implode(', ', $groupBy);
			}

			if ($count == 1 && $primaryKey)
			{
				$commands = self::objectInfoCommands($table, $objectId);
			}
			else
			{
				$relatedFilter = array(
					'type' => 'objectListFilter',
					'table' => $table,
					$objectIdFilter => $this->mainId,
				);

				foreach ($row as $key => $value)
				{
					$relatedFilter[self::filterFromColumn($key)] = $value;
				}

				$relatedFilter = array_merge($relatedFilter, $extraFilters);

				$commands = array(
					array('label' => 'List related', 'action' => COMMAND_SEARCH, 'data' => $relatedFilter),
					array('label' => 'List related in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $relatedFilter),
				);
			}

			if ($block)
			{
				$block[] = array('text' => "\n");
			}
			$block[] = array('text' => $line, 'commands' => $commands);
		}

		if (!$block)
		{
			return;
		}

		$line = array('column' => $table, 'body' => $block);
		echo json_encode($line) . "\n";
	}

	protected function doMain()
	{
		// TODO: support additional response formats

		$primaryKeys = self::getPrimaryKeysMap();
		if ($this->table == 'flavor_asset' && !ctype_digit($this->objectId))
		{
			$primaryKey = 'id';
		}
		else
		{
			$primaryKey = $primaryKeys[$this->table][0];
		}

		$sql = "SELECT * FROM {$this->table} WHERE {$primaryKey} = ?";
		$values = array(
			1 => $this->objectId
		);
		$stmt = K::get()->getProdPdo()->executeStatement($sql, $values, false);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row)
		{
			dieError(ERROR_NO_RESULTS, 'Cant find the object in the database');
		}

		if ($this->table == 'flavor_asset')
		{
			$this->mainId = $row['id'];
			$this->objectId = $row['int_id'];
		}
		else
		{
			$this->mainId = $this->objectId;
		}

		$header = $this->getResponseHeader($row);
		echo json_encode($header) . "\n";

		$relatedObjects = isset(self::$relatedObjectsMap[$this->table]) ? self::$relatedObjectsMap[$this->table] : array();
		foreach ($relatedObjects as $table => $params)
		{
			$this->outputRelatedObjects($table, $params);
		}

		foreach ($row as $key => $value)
		{
			if (preg_match('/^a:\d+:\{/', $value) || preg_match('/^O:\d+:"/', $value))
			{
				$decodedValue = unserialize($value);
				if ($decodedValue)
				{
					$value = print_r($decodedValue, true);
					$value = preg_replace('/__PHP_Incomplete_Class Object\n(\s*)\(\n\s*\[__PHP_Incomplete_Class_Name\] => (.*)/m', "object \$2\n\$1(", $value);
				}
			}

			$body = array('text' => $value);

			if (endsWith($key, '_id'))
			{
				$foreignTable = substr($key, 0, -3);
				if ($foreignTable == 'conversion_profile')
				{
					$foreignTable .= '_2';
				}
				if (isset($primaryKeys[$foreignTable]))
				{
					$body['commands'] = self::objectInfoCommands($foreignTable, $value);
				}
			}

			$line = array('column' => $key, 'body' => array($body));
			echo json_encode($line) . "\n";
		}
	}

	public static function main($params, $filter)
	{
		$obj = new ObjectInfoFilter($params, $filter);
		$obj->doMain();
	}
}
