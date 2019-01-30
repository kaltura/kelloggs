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

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands($row),
			'metadata' => array(), 
		);
	}

	protected function outputRelatedObjects($table, $params)
	{
		$objectIdColumn = $params['objectIdColumn'];
		$objectIdFilter = self::filterFromColumn($objectIdColumn);

		$selectColumns = array('COUNT(1)');
		$groupBy = '';
		if (isset($params['groupBy']))
		{
			$selectColumns = array_merge($selectColumns, $params['groupBy']);
			$groupBy = ' GROUP BY ' . implode(',', $params['groupBy']);
		}
		$selectColumns = implode(',', $selectColumns);

		$sql = "SELECT $selectColumns FROM $table WHERE $objectIdColumn = ?";
		$values = array(
			1 => $this->objectId
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

		$stmt = K::get()->getKelloggsPdo()->executeStatement($sql, $values, false);
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

			$relatedFilter = array(
				'type' => 'objectListFilter',
				'table' => $table,
				$objectIdFilter => $this->objectId,
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
		$primaryKey = $primaryKeys[$this->table][0];

		$sql = "SELECT * FROM {$this->table} WHERE {$primaryKey} = ?";
		$values = array(
			1 => $this->objectId
		);
		$stmt = K::get()->getKelloggsPdo()->executeStatement($sql, $values, false);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row)
		{
			dieError(ERROR_NO_RESULTS, 'Cant find the object in the database');
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
			if (preg_match('/^a:\d+:\{/', $value))
			{
				$decodedValue = unserialize($value);
				if ($decodedValue)
				{
					$value = print_r($decodedValue, true);
				}
			}

			$body = array('text' => $value);

			if (endsWith($key, '_id'))
			{
				$foreignTable = substr($key, 0, -3);
				if (isset($primaryKeys[$foreignTable]))
				{
					$objectFilter = array(
						'type' => 'objectInfoFilter',
						'table' => $foreignTable,
						'objectId' => $value
					);

					$commands = array(
						array('label' => 'Go to object', 'action' => COMMAND_SEARCH, 'data' => $objectFilter),
						array('label' => 'Go to object in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $objectFilter),
					);
					$body['commands'] = $commands;
				}
			}

			$line = array('column' => $key, 'body' => $body);
			echo json_encode($line) . "\n";
		}
	}

	public static function main($params, $filter)
	{
		$obj = new ObjectInfoFilter($params, $filter);
		$obj->doMain();
	}
}
