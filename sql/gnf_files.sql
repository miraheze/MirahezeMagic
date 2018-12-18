CREATE TABLE /*_*/gnf_files (
  `files_dbname` VARCHAR(64) NOT NULL,
  `files_url` LONGTEXT NOT NULL,
  `files_page` LONGTEXT NOT NULL,
  `files_name` VARCHAR(255) NOT NULL,
  `files_user` VARCHAR(255) NOT NULL,
  `files_private` TINYINT NOT NULL,
  `files_timestamp`INT(14) NOT NULL
) /*$wgDBTableOptions*/;

