<?php

class DatabaseSecretRepository
{
	protected static $instance;

	public static function init()
	{
		if (self::$instance)
		{
			return;
		}

		self::$instance = new DatabaseSecretRepository(K::get()->getProdPdo());
		KalturaSession::registerSecretRepository(self::$instance);
	}

	protected function __construct($pdo)
	{
		$this->pdo = $pdo;
	}

	public function getSecret($partnerId)
	{
		if (filter_var($partnerId, FILTER_VALIDATE_INT) === false)
		{
			return null;
		}
		$sql = "SELECT admin_secret FROM partner WHERE id = $partnerId";
		$stmt = $this->pdo->queryRetry($sql);
		if (!$stmt)
		{
			writeLog("Error: query failed - $sql");
			exit(1);
		}
		$rs = $stmt->fetch(PDO::FETCH_NUM);
		if (!$rs)
		{
			return null;
		}
		return reset($rs);
	}
}
