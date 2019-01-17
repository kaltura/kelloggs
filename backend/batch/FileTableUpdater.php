<?php

require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/Common.php');

define('FILEMTIME_MARGIN', 900);
define('MIN_LOG_FILE_SIZE', 32);
define('DB_MAX_SELECT_IN', 100);

function filterInputFile($fileName)
{
	return ($fileName[0] != '.' && substr($fileName, -3) == '.gz' && filesize($fileName) > MIN_LOG_FILE_SIZE);
}

function getFilesFromDir($curGlobPatterns) 
{
	$inputFiles = array();
	foreach ($curGlobPatterns as $curGlobPattern) 
	{
		writeLog("Info: getting file list $curGlobPattern");
		$curInputFiles = array_filter(dateGlob($curGlobPattern, '5 days ago', 'now'), 'filterInputFile');
		$inputFiles = array_merge($inputFiles, $curInputFiles);
	}
	return $inputFiles;
}

function getAllFilesByWorkerType($workers) 
{
	$files = array();
	foreach ($workers as $workerId => $section)
	{
		if (!isset($section['globPatterns']))
		{
			continue;
		}

		$files[$workerId] = getFilesFromDir(explode(',', $section['globPatterns']));
	}
	return $files;
}

function removeFilesExistingInDb($pdo, &$files) 
{
	writeLog('Info: getting all file paths from the database');

	foreach($files as $type => $fileList) 
	{
		$chunks = array_chunk($fileList, DB_MAX_SELECT_IN);

		$fileList = array_flip($fileList);		// enable fast lookup by path
		foreach($chunks as $chunk) 
		{
			$sql = 'SELECT file_path FROM kelloggs_files WHERE type = ? AND file_path IN (@ids@)';
			$values = array($type);
			$stmt = $pdo->executeInStatement($sql, $chunk, $values);
			$rows = $stmt->fetchall(PDO::FETCH_NUM);
			foreach ($rows as $row) 
			{
				unset($fileList[$row[0]]);
			}
		}

		$files[$type] = array_flip($fileList);
	}
}

function addFilesToDB($pdo, $type, $filenamePattern, $files)
{
	writeLog('Info: adding new files to the database (' . count($files). ' files)');
	$server = null;
	$result = 0;
	foreach ($files as $filePath) 
	{
		if (filemtime($filePath) > time() - FILEMTIME_MARGIN)	// skip files recently updated
		{
			continue;
		}

		if ($filenamePattern)
		{
			if (!preg_match($filenamePattern, basename($filePath), $matches))
			{
				continue;
			}

			if (isset($matches['server']))
			{
				$server = $matches['server'];
			}
		}

		$values = array(
			1 => $filePath,
			2 => filesize($filePath),
			3 => filemtime($filePath),
			4 => $server,
			5 => $type,
			6 => FILE_STATUS_FOUND,
		);

		writeLog('Info: adding to DB with ' . print_r($values, true));
		$sql = 'INSERT INTO kelloggs_files (file_path, file_size, file_mtime, server, type, status) VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?)';
		$pdo->executeStatement($sql, $values);
		$result++;
	}
	return $result;
}

// parse the command line
if ($argc < 3)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <workers ini>\n";
	exit(1);
}

$conf = loadIniFiles($argv[1]);
$workers = loadIniFiles($argv[2]);

// initialize
$pdo = PdoWrapper::create($conf['kelloggsdb'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$workers = getWorkerConfById($workers);

// get the files from disk
$files = getAllFilesByWorkerType($workers);

$totalFile = array_sum(array_map('count', $files));
writeLog("Info: $totalFile files found on disk: " . print_r($files, true));

// remove files that already exist in the db
removeFilesExistingInDb($pdo, $files);

$totalFile = array_sum(array_map('count', $files));
writeLog("Info: $totalFile files to add to db: " . print_r($files, true));

// add new files
$added = 0;
foreach($files as $type => $fileList)
{
	$filenamePattern = isset($workers[$type]['filenamePattern']) ? $workers[$type]['filenamePattern'] : null;
	$added += addFilesToDB($pdo, $type, $filenamePattern, $fileList);
}

writeLog("Done: Done, added $added files");
