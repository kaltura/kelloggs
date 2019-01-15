<?php

define('LAG_WARNING_THRESHOLD', 3600);

class PdoWrapper
{
	const RETRY_SLEEP = 10;
	const RETRY_COUNT = 18;
	
	protected $pdo;
	protected $connParams;
	protected $heartBeatTable;
	public static function create($dbParams, $options = array())
	{
		$dsn = 'mysql:host='.$dbParams['HOST'].';dbname='.$dbParams['DATABASE'].';user='.$dbParams['USER'].';password='.$dbParams['PASSWORD'].';';
		$result = new PdoWrapper(
			$dsn, 
			$dbParams['USER'], 
			$dbParams['PASSWORD'],
			$options);
		$result->heartBeatTable = isset($dbParams['HEARTBEAT']) ? $dbParams['HEARTBEAT'] : null;
		$result->reconnectRetry(false);
		return $result;
	}
	protected function __construct()
	{
		$this->connParams = func_get_args();
	}
	protected function reconnectRetry($retry = true)
	{
		$retryLimit = $retry ? self::RETRY_COUNT : 1;
		for ($attempt = 0; $attempt < $retryLimit; $attempt++)
		{
			if ($attempt > 0)
			{
				writeLog('Warning: failed to connect to the database');
				sleep(self::RETRY_SLEEP);
			}
			$err = $this->reconnect();
			if ($err === true)
			{
				return;
			}
		}
		writeLog('Error: failed to connect to the database: ' . $err);
		exit(1);
	}
	protected function reconnect()
	{
		$this->pdo = null;
		$reflect = new ReflectionClass('PDO');
		try 
		{
			$this->pdo = $reflect->newInstanceArgs($this->connParams);
			$this->pdo->exec('SET NAMES latin1');
			$this->pdo->exec('SET SESSION group_concat_max_len = 1000000');
			if ($this->heartBeatTable)
			{
				$stmt = $this->pdo->query('SELECT TIME_TO_SEC(TIMEDIFF(NOW(), ts)) FROM ' . $this->heartBeatTable);
				$lag = $stmt->fetch(PDO::FETCH_NUM);
				$lag = reset($lag);
				$severity = $lag > LAG_WARNING_THRESHOLD ? 'Warning' : 'Info';
				writeLog("$severity: {$this->connParams[0]} is lagging {$lag} seconds");
			}
		}
		catch (PDOException $e) 
		{
			$this->pdo = null;
			return $e->getMessage();
		}
		return true;
	}
	public function __call($method, $args)
	{
		return call_user_func_array(
			array($this->pdo, $method),
			$args
		);
	}
	public function queryRetry($sql)
	{
		for ($attempt = 0; $attempt < self::RETRY_COUNT; $attempt++)
		{
			if ($attempt > 0)
			{
				writeLog("Warning: query failed, retrying...");
				sleep(self::RETRY_SLEEP);
				$this->reconnectRetry();
			}
			$stmt = $this->pdo->query($sql);
			if ($stmt)
			{
				return $stmt;
			}
		}
		return false;
	}
	
	protected static function logStatement($sql, $values)
	{
		$preparedSql = '';
		$curPos = 0;
		reset($values);
		for (;;)
		{
			$nextPos = strpos($sql, '?', $curPos);
			if ($nextPos === false)
			{
				$preparedSql .= substr($sql, $curPos);
				break;
			}
			$preparedSql .= substr($sql, $curPos, $nextPos - $curPos);
			$preparedSql .= "'" . str_replace("'", "''", current($values)) . "'";
			next($values);
			$curPos = $nextPos + 1;
		}
		writeLog("Notice: running $preparedSql");
	}
	public function executeStatement($sql, $values, $log = true, $retry = false)
	{
		// log the sql
		if ($log)
		{
			self::logStatement($sql, $values);
		}
		$retryLimit = $retry ? self::RETRY_COUNT : 1;
		for ($attempt = 0; $attempt < $retryLimit; $attempt++)
		{
			if ($attempt > 0)
			{
				writeLog("Warning: execute failed, retrying...");
				sleep(self::RETRY_SLEEP);
				$this->reconnectRetry();
			}
			// execute the statement
			$stmt = $this->pdo->prepare($sql);
			foreach ($values as $key => $value)
			{
				$stmt->bindValue($key, $value);
			}
			try
			{
				if ($stmt->execute())
				{
					return $stmt;
				}
			}
			catch (PDOException $e) 
			{
				if ($attempt + 1 >= $retryLimit)
				{
					throw $e;
				}
			}
		}
		return false;
	}
	public function executeInStatement($sql, $ids, $additionalValues = null, $log = true)
	{
		$idsPlaceholder = rtrim(str_repeat('?,', count($ids)), ',');
		$sql = str_replace('@ids@', $idsPlaceholder, $sql);
		$values = array();
		$k = 1;
		if ($additionalValues)
		{
			foreach ($additionalValues as $value)
			{
				$values[$k++] = $value;
			}
		}
		foreach ($ids as $id)
		{
			$values[$k++] = $id;
		}
		return $this->executeStatement($sql, $values, $log);
	}
}
