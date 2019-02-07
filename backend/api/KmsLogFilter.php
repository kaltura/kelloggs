<?php

require_once(dirname(__file__) . '/ApiLogUtils.php');
require_once(dirname(__file__) . '/BaseLogFilter.php');

define('PATTERN_KMS', '^\[(\d{2}\-\w{3}-\d{4} \d{2}:\d{2}:\d{2})\] [^ ]+ [^ ]+ \[(\w+)\]');		// $1 = timestamp, $2 = session
define('TIME_FORMAT_KMS', '%d-%b-%Y %H:%M:%S');

define('PATTERN_KMS_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_KMS_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

define('PATTERN_KMS_ERROR', '^\[(\w+ \w+ \d+ [\d:]+)(\.\d+)? (\d+)\]');
define('TIME_FORMAT_KMS_ERROR', '%a %b %d %H:%M:%S %Y');

class KmsLogFilter extends BaseLogFilter
{
	protected function getGrepCommand()
	{
		list($fileRanges, $totalSize, $fileMap) = self::getFileRanges(array(LOG_TYPE_KMS), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$this->multiRanges = count($fileRanges) > 1;
		$fileRanges = implode(' ', $fileRanges);

		$pattern = PATTERN_KMS;

		$timeFormat = TIME_FORMAT_KMS;

		$captureConditions = array(
			'$1@>=' . gmstrftime($timeFormat, $this->fromTime),
			'$1@<=' . gmstrftime($timeFormat, $this->toTime),
		);

		if ($this->session)
		{
			$captureConditions[] = '$2=' . $this->session;
		}

		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam($this->textFilter);

		$delimiter = $this->responseFormat != RESPONSE_FORMAT_RAW ? '-d' . BLOCK_DELIMITER : '';
		$this->grepCommand = $this->zblockgrep . " -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $delimiter $fileRanges";

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

	protected function getAccessLogMetadataFields()
	{
		$parsedLine = $this->getAccessLogLine(array(LOG_TYPE_KMS_ACCESS), PATTERN_KMS_ACCESS, TIME_FORMAT_KMS_ACCESS, 23);
		if (!$parsedLine)
		{
			return array();
		}

		return array_merge(
			self::getAccessLogBaseMetadataFields($parsedLine), 
			array(
				'SSL protocol' => $parsedLine[24],
				'SSL cipher' => $parsedLine[25],
			));
	}

	protected function getKmsErrorLogLines()
	{
		$result = $this->getErrorLogLines(array(LOG_TYPE_KMS_ERROR), PATTERN_KMS_ERROR, TIME_FORMAT_KMS_ERROR);
		foreach ($result as &$line)
		{
			$line['body'] = self::formatBlock($line['body']);
		}

		return $result;
	}

	protected function getResponseHeader($multiSession, &$bufferedLines)
	{
		$bufferedLines = array();

		$columns = array();
		$metadata = array();

		$columns[] = array('label' => 'Severity', 	'name' => 'severity',	'type' => 'severity');

		if ($this->server)
		{
			$metadata['Server'] = $this->server;
		}
		else
		{
			$columns[] = array('label' => 'Server', 'name' => 'server', 'type' => 'text');
		}

		if ($this->session)
		{
			$metadata['Session'] = $this->session;
		}
		else
		{
			$columns[] = array('label' => 'Session', 'name' => 'session', 'type' => 'text');
		}

		$columns = array_merge($columns, array(
			array('label' => 'Timestamp', 	'name' => 'timestamp',	'type' => 'timestamp'),
			array('label' => 'Memory',		'name' => 'memory', 	'type' => 'text'),
			array('label' => 'Memory Real',	'name' => 'memoryReal', 'type' => 'text'),
			array('label' => 'Message', 	'name' => 'body', 		'type' => 'richText'),
		));

		if (!$multiSession)
		{
			$metadata = array_merge($metadata, $this->getAccessLogMetadataFields());

			$bufferedLines = $this->getKmsErrorLogLines();
		}

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands(),
			'metadata' => self::formatMetadata($metadata),
		);
	}

	protected function handleJsonFormat()
	{
		// run the grep process
		$descriptorSpec = array(
		   1 => array('pipe', 'w'),
		   2 => array('pipe', 'w')
		);

		$process = proc_open($this->grepCommand, $descriptorSpec, $pipes, realpath('./'), array());
		if (!is_resource($process))
		{
			dieError(ERROR_INTERNAL_ERROR, 'Failed to run process');
		}

		// output the response header
		$multiSession = !$this->server || !$this->session;
		$header = $this->getResponseHeader($multiSession, $bufferedLines);
		echo json_encode($header) . "\n";

		$bufferedLine = reset($bufferedLines);
		$curServer = $this->server;
		$block = '';
		//$indent = 0;
		for (;;)
		{
			$line = fgets($pipes[1]);
			if ($line === false)
			{
				break;
			}

			if ($line != BLOCK_DELIMITER . "\n")
			{
				if ($block)
				{
					$block .= $line;
					continue;
				}

				// block started
				if ($this->multiRanges)
				{
					// get the server name
					$fileEndPos = strpos($line, ': ');
					$fileName = substr($line, 0, $fileEndPos);
					if (!isset($this->fileMap[$fileName]))
					{
						continue;
					}
					list($curServer, $ignore) = $this->fileMap[$fileName];
					$line = substr($line, $fileEndPos + 2);
				}

				// parse the line fields
				$splittedLine = explode(' ', $line, 7);
				if (count($splittedLine) < 7)
				{
					continue;
				}
				list($date, $time, $ip, $ignore, $curSession, $severity, $block) = $splittedLine;

				if (preg_match('/^\[memory: ([^,]+), real: ([^\]]+)\]/', $block, $matches))
				{
					$block = trim(substr($block, strlen($matches[0])));
					$memory = trim(str_replace('MB', '', $matches[1]));
					$memoryReal = trim(str_replace('MB', '', $matches[2]));
				}
				else
				{
					$memory = null;
					$memoryReal = null;
				}


				$parsedTime = strptime(substr("$date $time", 1, -1), TIME_FORMAT_KMS);
				$timestamp = gmmktime($parsedTime['tm_hour'], $parsedTime['tm_min'], $parsedTime['tm_sec'],
					$parsedTime['tm_mon'] + 1, $parsedTime['tm_mday'], $parsedTime['tm_year'] + 1900);
				$ip = substr($ip, 1, -1);
				$curSession = substr($curSession, 1, -1);
				$severity = explode('(', $severity);
				$severity = strtolower($severity[0]);
				continue;
			}

			// block finished

			while ($bufferedLine && $bufferedLine['timestamp'] < $timestamp)
			{
				echo json_encode($bufferedLine) . "\n";
				$bufferedLine = next($bufferedLines);
			}

			$commands = array();

			if ($multiSession)
			{
				$commands = array_merge($commands,
					self::gotoSessionCommands($curServer, $curSession, $timestamp, null));
			}
			else
			{
				// TODO: add kms specific commands here
			}

			$formattedBlock = self::formatBlock($block);

			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'body' => $formattedBlock,
			);

			if (!$this->session)
			{
				$line['session'] = $curSession;
			}

			if (!$this->server)
			{
				$line['server'] = $curServer;
			}

			if ($memory)
			{
				$line['memory'] = $memory;
			}

			if ($memoryReal)
			{
				$line['memoryReal'] = $memoryReal;
			}

			if ($commands)
			{
				$line['commands'] = $commands;
			}
			echo json_encode($line) . "\n";
			$block = '';
		}

		while ($bufferedLine)
		{
			echo json_encode($bufferedLine) . "\n";
			$bufferedLine = next($bufferedLines);
		}
	}

	protected function doMain()
	{
		$this->getGrepCommand();

		$this->handleRawFormats();

		$this->handleJsonFormat();
	}

	public static function main($params, $filter)
	{
		$obj = new KmsLogFilter($params, $filter);
		$obj->doMain();
	}
}