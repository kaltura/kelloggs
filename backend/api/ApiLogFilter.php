<?php

require_once(dirname(__file__) . '/../lib/KalturaSession.php');
require_once(dirname(__file__) . '/DatabaseSecretRepository.php');
require_once(dirname(__file__) . '/ApiLogUtils.php');
require_once(dirname(__file__) . '/BaseFilter.php');

define('LOG_TYPE_APIV3', 1);
define('LOG_TYPE_PS2', 5);
define('PATTERN_API', '^(\d{4}\-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) [^ ]+ [^ ]+ \[(\d+)\]');		// $1 = timestamp, $2 = session
define('TIME_FORMAT_API', '%Y-%m-%d %H:%M:%S');

define('LOG_TYPE_API_ACCESS', 2);
define('PATTERN_API_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_API_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

define('LOG_TYPE_API_ERROR', 3);
define('PATTERN_API_ERROR', '^\[(\w+ \w+ \d+ [\d:]+)(\.\d+)? (\d+)\]');
define('TIME_FORMAT_API_ERROR', '%a %b %d %H:%M:%S %Y');

class ApiLogFilter extends BaseFilter
{
	protected $logTypes;

	protected static $logTypesMap = array(
		'apiV3' => LOG_TYPE_APIV3,
		'ps2' => LOG_TYPE_PS2,
	);

	protected static $errorLogSeverityMap = array(
		'PHP Notice:' => 'warn',
		'PHP Warning:' => 'warn',
		'PHP Fatal error:' => 'crit',
	);

	protected function __construct($filter)
	{
		parent::__construct($filter);

		$logTypes = isset($filter['logTypes']) ? explode(',', $filter['logTypes']) : array();
		$this->logTypes = array();
		foreach ($this->logTypes as $logType)
		{
			$logType = trim($logType);
			if (!isset(self::$logTypesMap[$logType]))
			{
				dieError(ERROR_BAD_REQUEST, 'Invalid log type');
			}

			$this->logTypes[] = self::$logTypesMap[$logType];
		}

		if (!$this->logTypes)
		{
			$this->logTypes = array_values(self::$logTypesMap);
		}
	}

	protected function getGrepCommand()
	{
		global $responseFormat, $zblockgrep;

		list($fileRanges, $totalSize, $fileMap) = self::getFileRanges($this->logTypes, $this->fromTime, $this->toTime, $this->serverPattern);
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
		global $zblockgrep;

		list($fileRanges, $ignore, $ignore) = self::getFileRanges(array(LOG_TYPE_API_ACCESS), $this->fromTime, $this->toTime, $this->server);
		if (!$fileRanges)
		{
			return array();
		}

		$fileRanges = implode(' ', $fileRanges);

		$timeFormat = TIME_FORMAT_API_ACCESS;
		$timeCapture = '$1';
		$captureConditions = array(
			$timeCapture . '@>=' . strftime(TIME_FORMAT_API_ACCESS, $this->fromTime),
			$timeCapture . '@<=' . strftime(TIME_FORMAT_API_ACCESS, $this->toTime),
		);
		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam(array('type' => 'match', 'text' => $this->session));

		$pattern = PATTERN_API_ACCESS;
		$grepCommand = "$zblockgrep -h -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $fileRanges";

		exec($grepCommand, $output);

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

	protected function getErrorLogLines()
	{
		global $zblockgrep;

		list($fileRanges, $ignore, $ignore) = self::getFileRanges(array(LOG_TYPE_API_ERROR), $this->fromTime, $this->toTime, $this->server);
		if (!$fileRanges)
		{
			return array();
		}

		$fileRanges = implode(' ', $fileRanges);

		$timeFormat = TIME_FORMAT_API_ERROR;
		$timeCapture = '"$1 $3"';
		$captureConditions = array(
			$timeCapture . '@>=' . strftime($timeFormat, $this->fromTime),
			$timeCapture . '@<=' . strftime($timeFormat, $this->toTime),
		);
		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam(array('type' => 'match', 'text' => 'session ' . $this->session));

		$pattern = PATTERN_API_ERROR;
		$grepCommand = "$zblockgrep -h -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $fileRanges";

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

			$func = 'error_log';
			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'took' => 0,
				'function' => $func,
				'body' => self::formatApiBlock($func, $body),
			);

			$result[] = $line;
		}

		return $result;
	}

	protected function getResponseHeader($multiSession, &$bufferedLines)
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

			$bufferedLines = $this->getErrorLogLines();
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
		$multiSession = !$this->server || !$this->session;
		$header = $this->getResponseHeader($multiSession, $bufferedLines);
		echo json_encode($header) . "\n";

		$bufferedLine = reset($bufferedLines);
		$curServer = $this->server;
		$curType = count($this->logTypes) == 1 ? reset($this->logTypes) : null;
		$block = '';
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
					list($curServer, $curType) = $this->fileMap[$fileName];
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

			while ($bufferedLine && $bufferedLine['timestamp'] < $timestamp)
			{
				echo json_encode($bufferedLine) . "\n";
				$bufferedLine = next($bufferedLines);
			}

			$commands = array();

			if ($multiSession)
			{
				$commands = array_merge($commands,
					self::gotoSessionCommands($curServer, $curSession, $timestamp, 
					$curType ? array_search($curType, $logTypesMap) : null));
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

	public static function main($filter)
	{
		$obj = new ApiLogFilter($filter);
		$obj->doMain();
	}
}