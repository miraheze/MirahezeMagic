<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'], [
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CreateWiki',
		'../../extensions/Echo',
		'../../extensions/GlobalUserPage',
		'../../extensions/ImportDump',
		'../../extensions/ManageWiki',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'], [
		'../../extensions/AbuseFilter',
		'../../extensions/CentralAuth',
		'../../extensions/CreateWiki',
		'../../extensions/Echo',
		'../../extensions/GlobalUserPage',
		'../../extensions/ImportDump',
		'../../extensions/ManageWiki',
	]
);

$cfg['suppress_issue_types'] = [
	'PhanAccessMethodInternal',
];

return $cfg;
