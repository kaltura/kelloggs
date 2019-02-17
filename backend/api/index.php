<?php

require_once(dirname(__file__) . '/../lib/Utils.php');
require_once(dirname(__file__) . '/../lib/PdoWrapper.php');
require_once(dirname(__file__) . '/../lib/AccessLogParser.php');
require_once(dirname(__file__) . '/../lib/Jwt.php');
require_once(dirname(__file__) . '/../shared/Globals.php');

define('RESPONSE_FORMAT_RAW', 'raw');
define('RESPONSE_FORMAT_DOWNLOAD', 'download');
define('RESPONSE_FORMAT_JSON', 'json');
define('BLOCK_DELIMITER', '--');

define('ERROR_UNAUTHORIZED', 'UNAUTHORIZED');
define('ERROR_BAD_REQUEST', 'BAD_REQUEST');
define('ERROR_NO_RESULTS', 'NO_RESULTS');
define('ERROR_INTERNAL_ERROR', 'INTERNAL_ERROR');

define('COMMAND_COPY', 'copyToClipboard');
define('COMMAND_LINK', 'link');
define('COMMAND_TOOLTIP', 'tooltip');
define('COMMAND_DOWNLOAD', 'download');
define('COMMAND_SEARCH', 'search');
define('COMMAND_SEARCH_NEW_TAB', 'searchNewTab');

function enableStreamingOutput()
{
	header('Content-Encoding: UTF-8');
	header('Charset: UTF-8');
	header('X-Accel-Buffering: no');

	ob_implicit_flush();
	ob_end_flush();
}

function dieError($code, $message)
{
	$result = array(
		'type' => 'error',
		'code' => $code,
		'message' => $message,
	);
	echo json_encode($result) . "\n";
	die;
}

function getRequestParams()
{
	$scriptParts = explode('/', $_SERVER['SCRIPT_NAME']);
	$pathParts = array();
	if (isset($_SERVER['PHP_SELF']))
	{
		$pathParts = explode('/', $_SERVER['PHP_SELF']);
	}
	$pathParts = array_diff($pathParts, $scriptParts);

	$params = array();
	reset($pathParts);
	while (current($pathParts))
	{
		$key = each($pathParts);
		$value = each($pathParts);
		if (!array_key_exists($key['value'], $params))
		{
			$params[$key['value']] = $value['value'];
		}
	}

	$post = null;
	if (isset($_SERVER['CONTENT_TYPE']))
	{
		if (strtolower($_SERVER['CONTENT_TYPE']) == 'application/json')
		{
			$requestBody = file_get_contents('php://input');
			if (startsWith($requestBody, '{') && endsWith($requestBody, '}'))
			{
				$post = json_decode($requestBody, true);
			}
		}
		else if (strpos(strtolower($_SERVER['CONTENT_TYPE']), 'multipart/form-data') === 0 && isset($_POST['json']))
		{
			$post = json_decode($_POST['json'], true);
		}
	}

	if (!$post)
	{
		$post = $_POST;
	}

	$params = array_replace_recursive($post, $_FILES, $_GET, $params);
	return $params;
}

function setElementByPath(&$array, $path, $value)
{
	$tmpArray = &$array;
	while(($key = array_shift($path)) !== null)
	{
		if ($key == '-' && count($path) == 0)
		{
			break;
		}

		if (!isset($tmpArray[$key]) || !is_array($tmpArray[$key]))
		{
			$tmpArray[$key] = array();
		}

		if (count($path) == 0)
		{
			$tmpArray[$key] = $value;
		}
		else
		{
			$tmpArray = &$tmpArray[$key];
		}
	}

	$array = &$tmpArray;
}

function groupParams($params)
{
	$result = array();

	foreach($params as $key => $value)
	{
		$path = explode(':', $key);
		setElementByPath($result, $path, $value);
	}

	return $result;
}

// response headers
header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');

if($_SERVER['REQUEST_METHOD'] == 'OPTIONS')
{
	header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Range, Cache-Control');
	header('Access-Control-Allow-Methods: POST, GET, HEAD, OPTIONS');
	header('Access-Control-Expose-Headers: Server, Content-Length, Content-Range, Date, X-Kaltura, X-Kaltura-Session, X-Me');
	header('Access-Control-Max-Age: 86400');
	exit;
}

// get request params and authenticate
$params = getRequestParams();
$params = groupParams($params);

if (!isset($params['jwt']))
{
	dieError(ERROR_UNAUTHORIZED, 'Unauthorized');
}

K::init(dirname(__file__) . '/../conf/kelloggs.ini');

$jwtPayload = jwtDecode($params['jwt'], K::get()->getConfParam('JWT_KEY'));
if (!$jwtPayload)
{
	dieError(ERROR_UNAUTHORIZED, 'Unauthorized');
}

// initialize
enableStreamingOutput();

// validate input params
if (!isset($params['filter']))
{
	dieError(ERROR_BAD_REQUEST, 'Missing filter param');
}
$filter = $params['filter'];

// get the query handler
$filterTypeMap = array(
	'apiLogFilter' => 'ApiLogFilter',
	'dbWritesFilter' => 'DbWritesFilter',
	'sphinxWritesFilter' => 'DbWritesFilter',
	'objectListFilter' => 'ObjectListFilter',
	'objectInfoFilter' => 'ObjectInfoFilter',
	'kmsLogFilter' => 'KmsLogFilter',
);

$filterLogTypeMap = array(
	'apiLogFilter_apiV3Analytics' => 'ApiAnalyticsLogFilter',
	'apiLogFilter_accessLog' => 'ApiAccessLogFilter',
	'kmsLogFilter_kmsFront' => 'KmsFrontLogFilter',
);

$filterType = isset($filter['type']) ? $filter['type'] : null;
$logTypes = isset($filter['logTypes']) ? $filter['logTypes'] : null;

if (isset($filterLogTypeMap[$filterType . '_' . $logTypes]))
{
	$handler = $filterLogTypeMap[$filterType . '_' . $logTypes];
}
else if (isset($filterTypeMap[$filterType]))
{
	$handler = $filterTypeMap[$filterType];
}
else
{
	dieError(ERROR_BAD_REQUEST, 'Invalid filter type');
}

// run the query handler
ini_set('max_execution_time', K::get()->getConfParam('GREP_TIMEOUT'));

require_once(dirname(__file__) . "/$handler.php");
$handler::main($params, $filter);
