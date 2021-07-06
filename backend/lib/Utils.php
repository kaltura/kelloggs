<?php

require_once(dirname(__file__) . '/IpAddressUtils.php');
require_once(dirname(__file__) . '/Stream.php');

define('CONF_GENERAL', 'general');
define('S3_CRED_FILE', '/tmp/s3_cred');

function writeLog($msg)
{
	echo date('Y-m-d H:i:s ') . $msg . "\n";
}
function errorLog($msg)
{
	fwrite(STDERR, date('Y-m-d H:i:s ') . $msg . "\n");
}

function startsWith($str, $prefix)
{
	return substr($str, 0, strlen($prefix)) == $prefix;
}

function endsWith($str, $postfix)
{
	return (substr($str, -strlen($postfix)) === $postfix);
}

function parseIniFileNested($file, $scanner_mode = INI_SCANNER_NORMAL)
{
	$data = parse_ini_file($file, true, $scanner_mode);
	if ($data === false)
	{
		return false;
	}
	foreach ($data as $sectionKey => $section)
	{
		foreach ($section as $key => $value)
		{
			$subKeys = explode('.', $key);
			if (count($subKeys) <= 1)
			{
				continue;
			}
			$subs = &$data[$sectionKey];
			foreach ($subKeys as $subKey)
			{
				if (!isset($subs[$subKey]))
				{
					$subs[$subKey] = array();
				}
				$subs = &$subs[$subKey];
			}
			$subs = $value;
			unset($data[$sectionKey][$key]);
		}
	}
	$result = array();
	foreach ($data as $sectionKey => $section)
	{
		$explodedSectionKey = explode(':', $sectionKey);
		switch (count($explodedSectionKey))
		{
		case 1:
			$result[$sectionKey] = $section;
			break;

		case 2:
			$sectionKey = trim($explodedSectionKey[0]);
			$baseSectionKey = trim($explodedSectionKey[1]);
			if (!isset($result[$baseSectionKey]))
			{
				return false;
			}
			$result[$sectionKey] = array_merge($result[$baseSectionKey], $section);
			break;

		default:
			return false;
		}
	}

	return $result;
}

function loadIniFiles($iniPaths, $basePath = null)
{
	$conf = array();
	foreach (explode(',', $iniPaths) as $iniPath)
	{
		if ($basePath)
		{
			$iniPath = $basePath . $iniPath;
		}

		if (!file_exists($iniPath))
		{
			writeLog("Error: ini file {$iniPath} not found");
			exit(1);
		}
		$curConf = parseIniFileNested($iniPath);
		if ($curConf === false)
		{
			writeLog("Error: failed to parse ini file {$iniPath}");
			exit(1);
		}

		$conf = array_merge_recursive($conf, $curConf);
	}
	if (isset($conf[CONF_GENERAL]))
	{
		$conf = array_merge($conf, $conf[CONF_GENERAL]);
		unset($conf[CONF_GENERAL]);
	}

	return $conf;
}

function getIpAddress($ipAddress, $xForwardedFor)
{
	if ($xForwardedFor)
	{
		$ipAddress = $xForwardedFor . ',' . $ipAddress;
	}

	return IpAddressUtils::getIpFromHttpHeader($ipAddress);
}

function dateGlob($pattern, $from = '7 days ago', $to = 'now', $interval = 'P1D')
{
	if (!preg_match_all('/%\w/', $pattern, $matches))
	{
		$streamHelper = getStreamHelper($pattern);
		return $streamHelper->glob($pattern);
	}

	$find = reset($matches);

	$curDate = new DateTime($to);
	$limit = new DateTime($from);
	$interval = new DateInterval($interval);

	$patterns = array();
	while ($curDate->getTimestamp() > $limit->getTimestamp())
	{
		$replace = array();
		foreach ($find as $curFind)
		{
			$replace[] = $curDate->format($curFind[1]);
		}

		$curPattern = str_replace($find, $replace, $pattern);
		$patterns[$curPattern] = true;
		$curDate->sub($interval);
	}

	$result = array();
	foreach ($patterns as $curPattern => $ignore)
	{
		$streamHelper = getStreamHelper($curPattern);
		$result = array_merge($result, $streamHelper->glob($curPattern));
	}

	return $result;
}

function getZBinGrepIndexCommand($filePath)
{
	$zBinGrepIndexCmd = K::Get()->getConfParam('ZGREPINDEX');
	if (isS3Path($filePath) && generateS3CredCacheFile(true))
	{
		$zBinGrepIndexCmd .= " -i " . S3_CRED_FILE;
	}
	
	return $zBinGrepIndexCmd;
}

function getZBlockGrepCommand()
{
	$zBlockGrepCmd = K::Get()->getConfParam('ZBLOCKGREP');
	if (generateS3CredCacheFile(false))
	{
		$zBlockGrepCmd .= " -i " . S3_CRED_FILE;
	}
	
	return $zBlockGrepCmd;
}

function isS3Path($filePath)
{
	return substr($filePath, 0, strlen(S3_PREFIX)) == S3_PREFIX;
}

function generateS3CredCacheFile($strict = true)
{
	if (!K::Get()->hasConfParam(S3_CONF_SECTION))
	{
		if ($strict)
		{
			throw new Exception("Trying to read remote s3 file without providing s3 cred file");
		}
		else
		{
			return false;
		}
	}
	
	$s3Config = K::Get()->getConfParam(S3_CONF_SECTION);
	
	if (!file_exists(S3_CRED_FILE))
	{
		$s3Args = getS3BaseArgs($s3Config);
		$credentials = getS3Credentials($s3Config, $s3Args);
		return writeS3CredCacheFile($credentials, $s3Config['S3_REGION']);
	}
	
	$s3CachedConfig = parseIniFileNested(S3_CRED_FILE, INI_SCANNER_RAW);
	if (time() > $s3CachedConfig['s3']['expiration'])
	{
		$s3Args = getS3BaseArgs($s3Config);
		$credentials = getS3Credentials($s3Config, $s3Args);
		return writeS3CredCacheFile($credentials, $s3Config['S3_REGION']);
	}
	
	return true;
}

function writeS3CredCacheFile($credentials, $region)
{
	$tmpCredFile = S3_CRED_FILE.".tmp";
	
	$credConfig = "[s3]\n";
	$credConfig .= "expiration = " . (time() + 3600) . "\n";
	
	$credConfig .= "region = " . $region . "\n";
	$credConfig .= "access_key = \"" . $credentials['key'] . "\"\n";
	$credConfig .= "secret_key = \"" . $credentials['secret'] . "\"\n";
	
	if (isset($credentials['token']))
	{
		$credConfig .= "security_token = \"" . $credentials['token'] . "\"";
	}
	
	$bytesWritten = file_put_contents($tmpCredFile, $credConfig);
	if ($bytesWritten === false)
	{
		throw new Exception("Failed to write tmp credentials file to " . $tmpCredFile);
	}
	
	return rename($tmpCredFile, S3_CRED_FILE);
}

