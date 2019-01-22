<?php

class K
{
	protected static $instance;

	protected $conf;
	protected $pdo;

	public static function init($confFile)
	{
		self::$instance = new K($confFile);
	}

	public static function get()
	{
		return self::$instance;
	}

	protected function __construct($confFile)
	{
		$this->conf = loadIniFiles($confFile);
	}

	protected function getPdo($key)
	{
		if (isset($this->pdo[$key]))
		{
			return $this->pdo[$key];
		}

		ob_start();
		$this->pdo[$key] = PdoWrapper::create($this->conf[$key]);
		ob_end_clean();

		// copy the pdo to any other keys sharing the same configuration
		$dbKeys = array(
			'kelloggsdb',
			'kelloggsdb_read',
			'database',
		);
		foreach ($dbKeys as $curKey)
		{
			if ($this->conf[$curKey] == $this->conf[$key])
			{
				$this->pdo[$curKey] = $this->pdo[$key];
			}
		}

		return $this->pdo[$key];
	}

	public function getKelloggsRWPdo()
	{
		return $this->getPdo('kelloggsdb');
	}

	public function getKelloggsPdo()
	{
		return $this->getPdo('kelloggsdb_read');
	}

	public function getProdPdo()
	{
		return $this->getPdo('database');
	}

	public function hasConfParam($param)
	{
		return isset($this->conf[$param]);
	}

	public function getConfParam($param)
	{
		return $this->conf[$param];
	}
}
