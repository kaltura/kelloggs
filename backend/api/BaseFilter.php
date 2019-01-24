<?php

class BaseFilter
{
	// filter
	protected $fromTime;
	protected $toTime;
	protected $serverPattern;
	protected $server;
	protected $session;
	protected $textFilter;

	protected $grepCommand;
	protected $fileMap;
	protected $multiRanges;

	protected $params;
	protected $responseFormat;
	protected $zblockgrep;

	protected static $primaryKeys;

	protected function __construct($params, $filter)
	{
		$this->responseFormat = isset($params['responseFormat']) ? $params['responseFormat'] : RESPONSE_FORMAT_JSON;
		if (!in_array($this->responseFormat, array(RESPONSE_FORMAT_RAW, RESPONSE_FORMAT_DOWNLOAD, RESPONSE_FORMAT_JSON)))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid responseFormat');
		}

		if (!isset($filter['fromTime']) || !isset($filter['toTime']))
		{
			dieError(ERROR_BAD_REQUEST, 'Missing fromTime/toTime');
		}

		$this->fromTime = $filter['fromTime'];
		$this->toTime = $filter['toTime'];
		if (!ctype_digit($this->fromTime) || !ctype_digit($this->toTime))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid fromTime/toTime');
		}

		if ($this->fromTime > $this->toTime)
		{
			dieError(ERROR_BAD_REQUEST, 'fromTime is greater than toTime');
		}

		$this->serverPattern = isset($filter['server']) ? $filter['server'] : null;
		$this->session = isset($filter['session']) ? $filter['session'] : null;
		if ($this->session && !ctype_digit($this->session))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid session id');
		}

		$this->textFilter = isset($filter['textFilter']) ? $filter['textFilter'] : null;

		$this->zblockgrep = 'timeout ' . K::get()->getConfParam('GREP_TIMEOUT') . ' ' . K::get()->getConfParam('ZBLOCKGREP');
		$this->params = $params;
	}

	protected function getDownloadFileName()
	{
		$result = 'log';
		if ($this->server)
		{
			$result .= '-' . $this->server;
		}
		if ($this->session)
		{
			$result .= '-' . $this->session;
		}
		$result .= date('-Y-m-d-H-i', $this->fromTime);
		if (!$this->session)
		{
			$result .= date('-Y-m-d-H:i', $this->toTime);
		}
		$result .= '.log';

		return $result;
	}

	protected function getTopLevelCommands()
	{
		$baseApiUrl = K::Get()->getConfParam('BASE_KELLOGGS_API_URL');
		$rawUrl = $baseApiUrl . '?' . http_build_query(array_merge($this->params, array('responseFormat' => RESPONSE_FORMAT_RAW)));
		$downloadUrl = $baseApiUrl . '?' . http_build_query(array_merge($this->params, array('responseFormat' => RESPONSE_FORMAT_DOWNLOAD)));

		return array(
			array('label' => 'Copy grep command', 'action' => COMMAND_COPY, 'data' => $this->grepCommand),
			array('label' => 'Copy raw log URL', 'action' => COMMAND_COPY, 'data' => $rawUrl),
			array('label' => 'Download raw log', 'action' => COMMAND_DOWNLOAD, 'data' => $downloadUrl),
		);
	}

	protected function handleRawFormats()
	{
		switch ($this->responseFormat)
		{
		case RESPONSE_FORMAT_DOWNLOAD:
			$downloadFileName = $this->getDownloadFileName();
			header("Content-Disposition: attachment; filename=\"$downloadFileName\"");
			header("Content-Type: application/force-download");
			header("Content-Description: File Transfer");

			// fallthrough

		case RESPONSE_FORMAT_RAW:
			passthru($this->grepCommand);
			die;
		}
	}

	protected static function getFileRanges($logTypes, $fromTime, $toTime, $serverPattern = null)
	{
		$sql = 'SELECT server, type, file_path, ranges FROM kelloggs_files WHERE start <= FROM_UNIXTIME(?) AND end >= FROM_UNIXTIME(?) AND start >= FROM_UNIXTIME(?) AND status = 2';
		$values = array(
			$toTime,
			$fromTime,
			$fromTime - 86400,
		);

		if ($serverPattern && strpos($serverPattern, '*') === false && strpos($serverPattern, '?') === false)
		{
			$sql .= ' AND server = ?';
			$values[] = $serverPattern;
			$serverPattern = null;
		}
		$sql .= ' AND type IN (@ids@)';
		$stmt = K::get()->getKelloggsPdo()->executeInStatement($sql, $logTypes, $values, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);

		$fileRanges = array();
		$totalSize = 0;
		$fileMap = array();
		foreach ($rows as $row)
		{
			$curServer = $row['server'];
			if ($serverPattern && !fnmatch($serverPattern, $curServer))
			{
				continue;
			}

			$curFilePath = $row['file_path'];
			$curFileType = $row['type'];
			$ranges = json_decode($row['ranges']);

			$minOffset = null;
			$maxOffset = null;
			$curOffset = 0;
			$curTime = 0;
			foreach ($ranges as $range)
			{
				list($startOffset, $size, $startTime, $duration) = $range;
				$curOffset += $startOffset;
				$curTime += $startTime;

				if ($curTime <= $toTime && $curTime + $duration >= $fromTime)
				{
					if (is_null($minOffset))
					{
						$minOffset = $curOffset;
					}
					$maxOffset = $curOffset + $size;
				}

				$curOffset += $size;
				$curTime += $duration;
			}

			if ($minOffset >= $maxOffset)
			{
				continue;
			}

			$rangeSize = $maxOffset - $minOffset;
			$totalSize += $rangeSize;
			$fileMap[$curFilePath] = array($curServer, $curFileType);
			while (isset($fileRanges[$rangeSize]))
			{
				$rangeSize++;
			}
			$fileRanges[$rangeSize] = "'" . $curFilePath . ':' . $minOffset . '-' . $maxOffset . "'";
		}

		ksort($fileRanges);		// sort by ascending size - if a log is not segmented it will be scanned last

		return array($fileRanges, $totalSize, $fileMap);
	}

	protected static function getTextFilterParam($filter)
	{
		if (!$filter)
		{
			return '';
		}

		$result = str_replace("'", '', json_encode($filter));
		$result = "-f '$result'";
		return $result;
	}

	protected static function addCommandsByString(&$commandsByRange, $block, $string, $commands)
	{
		$offset = 0;
		for (;;)
		{
			$offset = strpos($block, $string, $offset);
			if ($offset === false)
			{
				break;
			}

			$commandsByRange[] = array($offset, strlen($string), $commands);
			$offset++;
		}
	}

	protected static function formatBlock($block, $commandsByRange = array())
	{
		sort($commandsByRange);

		$result = array();
		$lastOffset = 0;
		foreach ($commandsByRange as $cur)
		{
			list($offset, $size, $commands) = $cur;

			if ($offset < $lastOffset)
			{
				// overlapping ranges - ignore
				continue;
			}

			if ($offset > $lastOffset)
			{
				$result[] = array('text' => substr($block, $lastOffset, $offset - $lastOffset));
			}

			$result[] = array('text' => substr($block, $offset, $size), 'commands' => $commands);
			$lastOffset = $offset + $size;
		}

		$offset = strlen($block);
		if ($offset > $lastOffset)
		{
			$result[] = array('text' => substr($block, $lastOffset, $offset - $lastOffset));
		}

		return $result;
	}

	protected static function addSourceCodeCommands(&$commandsByRange, $conf, $block)
	{
		$sourceRefs = array();

		foreach ($conf['PATTERNS'] as $pattern)
		{
			$matchCount = preg_match_all($pattern, $block, $matches);
			for ($i = 0; $i < $matchCount; $i++)
			{
				$match = $matches[0][$i];
				$fileName = $matches['file'][$i];
				$fileLine = $matches['line'][$i];

				$sourceRefs[$match] = array($fileName, $fileLine);
			}
		}

		$workingSourceBase = $conf['WORKING_SOURCE_BASE'];
		foreach ($sourceRefs as $match => $cur)
		{
			list($fileName, $fileLine) = $cur;

			// get the relative path
			if (!startsWith($fileName, $workingSourceBase))
			{
				continue;
			}
			$relativeFileName = substr($fileName, strlen($workingSourceBase . '/'));

			// make sure the file name is part of the code
			$testPathBase = realpath($conf['REFERENCE_SOURCE_BASE']) . '/';
			$realPath = realpath($testPathBase . $relativeFileName);
			if (!$realPath || 
				!startsWith($realPath, $testPathBase))
			{
				continue;
			}

			// ignore files that are part of private repos
			if (isset($conf['EXCLUDE_SOURCE_BASE']))
			{
				$branchRelativePath = substr($relativeFileName, strpos($relativeFileName, '/') + 1);
				if (file_exists($conf['EXCLUDE_SOURCE_BASE'] . '/' . $branchRelativePath))
				{
					continue;
				}
			}

			// generate the commands
			$commands = array();
			if (isset($conf['GITHUB_BASE_URL']))
			{
				$githubUrl = $conf['GITHUB_BASE_URL'] . $relativeFileName . '#L' . $fileLine;
				$commands[] = array('label' => 'Open in GitHub', 'action' => COMMAND_LINK, 'data' => $githubUrl);
			}

			$sourceLines = file($realPath);
			if (is_array($sourceLines) && $fileLine > 0 && $fileLine <= count($sourceLines))
			{
				$fileLine--;		// convert to 0 based
				$startLine = max($fileLine - 5, 0);
				$codeSection = implode('', array_slice($sourceLines, $startLine, $fileLine - $startLine));
				$codeSection .= '>>> ' . $sourceLines[$fileLine];
				$codeSection .= implode('', array_slice($sourceLines, $fileLine + 1, 5));

				$commands[] = array('label' => 'Show code', 'action' => COMMAND_TOOLTIP, 'data' => $codeSection);
			}

			self::addCommandsByString($commandsByRange, $block, $match, $commands);
		}
	}

	protected static function gotoSessionCommands($server, $session, $timestamp, $logType = null, $margin = 300)
	{
		$sessionFilter = array(
			'type' => 'apiLogFilter',
			'server' => $server,
			'session' => $session,
			'fromTime' => $timestamp - $margin,
			'toTime' => $timestamp + $margin,
		);

		if ($logType)
		{
			$sessionFilter['logTypes'] = $logType;
		}

		return array(
			array('label' => 'Go to session', 'action' => COMMAND_SEARCH, 'data' => $sessionFilter),
			array('label' => 'Open session in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $sessionFilter),
		);
	}

	protected static function formatApacheConnectionStatus($str)
	{
		$map = array(
			'X' => 'Aborted',
			'+' => 'Kept alive',
			'-' => 'Closed',
		);
		return isset($map[$str]) ? $map[$str] : '';
	}

	protected static function parseApacheExecutionTime($str)
	{
		$splitted = explode('/', $str);
		$micros = end($splitted);
		return strval($micros / 1000000.0);
	}

	protected static function formatMetadata($metadata)
	{
		$metadata = array_filter($metadata, function ($value) { 
			return $value && $value != '-'; });

		$result = array();
		foreach ($metadata as $key => $value)
		{
			$result[] = array('label' => $key, 'value' => $value);
		}
		return $result;
	}

	protected static function getPrimaryKeysMap()
	{
		if (self::$primaryKeys)
		{
			return self::$primaryKeys;
		}

		if (function_exists('apcu_fetch'))
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

		if (function_exists('apcu_fetch'))
		{
			self::$primaryKeys = apcu_store('primary_keys_map', self::$primaryKeys, 86400);
		}
		return self::$primaryKeys;
	}
}
