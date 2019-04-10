<?php

require_once(dirname(__file__) . '/BaseLogFilter.php');
require_once(dirname(__file__) . '/../shared/DbWritesParser.php');

define('TIME_FORMAT_DB_WRITES', '%Y-%m-%d %H:%M:%S');

class DbWritesFilter extends BaseLogFilter
{
	const TYPE_DATABASE = 'dbWritesFilter';
	const TYPE_SPHINX = 'sphinxWritesFilter';

	protected $type;
	protected $table;
	protected $objectId;

	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter);

		$this->type = $filter['type'];
		if (isset($filter['table']) && $filter['table'])
		{
			$this->table = $filter['table'];
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table))
			{
				dieError(ERROR_BAD_REQUEST, 'Invalid table param');
			}
		}
		else
		{
			$this->table = null;
		}
		$this->objectId = isset($filter['objectId']) ? $filter['objectId'] : null;
	}

	protected function searchIndexes($key, $fromTime, $toTime)
	{
		$logType = $this->type == self::TYPE_DATABASE ? LOG_TYPE_DB_WRITES_INDEX : LOG_TYPE_SPHINX_WRITES_INDEX;

		$sql = 'SELECT file_path, ranges, parent_id FROM kelloggs_files WHERE start <= FROM_UNIXTIME(?) AND end >= FROM_UNIXTIME(?) AND start >= FROM_UNIXTIME(?) AND status = 2 AND type = ? ORDER BY start ASC';
		$values = array(
			1 => $toTime,
			2 => $fromTime,
			3 => $fromTime - 86400,
			4 => $logType,
		);

		$stmt = K::get()->getKelloggsPdo()->executeStatement($sql, $values, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);

		$fileRanges = array();
		$fileToParentMap = array();
		foreach ($rows as $row)
		{
			$curFilePath = $row['file_path'];
			$parentId = $row['parent_id'];

			$ranges = json_decode($row['ranges']);
			$range = array_shift($ranges);
			list($curOffset, $curKey) = $range;
			if (strcmp($key, $curKey) < 0)
			{
				continue;
			}

			$lastRange = end($ranges);

			foreach ($ranges as $range)
			{
				list($size, $curKey) = $range;
				if (strcmp($key, $curKey) < 0 || 
					$range == $lastRange && strcmp($key, $curKey) <= 0)
				{
					$fileRanges[] = "'" . $curFilePath . ':' . $curOffset . '-' . ($curOffset + $size) . "'";
					$fileToParentMap[$curFilePath] = $parentId;
					break;
				}

				$curOffset += $size;
			}
		}

		$fileRanges = array_slice($fileRanges, -1000);
		$fileRanges = implode(' ', $fileRanges);

		$pattern = '([^ ]+) ';
		$captureConditions = "\$1=$key";
		$grepCommand = $this->zblockgrep . " -H -p '$pattern' -c '$captureConditions' $fileRanges";
		exec($grepCommand, $output);

		$result = array();
		foreach ($output as $line)
		{
			list($curFilePath, $line) = explode(': ', $line, 2);
			if (!isset($fileToParentMap[$curFilePath]))
			{
				continue;
			}

			$fileId = $fileToParentMap[$curFilePath];
			$line = explode(' ', $line);
			$timeDeltas = end($line);
			$curTime = 0;
			$timestamps = array();
			foreach (explode(',', $timeDeltas) as $timestamp)
			{
				$curTime += $timestamp;
				$timestamps[] = $curTime;
			}

			$result[$fileId] = $timestamps;
		}

		return $result;
	}

	protected static function mergeContinuousRanges($arr)
	{
		$result = array();
		$end = false;
		foreach ($arr as $cur)
		{
			list($curStart, $curEnd) = $cur;
			if ($end === false || $curStart != $end)
			{
				if ($end !== false)
				{
					$result[] = array($start, $end);
				}
				$start = $curStart;
			}
			$end = $curEnd;
		}

		if ($end !== false)
		{
			$result[] = array($start, $end);
		}

		return $result;
	}

	protected function getFileRangesUsingIndex()
	{
		$indexResult = $this->searchIndexes($this->table . '_' . $this->objectId, $this->fromTime, $this->toTime);
		if (!$indexResult)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$sql = 'SELECT id, file_path, ranges FROM kelloggs_files WHERE id IN (@ids@)';
		$stmt = K::get()->getKelloggsPdo()->executeInStatement($sql, array_keys($indexResult), null, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);

		$fileRanges = array();
		$totalSize = 0;
		foreach ($rows as $row)
		{
			$fileId = $row['id'];
			$filePath = $row['file_path'];
			$ranges = json_decode($row['ranges']);

			$timestamps = $indexResult[$fileId];

			$curOffset = 0;
			$curTime = 0;
			$fileOffsets = array();
			foreach ($ranges as $range)
			{
				list($startOffset, $size, $startTime, $duration) = $range;
				$curOffset += $startOffset;
				$curTime += $startTime;

				$match = false;
				foreach ($timestamps as $timestamp)
				{
					if ($curTime <= $timestamp + 60 && $curTime + $duration >= $timestamp)
					{
						$match = true;
					}
				}

				if ($match)
				{
					$fileOffsets[] = array($curOffset, $curOffset + $size);
				}

				$curOffset += $size;
				$curTime += $duration;
			}

			foreach (self::mergeContinuousRanges($fileOffsets) as $fileOffset)
			{
				$fileRanges[] = "'" . $filePath . ':' . $fileOffset[0] . '-' . $fileOffset[1] . "'";
				$totalSize += $fileOffset[1] - $fileOffset[0];
			}
		}

		$fileRanges = array_slice($fileRanges, -1000);
		return array($fileRanges, $totalSize);
	}

	protected function getGrepCommand()
	{
		if ($this->table && $this->objectId && $this->toTime - $this->fromTime > 3600)
		{
			list($fileRanges, $this->totalSize) = $this->getFileRangesUsingIndex();
		}
		else
		{
			$logType = $this->type == self::TYPE_DATABASE ? LOG_TYPE_DB_WRITES : LOG_TYPE_SPHINX_WRITES;
			list($fileRanges, $this->totalSize, $ignore) = self::getFileRanges(array($logType), $this->fromTime, $this->toTime);
		}

		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$this->multiRanges = count($fileRanges) > 1;
		$fileRanges = implode(' ', $fileRanges);

		$pattern = '^SET TIMESTAMP=(.*)';

		$captureConditions = array(
			'$1>=' . strftime(TIME_FORMAT_DB_WRITES, $this->fromTime),
			'$1<=' . strftime(TIME_FORMAT_DB_WRITES, $this->toTime),
		);

		$captureConditions = implode(',', $captureConditions);

		$filters = array();
		if ($this->table)
		{
			$filters[] = array('type' => 'match', 'text' => $this->table);
		}
		if ($this->objectId)
		{
			$filters[] = array('type' => 'match', 'text' => $this->objectId);
		}
		if ($this->textFilter)
		{
			$filters[] = $this->textFilter;
		}

		$textFilter = self::getAndTextFilter($filters);

		$this->grepCommand = $this->zblockgrep . " -h -p '$pattern' -c '$captureConditions' $textFilter $fileRanges";
	}

	protected function getTopLevelCommands()
	{
		$result = parent::getTopLevelCommands();

		if ($this->table && $this->objectId)
		{
			$result = array_merge($result, self::objectInfoCommands($this->table, $this->objectId));
		}

		return $result;
	}

	protected function getResponseHeader()
	{
		$columns = array();
		$metadata = array();

		$columns[] = array('label' => 'Server', 'name' => 'server', 'type' => 'text');
		$columns[] = array('label' => 'Session', 'name' => 'session', 'type' => 'text');

		if ($this->table)
		{
			$metadata['Table'] = $this->table;
		}
		else
		{
			$columns[] = array('label' => 'Table', 'name' => 'table', 'type' => 'text');
		}

		if ($this->objectId)
		{
			$metadata['Object Id'] = $this->objectId;
		}
		else
		{
			$columns[] = array('label' => 'Object id', 'name' => 'objectId', 'type' => 'text');
		}

		$columns = array_merge($columns, array(
			array('label' => 'Timestamp', 	'name' => 'timestamp',	'type' => 'timestamp'),
			array('label' => 'SQL', 		'name' => 'body', 		'type' => 'text'),
		));

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands(),
			'metadata' => self::formatMetadata($metadata),
		);
	}

	protected function handleJsonFormat()
	{
		// initialize the parser
		$primaryKeys = self::getPrimaryKeysMap();
		if (!$primaryKeys)
		{
			dieError(ERROR_INTERNAL_ERROR, 'Failed to get database schema');
		}

		$mode = $this->type == self::TYPE_DATABASE ? DbWritesParser::MODE_DB_WRITES : DbWritesParser::MODE_SPHINX_WRITES;
		$parser = new DbWritesParser($primaryKeys, $mode);

		// run the grep process
		$pipe = $this->runGrepCommand();

		// output the response header
		$header = $this->getResponseHeader();
		echo json_encode($header) . "\n";

		$block = '';
		for (;;)
		{
			$line = fgets($pipe);
			if ($line === false)
			{
				break;
			}

			$parseResult = $parser->processLine($line);
			if (!$parseResult)
			{
				continue;
			}

			list($tableName, $objectId, $timestamp, $comment, $statement) = $parseResult;
			if ($this->table && $tableName != $this->table)
			{
				continue;
			}

			if ($this->objectId && $objectId != $this->objectId)
			{
				continue;
			}

			$parsedComment = DbWritesParser::parseComment($comment);
			if (!$parsedComment)
			{
				continue;
			}

			if ($this->type == self::TYPE_SPHINX)
			{
				$statement = DbWritesParser::getInsertValues($statement, 'SQL');
				if (!$statement)
				{
					continue;
				}

				// strip the SQL escaping of the value
				$statement = str_replace(array('\\\\', '\\"', "\\'", '\\n', '\\r', '\\0'), array('\\', '"', "'", "\n", "\r", "\0"), $statement);
			}

			list($server, $session) = $parsedComment;

			$commands = self::gotoSessionCommands($server, $session, $timestamp);

			if (preg_match('/^a:\d+:\{/', $statement))
			{
				// Elastic updates are saved in serialized PHP
				$decodedStatement = unserialize($statement);
				if ($decodedStatement)
				{
					$statement = print_r($decodedStatement, true);
				}
			}
			else
			{
				$statement = self::prettyPrintStatement($statement, $commands);
			}

			$line = array(
				'timestamp' => $timestamp,
				'body' => $statement,
				'server' => $server,
				'session' => $session,
				'commands' => $commands,
			);

			if (!$this->table)
			{
				$line['table'] = $tableName;
			}

			if (!$this->objectId)
			{
				$line['objectId'] = $objectId;
			}

			echo json_encode($line) . "\n";
		}

		$this->grepCommandFinished();
	}

	protected function doMain()
	{
		$this->getGrepCommand();

		$this->handleRawFormats();

		$this->handleJsonFormat();
	}

	public static function main($params, $filter)
	{
		$obj = new DbWritesFilter($params, $filter);
		$obj->doMain();
	}
}
