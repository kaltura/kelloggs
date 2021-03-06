<?php

class DbWritesParser
{
	const STMT_PREFIX_SELECT = 'SELECT ';
	const STMT_PREFIX_INSERT = 'INSERT INTO ';
	const STMT_PREFIX_REPLACE = 'replace into ';
	const STMT_PREFIX_UPDATE = 'UPDATE ';
	const STMT_PREFIX_DELETE = 'DELETE FROM ';

	const MODE_DB_WRITES = 'db';
	const MODE_SPHINX_WRITES = 'sphinx';

	protected $primaryKeys;
	protected $timestamp;
	protected $insertId;

	protected static function parseTimestamp($ts)
	{
		list($year, $month, $day, $hour, $minute, $second) = sscanf($ts, '%d-%d-%d %d:%d:%d');
		return mktime($hour, $minute, $second, $month, $day, $year);
	}

	public static function getPrimaryKeysMap($pdo)
	{
		$stmt = $pdo->queryRetry('SHOW TABLES');
		if (!$stmt)
		{
			return false;
		}
		$tables = $stmt->fetchall(PDO::FETCH_NUM);
		$tables = array_map('reset', $tables);

		$result = array();
		foreach ($tables as $table)
		{
			$stmt = $pdo->queryRetry('DESCRIBE ' . $table);
			if (!$stmt)
			{
				return false;
			}
			$columns = $stmt->fetchall(PDO::FETCH_ASSOC);

			$pk = null;
			foreach ($columns as $column)
			{
				if ($column['Key'] == 'PRI')
				{
					if ($pk)
					{
						// multiple columns - unsupported
						$pk = null;
						break;
					}
					$pk = $column;
				}
			}

			if (!$pk)
			{
				continue;
			}

			$field = $pk['Field'];
			$autoIncrement = strpos($pk['Extra'], 'auto_increment') !== false;
			$result[$table] = array(strtoupper($field), $autoIncrement);
		}
		return $result;
	}

	public static function getInsertValues($statement, $field = null, $valuesDelim = ') VALUES (')
	{
		// find the columns and values
		$colsStart = strpos($statement, '(');
		$valuesStart = strpos($statement, $valuesDelim);
		if ($colsStart === false || $valuesStart === false)
		{
			return false;
		}

		// get the field index in the column list
		$columns = substr($statement, $colsStart + 1, $valuesStart - $colsStart - 1);
		$columns = explode(',', $columns);

		if ($field)
		{
			$fieldIndex = array_search("`$field`", $columns);
			if ($fieldIndex === false)
			{
				return false;
			}
		}
		else
		{
			$fieldIndex = false;
		}

		$curPos = $valuesStart + strlen($valuesDelim);
		for (;;)
		{
			// extract a value
			if ($statement[$curPos] == "'")
			{
				$nextPos = $curPos + 1;
				for (;;)
				{
					$nextPos = strpos($statement, "'", $nextPos);
					if ($nextPos === false)
					{
						return false;
					}
					$nextPos++;

					if ($statement[$nextPos - 2] == '\\')		// \' escape (Note: this is not entirely accurate - doesn't handle \\')
					{
						continue;
					}

					if ($statement[$nextPos] == "'")			// '' escape
					{
						$nextPos++;
						continue;
					}

					if (!in_array($statement[$nextPos], array(',', ')')))
					{
						return false;
					}

					break;
				}
			}
			else
			{
				$nextPos = strpos($statement, ',', $curPos);
				if ($nextPos === false)
				{
					$nextPos = strpos($statement, ')', $curPos);
					if ($nextPos === false)
					{
						return false;
					}
				}
			}

			if ($fieldIndex === false)
			{
				$values[] = substr($statement, $curPos, $nextPos - $curPos);
				if (count($values) > count($columns))
				{
					return false;
				}

				if ($statement[$nextPos] != ',')
				{
					return count($columns) == count($values) ? array_combine($columns, $values) : false;
				}
			}
			else
			{
				if ($fieldIndex == 0)
				{
					// got the required value
					$value = substr($statement, $curPos, $nextPos - $curPos);
					return trim($value, "'");
				}

				if ($statement[$nextPos] != ',')
				{
					return false;
				}
				$fieldIndex--;
			}

			// continue to the next value
			$curPos = $nextPos + 1;
		}
	}

