<?php

// GET /api/v1/subtree — full OTT subtree rooted at a focal node, in
// Newick. Distinct from /tree: no summary pruning, no coordinates, no
// upstream stub — just the topology rooted exactly at `taxon`, suitable
// for export to phylogenetic tooling.
//
// Bounded by SUBTREE_MAX_NODES so a request for the OTT root doesn't
// try to materialise the whole tree of life. Oversize requests get a
// 413 with code `subtree_too_large` and the actual node count in
// `error.details` so the caller can decide whether to narrow scope.

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../lib/format.php';
require_once dirname(__FILE__) . '/../../ott_tree.php';

const SUBTREE_MAX_NODES = 50000;

function api_handle_subtree(PDO $db, array $params)
{
	$taxon = isset($params['taxon']) ? trim((string)$params['taxon']) : '';
	if ($taxon === '')
	{
		api_error('bad_request', 'taxon is required.', array('param' => 'taxon'), 400);
	}
	api_validate_external_id($taxon, 'taxon');

	$format = isset($params['format']) ? strtolower(trim((string)$params['format'])) : 'newick';
	if ($format !== 'newick')
	{
		api_error('unsupported_format',
			"subtree format must be one of: newick",
			array('param' => 'format', 'value' => $format), 400);
	}

	$labels = isset($params['labels']) ? strtolower(trim((string)$params['labels'])) : 'names';
	$bl     = isset($params['branch_lengths']) ? strtolower(trim((string)$params['branch_lengths'])) : 'none';

	if (!in_array($labels, array('ids', 'names'), true))
	{
		api_error('bad_request', "labels must be one of: ids, names",
			array('param' => 'labels', 'value' => $labels), 400);
	}
	if (!in_array($bl, array('none', 'ones'), true))
	{
		api_error('bad_request', "branch_lengths must be one of: none, ones",
			array('param' => 'branch_lengths', 'value' => $bl), 400);
	}

	// Resolve focal node — fetch nleft/nright to scope the subtree.
	$focal_stmt = $db->prepare(
		'SELECT t.id, t.nleft, t.nright
		 FROM tree t INNER JOIN taxa ta USING(id)
		 WHERE ta.external_id = ?'
	);
	$focal_stmt->execute(array($taxon));
	$focal = $focal_stmt->fetch(PDO::FETCH_ASSOC);
	if (!$focal)
	{
		api_error('node_not_found', "No node with external_id '$taxon'.",
			array('id' => $taxon), 404);
	}

	$nleft  = (int)$focal['nleft'];
	$nright = (int)$focal['nright'];

	// Bound the size before we pull rows. The nested-set bounds check is
	// an index lookup, so this is cheap even for huge subtrees.
	$count_stmt = $db->prepare(
		'SELECT COUNT(*) FROM tree WHERE nleft >= :nleft AND nright <= :nright'
	);
	$count_stmt->execute(array(':nleft' => $nleft, ':nright' => $nright));
	$node_count = (int)$count_stmt->fetchColumn();

	if ($node_count > SUBTREE_MAX_NODES)
	{
		api_error(
			'subtree_too_large',
			"Subtree has $node_count nodes; the cap is " . SUBTREE_MAX_NODES . ". Re-root on a more specific clade.",
			array(
				'taxon'      => $taxon,
				'node_count' => $node_count,
				'max_nodes'  => SUBTREE_MAX_NODES,
			),
			413
		);
	}

	// Pull every row in the subtree, ordered by nleft so parents come
	// before children — lets us build the adjacency in one pass.
	$rows_stmt = $db->prepare(
		'SELECT t.id, t.parent, ta.external_id, ta.label
		 FROM tree t INNER JOIN taxa ta USING(id)
		 WHERE t.nleft >= :nleft AND t.nright <= :nright
		 ORDER BY t.nleft'
	);
	$rows_stmt->execute(array(':nleft' => $nleft, ':nright' => $nright));
	$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

	$int_to_ext = array();
	foreach ($rows as $r) $int_to_ext[(int)$r['id']] = $r['external_id'];

	$ott = ($labels === 'names') ? new OttTree($db) : null;

	$payload = new stdClass;
	$payload->focal_id          = $taxon;
	$payload->displayed_root_id = $taxon;
	$payload->nodes             = new stdClass;
	$payload->edges             = array();

	foreach ($rows as $r)
	{
		$ext = $r['external_id'];
		$n = new stdClass;
		$n->id      = $ext;
		// Skip the prettify for labels=ids — the emitter never reads
		// display in that mode, and prettify_label can do up to two
		// extra DB hits per mrca row.
		$n->display = $ott ? $ott->prettify_label((string)$r['label']) : (string)$r['label'];
		$payload->nodes->$ext = $n;

		// Edge from parent → this node, but skip:
		//   - the focal itself (its parent lives outside the export)
		//   - the OTT root's self-loop
		//   - any parent not in our int_to_ext map (defensive)
		$pid = ($r['parent'] !== null && $r['parent'] !== '') ? (int)$r['parent'] : null;
		$tid = (int)$r['id'];
		if ($tid === (int)$focal['id']) continue;
		if ($pid === null || $pid === $tid) continue;
		if (!isset($int_to_ext[$pid]))    continue;

		$e = new stdClass;
		$e->source = $int_to_ext[$pid];
		$e->target = $ext;
		$payload->edges[] = $e;
	}

	api_text(tree_to_newick($payload, array(
		'labels'         => $labels,
		'branch_lengths' => $bl,
	)));
}
