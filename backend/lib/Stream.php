<?php
require_once(dirname(__file__) . '/Utils.php');

use Aws\S3\S3Client;
use Aws\Sts\StsClient;

use Aws\S3\Exception\S3Exception;
use Aws\Exception\AwsException;
use Aws\S3\Enum\CannedAcl;


define('S3_PREFIX', 's3://');
define('S3_CONF_SECTION', 'S3');
define('S3_CRED_FILE', '/tmp/s3_cred');

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
		$profileParam = '';
		if ($profile)
		{
			$profileParam = " --profile $profile";
		}
		$cmdLine = "aws s3 cp $profileParam '{$inputFile}' - | gzip -d";
		
		$this->process = proc_open($cmdLine, array(1 => array("pipe", "w")), $pipes);
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
	
	public function __construct($inputFile)
	{
		$this->tempFile = tempnam('/tmp/', 's3reader-');
		if (!$this->tempFile)
		{
			writeLog("Error: failed to create a temporary file");
			die;
		}
		
		exec("aws s3 cp {$inputFile} {$this->tempFile}", $output, $exitCode);
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
		if(!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		initS3WrapperFromConf();
		if ($copyS3Files)
		{
			return new GZipS3CopyReader($inputFile);
		}
		else
		{
			$s3Config = K::Get()->getConfParam(S3_CONF_SECTION);
			return new GZipS3Reader($inputFile, isset($s3Config['S3_PROFILE']) ? $s3Config['S3_PROFILE'] : null);
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
		if(!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		initS3WrapperFromConf();
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
		$files = array();
		
		$patternFound = preg_match('(\*|\?|\[.+\])', $pattern, $parentPattern, PREG_OFFSET_CAPTURE);
		if ($patternFound)
		{
			$parent = dirname(substr($pattern, 0, $parentPattern[0][1] + 1));
			$parentLength = strlen($parent);
			$leftover = substr($pattern, $parentPattern[0][1]);
			if (($index = strpos($leftover, '/')) !== FALSE)
			{
				$searchPattern = substr($pattern, $parentLength + 1, $parentPattern[0][1] - $parentLength + $index - 1);
			}
			else
			{
				$searchPattern = substr($pattern, $parentLength + 1);
			}
			
			$replacement = [
				'/\*/' => '.*',
				'/\?/' => '.'
			];
			$searchPattern = preg_replace(array_keys($replacement), array_values($replacement), $searchPattern);
			
			if (is_dir($parent."/") && ($dh = opendir($parent."/")))
			{
				while($dir = readdir($dh))
				{
					if (!in_array($dir, ['.', '..']))
					{
						if (preg_match("/^". $searchPattern ."$/", $dir))
						{
							if ($index === FALSE || strlen($leftover) == $index + 1)
							{
								$files[] = $parent . "/" . $dir;
							}
							else
							{
								if (strlen($leftover) > $index + 1)
								{
									$files = array_merge($files, s3glob("{$parent}/{$dir}" . substr($leftover, $index)));
								}
							}
						}
					}
				}
			}
		}
		elseif(is_dir($pattern) || is_file($$pattern))
		{
			$files[] = $pattern;
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
		if($outFh === false)
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
				$bytesWritten=self::concatChunk($outFh, $filePath, 10000000, $remoteFileSize);
				if($bytesWritten !== false)
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
			
			if(!$mergeFileSuccess) {
				KalturaLog::debug("Failed to build merged file, Convert will fail, bytes fetched [$mergedFileSize]");
				$rv = false;
				break;
			}
		}
		
		fclose($outFh);
		
		writeLog("Log: File concat done, path $localOutFile size " . fileSize($localOutFile));
		if(!copy($localOutFile, $outputFile))
		{
			writeLog("Error: Failed to copy file from $localOutFile to $outputFile");
			$rv = false;
		}
		
		writeLog("Log: File uploaded to destination from $localOutFile to $outputFile");
		unlink($localOutFile);
		return  $rv;
	}

	static function concatChunk($fhd, $fileName, $rdSz=10000000, $expectedFileSize = null)
	{
		$inFh = fopen($fileName,"rb");
		if($inFh === false)
		{
			return false;
		}
		
		$wrSz=0;
		while(!feof($inFh))
		{
			$iBuf = fread($inFh, $rdSz);
			if($iBuf === false){
				return false;
			}
			if(($sz = fwrite($fhd, $iBuf, $rdSz))===false){
				return false;
			}
			$wrSz += $sz;
		}
		
		fclose($inFh);
		writeLog("sz:$wrSz ex: $expectedFileSize " . $fileName);
		
		if($expectedFileSize && $expectedFileSize != $wrSz)
			return false;
		
		return $wrSz;
	}
}

class streamHelper
{
	function glob($pattern)
	{
		return glob($pattern);
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
		if(!K::Get()->hasConfParam(S3_CONF_SECTION))
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		
		initS3WrapperFromConf();
		return new s3StreamHelper($path);
	}
	else
	{
		return new streamHelper($path);
	}
}

function initS3WrapperFromConf()
{
	global $client;
	
	if($client)
	{
		return;
	}
	
	$role = array();
	$s3Config = K::Get()->getConfParam(S3_CONF_SECTION);
	if (isset($s3Config['S3_ARN']))
	{
		$role['ARN'] = $s3Config['S3_ARN'];
		$role['session_name'] = $s3Config['S3_PROFILE'];
	}
	initS3Wrapper(array(
		'region' => $s3Config['S3_REGION'],
		'version' => $s3Config['S3_VERSION']
	), $role);
}

function initS3Wrapper($args = array('region' => 'us-east-1','version' => '2006-03-01'), $role = array())
{
	require_once(dirname(__file__) . '/../../vendor/aws/aws-autoloader.php');
	
	global $client;
	
	if (isset($role['ARN']) && isset($role['session_name']))
	{
		$stsClient = new Aws\Sts\StsClient($args);
		$ARN = $role['ARN'];
		$sessionName = $role['session_name'];
		
		$result = $stsClient->AssumeRole([
			'RoleArn' => $ARN,
			'RoleSessionName' => $sessionName,
			'DurationSeconds' => 10800,
		]);
		
		$args['credentials'] = array('key' => $result['Credentials']['AccessKeyId'],
			'secret' => $result['Credentials']['SecretAccessKey'],
			'token'  => $result['Credentials']['SessionToken']);
	}
	
	$client = new Aws\S3\S3Client($args);
	$client->registerStreamWrapper();
}