	public static function getFieldValueFromWhere($statement, $tableName, $field)
	{
		$condStr = $tableName . '.' . $field . '=';
		$condPos = strpos($statement, $condStr);
		if ($condPos === false)
		{
			return false;
		}

		$value = substr($statement, $condPos + strlen($condStr));
		if ($value[0] == "'")
		{
			$endPos = strpos($value, "'", 1);
			if ($endPos === false)
			{
				return false;
			}

			$value = substr($value, 1, $endPos - 1);
		}
		else
		{
			$value = intval($value);
		}

		return $value;
	}

	public static function parseComment($comment)
	{
		$openPos = strpos($comment, '[');
		if ($openPos === false)
		{
			return false;
		}

		$closePos = strpos($comment, ']', $openPos);
		if ($closePos === false)
		{
			return false;
		}

		return array(substr($comment, 0, $openPos), substr($comment, $openPos + 1, $closePos - $openPos - 1));
	}

	public function __construct($primaryKeys, $mode)
	{
		$this->primaryKeys = $primaryKeys;
		$this->mode = $mode;
	}

	public function processLine($line)
	{
		if (startsWith($line, 'SET '))
		{
			$set = trim(substr($line, strlen('SET ')));
			$equalPos = strpos($set, '=');
			if ($equalPos === false)
			{
				return false;
			}

			$var = trim(substr($set, 0, $equalPos));
			$value = trim(substr($set, $equalPos + 1));
			switch ($var)
			{
			case 'TIMESTAMP':
				$this->timestamp = self::parseTimestamp($value);
				break;

			case 'INSERT_ID':
				$this->insertId = intval($value);
				break;
			}

			return false;
		}

		// reset the insert id (must come right before the insert)
		$insertId = $this->insertId;
		$this->insertId = null;

		if (!startsWith($line, '/*'))
		{
			return false;
		}

		$commentEnd = strpos($line, '*/');
		if ($commentEnd === false)
		{
			return false;
		}

		$comment = trim(substr($line, 2, $commentEnd - 2));
		$statement = trim(substr($line, $commentEnd + 2));

		if ($this->mode == self::MODE_SPHINX_WRITES)
		{
			$parseResult = self::parseSphinxLogStatement($statement);
		}
		else
		{
			$parseResult = self::parseWriteStatement($statement, $this->primaryKeys, $insertId);
		}
		if (!$parseResult)
		{
			return false;
		}

		list($tableName, $id) = $parseResult;
		return array($tableName, $id, $this->timestamp, $comment, $statement);
	}

	protected static function parseSphinxLogStatement($statement)
	{
		if (!startsWith($statement, 'INSERT INTO sphinx_log '))
		{
			return false;
		}

		$objectType = self::getInsertValues($statement, 'OBJECT_TYPE');
		if (!$objectType)
		{
			return false;
		}

		$objectId = self::getInsertValues($statement, 'OBJECT_ID');
		if (!$objectId)
		{
			return false;
		}

		if (in_array($objectType, array('DocumentEntry', 'ExternalMediaEntry', 'LiveStreamEntry')))
		{
			$objectType = 'entry';
		}

		return array($objectType, $objectId);
	}

