<?php

function enableStreamingOutput()
{
	header('Content-Encoding: UTF-8');
	header('Charset: UTF-8');
	header('X-Accel-Buffering: no');
	ob_implicit_flush();
	ob_end_flush();
}

header("Content-Type: text/plain");
header('Access-Control-Allow-Origin: *');

enableStreamingOutput();

$pattern = '^\d{4}\-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [^ ]+ [^ ]+ \[(\d+)\]';
$cmd = "/opt/server-native-utils/log_compressor/zblockgrep/zblockgrep -p '$pattern' -c '$1=50621942' -d-- /web/logs/investigate/2018/01/01/ny-front-api1-kaltura_api_v3.log-2018-01-01-00-20.gz:54659119-62568741";

$descriptorspec = array(
   1 => array("pipe", "w"),
   2 => array("pipe", "w")
);

$process = proc_open($cmd, $descriptorspec, $pipes, realpath('./'), array());
if (!is_resource($process))
	die('failed to run process');

$header = array(
	'type' => 'searchResponse',
	'columns' => array(
		array('name' => 'timestamp', 'type' => 'timestamp', 'label' => 'timestamp'),
		array('name' => 'executionTime', 'type' => 'float', 'levels' => array(5, 10)),
		array('name' => 'function', 'type' => 'text', 'label' => 'function'),
		array('name' => 'severity', 'type' => 'severity', 'label' => 'severity'),
		array('name' => 'indent', 'type' => 'indent', 'label' => 'indent by event consumer level', 'column' => 'body'),
		array('name' => 'body', 'type' => 'richText', 'label' => 'message'),
	),
	'commands' => array(
		array('label' => 'copy grep command', 'action' => 'copyToClipboard', 'data' => 'zbingrepapi 1232 /web/logs/investigate/'),
	),
	'metadata' => array(
		array('label' => 'server', 'value' => 'ny-front-api21'),
		array('label' => 'session', 'value' => '1234'),
		array('label' => 'client', 'children' => array(
			array('label' => 'ip', 'value' => '1.2.3.4')
		)),
	),
);
echo json_encode($header) . "\n";

$block = '';
while ($s = fgets($pipes[1]))
{
	if ($s == "--\n")
	{
		$line = array(
			'timestamp' => strtotime("$date $time"),
			'executionTime' => floatval($took),
			'function' => $func,
			'severity' => $severity,
			'indent' => 0,
			'body' => array(array('text' => rtrim($block))),
		);
		echo json_encode($line) . "\n";
		$block = '';
	}
	else
	{
		if (!$block)
		{
			$splittedLine = explode(' ', $s, 9);
			list($date, $time, $took, $ip, $session, $context, $func, $severity, $block) = $splittedLine;
			$took = substr($took, 1, -1);
			$ip = substr($ip, 1, -1);
			$session = substr($session, 1, -1);
			$context = substr($context, 1, -1);
			$func = substr($func, 1, -1);
			$severity = strtolower(substr($severity, 0, -1));
		}
		else
		{
			$block .= $s;
		}
	}
}

