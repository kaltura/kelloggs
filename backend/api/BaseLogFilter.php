<?php

require_once(dirname(__file__) . '/BaseFilter.php');
require_once(dirname(__file__) . '/../shared/LogTypes.php');

class BaseLogFilter extends BaseFilter
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

	protected $zblockgrep;
	protected $process;
	protected $totalSize;

	protected static $errorLogSeverityMap = array(
		'PHP Notice:' => 'warn',
		'PHP Warning:' => 'warn',
		'PHP Fatal error:' => 'crit',
	);

	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter);

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
		if ($this->session && !ctype_alnum($this->session))
		{
			dieError(ERROR_BAD_REQUEST, 'Invalid session id');
		}

		$this->textFilter = isset($filter['textFilter']) ? $filter['textFilter'] : null;

		$this->zblockgrep = 'timeout ' . K::get()->getConfParam('GREP_TIMEOUT') . ' ' . getZBlockGrepCommand();
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

			$offsets = array();
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
					if (is_null($maxOffset))
					{
						$minOffset = $curOffset;
					}
					else if ($curOffset > $maxOffset + 1024 * 1024)
					{
						$offsets[] = array($minOffset, $maxOffset);

						$minOffset = $curOffset;
					}
					$maxOffset = $curOffset + $size;
				}

				$curOffset += $size;
				$curTime += $duration;
			}

			if (!is_null($maxOffset))
			{
				$offsets[] = array($minOffset, $maxOffset);
			}

			if (count($offsets) == 0)
			{
				continue;
			}

			$fileMap[$curFilePath] = array($curServer, $curFileType);
			foreach ($offsets as $curRange)
			{
				list($minOffset, $maxOffset) = $curRange;
				$rangeSize = $maxOffset - $minOffset;
				$totalSize += $rangeSize;
				while (isset($fileRanges[$rangeSize]))
				{
					$rangeSize++;
				}
				$fileRanges[$rangeSize] = "'" . $curFilePath . ':' . $minOffset . '-' . $maxOffset . "'";
			}
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

	protected static function addKsCommands(&$commandsByRange, $block)
	{
		$kss = array();
		if (preg_match_all("/[\[:]ks\] => (.*)$/m", $block, $matches))
		{
			$kss = array_merge($kss, $matches[1]);
		}
		if (preg_match_all("/'ks' => '([^']+)'/", $block, $matches))
		{
			$kss = array_merge($kss, $matches[1]);
		}
		if (preg_match_all('/"ks": "([^"]+)"/', $block, $matches))
		{
			$kss = array_merge($kss, $matches[1]);
		}
		$kss = array_unique($kss);

		foreach ($kss as $ks)
		{
			DatabaseSecretRepository::init();
			$ksObj = KalturaSession::getKsObject($ks);
			if (!$ksObj)
			{
				continue;
			}

			$formattedKs = formatKs($ksObj);

			$ksObj->valid_until = time() + 86400;
			$renewedKs = $ksObj->generateKs();

			$commands = array(
				array('label' => 'Show ks info', 'action' => COMMAND_TOOLTIP, 'data' => $formattedKs),
				array('label' => 'Renew + copy', 'action' => COMMAND_COPY, 'data' => $renewedKs),
			);

			self::addCommandsByString($commandsByRange, $block, $ks, $commands);
		}
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

	protected static function gotoSessionCommands($server, $session, $timestamp, $highlight = null, $logType = null, $margin = 300)
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

		if ($highlight)
		{
			$sessionFilter['highlight'] = $highlight;
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

	protected static function parseExecutionTime($str)
	{
		$slashPos = strpos($str, '/');
		if ($slashPos === false)
		{
			return $str;		// nginx
		}
		$micros = substr($str, $slashPos + 1);
		return strval($micros / 1000000.0);
	}

	protected static function prettyPrintStatement($block, &$commands)
	{
		$prettyBlock = DbWritesParser::prettyPrintStatement($block);
		if ($prettyBlock == $block)
		{
			return $block;
		}

		$commands[] = array(
			'label' => 'Copy original SQL', 
			'action' => COMMAND_COPY, 
			'data' => $block, 
		);

		return $prettyBlock;
	}

	protected function getAccessLogLine($logTypes, $pattern, $timeFormat, $sessionIndex)
	{
		list($fileRanges, $ignore, $ignore) = self::getFileRanges($logTypes, $this->fromTime, $this->toTime, $this->server);
		if (!$fileRanges)
		{
			return array();
		}

		$fileRanges = implode(' ', $fileRanges);

		$timeCapture = '$1';
		$captureConditions = array(
			$timeCapture . '@>=' . strftime($timeFormat, $this->fromTime),
			$timeCapture . '@<=' . strftime($timeFormat, $this->toTime),
		);
		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam(array('type' => 'match', 'text' => $this->session));

		$grepCommand = $this->zblockgrep . " -h -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $fileRanges";

		exec($grepCommand, $output);

		if (!is_array($output))
		{
			return array();
		}

		foreach ($output as $line)
		{
			$parsedLine = AccessLogParser::parse($line);
			if ($parsedLine[$sessionIndex] == $this->session)
			{
				return $parsedLine;
			}
		}

		return null;
	}

	protected static function getAccessLogBaseMetadataFields($parsedLine)
	{
		$ipAddress = $parsedLine[12];
		if (!filter_var($ipAddress, FILTER_VALIDATE_IP))
		{
			$ipAddress = $parsedLine[0];
		}

		$xForwardedFor = $parsedLine[20];

		$remoteAddr = getIpAddress($ipAddress, $xForwardedFor);

		return array(
			// client -> server
			'Request line' => $parsedLine[5],
			'Host' => $parsedLine[14],
			'Client ip' => ($remoteAddr ? $remoteAddr : $ipAddress),
			'Referrer' => $parsedLine[9],
			'User agent' => $parsedLine[10],
			'Bytes received' => $parsedLine[18],

			// server -> client
			'Status' => $parsedLine[6],
			'Bytes sent' => $parsedLine[7],
			'Execution time' => self::parseExecutionTime($parsedLine[8]),
			'Kaltura error' => $parsedLine[13],
			'Connection status' => self::formatApacheConnectionStatus($parsedLine[17]),
		);
	}

	protected function getErrorLogLines($logTypes, $pattern, $timeFormat)
	{
		list($fileRanges, $ignore, $ignore) = self::getFileRanges($logTypes, $this->fromTime, $this->toTime, $this->server);
		if (!$fileRanges)
		{
			return array();
		}

		$fileRanges = implode(' ', $fileRanges);

		$timeCapture = '"$1 $3"';
		$captureConditions = array(
			$timeCapture . '@>=' . strftime($timeFormat, $this->fromTime),
			$timeCapture . '@<=' . strftime($timeFormat, $this->toTime),
		);
		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam(array('type' => 'match', 'text' => 'session ' . $this->session));

		$grepCommand = $this->zblockgrep . " -h -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $fileRanges";

		exec($grepCommand, $output);

		if (!is_array($output))
		{
			return array();
		}

		$result = array();
		foreach ($output as $curLine)
		{
			$curLine = trim($curLine);
			$fields = array();
			for ($curPos = 0; $curPos < strlen($curLine); )
			{
				if ($curLine[$curPos] == '[')
				{
					$endPos = strpos($curLine, ']', $curPos);
					if ($endPos !== false)
					{
						$fields[] = substr($curLine, $curPos + 1, $endPos - $curPos - 1);
						$curPos = $endPos + 2;
						continue;
					}
				}

				$fields[] = substr($curLine, $curPos);
				break;
			}

			$fields = array_filter($fields);

			if (!$fields)
			{
				continue;
			}

			$timestamp = reset($fields);
			$timestamp = preg_replace('/\.\d+/', '', $timestamp);
			$timestamp = strtotime($timestamp);

			$body = end($fields);

			$body = str_replace(', session ' . $this->session, '', $body);		// strip out the session id

			$severity = 'error';
			foreach (self::$errorLogSeverityMap as $prefix => $curSeverity)
			{
				if (startsWith($body, $prefix))
				{
					$severity = $curSeverity;
					$body = trim(substr($body, strlen($prefix)));
					break;
				}
			}

			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'body' => $body,
			);

			$result[] = $line;
		}

		return $result;
	}

	protected function runGrepCommand()
	{
		// run the grep process
		$descriptorSpec = array(
		   1 => array('pipe', 'w'),
		   2 => array('pipe', 'w')
		);

		$this->process = proc_open($this->grepCommand, $descriptorSpec, $pipes, realpath('./'), array());
		if (!is_resource($this->process))
		{
			dieError(ERROR_INTERNAL_ERROR, 'Failed to run process');
		}

		$this->grepStartTime = microtime(true);
		return $pipes[1];
	}

	protected function grepCommandFinished()
	{
		// TODO: replace this with something else
		error_log(get_class($this) . ' took ' . (microtime(true) - $this->grepStartTime) . ' size ' . $this->totalSize);
	}

	protected static function getAndTextFilter($filters)
	{
		if (count($filters) > 1)
		{
			$textFilter = self::getTextFilterParam(
				array('type' => 'and', 'filters' => $filters));
		}
		else if (count($filters) > 0)
		{
			$textFilter = self::getTextFilterParam(reset($filters));
		}
		else
		{
			$textFilter = '';
		}

		return $textFilter;
	}

	protected function setFileMap($fileMap)
	{
		$serverNames = array();
		foreach ($fileMap as $fileInfo)
		{
			$serverName = $fileInfo[0];
			$serverNames[$serverName] = 1;
			if (count($serverNames) > 1)
			{
				break;
			}
		}
		if (count($serverNames) == 1)
		{
			reset($serverNames);
			$this->server = key($serverNames);
		}
		$this->fileMap = $fileMap;
	}

	protected function stripFileNameFromLine(&$line)
	{
		$fileEndPos = strpos($line, ': ');
		$fileName = substr($line, 0, $fileEndPos);
		if (!isset($this->fileMap[$fileName]))
		{
			return false;
		}
		$line = substr($line, $fileEndPos + 2);
		return $this->fileMap[$fileName];
	}
}
