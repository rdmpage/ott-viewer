<?php

// GET /api/v1/mrca — most-recent common ancestor of a set of node ids.

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../../tree_queries.php';

function api_handle_mrca(PDO $db, array $params)
{
	$ids_param = isset($params['ids']) ? trim((string)$params['ids']) : '';
	if ($ids_param === '')
	{
		api_error('bad_request', 'Missing required parameter `ids`.',
			array('param' => 'ids'), 400);
	}

	$ids = array_values(array_filter(
		array_map('trim', explode(',', $ids_param)),
		function ($s) { return preg_match('/^[A-Za-z0-9_]+$/', $s); }
	));
	if (count($ids) < 2)
	{
		api_error('bad_request', 'Provide at least two ids in `ids`.',
			array('param' => 'ids', 'value' => $ids_param), 400);
	}

	$ott = new OttTree($db);
	$q   = new TreeQueries($db, $ott);

	$rows = $q->lookup_external($ids);
	if (count($rows) < 2)
	{
		$found = array_map(function ($r) { return $r['external_id']; }, $rows);
		api_error('node_not_found',
			'Fewer than two of the supplied ids resolved.',
			array('requested' => $ids, 'found' => $found),
			404);
	}

	// MRCA across all rows: bound = min(nleft) / max(nright), one mrca query.
	$minL = PHP_INT_MAX;
	$maxR = PHP_INT_MIN;
	foreach ($rows as $r)
	{
		if ((int)$r['nleft']  < $minL) $minL = (int)$r['nleft'];
		if ((int)$r['nright'] > $maxR) $maxR = (int)$r['nright'];
	}
	$mrca = $q->mrca_by_bounds($minL, $maxR);
	if ($mrca === null)
	{
		api_error('internal_error', 'MRCA query returned no row.', null, 500);
	}

	$out = new stdClass;
	$out->mrca = (object)array(
		'id'      => $mrca['external_id'],
		'display' => $ott->prettify_label($mrca['label']),
	);
	$out->inputs = array();
	foreach ($rows as $r)
	{
		$out->inputs[] = (object)array(
			'id'      => $r['external_id'],
			'display' => $ott->prettify_label($r['label']),
		);
	}

	api_json($out);
}
