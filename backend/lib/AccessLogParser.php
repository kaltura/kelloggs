<?php

class AccessLogParser
{
	public static function parse($line)
	{
		$lineLength = strlen($line);
		$fields = array();
		$curPos = 0;
		while ($curPos < $lineLength)
		{
			if ($line[$curPos] == '"')
			{
				$nextPos = $curPos + 1;
				for (;;)
				{
					$nextPos = strpos($line, '"', $nextPos);
					if ($nextPos === false)
					{
						return false;
					}
					if ($line[$nextPos - 1] != '\\')
					{
						break;
					}
					$nextPos++;
				}
				$fields[] = str_replace('\\"', '"', substr($line, $curPos + 1, $nextPos - ($curPos + 1)));
				$curPos = $nextPos + 2;
			}
			else
			{
				$nextPos = strpos($line, ' ', $curPos);
				if ($nextPos === false)
				{
					$nextPos = $lineLength;
				}
				$fields[] = substr($line, $curPos, $nextPos - $curPos);
				$curPos = $nextPos + 1;
			}
		}
		return $fields;
	}
}