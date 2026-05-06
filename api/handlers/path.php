<?php

// GET /api/v1/path — topological path between two nodes via their LCA.
// Composes mrca + two ancestor walks. PhyQL primitive.

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../../tree_queries.php';

function api_handle_path(PDO $db, array $params)
{
	$from = isset($params['from']) ? trim((string)$params['from']) : '';
	$to   = isset($params['to'])   ? trim((string)$params['to'])   : '';
	if ($from === '' || $to === '')
	{
		api_error('bad_request', 'Both `from` and `to` are required.',
			array('from' => $from, 'to' => $to), 400);
	}
	api_validate_external_id($from, 'from');
	api_validate_external_id($to,   'to');

	$ott = new OttTree($db);
	$q   = new TreeQueries($db, $ott);

	$rows = $q->lookup_external(array($from, $to));
	if (count($rows) < 2)
	{
		$found = array_map(function ($r) { return $r['external_id']; }, $rows);
		api_error('node_not_found', "One or both ids not found.",
			array('requested' => array($from, $to), 'found' => $found), 404);
	}

	$by_ext = array();
	foreach ($rows as $r) $by_ext[$r['external_id']] = $r;
	$a = $by_ext[$from];
	$b = $by_ext[$to];

	$minL = min((int)$a['nleft'],  (int)$b['nleft']);
	$maxR = max((int)$a['nright'], (int)$b['nright']);
	$lca  = $q->mrca_by_bounds($minL, $maxR);
	if ($lca === null)
	{
		api_error('internal_error', 'No LCA found.', null, 500);
	}

	// Walk parent links from a node up to (but not including) the LCA.
	$walk_up_to_lca = function ($start_internal_id) use ($db, $lca) {
		$ids = array();
		$cur = $start_internal_id;
		$max_steps = 1000;
		while ($cur !== null && $cur !== (int)$lca['id'] && $max_steps-- > 0)
		{
			$row = $db->prepare(
				'SELECT t.id, t.parent, ta.external_id, ta.label
				 FROM tree t INNER JOIN taxa ta USING(id) WHERE t.id = ?'
			);
			$row->execute(array($cur));
			$r = $row->fetch(PDO::FETCH_ASSOC);
			if (!$r) break;
			$ids[] = $r;
			$cur = ($r['parent'] !== null && (int)$r['parent'] !== (int)$r['id'])
				? (int)$r['parent']
				: null;
		}
		return $ids;
	};

	$up   = $walk_up_to_lca((int)$a['id']);
	$down = $walk_up_to_lca((int)$b['id']);

	// Path: up-path (a → just-below LCA), then LCA, then reverse(down-path).
	$path_rows = array_merge($up, array($lca), array_reverse($down));

	$out = new stdClass;
	$out->from = $from;
	$out->to   = $to;
	$out->lca  = (object)array(
		'id'      => $lca['external_id'],
		'display' => $ott->prettify_label($lca['label']),
	);
	$out->length = max(0, count($path_rows) - 1);   // edges, not nodes
	$out->via    = array();
	foreach ($path_rows as $r)
	{
		$out->via[] = $r['external_id'];
	}

	api_json($out);
}
