<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/../shared/Globals.php');
require_once(dirname(__file__) . '/../shared/LogTypes.php');
require_once(dirname(__file__) . '/../shared/DbWritesParser.php');
require_once(dirname(__file__) . '/Common.php');

function updateFileData($pdo, $id, $start, $end, $ranges)
{
	$sql = 'UPDATE kelloggs_files SET status = ?, start = FROM_UNIXTIME(?), end = FROM_UNIXTIME(?), ranges = ? WHERE id = ?';
	$values = array(
		1 => FILE_STATUS_READY,
		2 => $start,
		3 => $end,
		4 => json_encode($ranges),
		5 => $id,
	);
	$stmt = $pdo->executeStatement($sql, $values);
	return ($stmt->rowCount() === 1);
}

function getFileRanges($zgrepIndex, $params, $filePath)
{
	// TODO: break the file to multiple database rows if it spans on more than 25H
	$cmd = $zgrepIndex . ' ' . $params . ' ' . $filePath;
	writeLog("Info: running: $cmd");
	exec($cmd, $output);

	$lastOffset = $lastTime = 0;
	$result = array();
	foreach($output as $line) 
	{
		if (!$line) 
		{
			continue;
		}

		list($startOffset, $endOffset, $startTime, $endTime) = explode("\t", $line);
		$startTime = strtotime($startTime);
		$endTime = strtotime($endTime);

		$result[] = array(
			$startOffset - $lastOffset,
			$endOffset - $startOffset,
			$startTime - $lastTime,
			$endTime - $startTime,
		);

		$lastOffset = $endOffset;
		$lastTime = $endTime;
	}

	if (!$result)
	{
		return false;
	}

	return array($result, $result[0][2], $lastTime);
}

function createDbWritesIndex($confFile, $pdo, $filePath, $mode, $outputType, $id, $start, $end)
{
	// create an index file
	$pathInfo = pathinfo($filePath);
	$indexPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '-index.gz';

	if (K::Get()->hasConfParam('OUTPUT_LOGS_SEARCH'))
	{
		$outputPath = preg_replace(K::Get()->getConfParam('OUTPUT_LOGS_SEARCH'), K::Get()->getConfParam('OUTPUT_LOGS_REPLACE'), $indexPath);
	}
	else
	{
		$outputPath = $indexPath;
	}
	$indexRangesPath = tempnam('/tmp', 'dbindex');
	
	if (isS3Path($indexPath) || isS3Path($outputPath))
	{
		initS3WrapperFromConf();
	}

	$commandLine = 'php ' . dirname(__file__) . "/IndexDBWrites.php '$confFile' '$filePath' '$mode' '$outputPath' '$indexRangesPath'";
	writeLog('Info: running ' . $commandLine);
	passthru($commandLine);

	// add the index file to db
	$ranges = array();
	foreach (file($indexRangesPath) as $line)
	{
		$splittedLine = explode("\t", trim($line));
		if (count($splittedLine) != 2)
		{
			continue;
		}

		list($size, $key) = $splittedLine;
		$ranges[] = array(intval($size), $key);
	}

	unlink($indexRangesPath);

	if ($ranges)
	{
		// delete any previously created rows for this parent, if somehow there are any...
		$sql = 'DELETE FROM kelloggs_files WHERE parent_id = ?';
		$values = array(
			1 => $id,
		);
		$pdo->executeStatement($sql, $values);

		// add a new row
		$sql = 'INSERT INTO kelloggs_files (file_path, file_size, file_mtime, type, status, start, end, ranges, parent_id) VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?, ?)';
		$values = array(
			1 => $indexPath,
			2 => filesize($indexPath),
			3 => filemtime($indexPath),
			4 => $outputType,
			5 => FILE_STATUS_READY,
			6 => $start,
			7 => $end,
			8 => json_encode($ranges),
			9 => $id,
		);

		writeLog('Info: adding to DB with ' . print_r($values, true));
		$pdo->executeStatement($sql, $values);
	}
}

// parse the command line
if ($argc < 5)
{
		echo "Usage:\n\t" . basename(__file__) . " <process index> <ini file paths> <workers ini> <type1>:<id1>:<path1> [<type2>:<id2>:<path2> ...]\n";
		exit(1);
}

$confFile = realpath($argv[2]);
$workers = loadIniFiles($argv[3]);

// initialize
K::init($confFile);
$pdo = K::Get()->getKelloggsRWPdo();
$workers = getWorkerConfById($workers);

for ($index = 4; $index < $argc; $index++)
{
	$fileInfo = explode(':', $argv[$index], 3);
	if (count($fileInfo) < 3)
	{
		writeLog("Error: failed to parse param " . $argv[$index]);
		continue;
	}

	list($type, $id, $filePath) = $fileInfo;

	if (!isset($workers[$type]))
	{
		writeLog("Error: can't find id $type in worker conf");
		continue;
	}

	$workerConf = $workers[$type];
	if (!isset($workerConf['blockPattern']))
	{
		writeLog("Error: worker id $type has no block pattern");
		continue;
	}

	$params = "-p '" . $workerConf['blockPattern'] . "'";
	if (isset($workerConf['timePattern']))
	{
		$params .= " -t '" . $workerConf['timePattern'] . "'";
	}
	if (isset($workerConf['captureExpression']))
	{
		$params .= " -c '" . $workerConf['captureExpression'] . "'";
	}

	// get the file ranges
	$ranges = getFileRanges(getZBinGrepIndexCommand($filePath), $params, $filePath);
	if (!$ranges)
	{
		writeLog("Warning: no ranges found in file $filePath");
		continue;
	}
	list($data, $start, $end) = $ranges;

	// index writes if needed
	$mode = null;
	switch ($type)
	{
	case LOG_TYPE_DB_WRITES:
		$mode = DbWritesParser::MODE_DB_WRITES;
		$outputType = LOG_TYPE_DB_WRITES_INDEX;
		break;

	case LOG_TYPE_SPHINX_WRITES:
		$mode = DbWritesParser::MODE_SPHINX_WRITES;
		$outputType = LOG_TYPE_SPHINX_WRITES_INDEX;
		break;
	}

	if ($mode)
	{
		createDbWritesIndex($confFile, $pdo, $filePath, $mode, $outputType, $id, $start, $end);
	}

	// update the result
	if (updateFileData($pdo, $id, $start, $end, $data))
	{
		writeLog("Info: updated $id");
	}
}
