<?php

require_once (dirname(__FILE__) . '/tree_queries.php');

// Hoptree endpoint.
//
//   GET hoptree.php?ids=ott123,ott456,ott789
//
// Returns the minimum spanning subtree of the supplied taxa (in visit
// order), with x/y coordinates from coordinates.php so the client can
// render it directly without computing layout.
//
// Response shape mirrors tree.php so the client renderer can reuse the
// same JSON conventions:
//   { focal_id, displayed_root_id, nodes: { <id>: {...} }, edges: [...] }
// Each node in the response carries:
//   id, display, type, supertree_leaf, weight, depth, x, y,
//   visited (bool), visit_order (int|null)

header('Content-Type: application/json');

$ids_param = isset($_GET['ids']) ? trim($_GET['ids']) : '';
if ($ids_param === '')
{
	echo json_encode(array(
		'focal_id'          => null,
		'displayed_root_id' => null,
		'nodes'             => new stdClass,
		'edges'             => array(),
	));
	return;
}

$ids = array_filter(array_map('trim', explode(',', $ids_param)),
	function ($s) { return preg_match('/^[A-Za-z0-9_]+$/', $s); });
$ids = array_values($ids);

$db  = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$ott = new OttTree($db);
$q   = new TreeQueries($db, $ott);

$out = $q->spanning_subtree($ids);
TreeQueries::layout_spanning_subtree($out);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
