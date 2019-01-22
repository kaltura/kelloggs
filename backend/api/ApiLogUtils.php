<?php

require_once(dirname(__file__) . '/../lib/KalturaSession.php');
require_once(dirname(__file__) . '/DatabaseSecretRepository.php');

function print_r_reverse($in)
{
    $lines = explode("\n", trim($in));
    if (trim($lines[0]) != 'Array')
	{
        // bottomed out to something that isn't an array
        return $in;
    }
	else
	{
        // this is an array, lets parse it
        if (preg_match('/(\s{5,})\(/', $lines[1], $match))
		{
            // this is a tested array/recursive call to this function
            // take a set of spaces off the beginning
            $spaces = $match[1];
            $spaces_length = strlen($spaces);
            $lines_total = count($lines);
            for ($i = 0; $i < $lines_total; $i++)
			{
                if (substr($lines[$i], 0, $spaces_length) == $spaces)
				{
                    $lines[$i] = substr($lines[$i], $spaces_length);
                }
            }
        }
        array_shift($lines); // Array
        array_shift($lines); // (
        array_pop($lines); // )
        $in = implode("\n", $lines);
        // make sure we only match stuff with 4 preceding spaces (stuff for this array and not a nested one)
        preg_match_all('/^\s{4}\[(.+?)\] \=\> ?/m', $in, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $pos = array();
        $previous_key = '';
        $in_length = strlen($in);
        // store the following in $pos:
        // array with key = key of the parsed array's item
        // value = array(start position in $in, $end position in $in)
        foreach ($matches as $match)
		{
            $key = $match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);
            $pos[$key] = array($start, $in_length);
            if ($previous_key != '') $pos[$previous_key][1] = $match[0][1] - 1;
            $previous_key = $key;
        }
        $ret = array();
        foreach ($pos as $key => $where)
		{
            // recursively see if the parsed out value is an array too
            $ret[$key] = print_r_reverse(substr($in, $where[0], $where[1] - $where[0]));
        }
        return $ret;
    }
}

function flattenArray($input, $prefix)
{
	$result = array();
	foreach ($input as $key => $value)
	{
		if (is_array($value))
		{
			$result = array_merge($result, flattenArray($value, $prefix . "$key:"));
		}
		else
		{
			$result[$prefix . $key] = $value;
		}
	}
	return $result;
}

function renewKss($params)
{
	$renewedSessions = array();		// cache the renewed kss for multirequest
	foreach ($params as $key => &$value)
	{
		if ($key != 'ks' && !preg_match('/[\d]+:ks/', $key))
		{
			continue;
		}

		if (isset($renewedSessions[$value]))
		{
			$value = $renewedSessions[$value];
			continue;
		}

		DatabaseSecretRepository::init();
		$renewedKs = KalturaSession::extendKs($value);
		if (!$renewedKs)
		{
			continue;
		}

		$renewedSessions[$value] = $renewedKs; 
		$value = $renewedKs;
	}

	return $params;
}

function parseMultirequest($parsedParams)
{
	$paramsByRequest = array();
	foreach ($parsedParams as $paramName => $paramValue)
	{
		$explodedName = explode(':', $paramName);
		if (count($explodedName) <= 1 || !is_numeric($explodedName[0]))
		{
			$requestIndex = 'common';
		}
		else
		{
			$requestIndex = (int)$explodedName[0];
			$paramName = implode(':', array_slice($explodedName, 1));
		}

		if (!array_key_exists($requestIndex, $paramsByRequest))
		{
			$paramsByRequest[$requestIndex] = array();
		}
		$paramsByRequest[$requestIndex][$paramName] = $paramValue;
	}

	if (isset($paramsByRequest['common']))
	{
		foreach ($paramsByRequest as $requestIndex => &$curParams)
		{
			if ($requestIndex === 'common')
				continue;
			$curParams = array_merge($curParams, $paramsByRequest['common']);
		}
		unset($paramsByRequest['common']);
	}
	ksort($paramsByRequest);
	return $paramsByRequest;
}

function genKalcliCommand($parsedParams)
{
	if (!isset($parsedParams['service']) || !isset($parsedParams['action']))
	{
		return false;
	}

	$service = $parsedParams['service'];
	$action = $parsedParams['action'];
	$res = "kalcli -x {$service} {$action}";
	unset($parsedParams['service']);
	unset($parsedParams['action']);

	ksort($parsedParams);
	foreach ($parsedParams as $param => $value)
	{
		$curParam = "{$param}={$value}";
		if (!preg_match('/^[a-zA-Z0-9\:_\-,=\.]+$/', $curParam))
		{
			if (strpos($curParam, "'") === false)
			{
				$res .= " '{$curParam}'";
			}
			else
			{
				$res .= " \"{$curParam}\"";
			}
		}
		else
		{
			$res .= " {$curParam}";
		}
	}
	return $res;
}

function genCurlCommand($parsedParams)
{
	if (!isset($parsedParams['service']) || !isset($parsedParams['action']))
	{
		return false;
	}

	$service = $parsedParams['service'];
	$action = $parsedParams['action'];
	$url = K::Get()->getConfParam('BASE_KALTURA_API_URL') . "/api_v3/service/{$service}/action/{$action}";
	unset($parsedParams['service']);
	unset($parsedParams['action']);
	return "curl -d '" . http_build_query($parsedParams) . "' " . $url;
}

function formatTimeInterval($secs)
{
	$bit = array(
		' year'   => intval($secs / 31556926),
		' month'  => $secs / 2628000 % 12,
		' day'    => $secs / 86400 % 30,
		' hour'   => $secs / 3600 % 24,
		' minute' => $secs / 60 % 60,
		' second' => $secs % 60
	);
	foreach($bit as $k => $v)
	{
		if($v > 1)
		{
			$ret[] = $v . $k . 's';
		}
		else if($v == 1)
		{
			$ret[] = $v . $k;
		}
	}

	$ret = array_slice($ret, 0, 2);		// don't care about more than 2 levels
	array_splice($ret, count($ret) - 1, 0, 'and');
	return join(' ', $ret);
}

function formatKs($ksObj)
{
	$result = '';
	$fieldNames = array('partner_id', 'partner_pattern', 'valid_until', 'type', 'user', 'privileges');
	foreach ($fieldNames as $fieldName)
	{
		$result .= str_pad($fieldName, 20) . $ksObj->$fieldName;
		if ($fieldName == 'valid_until')
		{
			$currentTime = time();
			$result .= ' = ' . date('Y-m-d H:i:s', $ksObj->valid_until);
			if ($currentTime >= $ksObj->valid_until)
			{
				$result .= ' (expired ' . formatTimeInterval($currentTime - $ksObj->valid_until) . ' ago';
			}
			else
			{
				$result .= ' (will expire in ' . formatTimeInterval($ksObj->valid_until - $currentTime);
			}
			$result .= ')';
		}
		$result .= "\n";
	}
	return $result;
}
