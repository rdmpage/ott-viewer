<?php

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../../tree_queries.php';

function api_handle_query(PDO $db, array $params)
{
	$op = isset($params['op']) ? trim((string)$params['op']) : '';
	if ($op === '')
		api_error('bad_request', 'Missing required parameter `op`.', null, 400);

	$q = new TreeQueries($db);

	switch ($op)
	{
		case 'resolve':    _op_resolve($q, $params);    break;
		case 'mrca':       _op_mrca($q, $params);       break;
		case 'maxclade':   _op_maxclade($q, $params);   break;
		case 'sister':     _op_sister($q, $params);     break;
		case 'monophyly':  _op_monophyly($q, $params);  break;
		case 'topology':   _op_topology($q, $params);   break;
		case 'node':       _op_node($q, $db, $params);  break;
		default:
			api_error('bad_request', "Unknown op: '$op'.",
				array('op' => $op, 'valid' => array('resolve','mrca','maxclade','sister','monophyly','topology','node')),
				400);
	}
}

// ── Helpers ──────────────────────────────────────────────────────────────

function _query_require($params, $key)
{
	if (!isset($params[$key]) || trim((string)$params[$key]) === '')
		api_error('bad_request', "Missing required parameter `$key`.", null, 400);
	return trim((string)$params[$key]);
}

function _query_parse_names(TreeQueries $q, $csv)
{
	$raw = array_filter(array_map('trim', explode(',', $csv)));
	if (empty($raw))
		api_error('bad_request', 'Empty taxon list.', null, 400);

	$resolved = array();
	$failed   = array();
	foreach ($raw as $name) {
		$ext = $q->resolve_name($name);
		if ($ext) $resolved[] = $ext;
		else      $failed[]   = $name;
	}
	return array('resolved' => $resolved, 'failed' => $failed, 'input' => $raw);
}

function _query_require_names(TreeQueries $q, $params, $key, $min = 1)
{
	$csv = _query_require($params, $key);
	$r = _query_parse_names($q, $csv);
	if (count($r['resolved']) < $min)
		api_error('node_not_found',
			"Fewer than $min taxa resolved for `$key`.",
			array('param' => $key, 'failed' => $r['failed']),
			404);
	return $r;
}

function _format_node($row, TreeQueries $q)
{
	$ott = $q->ott;
	return array(
		'id'      => $row['external_id'],
		'display' => $ott->prettify_label($row['label']),
		'weight'  => (int)$row['weight'],
		'depth'   => (int)$row['depth'],
	);
}

// ── Operations ───────────────────────────────────────────────────────────

function _op_resolve(TreeQueries $q, $params)
{
	$csv = _query_require($params, 'names');
	$r = _query_parse_names($q, $csv);

	$results = array();
	foreach ($r['input'] as $name) {
		$ext = $q->resolve_name($name);
		$results[] = array(
			'input'       => $name,
			'id'          => $ext,
			'resolved'    => $ext !== null,
		);
	}
	api_json(array('op' => 'resolve', 'results' => $results));
}

function _op_mrca(TreeQueries $q, $params)
{
	$r = _query_require_names($q, $params, 'taxa', 2);
	$mrca = $q->mrca_of($r['resolved']);
	if (!$mrca)
		api_error('internal_error', 'MRCA query returned no result.', null, 500);

	$out = array(
		'op'     => 'mrca',
		'mrca'   => _format_node($mrca, $q),
		'inputs' => array(),
	);
	$rows = $q->lookup_external($r['resolved']);
	foreach ($rows as $row) $out['inputs'][] = _format_node($row, $q);
	if (!empty($r['failed'])) $out['unresolved'] = $r['failed'];

	api_json($out);
}

function _op_maxclade(TreeQueries $q, $params)
{
	$inc = _query_require_names($q, $params, 'include', 1);
	$exc = _query_require_names($q, $params, 'exclude', 1);

	$node = $q->max_clade($inc['resolved'], $exc['resolved']);
	if (!$node)
		api_error('not_found',
			'Maximum clade definition does not resolve on this tree.',
			array('include' => $inc['input'], 'exclude' => $exc['input']),
			404);

	$out = array(
		'op'      => 'maxclade',
		'node'    => _format_node($node, $q),
		'include' => array(),
		'exclude' => array(),
	);
	foreach ($q->lookup_external($inc['resolved']) as $row)
		$out['include'][] = _format_node($row, $q);
	foreach ($q->lookup_external($exc['resolved']) as $row)
		$out['exclude'][] = _format_node($row, $q);

	$failed = array_merge($inc['failed'], $exc['failed']);
	if (!empty($failed)) $out['unresolved'] = $failed;

	api_json($out);
}

function _op_sister(TreeQueries $q, $params)
{
	$r = _query_require_names($q, $params, 'taxon', 1);
	$ext = $r['resolved'][0];

	$sisters = $q->sister_of($ext);
	if ($sisters === null)
		api_error('node_not_found', 'Taxon not found.', null, 404);

	$rows = $q->lookup_external(array($ext));
	$focal = !empty($rows) ? _format_node($rows[0], $q) : null;

	$out = array(
		'op'      => 'sister',
		'taxon'   => $focal,
		'sisters' => array(),
	);
	foreach ($sisters as $s) $out['sisters'][] = _format_node($s, $q);

	api_json($out);
}

