<?php

// Legacy entry point. The real implementation lives at /api/v1/tree
// (handler in api/handlers/tree.php, builder in api/lib/tree_builder.php).
// This file remains so existing callers — the test runner, any saved
// CLI invocations, and consumers that bookmarked the old URL — keep
// working until the next release.
//
//   HTTP:  tree.php?taxon=ott452461&k=30   → identical JSON shape
//   CLI:   php tree.php ott452461 30       → identical JSON shape

require_once dirname(__FILE__) . '/api/lib/response.php';
require_once dirname(__FILE__) . '/api/lib/tree_builder.php';

$db = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (php_sapi_name() === 'cli')
{
	$taxon = isset($argv[1]) ? $argv[1] : 'ott93302';
	$k     = isset($argv[2]) ? (int)$argv[2] : 30;
	$payload = build_tree_payload($db, $taxon, $k);
	echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	return;
}

require_once dirname(__FILE__) . '/api/handlers/tree.php';
api_handle_tree($db, $_GET);
?>