	public static function parseWriteStatement($statement, $primaryKeys, $insertId = null)
	{
		$wherePos = false;
		foreach (array(self::STMT_PREFIX_UPDATE, self::STMT_PREFIX_DELETE) as $prefix)
		{
			if (!startsWith($statement, $prefix))
			{
				continue;
			}

			$tableStart = strlen($prefix);
			$wherePos = strpos($statement, ' WHERE ');
			if ($wherePos === false)
			{
				return false;
			}
			break;
		}

		if ($wherePos === false)
		{
			$prefix = self::STMT_PREFIX_INSERT;
			if (startsWith($statement, $prefix))
			{
				$tableStart = strlen($prefix);
			}
			else
			{
				return false;
			}
		}

		$spacePos = strpos($statement, ' ', $tableStart);
		if ($spacePos === false)
		{
			return false;
		}

		$tableName = trim(substr($statement, $tableStart, $spacePos - $tableStart));

		if (!isset($primaryKeys[$tableName]))
		{
			return false;
		}
		list($field, $autoIncrement) = $primaryKeys[$tableName];

		if ($wherePos !== false)
		{
			$id = self::getFieldValueFromWhere(substr($statement, $wherePos), $tableName, $field);
		}
		else
		{
			if ($autoIncrement)
			{
				if (!$insertId)
				{
					return false;
				}
				$id = $insertId;
			}
			else
			{
				$id = self::getInsertValues($statement, $field);
				if ($id === false)
				{
					return false;
				}
			}
		}

		return array($tableName, $id);
	}

	public static function parseSelectStatement($statement, $primaryKeys)
	{
		$fromPos = strpos($statement, ' FROM ');
		if ($fromPos === false)
		{
			return null;
		}

		$selectFields = substr($statement, strlen(self::STMT_PREFIX_SELECT), $fromPos - strlen(self::STMT_PREFIX_SELECT));
		$selectStatement = substr($statement, $fromPos);

		// get the table name
		$tableName = trim(substr($statement, $fromPos + strlen(' FROM ')));
		$tableNameEnd = $tableName[0] == '`' ? strpos($tableName, '`', 1) : strpos($tableName, ' ');
		if ($tableNameEnd !== false)
		{
			$tableName = trim(substr($tableName, 0, $tableNameEnd), '`');
		}

		// get the where block
		$wherePos = strpos($statement, ' WHERE ');
		if ($wherePos === false)
		{
			return array($selectFields, $selectStatement, $tableName, null);
		}

		// get the primary key
		if (!isset($primaryKeys[$tableName]))
		{
			return array($selectFields, $selectStatement, $tableName, null);
		}
		list($field, $autoIncrement) = $primaryKeys[$tableName];

		// get the object id
		$objectId = self::getFieldValueFromWhere(substr($statement, $wherePos), $tableName, $field);

		return array($selectFields, $selectStatement, $tableName, $objectId);
	}

	protected static function prettyPrintInsertStatement($block, $prefix, $valuesDelim)
	{
		$insertValues = self::getInsertValues($block, null, $valuesDelim);
		if (!$insertValues)
		{
			return $block;
		}

		// get the table name
		$spacePos = strpos($block, ' ');
		if ($spacePos === false)
		{
			return $block;
		}

		$tableName = trim(substr($block, 0, $spacePos));

		// format the values
		$block = $prefix . " $tableName (\n";
		foreach ($insertValues as $column => $value)
		{
			$block .= "  $column=$value,\n";
		}
		$block .= ')';
		return $block;
	}

	public static function prettyPrintStatement($block)
	{
		if (startsWith($block, self::STMT_PREFIX_INSERT))
		{
			return self::prettyPrintInsertStatement(
				trim(substr($block, strlen(self::STMT_PREFIX_INSERT))), 
				'INSERT INTO', 
				') VALUES (');
		}
		else if (startsWith($block, self::STMT_PREFIX_REPLACE))
		{
			return self::prettyPrintInsertStatement(
				trim(substr($block, strlen(self::STMT_PREFIX_REPLACE))), 
				'REPLACE INTO', 
				') values(');
		}
		else if (startsWith($block, self::STMT_PREFIX_UPDATE))
		{
			$block = str_replace(' `', "\n  `", $block);
			$block = str_replace(' WHERE ', "\n  WHERE ", $block);
		}

		return $block;
	}
}
