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
		case 'triplet':    _op_triplet($q, $params);    break;
		case 'node':       _op_node($q, $db, $params);  break;
		case 'study':      _op_study($q, $db, $params); break;
		default:
			api_error('bad_request', "Unknown op: '$op'.",
				array('op' => $op, 'valid' => array('resolve','mrca','maxclade','sister','monophyly','triplet','node','study')),
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

// Triplet test: are A and B more closely related to each other than
// either is to C? Expressed as ((A,B),C). Exactly three taxa required.
function _op_triplet(TreeQueries $q, $params)
{
	$closer  = _query_require_names($q, $params, 'closer', 2);
	$distant = _query_require_names($q, $params, 'distant', 1);

	if (count($closer['resolved']) !== 2)
		api_error('bad_request', '`closer` must name exactly two taxa.', null, 400);
	if (count($distant['resolved']) !== 1)
		api_error('bad_request', '`distant` must name exactly one taxon.', null, 400);

	$a = $closer['resolved'][0];
	$b = $closer['resolved'][1];
	$c = $distant['resolved'][0];

	$mrca_ab  = $q->mrca($a, $b);
	$mrca_abc = $q->mrca_of(array($a, $b, $c));

	if (!$mrca_ab || !$mrca_abc)
		api_error('internal_error', 'MRCA computation failed.', null, 500);

	// The triplet holds iff MRCA(A,B) is a strict descendant of MRCA(A,B,C).
	$consistent = (int)$mrca_ab['nleft'] > (int)$mrca_abc['nleft']
	           && (int)$mrca_ab['nright'] < (int)$mrca_abc['nright'];

	$closer_rows  = $q->lookup_external($closer['resolved']);
	$distant_rows = $q->lookup_external($distant['resolved']);

	$out = array(
		'op'         => 'triplet',
		'consistent' => $consistent,
		'closer'     => array_map(function ($r) use ($q) { return _format_node($r, $q); }, $closer_rows),
		'distant'    => _format_node($distant_rows[0], $q),
		'mrca_closer'  => _format_node($mrca_ab, $q),
		'mrca_all'     => _format_node($mrca_abc, $q),
	);

	$failed = array_merge($closer['failed'], $distant['failed']);
	if (!empty($failed)) $out['unresolved'] = $failed;

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

// ── Study lookup ─────────────────────────────────────────────────────────

const STUDY_NODE_CAP = 50;

function _op_study(TreeQueries $q, PDO $db, $params)
{
	$study_id = isset($params['study']) ? trim((string)$params['study']) : '';
	$doi      = isset($params['doi'])   ? trim((string)$params['doi'])   : '';

	if ($study_id === '' && $doi === '')
		api_error('bad_request', 'Provide `study` (e.g. ot_1278) or `doi` (e.g. 10.1126/science.1211028).', null, 400);

	// Resolve DOI to study_id if needed.
	if ($study_id === '' && $doi !== '') {
		$doi = preg_replace('#^https?://(dx\.)?doi\.org/#', '', $doi);
		$stmt = $db->prepare('SELECT study_id FROM studies WHERE doi = ?');
		$stmt->execute(array($doi));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row)
			api_error('not_found', "No study found with DOI '$doi'.", array('doi' => $doi), 404);
		$study_id = $row['study_id'];
	}

	// Fetch study metadata.
	$stmt = $db->prepare(
		'SELECT study_id, publication_ref, doi, year, focal_clade_name, curator_names
		 FROM studies WHERE study_id = ?'
	);
	$stmt->execute(array($study_id));
	$study = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$study)
		api_error('not_found', "Study '$study_id' not found.", array('study' => $study_id), 404);

	$out = array(
		'op'              => 'study',
		'study_id'        => $study['study_id'],
		'publication_ref' => $study['publication_ref'],
		'doi'             => $study['doi'],
		'year'            => $study['year'] ? (int)$study['year'] : null,
		'focal_clade'     => $study['focal_clade_name'],
		'curators'        => $study['curator_names'] ? json_decode($study['curator_names'], true) : null,
	);

	// Find all trees from this study that appear in annotations.
	$tree_stmt = $db->prepare(
		"SELECT DISTINCT study_tree FROM annotations
		 WHERE study_tree LIKE ? || '@%'"
	);
	$tree_stmt->execute(array($study_id));
	$trees = array();
	while ($r = $tree_stmt->fetch(PDO::FETCH_ASSOC)) {
		$trees[] = $r['study_tree'];
	}
	$out['trees'] = $trees;

	// Per-relation summary: count of distinct nodes, plus a sample of named nodes.
	$rel_stmt = $db->prepare(
		"SELECT a.relation,
		        COUNT(DISTINCT a.node_external_id) AS node_count
		 FROM annotations a
		 WHERE a.study_tree LIKE ? || '@%'
		 GROUP BY a.relation
		 ORDER BY node_count DESC"
	);
	$rel_stmt->execute(array($study_id));

	$relations = array();
	while ($r = $rel_stmt->fetch(PDO::FETCH_ASSOC)) {
		$relations[$r['relation']] = array(
			'count' => (int)$r['node_count'],
			'nodes' => array(),
		);
	}

	// Fetch named nodes per relation (capped).
	$node_stmt = $db->prepare(
		"SELECT DISTINCT a.relation, a.node_external_id, ta.label
		 FROM annotations a
		 INNER JOIN taxa ta ON ta.external_id = a.node_external_id
		 WHERE a.study_tree LIKE ? || '@%'
		   AND ta.label NOT LIKE 'mrca%'
		 ORDER BY a.relation, ta.label"
	);
	$node_stmt->execute(array($study_id));
	$counts = array();
	while ($r = $node_stmt->fetch(PDO::FETCH_ASSOC)) {
		$rel = $r['relation'];
		if (!isset($relations[$rel])) continue;
		if (!isset($counts[$rel])) $counts[$rel] = 0;
		if ($counts[$rel] >= STUDY_NODE_CAP) continue;
		$counts[$rel]++;
		$relations[$rel]['nodes'][] = array(
			'id'      => $r['node_external_id'],
			'display' => $q->ott->prettify_label($r['label']),
		);
	}

	$out['annotations'] = $relations;

	$out['external_links'] = array(
		'opentree' => 'https://tree.opentreeoflife.org/curator/study/view/' . urlencode($study_id),
	);
	if ($study['doi']) {
		$out['external_links']['doi'] = 'https://doi.org/' . $study['doi'];
	}

	api_json($out);
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
