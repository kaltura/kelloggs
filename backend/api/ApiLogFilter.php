<?php

require_once(dirname(__file__) . '/../lib/KalturaSession.php');
require_once(dirname(__file__) . '/DatabaseSecretRepository.php');
require_once(dirname(__file__) . '/ApiLogUtils.php');
require_once(dirname(__file__) . '/BaseLogFilter.php');

define('PATTERN_API', '^(\d{4}\-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) [^ ]+ [^ ]+ \[(\d+)\]');		// $1 = timestamp, $2 = session
define('TIME_FORMAT_API', '%Y-%m-%d %H:%M:%S');

define('PATTERN_API_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_API_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

define('PATTERN_API_ERROR', '^\[(\w+ \w+ \d+ [\d:]+)(\.\d+)? (\d+)\]');
define('TIME_FORMAT_API_ERROR', '%a %b %d %H:%M:%S %Y');

class ApiLogFilter extends BaseLogFilter
{
	protected $queryCacheKey;
	protected $logTypes;

	protected static $logTypesMap = array(
		'apiV3' => LOG_TYPE_APIV3,
		'ps2' => LOG_TYPE_PS2,
	);

	protected function __construct($params, $filter)
	{
		parent::__construct($params, $filter);

		if (isset($filter['queryCacheKey']))
		{
			$this->queryCacheKey = $filter['queryCacheKey'];
		}

		$logTypes = isset($filter['logTypes']) ? explode(',', $filter['logTypes']) : array();
		$this->logTypes = array();
		foreach ($logTypes as $logType)
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
		list($fileRanges, $this->totalSize, $fileMap) = self::getFileRanges($this->logTypes, $this->fromTime, $this->toTime, $this->serverPattern);
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

		$delimiter = $this->responseFormat != RESPONSE_FORMAT_RAW ? '-d' . BLOCK_DELIMITER : '';
		$this->grepCommand = $this->zblockgrep . " -p '$pattern' -c '$captureConditions' $textFilter $delimiter $fileRanges";

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
		$parsedLine = $this->getAccessLogLine(array(LOG_TYPE_API_ACCESS), PATTERN_API_ACCESS, TIME_FORMAT_API_ACCESS, 16);
		if (!$parsedLine)
		{
			return array();
		}

		return array_merge(
			self::getAccessLogBaseMetadataFields($parsedLine), 
			array(
				'Protocol' => $parsedLine[11] == 'ON' ? 'HTTPS' : 'HTTP',
				'Partner id' => $parsedLine[24],
			));
	}

	protected function getApiErrorLogLines()
	{
		$result = $this->getErrorLogLines(array(LOG_TYPE_API_ERROR), PATTERN_API_ERROR, TIME_FORMAT_API_ERROR);
		foreach ($result as &$line)
		{
			$func = 'error_log';
			$body = $line['body'];
			$timestamp = $line['timestamp'];

			$line['took'] = 0;
			$line['function'] = $func;

			$formattedBlock = self::formatApiBlock($timestamp, $func, $body, $commands);

			$line['body'] = $formattedBlock;

			if ($commands)
			{
				$line['commands'] = $commands;
			}
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
			array('label' => 'Took', 		'name' => 'took', 		'type' => 'float', 'levels' => array(5, 10)),
			array('label' => 'Function', 	'name' => 'function', 	'type' => 'text'),
			array('label' => 'Message', 	'name' => 'body', 		'type' => 'richText'),
		));

		if (!$multiSession)
		{
			$metadata = array_merge($metadata, $this->getAccessLogMetadataFields());

			$bufferedLines = $this->getApiErrorLogLines();
		}

		return array(
			'type' => 'searchResponse',
			'columns' => $columns,
			'commands' => $this->getTopLevelCommands(),
			'metadata' => self::formatMetadata($metadata),
		);
	}

	protected static function parsePS2Params($block)
	{
		$startPos = strpos($block, '(');
		$endPos = strrpos($block, ')');
		if ($startPos === false || $endPos === false)
		{
			return null;
		}

		$result = array();
		$arrayBody = substr($block, $startPos + 1, $endPos - $startPos - 1);
		$curPos = 0;
		for (;;)
		{
			$keyStart = strpos($block, "'", $curPos);
			if ($keyStart === false)
			{
				break;
			}

			$keyStart++;
			$keyEnd = strpos($block, "' => '", $keyStart);
			if ($keyEnd === false)
			{
				break;
			}

			$valueStart = $keyEnd + strlen("' => '");
			$valueEnd = strpos($block, "'", $valueStart);
			if ($valueEnd === false)
			{
				break;
			}

			$key = substr($block, $keyStart, $keyEnd - $keyStart);
			$value = substr($block, $valueStart, $valueEnd - $valueStart);
			$result[$key] = $value;

			$curPos = $valueEnd + 1;
		}

		return $result;
	}

	protected static function addPS2CurlCommands(&$commands, $func, &$block)
	{
		if ($func != 'sfWebRequest->loadParameters' || !startsWith($block, '{sfRequest} request parameters '))
		{
			return;
		}

		$params = self::parsePS2Params($block);
		if (!isset($params['module']) || !isset($params['action']))
		{
			return;
		}

		$module = $params['module'];
		$action = $params['action'];
		unset($params['module']);
		unset($params['action']);
		$params = renewKss($params);

		$uri = K::Get()->getConfParam('BASE_KALTURA_API_URL') . "/index.php/$module/$action?" . http_build_query($params, null, '&');

		$commands[] = array(
			'label' => 'Copy curl command', 
			'action' => COMMAND_COPY, 
			'data' => "curl '$uri'", 
		);

		$block = str_replace("  '", "\n  '", $block);
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

		$parsedParams = renewKss($parsedParams);

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

	protected static function addQueryCacheCommands(&$commands, $func, $block, $params)
	{
		if ($func != 'kQueryCache::getCachedQueryResults' ||
			!startsWith($block, 'kQueryCache: returning from memcache'))
		{
			return;
		}

		if (!preg_match_all('/key=([^ ]+) queryTime=(\d+) debugInfo=([^\]]+)\[(\d+)\]/', $block, $matches))
		{
			return;
		}

		$key = $matches[1][0];
		$queryTime = $matches[2][0];
		$server = $matches[3][0];
		$session = $matches[4][0];

		$showQueryParams = array(
			'filter' => array(
				'type' => 'apiLogFilter',
				'server' => $server,
				'session' => $session,
				'fromTime' => $queryTime - 60,
				'toTime' => $queryTime + 60,
				'queryCacheKey' => $key,
			),
			'jwt' => $params['jwt'],
		);

		$baseApiUrl = K::Get()->getConfParam('BASE_KELLOGGS_API_URL');
		$showQueryUrl = $baseApiUrl . '?' . http_build_query($showQueryParams);

		$commands = array_merge($commands,
			self::gotoSessionCommands($server, $session, $queryTime, 'Updating memcache, key=' . $key));
		$commands[] = array('label' => 'Show query', 'action' => COMMAND_LINK, 'data' => $showQueryUrl);		// TODO: change the command type once front supports async text
	}

	protected static function gotoObjectCommands($timestamp, $tableName, $objectId, $margin = 864000)
	{
		$sessionFilter = array(
			'type' => 'dbWritesFilter',
			'fromTime' => $timestamp - $margin,
			'toTime' => $timestamp + $margin,
			'table' => $tableName,
			'objectId' => $objectId,
		);

		return array_merge(
			self::objectInfoCommands($tableName, $objectId), 
			array(
				array('label' => 'Search updates', 'action' => COMMAND_SEARCH, 'data' => $sessionFilter),
				array('label' => 'Search updates in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $sessionFilter),
			));
	}

	protected static function prettyPrintSelect($block, $primaryKeys)
	{
		$parseResult = DbWritesParser::parseSelectStatement($block, $primaryKeys);
		if (!$parseResult)
		{
			return array(self::formatBlock($block), null, null);
		}

		list($selectFields, $selectStatement, $tableName, $objectId) = $parseResult;
		if ($tableName)
		{
			$selectFields = str_replace($tableName . '.', '', $selectFields);
			$selectStatement = str_replace($tableName . '.', '', $selectStatement);
		}

		$selectStatement = str_replace(' AND ', " AND\n  ", $selectStatement);
		$selectStatement = str_replace(' WHERE ', " WHERE\n  ", $selectStatement);

		// hide select fields by default
		if (strlen($selectFields) > 20)
		{
			$result = array();
			$result[] = array('text' => 'SELECT ');
			$result[] = array('text' => '...', 'commands' => array( 
				array('label' => 'Show fields', 'action' => COMMAND_TOOLTIP, 'data' => $selectFields)
			));
			$result[] = array('text' => $selectStatement);
		}
		else
		{
			$result = array(array('text' => 'SELECT ' . $selectFields . $selectStatement));
		}

		return array($result, $tableName, $objectId);
	}

	protected static function formatDbStatements($block, $timestamp, &$commands)
	{
		// strip the comment
		$commentEnd = strpos($block, ' */');
		if ($commentEnd === false)
		{
			return self::formatBlock($block);
		}
		$block = trim(substr($block, $commentEnd + 3));

		// get the primary keys map
		$primaryKeys = self::getPrimaryKeysMap();
		if (!$primaryKeys)
		{
			return self::formatBlock($block);
		}

		if (!startsWith($block, DbWritesParser::STMT_PREFIX_SELECT))
		{
			$parseResult = DbWritesParser::parseWriteStatement($block, $primaryKeys);
			if (!$parseResult)
			{
				$block = self::prettyPrintStatement($block, $commands);
				return self::formatBlock($block);
			}

			list($tableName, $objectId) = $parseResult;
			if ($tableName && $objectId)
			{
				$commands = array_merge($commands,
					self::gotoObjectCommands($timestamp, $tableName, $objectId));
			}

			$block = self::prettyPrintStatement($block, $commands);
			return self::formatBlock($block);
		}

		list($result, $tableName, $objectId) = self::prettyPrintSelect($block, $primaryKeys);

		if ($tableName && $objectId)
		{
			$commands = array_merge($commands,
				self::gotoObjectCommands($timestamp, $tableName, $objectId));
		}

		return $result;
	}

	protected static function formatApiBlock($timestamp, $func, $block, &$commands)
	{
		$block = rtrim($block);

		switch ($func)
		{
		case 'KalturaStatement->execute':
			if (startsWith($block, '/* '))
			{
				return self::formatDbStatements($block, $timestamp, $commands);
			}
			break;

		case 'kSphinxSearchManager->execSphinx':
			$block = self::prettyPrintStatement($block, $commands);
			break;

		case 'KalturaPDO->queryAndFetchAll':
			if (startsWith($block, 'SELECT '))
			{
				$block = str_replace(' AND ', " AND\n  ", $block);
				$block = str_replace(' WHERE ', " WHERE\n  ", $block);
				$block = str_replace(' LIMIT ', "\n  LIMIT ", $block);
				$block = str_replace(' OPTION ', "\n  OPTION ", $block);
			}
			break;
		}

		$commandsByRange = array();
		self::addKsCommands($commandsByRange, $block);
		self::addSourceCodeCommands($commandsByRange, K::Get()->getConfParam('api_source'), $block);
		return self::formatBlock($block, $commandsByRange);
	}

	protected function handleCachedSql()
	{
		$pipe = $this->runGrepCommand();

		$block = '';
		$inQuery = false;
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

				if ($this->multiRanges)
				{
					$fileEndPos = strpos($line, ': ');
					$line = substr($line, $fileEndPos + 2);
				}

				$splittedLine = explode(' ', $line, 9);
				if (count($splittedLine) < 9)
				{
					continue;
				}

				$func = substr($splittedLine[6], 1, -1);
				$block = $splittedLine[8];
				continue;
			}

			switch ($func)
			{
			case 'kQueryCache::getCachedQueryResults':
				if ((startsWith($block, 'kQueryCache: cache miss') || startsWith($block, 'kQueryCache: cached query invalid')) && 
					strpos($block, $this->queryCacheKey) !== false)
				{
					$inQuery = true;
					$result = null;
				}
				break;

			case 'KalturaStatement->execute':
				if (!$inQuery || $result || !startsWith($block, '/* '))
				{
					break;
				}

				$commentEnd = strpos($block, ' */');
				if ($commentEnd === false)
				{
					break;
				}

				$block = trim(substr($block, $commentEnd + 3));
				if (!startsWith($block, DbWritesParser::STMT_PREFIX_SELECT))
				{
					break;
				}

				list($formattedSelect, $ignore, $ignore) = self::prettyPrintSelect($block, array());

				$result = '';
				foreach ($formattedSelect as $curBlock)
				{
					$result .= $curBlock['text'];
				}
				break;

			case 'kQueryCache::cacheQueryResults':
				if (!$inQuery || !startsWith($block, 'kQueryCache: Updating memcache') && strpos($block, $this->queryCacheKey) !== false)
				{
					break;
				}

				if ($result)
				{
					echo $result . "\n";
					die;
				}

				$inQuery = false;
				break;
			}

			$block = '';
		}

		$this->grepCommandFinished();
		echo "Not found\n";
		die;
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
		$curType = count($this->logTypes) == 1 ? reset($this->logTypes) : null;
		$block = '';
		$indent = 0;
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
				$logType = $curType ? array_search($curType, self::$logTypesMap) : null;
				$commands = array_merge($commands,
					self::gotoSessionCommands($curServer, $curSession, $timestamp, null, $logType));
			}
			else
			{
				self::addKalcliCommands($commands, $func, $block);
				self::addPS2CurlCommands($commands, $func, $block);
				self::addQueryCacheCommands($commands, $func, $block, $this->params);
			}

			if ($indent > 0 && startsWith($block, 'consumer ') && strpos($block, 'finished handling'))
			{
				$indent--;
			}

			$formattedBlock = self::formatApiBlock($timestamp, $func, $block, $commands);
			if ($indent > 0)
			{
				$formattedBlock[0]['text'] = str_repeat('| ', $indent) . $formattedBlock[0]['text'];
			}

			if (!$multiSession && startsWith($block, 'consumer ') && strpos($block, 'started handling'))
			{
				$indent++;
			}

			$line = array(
				'severity' => $severity,
				'timestamp' => $timestamp,
				'took' => $took,
				'function' => $func,
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

		if ($this->queryCacheKey)
		{
			$this->handleCachedSql();
		}

		$this->handleRawFormats();

		$this->handleJsonFormat();
	}

	public static function main($params, $filter)
	{
		$obj = new ApiLogFilter($params, $filter);
		$obj->doMain();
	}
}