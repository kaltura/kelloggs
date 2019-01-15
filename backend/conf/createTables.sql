CREATE TABLE `kelloggs_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dc` tinyint(4) DEFAULT NULL,
  `file_path` varchar(512) DEFAULT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `file_mtime` datetime DEFAULT NULL,
  `server` varchar(512) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `status` tinyint(4) DEFAULT NULL,
  `start` datetime DEFAULT NULL,
  `end` datetime DEFAULT NULL,
  `ranges` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `file_path` (`file_path`),
  KEY `start` (`start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4;
