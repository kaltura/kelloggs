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
	protected function getBaseGrepCommand()
	{
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
		return $this->zblockgrep . " -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $delimiter";
	}

	protected function getGrepCommand()
	{
		list($fileRanges, $this->totalSize, $fileMap) = self::getFileRanges(array(LOG_TYPE_KMS), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$this->multiRanges = count($fileRanges) > 1;
		$fileRanges = implode(' ', $fileRanges);

		$baseCommand = $this->getBaseGrepCommand();
		$this->grepCommand = "$baseCommand $fileRanges";

		$this->setFileMap($fileMap);
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

	protected static function parseKmsLine($line)
	{
		$splittedLine = explode(' ', $line, 7);
		if (count($splittedLine) < 7)
		{
			return false;
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

		return array($timestamp, $ip, $curSession, $severity, $memory, $memoryReal, $block);
	}

	protected function getKmsApiLogLines()
	{
		list($fileRanges, $ignore, $fileMap) = self::getFileRanges(array(LOG_TYPE_KMS_API, LOG_TYPE_KMS_API_DEBUG), $this->fromTime, $this->toTime, $this->server);
		if (!$fileRanges)
		{
			return array();
		}

		$fileRanges = implode(' ', $fileRanges);
		$grepCommand = $this->getBaseGrepCommand() . " -H $fileRanges";

		exec($grepCommand, $output);

		if (!is_array($output))
		{
			return array();
		}

		$block = '';
		$service = $action = null;
		$result = array();
		foreach ($output as $line)
		{
			if ($line != BLOCK_DELIMITER)
			{
				if ($block)
				{
					$block .= $line;
					continue;
				}

				// block started - get the log type
				$fileEndPos = strpos($line, ': ');
				$fileName = substr($line, 0, $fileEndPos);
				if (!isset($fileMap[$fileName]))
				{
					continue;
				}
				list($ignore, $curType) = $fileMap[$fileName];
				$line = substr($line, $fileEndPos + 2);

				// parse the line
				$parsedLine = self::parseKmsLine($line);
				if (!$parsedLine)
				{
					continue;
				}

				list($timestamp, $ip, $curSession, $severity, $memory, $memoryReal, $block) = $parsedLine;
				if (!preg_match('/^\[request: (\d+)\]/', $block, $matches))
				{
					$block = '';
					continue;
				}

				$requestIndex = $matches[1];
				continue;
			}

			if (!$block)
			{
				continue;
			}

			// block finished
			$commandsByRange = array();
			$commands = array();

			if (strpos($block, '[Stack: ') !== false)
			{
				$block = str_replace('>', " >\n  ", $block);
			}

			$curlPos = strpos($block, 'curl: ');
			$postPos = strpos($block, 'post: ');
			if ($curlPos !== false)
			{
				$urlStart = $curlPos + strlen('curl: ');
				$url = substr($block, $urlStart);

				$service = $action = null;
				if (preg_match('#/service/([^/]+)#', $url, $matches))
				{
					$service = $matches[1];
				}
				if (preg_match('#/action/([^/]+)#', $url, $matches))
				{
					$action = $matches[1];
				}
			}
			else if ($postPos !== false)
			{
				$paramsStart = $postPos + strlen('post: ');
				$params = json_decode(substr($block, $paramsStart), true);
				if ($params)
				{
					$block = substr($block, 0, $paramsStart) . json_encode($params, JSON_PRETTY_PRINT);
					self::addKsCommands($commandsByRange, $block);

					if ($service)
					{
						$params['service'] = $service;
						$params['action'] = $action ? $action : 'null';
						$params = flattenArray($params, '');

						$kalcliCommand = genKalcliCommand($params);
						if ($kalcliCommand)
						{
							$commands[] = array(
								'label' => 'Copy kalcli command', 
								'action' => COMMAND_COPY, 
								'data' => $kalcliCommand,
							);
						}

						$curlCommand = genCurlCommand($params);
						if ($curlCommand)
						{
							$commands[] = array(
								'label' => 'Copy curl command', 
								'action' => COMMAND_COPY, 
								'data' => $curlCommand,
							);
						}
					}
				}
			}
			else
			{
				$service = $action = null;
			}

			$resultPos = strpos($block, 'result (serialized): ');
			if ($resultPos !== false)
			{
				$resultStart = $resultPos + strlen('result (serialized): ');
				$doc = DOMDocument::loadXML(substr($block, $resultStart));
				if ($doc)
				{
					$doc->preserveWhiteSpace = false;
					$doc->formatOutput = true;
					$block = substr($block, 0, $resultStart) . $doc->saveXML();
				}
			}

			$formattedBlock = self::formatBlock($block, $commandsByRange);

			if (preg_match('/server: \[([^\]]+)\], session: \[(\d+)\]/', $block, $matches))
			{
				$commands = array_merge($commands,
					self::gotoSessionCommands($matches[1], $matches[2], $timestamp));
			}

			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'body' => $formattedBlock,
				'type' => $curType == LOG_TYPE_KMS_API ? 'API' : 'API debug',
			);

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

			// sort by request index, then by log type
			$outputKey = "$requestIndex/" . ($curType == LOG_TYPE_KMS_API_DEBUG ? 1 : 2);
			$result[$outputKey][] = $line;
			$block = '';
		}

		ksort($result);

		return call_user_func_array('array_merge', $result);
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
			array('label' => 'Log type',	'name' => 'type', 		'type' => 'text'),
			array('label' => 'Memory',		'name' => 'memory', 	'type' => 'text'),
			array('label' => 'Memory real',	'name' => 'memoryReal', 'type' => 'text'),
			array('label' => 'Message', 	'name' => 'body', 		'type' => 'richText'),
		));

		if (!$multiSession)
		{
			$metadata = array_merge($metadata, $this->getAccessLogMetadataFields());

			$bufferedLines = array_merge(
				$this->getKmsErrorLogLines(),
				$this->getKmsApiLogLines()
			);
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
		$pipe = $this->runGrepCommand();

		// output the response header
		$multiSession = !$this->server || !$this->session;
		$header = $this->getResponseHeader($multiSession, $bufferedLines);
		echo json_encode($header) . "\n";

		$bufferedLine = reset($bufferedLines);
		$curServer = $this->server;
		$block = '';
		for (;;)
		{
			$line = fgets($pipe);
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
					$fileInfo = $this->stripFileNameFromLine($line);
					if (!$fileInfo)
					{
						continue;
					}
					list($curServer, $ignore) = $fileInfo;
				}

				// parse the line fields
				$parsedLine = self::parseKmsLine($line);
				if ($parsedLine)
				{
					list($timestamp, $ip, $curSession, $severity, $memory, $memoryReal, $block) = $parsedLine;
				}

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
					self::gotoSessionCommands($curServer, $curSession, $timestamp));
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
				'type' => 'KMS',
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
		$obj = new KmsLogFilter($params, $filter);
		$obj->doMain();
	}
}