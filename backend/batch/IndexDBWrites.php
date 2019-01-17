<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Stream.php');

define('STMT_PREFIX_INSERT', 'INSERT INTO ');
define('STMT_PREFIX_UPDATE', 'UPDATE ');
define('STMT_PREFIX_DELETE', 'DELETE FROM ');
define('TIME_GRANULARITY', 60);

$ignoredTables = array(
	'scheduler_worker',		// frequent keep alive updates
	'caption_asset_item',	// many inserts when upload a caption asset
	'server_node',			// frequent heartbeat updates
	'tag',					// many updates for instance count
);

function parseTimestamp($ts)
{
	list($year, $month, $day, $hour, $minute, $second) = sscanf($ts, '%d-%d-%d %d:%d:%d');
	return mktime($hour, $minute, $second, $month, $day, $year);
}

function getPrimaryKeysMap($pdo)
{
	$stmt = $pdo->queryRetry('SHOW TABLES');
	$tables = $stmt->fetchall(PDO::FETCH_NUM);
	$tables = array_map('reset', $tables);

	foreach ($tables as $table)
	{
		$stmt = $pdo->queryRetry('DESCRIBE ' . $table);
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

function getFieldValueFromInsert($statement, $field)
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

function buildIndex($inputPath, $primaryKeys)
{
	$reader = new GZipReader($inputPath, 1024 * 1024);

	$result = array();
	for (;;)
	{
		// parse the line
		$line = $reader->getLine();
		if ($line === false)
		{
			break;
		}

		if (startsWith($line, 'SET '))
		{
			$set = trim(substr($line, strlen('SET ')));
			$equalPos = strpos($set, '=');
			if ($equalPos === false)
			{
				continue;
			}

			$var = trim(substr($set, 0, $equalPos));
			$value = trim(substr($set, $equalPos + 1));
			switch ($var)
			{
			case 'TIMESTAMP':
				$timestamp = parseTimestamp($value);
				$timestamp = intdiv($timestamp, TIME_GRANULARITY) * TIME_GRANULARITY;
				break;

			case 'INSERT_ID':
				$insertId = intval($value);
				break;
			}

			continue;
		}

		if (!startsWith($line, '/*'))
		{
			continue;
		}

		$commentEnd = strpos($line, '*/');
		if ($commentEnd === false)
		{
			continue;
		}

		$comment = trim(substr($line, 2, $commentEnd - 2));
		$statement = trim(substr($line, $commentEnd + 2));

		$wherePos = false;
		foreach (array(STMT_PREFIX_UPDATE, STMT_PREFIX_DELETE) as $prefix)
		{
			if (startsWith($statement, $prefix))
			{
				$statement = substr($statement, strlen($prefix));
				$wherePos = strpos($statement, ' WHERE ');
				if ($wherePos === false)
				{
					continue;
				}
			}
		}

		if ($wherePos === false)
		{
			if (startsWith($statement, STMT_PREFIX_INSERT))
			{
				$statement = substr($statement, strlen(STMT_PREFIX_INSERT));
			}
			else
			{
				continue;
			}
		}

		$spacePos = strpos($statement, ' ');
		if ($spacePos === false)
		{
			continue;
		}

		$tableName = trim(substr($statement, 0, $spacePos));

		if (!isset($primaryKeys[$tableName]))
		{
			continue;
		}

		list($field, $autoIncrement) = $primaryKeys[$tableName];

		if ($wherePos !== false)
		{
			$condStr = $tableName . '.' . $field . '=';
			$condPos = strpos($statement, $condStr, $wherePos);
			if ($condPos === false)
			{
				continue;
			}

			$value = substr($statement, $condPos + strlen($condStr));
			if ($value[0] == "'")
			{
				$endPos = strpos($value, "'", 1);
				if ($endPos === false)
				{
					continue;
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
				$id = $insertId;
			}
			else
			{
				$id = getFieldValueFromInsert($statement, $field);
				if ($id === false)
				{
					continue;
				}
			}
		}

		$key = $tableName . '_' . $id;
		if (!isset($result[$key]))
		{
			$result[$key] = array();
		}
		$result[$key][$timestamp] = 1;
	}

	ksort($result);

	return $result;
}

function writeIndex($outputPath, $rangesPath, $index)
{
	$count = 0;
	$writer = new GZipWriter($outputPath);

	$rangesFile = fopen($rangesPath, 'w');

	reset($index);
	$firstKey = key($index);
	fwrite($rangesFile, "0\t$firstKey\n");
	$lastFileSize = 0;

	foreach ($index as $key => $timestamps)
	{
		$count++;
		if ($count % 1000 == 0 && $writer->tell() > 10 * 1024 * 1024)
		{
			$fileSize = $writer->flush();
			fwrite($rangesFile, ($fileSize - $lastFileSize) . "\t$key\n");
			$lastFileSize = $fileSize;
		}

		$timestamps = array_keys($timestamps);
		$lastTimestamp = array_shift($timestamps);
		$line = $key . ' ' . $lastTimestamp;

		foreach ($timestamps as $timestamp)
		{
			$line .= ',' . ($timestamp - $lastTimestamp);
			$lastTimestamp = $timestamp;
		}
		$writer->write($line . "\n");
	}
	$writer->close();

	clearstatcache();
	$fileSize = filesize($outputPath);
	fwrite($rangesFile, ($fileSize - $lastFileSize) . "\t$key\n");
	fclose($rangesFile);
}

// parse the command line
if ($argc < 5)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <input path> <output path> <ranges path>\n";
	exit(1);
}

$conf = loadIniFiles($argv[1]);
$inputPath = $argv[2];
$outputPath = $argv[3];
$rangesPath = $argv[4];

$pdo = PdoWrapper::create($conf['database']);

writeLog('Info: started, pid=' . getmypid());

$primaryKeys = getPrimaryKeysMap($pdo);

// remove ignored tables
foreach ($ignoredTables as $table)
{
	unset($primaryKeys[$table]);
}

writeLog('Info: indexing ' . $inputPath);
$index = buildIndex($inputPath, $primaryKeys);

writeLog('Info: writing ' . count($index) . ' lines to ' . $outputPath);
writeIndex($outputPath, $rangesPath, $index);

writeLog('Info: done');
