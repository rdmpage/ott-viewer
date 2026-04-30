<?php

// Simple taxon search endpoint.
//
// GET search.php?q=<name>  →  JSON array of { external_id, label } rows
// matching `q` exactly (case-insensitive). At most 50 hits.
//
// Multiple matches are possible (homonyms across kingdoms — "Drosophila"
// is both a fly genus and a plant genus, etc.), so the client shows all
// hits in a dropdown and lets the user pick.
//
// Future: switch to prefix / substring matching, paginate, rank by
// descendant count.

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '')
{
	echo json_encode(array());
	return;
}

$db   = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$stmt = $db->prepare(
	'SELECT external_id, label FROM taxa
	WHERE label = :q COLLATE NOCASE
	ORDER BY label
	LIMIT 50'
);
$stmt->execute(array(':q' => $q));

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
?>
