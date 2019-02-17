<?php

require_once(dirname(__file__) . '/BaseLogFilter.php');

define('PATTERN_API_ANALYTICS', '^(\d{4}\-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) [^ ]+ \[(\d+)\]');		// $1 = timestamp, $2 = session
define('TIME_FORMAT_API', '%Y-%m-%d %H:%M:%S');

class ApiAnalyticsLogFilter extends BaseLogFilter
{
	protected function getGrepCommand()
	{
		list($fileRanges, $this->totalSize, $fileMap) = self::getFileRanges(array(LOG_TYPE_APIV3_ANALYTICS), $this->fromTime, $this->toTime, $this->serverPattern);
		if (!$fileRanges)
		{
			dieError(ERROR_NO_RESULTS, 'No logs matched the search filter');
		}

		$fileRanges = implode(' ', $fileRanges);

		$pattern = PATTERN_API_ANALYTICS;

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
			array('label' => 'Message', 	'name' => 'body', 		'type' => 'richText'),
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
			$splittedLine = explode(' ', $line, 5);
			if (count($splittedLine) < 5)
			{
				continue;
			}
			list($date, $time, $ip, $curSession, $block) = $splittedLine;
			$timestamp = strtotime("$date $time");
			$ip = substr($ip, 1, -1);
			$curSession = substr($curSession, 1, -2);

			// block finished
			$formattedBlock = self::formatBlock($block);

			$line = array(
				'timestamp' => $timestamp,
				'ip' => $ip,
				'body' => $formattedBlock,
				'commands' => self::gotoSessionCommands($curServer, $curSession, $timestamp),
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
		$obj = new ApiAnalyticsLogFilter($params, $filter);
		$obj->doMain();
	}	
}
