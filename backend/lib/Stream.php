<?php
require_once(dirname(__file__) . '/Utils.php');

use Aws\S3\S3Client;
use Aws\Sts\StsClient;

use Aws\S3\Exception\S3Exception;
use Aws\Exception\AwsException;
use Aws\S3\Enum\CannedAcl;


define('S3_PREFIX', 's3://');
define('S3_CONF_SECTION', 'S3');

/* @var S3Client $client */
$client = null;

// readers
class GZipReader
{
	protected $handle;
	protected $maxLineSize;

	public function __construct($inputFile, $maxLineSize = 16384)
	{
		$this->handle = @gzopen($inputFile, 'r');
		if (!$this->handle)
		{
			writeLog("Error: failed to open input file {$inputFile}");
			die;
		}
		$this->maxLineSize = $maxLineSize;
	}

	public function __destruct()
	{
		if ($this->handle !== false)
		{
			gzclose($this->handle);
		}
	}

	public function getLine()
	{
		if (gzeof($this->handle))
		{
			return false;
		}

		return gzgets($this->handle, $this->maxLineSize);
	}
}

class GZipS3Reader
{
	protected $process = null;
	protected $pipe = null;
	
	public function __construct($inputFile, $profile = null)
	{
		// Note: using external processes since:
		//	1. gzopen works only with a real file handle, can't handle an S3 stream created by the AWS sdk,
		//		since it uses zlib's gzdopen function. also, it doesn't accept a file handle, only file name.
		//	2. it is possible to create a stream filter that decompresses gzip -
		//		stream_filter_append($s, 'zlib.inflate', STREAM_FILTER_READ, array('window' => 30));
		//		but this doesn't work since the nginx logs are composed of multiple blocks,
		//		and once the stream filter gets the eof on the first block, it stops.
		
		$cmd = "aws s3 cp ";
		if ($profile)
		{
			$cmd .= " --profile $profile ";
		}
		$cmd .= "'{$inputFile}' - | gzip -d";
		
		$this->process = proc_open($cmd, array(1 => array("pipe", "w")), $pipes);
		if (!is_resource($this->process))
		{
			writeLog('Error: failed to read s3 file');
			die;
		}
		
		$this->pipe = $pipes[1];
	}
	
	public function __destruct()
	{
		fclose($this->pipe);
		proc_close($this->process);
	}
	
	public function getLine()
	{
		$result = fgets($this->pipe);
		if ($result !== false)
		{
			return $result;
		}
		
		// wait for the process to complete
		$exitCode = null;
		for ($i = 0; $i < 10; $i++)
		{
			$status = proc_get_status($this->process);
			if ($status['running'] !== false)
			{
				sleep(1);
				continue;
			}
			
			$exitCode = $status['exitcode'];
			break;
		}
		
		if (is_null($exitCode))
		{
			writeLog('Error: timed out waiting for aws/gzip to complete');
			die;
		}
		
		if ($exitCode)
		{
			writeLog('Error: aws/gzip returned an error');
			die;
		}
		
		return false;
	}
}

class GZipS3CopyReader
{
	protected $handle;
	protected $tempFile;
	
	public function __construct($inputFile, $profile = null)
	{
		$this->tempFile = tempnam('/tmp/', 's3reader-');
		if (!$this->tempFile)
		{
			writeLog("Error: failed to create a temporary file");
			die;
		}
		
		$cmd = "aws s3 cp ";
		if ($profile)
		{
			$cmd .= " --profile $profile ";
		}
		$cmd .= "{$inputFile} {$this->tempFile}";
		
		exec($cmd, $output, $exitCode);
		if ($exitCode)
		{
			writeLog("Error: failed to copy {$inputFile} to {$this->tempFile}");
			die;
		}
		
		$this->handle = @gzopen($this->tempFile, 'r');
		if (!$this->handle)
		{
			writeLog("Error: failed to open temporary file {$this->tempFile}");
			die;
		}
	}
	
	public function __destruct()
	{
		if ($this->handle !== false)
		{
			gzclose($this->handle);
		}
		
		if ($this->tempFile)
		{
			unlink($this->tempFile);
		}
	}
	
	public function getLine()
	{
		if (gzeof($this->handle))
		{
			return false;
		}
		
		return gzgets($this->handle, 16384);
	}
}

// writers
class GZipWriter
{
	protected $handle;
	protected $outputPath;
	protected $tempOutputPath;

	function __construct($outputPath)
	{
		$this->outputPath = $outputPath;
		$this->tempOutputPath = "{$outputPath}.tmp";
		$this->handle = @gzopen($this->tempOutputPath, 'w');
		if (!$this->handle)
		{
			writeLog("Error: failed to open output file {$outputPath}");
			die;
		}
	}

	function close()
	{
		gzclose($this->handle);
		rename($this->tempOutputPath, $this->outputPath);
	}

