-- Fix breakage inside files_timestamp column

ALTER TABLE /*_*/gnf_files
    MODIFY COLUMN files_timestamp binary(14) NOT NULL;
