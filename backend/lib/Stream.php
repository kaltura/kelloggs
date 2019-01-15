<?php
require_once(dirname(__file__) . '/Utils.php');
// readers
class GZipReader
{
	protected $handle;
	public function __construct($inputFile)
	{
		$this->handle = @gzopen($inputFile, 'r');
		if (!$this->handle)
		{
			writeLog("Error: failed to open input file {$inputFile}");
			die;
		}
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
		return gzgets($this->handle, 16384);
	}
}
