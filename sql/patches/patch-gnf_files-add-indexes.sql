-- Convert gnf_files table to use indexes for the db column
ALTER TABLE /*$wgDBprefix*/gnf_files
  ADD INDEX files_dbname (files_dbname);
