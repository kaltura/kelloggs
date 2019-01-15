<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/Common.php');

function updateFileData($pdo, $id, $start, $end, $ranges)
{
    $sql = 'UPDATE kelloggs_files SET status = ?, start = FROM_UNIXTIME (?), end = FROM_UNIXTIME(?), ranges = ? WHERE id = ?';
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

// parse the command line
if ($argc < 5)
{
        echo "Usage:\n\t" . basename(__file__) . " <process index> <ini file paths> <workers ini> <type1>:<id1>:<path1> [<type2>:<id2>:<path2> ...]\n";
        exit(1);
}

$conf = loadIniFiles($argv[2]);
$workers = loadIniFiles($argv[3]);

// initialize
$pdo = PdoWrapper::create($conf['kelloggsdb'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
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
	
	// get the file ranges
	$ranges = getFileRanges($conf['ZGREPINDEX'], $params, $filePath);
	if (!$ranges)
	{
		writeLog("Warning: no ranges found in file $filePath");
		continue;
	}

	// update the result
    list($data, $start, $end) = $ranges;
    if (updateFileData($pdo, $id, $start, $end, $data))
	{
		writeLog("Info: updated $id");
	}
}
