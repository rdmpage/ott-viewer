<?php

// GET /api/v1/hoptree — minimum spanning subtree of a list of visited nodes.
// Replaces the legacy hoptree.php. Same JSON shape as /api/v1/tree
// (nodes map + edges list); each node carries `visited` and
// `visit_order` so the client can highlight the user's path.

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../../tree_queries.php';

function api_handle_hoptree(PDO $db, array $params)
{
	$ids_param = isset($params['ids']) ? trim((string)$params['ids']) : '';
	if ($ids_param === '')
	{
		$out = new stdClass;
		$out->focal_id          = null;
		$out->displayed_root_id = null;
		$out->nodes             = new stdClass;
		$out->edges             = array();
		api_json($out);
		return;
	}

	$ids = array_values(array_filter(
		array_map('trim', explode(',', $ids_param)),
		function ($s) { return preg_match('/^[A-Za-z0-9_]+$/', $s); }
	));
	if (count($ids) === 0)
	{
		api_error('bad_request', 'No valid ids in `ids` parameter.',
			array('param' => 'ids', 'value' => $ids_param), 400);
	}

	$ott = new OttTree($db);
	$q   = new TreeQueries($db, $ott);

	$out = $q->spanning_subtree($ids);
	TreeQueries::layout_spanning_subtree($out);

	api_json($out);
}