function _op_monophyly(TreeQueries $q, $params)
{
	$r = _query_require_names($q, $params, 'taxa', 2);
	$result = $q->is_monophyletic($r['resolved']);

	$out = array(
		'op'           => 'monophyly',
		'monophyletic' => $result['monophyletic'],
		'reason'       => $result['reason'],
		'mrca'         => $result['mrca'],
		'inputs'       => array(),
	);
	$rows = $q->lookup_external($r['resolved']);
	foreach ($rows as $row) $out['inputs'][] = _format_node($row, $q);
	if (!empty($r['failed'])) $out['unresolved'] = $r['failed'];

	api_json($out);
}

function _op_topology(TreeQueries $q, $params)
{
	$newick = _query_require($params, 'topology');

	// Parse a simple Newick-like string into a nested array.
	// Supports ((A,B),C) where A/B/C are taxon names or OTT IDs.
	$parsed = _parse_simple_newick($newick);
	if ($parsed === null)
		api_error('bad_request', 'Could not parse topology string.', null, 400);

	// Resolve all leaf names.
	$leaves = array();
	_collect_leaves($parsed, $leaves);
	$resolved_map = array();
	$failed = array();
	foreach ($leaves as $name) {
		$ext = $q->resolve_name($name);
		if ($ext) $resolved_map[$name] = $ext;
		else      $failed[] = $name;
	}

	if (!empty($failed))
		api_error('node_not_found',
			'Some taxa in the topology did not resolve.',
			array('failed' => $failed), 404);

	// Replace names with external_ids in the topology structure.
	$resolved_topo = _replace_leaves($parsed, $resolved_map);

	$result = $q->test_topology($resolved_topo);

	$out = array(
		'op'         => 'topology',
		'topology'   => $newick,
		'consistent' => $result['consistent'],
		'reason'     => $result['reason'],
	);

	api_json($out);
}

function _op_node(TreeQueries $q, PDO $db, $params)
{
	$r = _query_require_names($q, $params, 'taxon', 1);
	$ext = $r['resolved'][0];

	$rows = $q->lookup_external(array($ext));
	if (empty($rows))
		api_error('node_not_found', 'Taxon not found.', null, 404);

	$row = $rows[0];
	$out = _format_node($row, $q);

	// Annotations.
	$ann_stmt = $db->prepare(
		"SELECT DISTINCT a.relation, a.study_tree,
		        s.publication_ref, s.doi
		 FROM annotations a
		 LEFT JOIN studies s
		   ON s.study_id = substr(a.study_tree, 1, instr(a.study_tree, '@') - 1)
		 WHERE a.node_external_id = ?"
	);
	$ann_stmt->execute(array($ext));
	$annotations = array(
		'supported_by'    => array(),
		'conflicts_with'  => array(),
		'resolves'        => array(),
		'partial_path_of' => array(),
		'terminal'        => array(),
	);
	while ($a = $ann_stmt->fetch(PDO::FETCH_ASSOC)) {
		if (!isset($annotations[$a['relation']])) continue;
		$annotations[$a['relation']][] = array(
			'study_tree'      => $a['study_tree'],
			'publication_ref' => $a['publication_ref'],
			'doi'             => $a['doi'],
		);
	}
	$out['annotations'] = $annotations;

	// Sister group.
	$sisters = $q->sister_of($ext);
	$out['sisters'] = array();
	if ($sisters) {
		foreach ($sisters as $s) $out['sisters'][] = _format_node($s, $q);
	}

	// Parent.
	if ($row['parent'] && $row['parent'] !== $row['id']) {
		$parent_rows = $q->lookup_external(array());
		$p_stmt = $db->prepare(
			'SELECT ta.external_id, ta.label,
			        CAST(t.depth AS INTEGER) AS depth,
			        CAST(t.weight AS INTEGER) AS weight
			 FROM tree t INNER JOIN taxa ta USING(id)
			 WHERE t.id = ?'
		);
		$p_stmt->execute(array($row['parent']));
		$p = $p_stmt->fetch(PDO::FETCH_ASSOC);
		if ($p) {
			$out['parent'] = array(
				'id'      => $p['external_id'],
				'display' => $q->ott->prettify_label($p['label']),
			);
		}
	}

	if (!empty($r['failed'])) $out['unresolved'] = $r['failed'];

	api_json(array('op' => 'node', 'node' => $out));
}

// ── Newick parser ────────────────────────────────────────────────────────

function _parse_simple_newick($s)
{
	$s = trim($s);
	if (substr($s, -1) === ';') $s = substr($s, 0, -1);
	$s = trim($s);
	$pos = 0;
	$result = _parse_newick_node($s, $pos);
	return $result;
}

function _parse_newick_node($s, &$pos)
{
	$len = strlen($s);
	if ($pos >= $len) return null;

	if ($s[$pos] === '(') {
		$pos++; // skip (
		$children = array();
		$children[] = _parse_newick_node($s, $pos);
		while ($pos < $len && $s[$pos] === ',') {
			$pos++; // skip ,
			$children[] = _parse_newick_node($s, $pos);
		}
		if ($pos < $len && $s[$pos] === ')') $pos++; // skip )
		return $children;
	} else {
		// Leaf: read until , or ) or end
		$start = $pos;
		while ($pos < $len && $s[$pos] !== ',' && $s[$pos] !== ')') $pos++;
		return trim(substr($s, $start, $pos - $start));
	}
}

function _collect_leaves($node, &$out)
{
	if (is_string($node)) { $out[] = $node; return; }
	foreach ($node as $child) _collect_leaves($child, $out);
}

function _replace_leaves($node, $map)
{
	if (is_string($node)) return isset($map[$node]) ? $map[$node] : $node;
	return array_map(function ($child) use ($map) {
		return _replace_leaves($child, $map);
	}, $node);
}
