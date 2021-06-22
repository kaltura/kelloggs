<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/Common.php');

define('LOCK_ATTEMPTS', 10);

function lockTask($pdo)
{
	for ($attempt = 0; $attempt < LOCK_ATTEMPTS; $attempt++)
	{
		$sql = 'SELECT id, file_path, type FROM kelloggs_files WHERE status = ? ORDER BY id ASC LIMIT 1';
		$values = array(
			1 => FILE_STATUS_FOUND
		);
		$stmt = $pdo->executeStatement($sql, $values);
		$row = $stmt->fetch(PDO::FETCH_NUM);
		if (!$row)
		{
			break;
		}
		list($id, $filePath, $type) = $row;

		$sql = 'UPDATE kelloggs_files SET status = ? WHERE status = ? AND id = ?';
		$values = array(
			1 => FILE_STATUS_LOCKED,
			2 => FILE_STATUS_FOUND,
			3 => $id,
		);
		$stmt = $pdo->executeStatement($sql, $values);
		if ($stmt->rowCount() !== 1)
		{
			writeLog("Info: task $taskId already locked, retrying");
			usleep(rand(0, 1000000));
			continue;
		}
		writeLog("Info: locked task $id");
		return array($id, $filePath, $type);
	}
	return false;
}

function getRunningTaskIndexes($processGroupName)
{
	$result = array();
	exec("ps aux | grep php | grep $processGroupName | grep -v grep", $output);
	foreach ($output as $curLine)
	{
		$splittedLine = explode("$processGroupName-", $curLine);
		if (count($splittedLine) < 2)
		{
			continue;
		}

		$splittedLine = explode(' ', $splittedLine[1]);
		if (count($splittedLine) < 2)
		{
			continue;
		}

		$processIndex = intval($splittedLine[0]);
		$result[$processIndex] = true;
	}

	return $result;
}

// parse the command line
if (count($argv) < 5)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <workers ini> <process group name> <worker processes>\n";
	exit(1);
}

$dbConfFile = realpath($argv[1]);
$workersFile = realpath($argv[2]);
$processGroupName = $argv[3];
$workerProcesses = $argv[4];

// load the configuration
$dbConf = loadIniFiles($dbConfFile);
$pdo = PdoWrapper::create($dbConf['kelloggsdb'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$workers = loadIniFiles($workersFile);
$workers = getWorkerConfById($workers);

// get running task indexes
$runningIndexes = getRunningTaskIndexes($processGroupName);
ksort($runningIndexes);
writeLog('Info: running process indexes: ' . implode(',', array_keys($runningIndexes)));

if (count($runningIndexes) == $workerProcesses)
{
	writeLog('Info: No open slots running ' . count($runningIndexes) . " available $workerProcesses");
	exit(0);
}

for ($processIndex = 0; $processIndex < $workerProcesses; $processIndex++)
{
	if (isset($runningIndexes[$processIndex]))
	{
		continue;
	}

	// lock a task
	$taskInfo = lockTask($pdo);
	if (!$taskInfo)
	{
		writeLog('Info: no task to run');
		break;
	}

	list($id, $filePath, $type) = $taskInfo;

	$scriptPath = dirname(__file__) . '/FileRanger.php';
	$logFolder = '/var/log/kelloggs/';
	$logPath = $logFolder . 'ranger-' . gethostname() . date('-Y-m-d-') . $processIndex . '.log';

	// start the task
	$commandLine = "php $scriptPath $processGroupName-$processIndex $dbConfFile $workersFile $type:$id:$filePath >> $logPath 2>&1 &";
	writeLog("Info: running: $commandLine");
	exec($commandLine);
}

writeLog('Info: done');
