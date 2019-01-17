<?php

require_once(dirname(__file__) . '/../lib/KalturaSession.php');
require_once(dirname(__file__) . '/DatabaseSecretRepository.php');
require_once(dirname(__file__) . '/ApiLogUtils.php');
require_once(dirname(__file__) . '/BaseFilter.php');

define('LOG_TYPE_API', 1);
define('PATTERN_API', '^(\d{4}\-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) [^ ]+ [^ ]+ \[(\d+)\]');		// $1 = timestamp, $2 = session
define('TIME_FORMAT_API', '%Y-%m-%d %H:%M:%S');

define('LOG_TYPE_API_ACCESS', 2);
define('PATTERN_API_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_API_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

class ApiLogFilter extends BaseFilter
{
	protected function getGrepCommand()
	{
		global $responseFormat, $zblockgrep;

		list($fileRanges, $totalSize, $fileToServerMap) = self::getFileRanges(array(LOG_TYPE_API), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$this->multiRanges = count($fileRanges) > 1;
		$fileRanges = implode(' ', $fileRanges);

		$pattern = PATTERN_API;

		$captureConditions = array(
			'$1>=' . strftime(TIME_FORMAT_API, $this->fromTime),
			'$1<=' . strftime(TIME_FORMAT_API, $this->toTime),
		);

		if ($this->session)
		{
			$captureConditions[] = '$2=' . $this->session;
		}

		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam($this->textFilter);

		$delimiter = $responseFormat != RESPONSE_FORMAT_RAW ? '-d' . BLOCK_DELIMITER : '';
		$this->grepCommand = "$zblockgrep -p '$pattern' -c '$captureConditions' $textFilter $delimiter $fileRanges";

		if (count($fileToServerMap) == 1)
		{
			$this->server = reset($fileToServerMap);
		}
		$this->fileToServerMap = $fileToServerMap;
	}

	protected function getAccessLogMetadataFields()
	{
		global $zblockgrep;

		list($accessRanges, $ignore, $ignore) = self::getFileRanges(array(LOG_TYPE_API_ACCESS), $this->fromTime, $this->toTime, $this->server);
		if (!$accessRanges)
		{
			return array();
		}

		$accessRanges = implode(' ', $accessRanges);
		$captureConditions = array(
			'$1>=' . strftime(TIME_FORMAT_API_ACCESS, $this->fromTime),
			'$1<=' . strftime(TIME_FORMAT_API_ACCESS, $this->toTime),
		);
		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam(array('type' => 'match', 'text' => $this->session));

		$pattern = PATTERN_API_ACCESS;
		$accessGrepCommand = "$zblockgrep -h -p '$pattern' -c '$captureConditions' $textFilter $accessRanges";

		exec($accessGrepCommand, $output);

		if (!is_array($output))
		{
			return array();
		}

		$accessLine = null;
		foreach ($output as $curLine)
		{
			$parsedLine = AccessLogParser::parse($curLine);
			if ($parsedLine[16] == $this->session)
			{
				$accessLine = $parsedLine;
				break;
			}
		}

		if (!$accessLine)
		{
			return array();
		}

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
			'Protocol' => $parsedLine[11] == 'ON' ? 'HTTPS' : 'HTTP',
			'Partner id' => $parsedLine[24],
			'Bytes received' => $parsedLine[18],

			// server -> client
			'Status' => $parsedLine[6],
			'Bytes sent' => $parsedLine[7],
			'Execution time' => self::parseApacheExecutionTime($parsedLine[8]),
			'Kaltura error' => $parsedLine[13],
			'Connection status' => self::formatApacheConnectionStatus($parsedLine[17]),
		);
	}

	protected function getResponseHeader($multiSession)
	{
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
			array('label' => 'Took', 		'name' => 'took', 		'type' => 'float', 'levels' => array(5, 10)),
			array('label' => 'Function', 	'name' => 'function', 	'type' => 'text'),
			array('label' => 'Message', 	'name' => 'body', 		'type' => 'richText'),
		));

		if (!$multiSession)
		{
			$metadata = array_merge($metadata, $this->getAccessLogMetadataFields());

			$columns[] = array('label' => 'Indent by event consumer level', 'name' => 'indent', 'type' => 'indent', 'column' => 'body');
		}

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands(),
			'metadata' => self::formatMetadata($metadata),
		);
	}

	protected static function addKalcliCommands(&$commands, $func, $block)
	{
		if ($func != 'KalturaFrontController->run' || !startsWith($block, 'Params ['))
		{
			return;
		}

		$endPos = strrpos($block, ']');
		if ($endPos === false)
		{
			return;
		}

		$parsedParams = print_r_reverse(substr($block, strlen('Params ['), $endPos - strlen($block)));
		if (!is_array($parsedParams))
		{
			return;
		}

		$parsedParams = flattenArray($parsedParams, '');
		if (!isset($parsedParams['service']))
		{
			return;
		}

		if ($parsedParams['service'] == 'multirequest')
		{
			/* XXXX TODO - add sub request kalcli commands
			if ($multireqMode == 'multi')
			{
				unset($parsedParams['service']);
				unset($parsedParams['action']);
				$requestByParams = parseMultirequest($parsedParams);
				foreach ($requestByParams as $curParams)
				{
					$curCmd = genKalcliCommand($curParams);
					echo $curCmd . "\n";
				}
				return;
			}*/
			$parsedParams['action'] = 'null';
		}

		$kalcliCommand = genKalcliCommand($parsedParams);
		if ($kalcliCommand)
		{
			$commands[] = array(
				'label' => 'Copy kalcli command', 
				'action' => COMMAND_COPY, 
				'data' => $kalcliCommand,
			);
		}

		$curlCommand = genCurlCommand($parsedParams);
		if ($curlCommand)
		{
			$commands[] = array(
				'label' => 'Copy curl command', 
				'action' => COMMAND_COPY, 
				'data' => $curlCommand,
			);
		}
	}

	protected static function addQueryCacheCommands(&$commands, $func, $block)
	{
		if ($func != 'kQueryCache::getCachedQueryResults' ||
			!startsWith($block, 'kQueryCache: returning from memcache'))
		{
			return;
		}

		if (!preg_match_all('/queryTime=(\d+) debugInfo=([^\]]+)\[(\d+)\]/', $block, $matches))
		{
			return;
		}

		$commands = array_merge($commands,
			self::gotoSessionCommands($matches[2][0], $matches[3][0], $matches[1][0]));
	}

	protected static function addKsCommands(&$commandsByRange, $block)
	{
		$kss = array();
		$offset = 1;
		for (;;)
		{
			$offset = strpos($block, 'ks] => ', $offset);
			if ($offset === false)
			{
				break;
			}

			if (!in_array($block[$offset - 1], array(':', '[')))
			{
				continue;
			}

			$offset += strlen('ks] => ');
			$newLinePos = strpos($block, "\n", $offset);
			$ks = substr($block, $offset, $newLinePos - $offset);
			if (!isset($kss[$ks]))
			{
				$kss[$ks] = array();
			}
			$kss[$ks][] = $offset;
		}

		foreach ($kss as $ks => $offsets)
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

			foreach ($offsets as $offset)
			{
				$commandsByRange[] = array($offset, strlen($ks), $commands);
			}
		}
	}

	protected static function formatApiBlock($func, $block)
	{
		global $conf;

		$block = rtrim($block);

		if ($func == 'KalturaStatement->execute' && startsWith($block, '/* '))
		{
			$selectPos = strpos($block, ' SELECT ');
			$fromPos = strpos($block, ' FROM ');
			if ($selectPos !== false && $fromPos !== false && $selectPos < $fromPos)
			{
				// hide select fields by default
				$result = array();
				$result[] = array('text' => 'SELECT ');
				$result[] = array('text' => '...', 'commands' => array( 
					array('label' => 'Show fields', 'action' => COMMAND_TOOLTIP, 'data' => substr($block, $selectPos + 8, $fromPos - $selectPos - 8))
				));
				$result[] = array('text' => substr($block, $fromPos));
				return $result;
			}
		}

		$commandsByRange = array();
		self::addKsCommands($commandsByRange, $block);
		self::addSourceCodeCommands($commandsByRange, $conf['api_source'], $block);
		return self::formatBlock($block, $commandsByRange);
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
		$multiSession = count($this->fileToServerMap) > 1 || !$this->session;
		$header = $this->getResponseHeader($multiSession);
		echo json_encode($header) . "\n";

		$curServer = $this->server;
		$block = '';
		while ($line = fgets($pipes[1]))
		{
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
					if (!isset($this->fileToServerMap[$fileName]))
					{
						continue;
					}
					$curServer = $this->fileToServerMap[$fileName];
					$line = substr($line, $fileEndPos + 2);
				}

				// parse the line fields
				$splittedLine = explode(' ', $line, 9);
				if (count($splittedLine) < 9)
				{
					continue;
				}
				list($date, $time, $took, $ip, $curSession, $context, $func, $severity, $block) = $splittedLine;
				$timestamp = strtotime("$date $time");
				$took = floatval(substr($took, 1, -1));
				$ip = substr($ip, 1, -1);
				$curSession = substr($curSession, 1, -1);
				$context = substr($context, 1, -1);
				$func = substr($func, 1, -1);
				$severity = strtolower(substr($severity, 0, -1));
				continue;
			}

			// block finished
			$commands = array();

			if ($multiSession)
			{
				$commands = array_merge($commands,
					self::gotoSessionCommands($curServer, $curSession, $timestamp));
			}
			else
			{
				self::addKalcliCommands($commands, $func, $block);
				self::addQueryCacheCommands($commands, $func, $block);
			}

			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'took' => $took,
				'function' => $func,
				'body' => self::formatApiBlock($func, $block),

			);
			if (!$multiSession)
			{
				$line['indent'] = 0;
			}

			if (!$this->session)
			{
				$line['session'] = $curSession;
			}

			if (!$this->server)
			{
				$line['server'] = $curServer;
			}

			if ($commands)
			{
				$line['commands'] = $commands;
			}
			echo json_encode($line) . "\n";
			$block = '';
		}
	}

	protected function doMain()
	{
		$this->getGrepCommand();

		$this->handleRawFormats();

		$this->handleJsonFormat();
	}

	public static function main($filter)
	{
		$obj = new ApiLogFilter($filter);
		$obj->doMain();
	}
}