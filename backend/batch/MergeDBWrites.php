<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/../shared/Globals.php');

function filterInputFile($filePath)
{
	global $excludePattern;
	return !strpos($filePath, $excludePattern);
}

function getDbWriteFiles($dbName, $conf)
{
	$from = $conf['interval_from'];
	$to = $conf['interval_to'];
	$globPattern = $conf['glob_pattern'];
	
	writeLog("Info: getting file list $globPattern from $from to $to");
	return array_filter(dateGlob($globPattern, $from, $to), 'filterInputFile');
}

function getDbWritesIndexFiles($pdo, $files)
{
	//Get file ids from DB
	$sql = 'SELECT id FROM kelloggs_files WHERE file_path IN (@ids@)';
	$stmt = $pdo->executeInStatement($sql, $files);
	$rows = $stmt->fetchall(PDO::FETCH_NUM);
//	if (count($rows) != count($files))
//	{
//		writeLog('Error: failed to fetch one or more index files from the database');
//		return array(false, false);
//	}
	
	$fileIds = array_map('reset', $rows);
	
	//For each file get the id of its index file
	// get the ids + paths of the children of the input files
	$sql = 'SELECT id, file_path FROM kelloggs_files WHERE parent_id IN (@ids@)';
	$stmt = $pdo->executeInStatement($sql, $fileIds);
	$rows = $stmt->fetchall(PDO::FETCH_NUM);
//	if (count($rows) != count($files))
//	{
//		writeLog('Error: failed to fetch one or more index files from the database');
//		return array(false, false);
//	}
	
	$fileIds = array_merge($fileIds, array_map('reset', $rows));
	$childPaths = array();
	foreach ($rows as $row)
	{
		$childPaths[] = $row[1];
	}
	
	return $childPaths;
}

function mergeInputFiles($files, $tempOutputPath)
{
	$streamConcat = getStreamHelper(reset($files));
	return $streamConcat->concat($files, $tempOutputPath);
}

function mergeInputFilesOld($files, $tempOutputPath)
{
	// merge the input files
	$totalSize = 0;
	$commandLine = 'cat';
	foreach ($files as $inputPath)
	{
		$totalSize += filesize($inputPath);
		$commandLine .= ' "' . str_replace('"', '\\"', $inputPath) . '"';
	}
	$commandLine .= ' > ' . $tempOutputPath;
	writeLog('Info: running ' . $commandLine);
	passthru($commandLine);
	
	if (filesize($tempOutputPath) != $totalSize)
	{
		writeLog('Error: invalid merged file size');
		return false;
	}
}

function deleteMergedFiles($files, $indexFilePaths)
{
	// delete from disk
	foreach (array_merge($files, $indexFilePaths) as $filePath)
	{
		if (K::Get()->hasConfParam('OUTPUT_LOGS_SEARCH'))
		{
			$filePath = preg_replace(K::Get()->getConfParam('OUTPUT_LOGS_SEARCH'), K::Get()->getConfParam('OUTPUT_LOGS_REPLACE'), $filePath);
		}
		
		if (!unlink($filePath))
		{
			writeLog('Error: failed to delete ' . $filePath);
		}
	}
}

function deleteMergedFileRecords($pdo, $fileIds)
{
	// delete from db
	$sql = 'DELETE FROM kelloggs_files WHERE id IN (@ids@)';
	$stmt = $pdo->executeInStatement($sql, $fileIds);
	if ($stmt->rowCount() !== count($fileIds))
	{
		writeLog('Error: not all rows were deleted from the database');
	}
}

function mergeDbWrites($pdo, $files, $outputPath)
{
	if (K::Get()->hasConfParam('OUTPUT_LOGS_SEARCH'))
	{
		$outputPath = preg_replace(K::Get()->getConfParam('OUTPUT_LOGS_SEARCH'), K::Get()->getConfParam('OUTPUT_LOGS_REPLACE'), $outputPath);
	}
	
	$tempOutputPath = $outputPath;
	if (!isS3Path($outputPath))
	{
		$tempOutputPath = $outputPath . '.tmp';
	}
	
	//Get index files records
	$dbWritesIndexFiles = getDbWritesIndexFiles($pdo, $files);
	if (!$dbWritesIndexFiles)
	{
		return false;
	}
	
	// merge the input files
	if (!mergeInputFiles($files, $tempOutputPath))
	{
		return false;
	}
	
	deleteMergedFiles($files, $dbWritesIndexFiles);
	
	// rename the output file
	if (!isS3Path($tempOutputPath))
	{
		writeLog("Log: renaming output file from $tempOutputPath to $outputPath");
		rename($tempOutputPath, $outputPath);
	}
	
}

// parse the command line
if ($argc < 3)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <db writes ini file path>\n";
	exit(1);
}

$confFile = $argv[1];
$dbWritesConfig = loadIniFiles($argv[2]);

//Init Kaltura conf
K::init($confFile);
$pdo = K::Get()->getKelloggsRWPdo();
$excludePattern = $dbWritesConfig['exclude_patterns'];
$outFileDateTemplate = $dbWritesConfig['out_file_date_template'];

foreach ($dbWritesConfig['tasks'] as $dbName => $task)
{
	$files = getDbWriteFiles($dbName, $task);
	if (count($files) <= 1)
	{
		writeLog('Info: only one input file, nothing to do');
		continue;
	}
	
	$toDate = new DateTime($task['interval_to']);
	$outputPath = dirname(reset($files)) . "/" .
		str_replace($outFileDateTemplate, $toDate->format('Y-m-d'), $task['output_path']);
	
	mergeDbWrites($pdo, $files, $outputPath);
}