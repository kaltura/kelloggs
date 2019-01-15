<?php

class IpAddressUtils
{
	protected static $privateRanges = array();
	
	protected static function parseRange($range)
	{
		if (strpos($range, '-') !== false)
		{
			list($start, $end) = explode('-', $range);
			return array(ip2long($start), ip2long($end));
		}
		
		if (strpos($range, '/') !== false)
		{
			list($start, $bits) = explode('/', $range);
			$start = ip2long($start);
			$end = $start | ((1 << (32 - $bits)) - 1);
			return array($start, $end);
		}
		
		$start = ip2long($range);
		return array($start, $start);
	}
	
	public static function initPrivateRanges($internalRanges)
	{
		self::$privateRanges = array();
		foreach ($internalRanges as $range)
		{
			self::$privateRanges[] = self::parseRange($range);
		}
	}
	
	protected static function isIpPrivate($ip)
	{
		$longIp = ip2long($ip);
		if ($longIp && $longIp != -1)
		{
			foreach (self::$privateRanges as $range)
			{
				list($start, $end) = $range;
				if ($longIp >= $start && $longIp <= $end)
				{
					return true;
				}
			}
		}
		
		return false;
	}

	public static function getIpFromHttpHeader($httpHeader)
	{
		// pick the first non private ip
		$headerIPs = explode(',', trim($httpHeader, ','));
		foreach ($headerIPs as $ip)
		{
			$ip = trim($ip);
			if (!filter_var($ip, 
				FILTER_VALIDATE_IP, 
				FILTER_FLAG_IPV4 | 
				FILTER_FLAG_IPV6 | 
				FILTER_FLAG_NO_PRIV_RANGE | 
				FILTER_FLAG_NO_RES_RANGE))
			{
				continue;
			}

			if (self::isIpPrivate($ip))	// verify that ip is not from a private range
			{
				continue;
			}

			return $ip;
		}
		 
		return null;
	}
}