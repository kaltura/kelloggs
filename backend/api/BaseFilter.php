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
	protected $fileToServerMap;
	protected $multiRanges;
	
	protected function __construct($filter)
	{
		if (!isset($filter['fromTime']) || !isset($filter['toTime']))
		{
			dieError(ERROR_BAD_REQUEST, 'Missing fromTime/toTime');
		}

		$this->fromTime = $filter['fromTime'];
		$this->toTime = $filter['toTime'];
		$this->serverPattern = isset($filter['server']) ? $filter['server'] : null;
		$this->session = isset($filter['session']) ? $filter['session'] : null;
		if ($this->session && !ctype_digit($this->session))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid session id');
		}

		$this->textFilter = isset($filter['textFilter']) ? $filter['textFilter'] : null;
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
		if (!$session)
		{
			$result .= date('-Y-m-d-H:i', $this->toTime);
		}
		$result .= '.log';
		
		return $result;
	}

	protected function handleRawFormats()
	{
		global $responseFormat;
		
		switch ($responseFormat)
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
		global $kelloggsPdo;
		
		$sql = 'SELECT server, file_path, ranges FROM kelloggs_files WHERE start <= FROM_UNIXTIME(?) AND end >= FROM_UNIXTIME(?) AND start >= FROM_UNIXTIME(?) AND status = 2';
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
		$stmt = $kelloggsPdo->executeInStatement($sql, $logTypes, $values, false);
		$rows = $stmt->fetchall(PDO::FETCH_ASSOC);

		$fileRanges = array();
		$totalSize = 0;
		$fileToServerMap = array();
		foreach ($rows as $row)
		{
			$curServer = $row['server'];
			$curFilePath = $row['file_path'];
			if ($serverPattern && !fnmatch($serverPattern, $curServer))
			{
				continue;
			}

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
			$fileToServerMap[$curFilePath] = $curServer;
			while (isset($fileRanges[$rangeSize]))
			{
				$rangeSize++;
			}
			$fileRanges[$rangeSize] = "'" . $curFilePath . ':' . $minOffset . '-' . $maxOffset . "'";
		}
		
		ksort($fileRanges);		// sort by ascending size - if a log is not segmented it will be scanned last
		
		return array($fileRanges, $totalSize, $fileToServerMap);
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

	protected static function formatBlock($block, $commandsByRange)
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
				$fileName = $matches[1][$i];
				$fileLine = $matches[2][$i];

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
}