	function write($data)
	{
		gzwrite($this->handle, $data);
	}

	function tell()
	{
		return gztell($this->handle);
	}

	function flush()
	{
		gzclose($this->handle);
		clearstatcache();
		$fileSize = filesize($this->tempOutputPath);
		$this->handle = @gzopen($this->tempOutputPath, 'a');
		if (!$this->handle)
		{
			writeLog("Error: failed to open output file {$this->outputPath}");
			die;
		}

		return $fileSize;
	}
}

class GZipS3Writer
{
	protected $handle;
	protected $outputPath;
	protected $tempOutputPath;
	protected $hasData = false;
	
	public function hasData()
	{
		return $this->hasData;
	}
	
	function __construct($outputPath)
	{
		$this->outputPath = $outputPath;
		$this->tempOutputPath = "{$outputPath}.tmp";
		$this->handle = fopen($this->tempOutputPath, 'w');
		if (!$this->handle)
		{
			writeLog("Error: failed to open output file {$outputPath}");
			die;
		}
		
		if (stream_filter_append($this->handle, 'zlib.deflate', STREAM_FILTER_WRITE, array('window' => 30, 'level' => 9)) === false)
		{
			writeLog("Error: failed to append deflate filter");
			die;
		}
	}
	
	function close()
	{
		fclose($this->handle);
		if ($this->hasData)
		{
			writeLog("Info: renaming file {$this->tempOutputPath} to {$this->outputPath}");
			rename($this->tempOutputPath, $this->outputPath);
		}
		else
		{
			writeLog("Info: deleting empty file {$this->tempOutputPath}");
			unlink($this->tempOutputPath);
		}
	}
	
	function write($data)
	{
		fwrite($this->handle, $data);
		$this->hasData = true;
	}
	
	function tell()
	{
		return ftell($this->handle);
	}
	
	function flush()
	{
		fclose($this->handle);
		clearstatcache();
		$fileSize = filesize($this->tempOutputPath);
		$this->handle = fopen($this->tempOutputPath, 'a');
		if (!$this->handle)
		{
			writeLog("Error: failed to open output file {$this->outputPath}");
			die;
		}
		
		return $fileSize;
	}
}

