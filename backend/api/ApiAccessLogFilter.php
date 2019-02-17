<?php

require_once(dirname(__file__) . '/BaseLogFilter.php');

define('PATTERN_API_ACCESS', '^[^ ]+ [^ ]+ [^ ]+ \[([^\]]+)\]');	// $1 = timestamp
define('TIME_FORMAT_API_ACCESS', '%d/%b/%Y:%H:%M:%S %z');

class ApiAccessLogFilter extends BaseLogFilter
{
	protected function getGrepCommand()
	{
		list($fileRanges, $this->totalSize, $fileMap) = self::getFileRanges(array(LOG_TYPE_API_ACCESS), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$fileRanges = implode(' ', $fileRanges);

		$pattern = PATTERN_API_ACCESS;
		$timeFormat = TIME_FORMAT_API_ACCESS;

		$captureConditions = array(
			'$1@>=' . strftime($timeFormat, $this->fromTime),
			'$1@<=' . strftime($timeFormat, $this->toTime),
		);

		if ($this->session)
		{
			$captureConditions[] = '$2=' . $this->session;
		}

		$captureConditions = implode(',', $captureConditions);

		$filters = array();
		if ($this->session)
		{
			$filters[] = array('type' => 'match', 'text' => $this->session);
		}
		if ($this->textFilter)
		{
			$filters[] = $this->textFilter;
		}

		$textFilter = self::getAndTextFilter($filters);

		$this->grepCommand = $this->zblockgrep . " -H -p '$pattern' -t '$timeFormat' -c '$captureConditions' $textFilter $fileRanges";

		$this->setFileMap($fileMap);
	}

	protected function getResponseHeader()
	{
		$columns = array();
		$metadata = array();

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
			array('label' => 'Client ip', 	'name' => 'ip', 		'type' => 'text'),
			array('label' => 'Request line', 	'name' => 'body', 		'type' => 'richText'),
			array('label' => 'Host', 	'name' => 'host', 		'type' => 'text'),
			array('label' => 'Protocol', 	'name' => 'protocol', 		'type' => 'text'),
			array('label' => 'Referrer', 	'name' => 'referrer', 		'type' => 'text'),
			array('label' => 'User agent', 	'name' => 'userAgent', 		'type' => 'text'),
			array('label' => 'Bytes received', 	'name' => 'bytesReceived', 		'type' => 'text'),
			array('label' => 'Status', 	'name' => 'status', 		'type' => 'text'),
			array('label' => 'Bytes sent', 	'name' => 'bytesSent', 		'type' => 'text'),
			array('label' => 'Execution time', 	'name' => 'executionTime', 		'type' => 'text'),
			array('label' => 'Kaltura error', 	'name' => 'kalturaError', 		'type' => 'text'),
			array('label' => 'Connection status', 	'name' => 'connectionStatus', 		'type' => 'text'),
			array('label' => 'Partner id', 	'name' => 'partnerId', 		'type' => 'text'),
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
		// run the grep process
		$pipe = $this->runGrepCommand();

		// output the response header
		$header = $this->getResponseHeader();
		echo json_encode($header) . "\n";

		for (;;)
		{
			$line = fgets($pipe);
			if ($line === false)
			{
				break;
			}

			// get the server name
			$fileInfo = $this->stripFileNameFromLine($line);
			if (!$fileInfo)
			{
				continue;
			}
			list($curServer, $ignore) = $fileInfo;

			// parse the line fields
			$parsedLine = AccessLogParser::parse($line);

			$curSession = $parsedLine[16];
			if ($this->session && $curSession != $this->session)
			{
				continue;
			}
			
			$ipAddress = $parsedLine[12];
			if (!filter_var($ipAddress, FILTER_VALIDATE_IP))
			{
				$ipAddress = $parsedLine[0];
			}

			$xForwardedFor = $parsedLine[20];

			$remoteAddr = getIpAddress($ipAddress, $xForwardedFor);
			
			$timestamp = strtotime(substr($parsedLine[3] . ' ' . $parsedLine[4], 1, -1));

			$line = array(
				'timestamp' => $timestamp,

				// client -> server
				'body' => self::formatBlock($parsedLine[5]),
				'host' => $parsedLine[14],
				'protocol' => $parsedLine[11] == 'ON' ? 'HTTPS' : 'HTTP',
				'ip' => ($remoteAddr ? $remoteAddr : $ipAddress),
				'referrer' => $parsedLine[9],
				'userAgent' => $parsedLine[10],
				'bytesReceived' => $parsedLine[18],

				// server -> client
				'status' => $parsedLine[6],
				'bytesSent' => $parsedLine[7],
				'executionTime' => self::parseApacheExecutionTime($parsedLine[8]),
				'kalturaError' => $parsedLine[13],
				'connectionStatus' => self::formatApacheConnectionStatus($parsedLine[17]),
				'partnerId' => $parsedLine[24],
			);
			
			$commands = array();
			if ($curSession != '-')
			{
				$commands = self::gotoSessionCommands($curServer, $curSession, $timestamp);
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
		$obj = new ApiAccessLogFilter($params, $filter);
		$obj->doMain();
	}	
}
