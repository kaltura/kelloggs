<?php
require_once(dirname(__file__) . '/Utils.php');

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
			writeLog("Error: failed to open output file {$outputPath}");
			die;
		}

		return $fileSize;
	}
}