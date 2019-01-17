<?php

class DbWritesParser
{
	const STMT_PREFIX_INSERT = 'INSERT INTO ';
	const STMT_PREFIX_UPDATE = 'UPDATE ';
	const STMT_PREFIX_DELETE = 'DELETE FROM ';

	protected $primaryKeys;
	protected $timestamp;
	protected $insertId;

	protected static function parseTimestamp($ts)
	{
		list($year, $month, $day, $hour, $minute, $second) = sscanf($ts, '%d-%d-%d %d:%d:%d');
		return mktime($hour, $minute, $second, $month, $day, $year);
	}

	public static function getPrimaryKeysMap($pdo, $tables = null)
	{
		if (!$tables)
		{
			$stmt = $pdo->queryRetry('SHOW TABLES');
			if (!$stmt)
			{
				return false;
			}
			$tables = $stmt->fetchall(PDO::FETCH_NUM);
			$tables = array_map('reset', $tables);
		}

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

	protected static function getFieldValueFromInsert($statement, $field)
	{
		// find the columns and values
		$colsStart = strpos($statement, '(');
		$valuesStart = strpos($statement, ') VALUES (');
		if ($colsStart === false || $valuesStart === false)
		{
			return false;
		}

		// get the field index in the column list
		$columns = substr($statement, $colsStart + 1, $valuesStart - $colsStart - 1);
		$columns = explode(',', $columns);
		$fieldIndex = array_search("`$field`", $columns);
		if ($fieldIndex === false)
		{
			return false;
		}

		$curPos = $valuesStart + strlen(') VALUES (');
		for (;;)
		{
			// extract a value
			if ($statement[$curPos] == "'")
			{
				$nextPos = strpos($statement, "'", $curPos + 1);
				if ($nextPos === false)
				{
					return false;
				}
				$nextPos++;
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

			if ($fieldIndex == 0)
			{
				// got the required value
				$value = substr($statement, $curPos, $nextPos - $curPos);
				return trim($value, "'");
			}

			// continue to the next value
			if ($statement[$nextPos] != ',')
			{
				return false;
			}

			$fieldIndex--;
			$curPos = $nextPos + 1;
		}
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

	public function __construct($primaryKeys)
	{
		$this->primaryKeys = $primaryKeys;
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
		$curInsertId = $this->insertId;
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

		$wherePos = false;
		foreach (array(self::STMT_PREFIX_UPDATE, self::STMT_PREFIX_DELETE) as $prefix)
		{
			if (startsWith($statement, $prefix))
			{
				$tableStart = strlen($prefix);
				$wherePos = strpos($statement, ' WHERE ');
				if ($wherePos === false)
				{
					return false;
				}
			}
		}

		if ($wherePos === false)
		{
			if (startsWith($statement, self::STMT_PREFIX_INSERT))
			{
				$tableStart = strlen(self::STMT_PREFIX_INSERT);
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

		if (!isset($this->primaryKeys[$tableName]))
		{
			return false;
		}

		list($field, $autoIncrement) = $this->primaryKeys[$tableName];

		if ($wherePos !== false)
		{
			$condStr = $tableName . '.' . $field . '=';
			$condPos = strpos($statement, $condStr, $wherePos);
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

				$id = substr($value, 1, $endPos - 1);
			}
			else
			{
				$id = intval($value);
			}
		}
		else
		{
			if ($autoIncrement)
			{
				if (!$curInsertId)
				{
					return false;
				}
				$id = $curInsertId;
			}
			else
			{
				$id = self::getFieldValueFromInsert($statement, $field);
				if ($id === false)
				{
					return false;
				}
			}
		}

		return array($tableName, $id, $this->timestamp, $comment, $statement);
	}
}
