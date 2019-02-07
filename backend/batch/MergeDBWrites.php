<?php

require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/../shared/Globals.php');

// parse the command line
if ($argc < 4)
{
	echo "Usage:\n\t" . basename(__file__) . " <ini file paths> <output path> <input path1> <input path2> ...\n";
	exit(1);
}

$confFile = $argv[1];
$outputPath = $argv[2];
$inputPaths = array_slice($argv, 3);

if (count($inputPaths) == 1)
{
	writeLog('Info: only one input file, nothing to do');
	exit(0);
}

// initialize
K::init($confFile);
$pdo = K::Get()->getKelloggsRWPdo();

if (K::Get()->hasConfParam('OUTPUT_LOGS_SEARCH'))
{
	$outputPath = preg_replace(K::Get()->getConfParam('OUTPUT_LOGS_SEARCH'), K::Get()->getConfParam('OUTPUT_LOGS_REPLACE'), $outputPath);
}

$tempOutputPath = $outputPath . '.tmp';

// get the ids of the input files
$sql = 'SELECT id FROM kelloggs_files WHERE file_path IN (@ids@)';
$stmt = $pdo->executeInStatement($sql, $inputPaths);
$rows = $stmt->fetchall(PDO::FETCH_NUM);

if (count($rows) != count($inputPaths))
{
	writeLog('Error: failed to fetch one or more files from the database');
	exit(1);
}

$fileIds = array_map('reset', $rows);

// get the ids + paths of the children of the input files
$sql = 'SELECT id, file_path FROM kelloggs_files WHERE parent_id IN (@ids@)';
$stmt = $pdo->executeInStatement($sql, $fileIds);
$rows = $stmt->fetchall(PDO::FETCH_NUM);

if (count($rows) != count($inputPaths))
{
	writeLog('Error: failed to fetch one or more index files from the database');
	exit(1);
}

$fileIds = array_merge($fileIds, array_map('reset', $rows));
$childPaths = array();
foreach ($rows as $row)
{
	$childPaths[] = $row[1];
}

// merge the input files
$totalSize = 0;
$commandLine = 'cat';
foreach ($inputPaths as $inputPath)
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
	exit(1);
}

// delete from disk
foreach (array_merge($inputPaths, $childPaths) as $filePath)
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

// delete from db
$sql = 'DELETE FROM kelloggs_files WHERE id IN (@ids@)';
$stmt = $pdo->executeInStatement($sql, $fileIds);
if ($stmt->rowCount() !== count($fileIds))
{
	writeLog('Error: not all rows were deleted from the database');
}

// rename the output file
rename($tempOutputPath, $outputPath);
