<?php

define('FILE_STATUS_FOUND', 0);
define('FILE_STATUS_LOCKED', 1);
define('FILE_STATUS_READY', 2);

function getWorkerConfById($workers)
{
	$result = array();
	foreach ($workers as $sectionKey => $section)
	{
		if (!isset($section['id']))
		{
			continue;
		}

		// make sure all worker ids are unique
		$workerId = $section['id'];
		if (isset($result[$workerId]))
		{
			writeLog("Error: duplicate workers with id $workerId");
			exit(1);
		}

		$section['workerKey'] = $sectionKey;
		$result[$workerId] = $section;
	}

	return $result;
}
