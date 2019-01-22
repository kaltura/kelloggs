<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Stream.php');
require_once(dirname(__file__) . '/../shared/DbWritesParser.php');
require_once(dirname(__file__) . '/../shared/Globals.php');

define('TIME_GRANULARITY', 60);

$ignoredTables = array(
	'scheduler_worker',		// frequent keep alive updates
	'caption_asset_item',	// many inserts when upload a caption asset
	'server_node',			// frequent heartbeat updates
	'tag',					// many updates for instance count
);

function buildIndex($inputPath, $primaryKeys)
{
	$reader = new GZipReader($inputPath, 1024 * 1024);
	$parser = new DbWritesParser($primaryKeys);

	$result = array();
	for (;;)
	{
		// parse the line
		$line = $reader->getLine();
		if ($line === false)
		{
			break;
		}

		$parseResult = $parser->processLine($line);
		if (!$parseResult)
		{
			continue;
		}

		list($tableName, $id, $timestamp, $comment, $statement) = $parseResult;
		$timestamp = intdiv($timestamp, TIME_GRANULARITY) * TIME_GRANULARITY;

		$key = $tableName . '_' . $id;
		if (!isset($result[$key]))
		{
			$result[$key] = array();
		}
		$result[$key][$timestamp] = 1;
	}

	ksort($result);

	return $result;
}

function writeIndex($outputPath, $rangesPath, $index)
{
	$count = 0;
	$writer = new GZipWriter($outputPath);

	$rangesFile = fopen($rangesPath, 'w');

	reset($index);
	$firstKey = key($index);
	fwrite($rangesFile, "0\t$firstKey\n");
	$lastFileSize = 0;

	foreach ($index as $key => $timestamps)
	{
		$count++;
		if ($count % 1000 == 0 && $writer->tell() > 10 * 1024 * 1024)
		{
			$fileSize = $writer->flush();
			fwrite($rangesFile, ($fileSize - $lastFileSize) . "\t$key\n");
			$lastFileSize = $fileSize;
		}

		$timestamps = array_keys($timestamps);
		$lastTimestamp = array_shift($timestamps);
		$line = $key . ' ' . $lastTimestamp;

		foreach ($timestamps as $timestamp)
		{
			$line .= ',' . ($timestamp - $lastTimestamp);
			$lastTimestamp = $timestamp;
		}
		$writer->write($line . "\n");
	}
	$writer->close();

	clearstatcache();
	$fileSize = filesize($outputPath);
	fwrite($rangesFile, ($fileSize - $lastFileSize) . "\t$key\n");
	fclose($rangesFile);
}

// parse the command line
if ($argc < 5)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <input path> <output path> <ranges path>\n";
	exit(1);
}

$confFile = $argv[1];
$inputPath = $argv[2];
$outputPath = $argv[3];
$rangesPath = $argv[4];

K::init($confFile);
$pdo = K::get()->getProdPdo();

writeLog('Info: started, pid=' . getmypid());

$primaryKeys = DbWritesParser::getPrimaryKeysMap($pdo);

// remove ignored tables
foreach ($ignoredTables as $table)
{
	unset($primaryKeys[$table]);
}

writeLog('Info: indexing ' . $inputPath);
$index = buildIndex($inputPath, $primaryKeys);

writeLog('Info: writing ' . count($index) . ' lines to ' . $outputPath);
writeIndex($outputPath, $rangesPath, $index);

writeLog('Info: done');
