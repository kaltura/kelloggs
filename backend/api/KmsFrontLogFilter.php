<?php

require_once(dirname(__file__) . '/BaseLogFilter.php');

define('PATTERN_KMS_FRONT', '^[^ ]+ \[([^\]]+)\] \[(\d+)\.?\d*\]');		// $1 = session, $2 = timestamp
define('TIME_FORMAT_API', '%Y-%m-%d %H:%M:%S');

class KmsFrontLogFilter extends BaseLogFilter
{
	protected function getGrepCommand()
	{
		list($fileRanges, $this->totalSize, $fileMap) = self::getFileRanges(array(LOG_TYPE_KMS_FRONT), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$fileRanges = implode(' ', $fileRanges);

		$pattern = PATTERN_KMS_FRONT;

		$captureConditions = array(
			'$2#>=' . $this->fromTime,
			'$2#<=' . $this->toTime,
		);

		if ($this->session)
		{
			$captureConditions[] = '$1=' . $this->session;
		}

		$captureConditions = implode(',', $captureConditions);

		$textFilter = self::getTextFilterParam($this->textFilter);

		$this->grepCommand = $this->zblockgrep . " -H -p '$pattern' -c '$captureConditions' $textFilter $fileRanges";

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
			array('label' => 'Type', 		'name' => 'type', 		'type' => 'text'),
			array('label' => 'Host', 		'name' => 'host', 		'type' => 'text'),
			array('label' => 'Url', 		'name' => 'body', 		'type' => 'richText'),
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
			$splittedLine = explode(' ', $line, 8);
			if (count($splittedLine) < 8)
			{
				continue;
			}
			list($type, $curSession, $timestamp, $ignore, $ignore, $host, $ip, $url) = $splittedLine;
			$curSession = substr($curSession, 1, -1);
			$timestamp = intval(substr($timestamp, 1, -1));
			$host = substr($host, 1, -1);
			$ip = substr($ip, 1, -1);

			// block finished
			switch ($type)
			{
			case 'start':
				$fromTime = $timestamp - 10;
				$toTime = $timestamp + 300;
				break;

			case 'end':
				$fromTime = $timestamp - 300;
				$toTime = $timestamp + 10;
				break;

			default:
				continue 2;
			}
			
			$sessionFilter = array(
				'type' => 'kmsLogFilter',
				'server' => $curServer,
				'session' => $curSession,
				'fromTime' => $fromTime,
				'toTime' => $toTime,
			);

			$commands = array(
				array('label' => 'Go to session', 'action' => COMMAND_SEARCH, 'data' => $sessionFilter),
				array('label' => 'Open session in new tab', 'action' => COMMAND_SEARCH_NEW_TAB, 'data' => $sessionFilter),
			);
			
			$line = array(
				'timestamp' => $timestamp,
				'type' => $type,
				'host' => $host,
				'ip' => $ip,
				'body' => self::formatBlock($url),
				'commands' => $commands,
			);

			if (!$this->session)
			{
				$line['session'] = $curSession;
			}

			if (!$this->server)
			{
				$line['server'] = $curServer;
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
		$obj = new KmsFrontLogFilter($params, $filter);
		$obj->doMain();
	}	
}
