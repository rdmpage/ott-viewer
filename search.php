<?php

// Legacy entry point. Real implementation at /api/v1/search.
// Note: the new endpoint wraps results in { query, mode, results }; the
// shim returns the legacy bare array shape so old consumers don't break.

require_once dirname(__FILE__) . '/api/handlers/search.php';
require_once dirname(__FILE__) . '/api/lib/response.php';

$db = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Inline the search query so we can return the legacy shape (bare array
// of { external_id, label }). The /api/v1/search wrapper reformats and
// also adds query/mode/limit envelope; this shim matches the old output.
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($q === '')
{
	echo json_encode(array());
	return;
}

$stmt = $db->prepare(
	'SELECT external_id, label FROM taxa
	 WHERE label = :q COLLATE NOCASE
	 ORDER BY label
	 LIMIT 50'
);
$stmt->execute(array(':q' => $q));
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
?>