function getGZipReader($inputFile, $maxLineSize = 16384, $copyS3Files = false)
{
	if (isS3Path($inputFile))
	{
		if (!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		$s3Config = K::Get()->getConfParam(S3_CONF_SECTION);
		$profile = isset($s3Config['S3_PROFILE']) ? $s3Config['S3_PROFILE'] : null;
		if ($copyS3Files)
		{
			return new GZipS3CopyReader($inputFile, $profile);
		}
		else
		{
			return new GZipS3Reader($inputFile, $profile);
		}
	}
	else
	{
		return new GZipReader($inputFile, $maxLineSize);
	}
}

function getGZipWriter($outputPath)
{
	if (isS3Path($outputPath))
	{
		if (!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		initS3Wrapper();
		return new GZipS3Writer($outputPath);
	}
	else
	{
		return new GZipWriter($outputPath);
	}
}

class s3StreamHelper
{
	function glob($pattern)
	{
		global $client;
		
		$files = array();
		$s3Prefix = "s3:/";
		
		list($bucket, $patternFilePath) = explode("/", ltrim(substr($pattern, strlen($s3Prefix)), "/"), 2);
		$pattern = str_replace("*", '', basename($patternFilePath));
		$prefix = dirname($patternFilePath);
		
		$dirListObjectsRaw = $client->getIterator('ListObjects', array(
			'Bucket' => $bucket,
			'Prefix' => $prefix,
		));
		
		foreach ($dirListObjectsRaw as $dirListObject)
		{
			if(!preg_match("/$pattern/", $dirListObject['Key']))
				continue;
			
			$files[] = array(
				'filePath' => $s3Prefix . "/" . $bucket . DIRECTORY_SEPARATOR . $dirListObject['Key'],
				'fileSize' => $dirListObject['Size'],
			);
		}
		
		return $files;
	}
	
	function concat($filePaths, $outputFile)
	{
		$localOutFile = sys_get_temp_dir() . '/' . basename($outputFile);
		$mergedFileSize = 0;
		$rv = true;
		
		//Open local output stream
		$outFh = fopen($localOutFile,"wb");
		if ($outFh === false)
		{
			writeLog('Error: invalid merged file size');
			return false;
		}
		
		foreach ($filePaths as $filePath)
		{
			$retries = 3;
			$mergeFileSuccess = false;
			while($retries > 0)
			{
				$remoteFileSize = filesize($filePath);
				$bytesWritten=self::concatChunk($outFh, $filePath, 10 * 1024 * 1024, $remoteFileSize);
				if ($bytesWritten !== false)
				{
					$mergedFileSize += $bytesWritten;
					$mergeFileSuccess = true;
					break;
				}
				
				$retries--;
				fseek($outFh, $mergedFileSize, SEEK_SET);
				$remoteFileSize = filesize($filePath);
				writeLog("Failed to download [$filePath], rfs [$remoteFileSize], ofs [$bytesWritten], retries left [$retries]");
				sleep(rand(1,3));
			}
			
			if (!$mergeFileSuccess)
			{
				KalturaLog::debug("Failed to build merged file, Convert will fail, bytes fetched [$mergedFileSize]");
				$rv = false;
				break;
			}
		}
		
		fclose($outFh);
		
		writeLog("Log: File concat done, path $localOutFile size " . fileSize($localOutFile) . " uploading to $outputFile");
		if (!copy($localOutFile, $outputFile))
		{
			writeLog("Error: Failed to copy file from $localOutFile to $outputFile");
			$rv = false;
		}
		
		writeLog("Log: File uploaded to destination from $localOutFile to $outputFile");
		unlink($localOutFile);
		return  $rv;
	}

	static function concatChunk($fhd, $fileName, $rdSz = 10 * 1024 * 1024, $expectedFileSize = null)
	{
		$inFh = fopen($fileName,"rb");
		if ($inFh === false)
		{
			return false;
		}
		
		$wrSz=0;
		while(!feof($inFh))
		{
			$iBuf = fread($inFh, $rdSz);
			if ($iBuf === false)
			{
				return false;
			}
			if (($sz = fwrite($fhd, $iBuf, $rdSz)) === false)
			{
				return false;
			}
			$wrSz += $sz;
		}
		
		fclose($inFh);
		writeLog("sz:$wrSz ex: $expectedFileSize " . $fileName);
		
		if ($expectedFileSize && $expectedFileSize != $wrSz)
		{
			return false;
		}
		
		return $wrSz;
	}
}

class streamHelper
{
	function glob($pattern)
	{
		$files = array();
		
		$filesMatching = glob($pattern);
		foreach ($filesMatching as $file)
		{
			$files = array (
				'filePath' => $file,
				'fileSize' => filesize($file)
			);
		}
		
		return $files;
	}
	
	function concat($files, $outputFile)
	{
		// merge the input files
		$totalSize = 0;
		$commandLine = 'cat';
		foreach ($files as $inputPath)
		{
			$totalSize += filesize($inputPath);
			$commandLine .= ' "' . str_replace('"', '\\"', $inputPath) . '"';
		}
		$commandLine .= ' > ' . $outputFile;
		writeLog('Info: running ' . $commandLine);
		passthru($commandLine);
		
		if (filesize($outputFile) != $totalSize)
		{
			writeLog('Error: invalid merged file size');
			return false;
		}
	}
}

function getStreamHelper($path)
{
	if (isS3Path($path))
	{
		if (!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		initS3Wrapper();
		return new s3StreamHelper($path);
	}
	else
	{
		return new streamHelper($path);
	}
}

function getS3BaseArgs($s3Config)
{
	return array(
		'region' => $s3Config['S3_REGION'],
		'version' => $s3Config['S3_VERSION']
	);
}

function getS3Credentials($s3Config, $s3Args)
{
	loadAwsAutoLoader();
	if (isset($s3Config['S3_ACCESS_KEY']) && isset($s3Config['S3_SECRET_KEY']))
	{
		return array(
			'key'    => $s3Config['S3_ACCESS_KEY'],
			'secret' => $s3Config['S3_SECRET_KEY'],
		);
	}
	
	return generateTempCredentials($s3Config, $s3Args);
}

function generateTempCredentials($s3Config, $s3Args)
{
	$stsClient = new Aws\Sts\StsClient($s3Args);
	
	$result = $stsClient->AssumeRole([
		'RoleArn' => $s3Config['S3_ARN'],
		'RoleSessionName' => $s3Config['S3_PROFILE'],
		'DurationSeconds' => 36000,
	]);
	
	return array(
		'key' => $result['Credentials']['AccessKeyId'],
		'secret' => $result['Credentials']['SecretAccessKey'],
		'token'  => $result['Credentials']['SessionToken']
	);
}

function loadAwsAutoLoader()
{
	require_once(dirname(__file__) . '/../../vendor/aws/aws-autoloader.php');
}

function initS3Wrapper()
{
	global $client;
	
	if ($client)
	{
		return;
	}
	
	$s3Config = K::Get()->getConfParam(S3_CONF_SECTION);
	if (!isset($s3Config['S3_ARN']) && !(isset($s3Config['S3_ACCESS_KEY']) && isset($s3Config['S3_SECRET_KEY'])))
	{
		throw new Exception("Missing mandatory params to initiate s3 client");
	}
	
	$s3Args = getS3BaseArgs($s3Config);
	$s3Args['credentials'] = getS3Credentials($s3Config, $s3Args);
	
	$client = new Aws\S3\S3Client($s3Args);
	$client->registerStreamWrapper();
}