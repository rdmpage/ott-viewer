<?php

// GET /api/v1/nodes/{id}              — per-node detail (info-panel feed).
// GET /api/v1/nodes/{id}/children     — paginated children (for fanning
//                                        out a node without re-fetching the
//                                        whole focal tree).

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../../ott_tree.php';

const NODES_CHILDREN_SAMPLE_CAP = 50;
const NODES_CHILDREN_LIMIT_CAP  = 500;

function api_handle_node(PDO $db, $external_id)
{
	api_validate_external_id($external_id, 'id');

	$ott  = new OttTree($db);
	$id   = $ott->get_id_by_external($external_id);
	if ($id === null)
	{
		api_error(
			'node_not_found',
			"No node with external_id '$external_id'.",
			array('id' => $external_id),
			404
		);
	}

	$node = $ott->get_node($id);
	if (!$node || empty($node->id))
	{
		api_error('node_not_found', "Node $external_id has no row in tree.", array('id' => $external_id), 404);
	}

	$out = new stdClass;
	$out->id             = isset($node->external_id) ? $node->external_id : (string)$node->id;
	$out->display        = isset($node->name) ? $node->name : (string)$node->id;
	$out->weight         = isset($node->weight) ? (int)$node->weight : 0;

	// Walk parent chain up to the OTT root. Returns a list ordered
	// child-side-first ([direct parent, grandparent, ..., root]).
	$out->parents = _node_parent_chain($ott, $node);

	// Children sample (up to 50) plus true count, so the info panel can
	// show a preview without a separate paginated call. Beyond 50, the
	// caller drops to /nodes/{id}/children.
	$kids = $ott->get_children($id);
	$out->child_count = count($kids);
	$out->type        = $out->child_count > 0 ? 'internal' : 'leaf';

	$sample = array();
	foreach (array_slice($kids, 0, NODES_CHILDREN_SAMPLE_CAP) as $k)
	{
		$row = new stdClass;
		$row->id      = isset($k->external_id) ? $k->external_id : (string)$k->id;
		$row->display = isset($k->name) ? $k->name : (string)$k->id;
		if (isset($k->weight)) $row->weight = (int)$k->weight;
		$sample[] = $row;
	}
	$out->children_sample = $sample;

	$out->annotations = _node_annotations($db, $out->id);

	$out->external_links = new stdClass;
	$out->external_links->opentree = 'https://tree.opentreeoflife.org/opentree/argus/opentree@' . $out->id;
	$out->external_links->wikidata = null;
	$out->external_links->ncbi     = null;

	api_json($out);
}

function api_handle_node_children(PDO $db, $external_id, array $params)
{
	api_validate_external_id($external_id, 'id');

	$offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
	$limit  = isset($params['limit'])  ? max(1, (int)$params['limit'])  : NODES_CHILDREN_SAMPLE_CAP;
	if ($limit > NODES_CHILDREN_LIMIT_CAP) $limit = NODES_CHILDREN_LIMIT_CAP;

	$ott = new OttTree($db);
	$id  = $ott->get_id_by_external($external_id);
	if ($id === null)
	{
		api_error('node_not_found', "No node with external_id '$external_id'.",
			array('id' => $external_id), 404);
	}

	$kids = $ott->get_children($id);
	$total = count($kids);
	$slice = array_slice($kids, $offset, $limit);

	$out = new stdClass;
	$out->id       = $external_id;
	$out->total    = $total;
	$out->offset   = $offset;
	$out->limit    = $limit;
	$out->children = array();
	foreach ($slice as $k)
	{
		$row = new stdClass;
		$row->id      = isset($k->external_id) ? $k->external_id : (string)$k->id;
		$row->display = isset($k->name) ? $k->name : (string)$k->id;
		if (isset($k->weight)) $row->weight = (int)$k->weight;
		$out->children[] = $row;
	}

	api_json($out);
}

function api_handle_node_descendants(PDO $db, $external_id, array $params)
{
	api_validate_external_id($external_id, 'id');

	$tips_only = isset($params['tips_only']) && in_array(
		strtolower((string)$params['tips_only']),
		array('1', 'true', 'yes'), true
	);
	$offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;
	$limit  = isset($params['limit'])  ? max(1, (int)$params['limit'])  : 500;
	if ($limit > 5000) $limit = 5000;

	// nleft / nright contains-check: descendants are those with bounds
	// strictly inside the focal node's bounds. Tips have nright = nleft + 1.
	$row_stmt = $db->prepare(
		'SELECT t.nleft, t.nright FROM tree t INNER JOIN taxa ta USING(id) WHERE ta.external_id = ?'
	);
	$row_stmt->execute(array($external_id));
	$bounds = $row_stmt->fetch(PDO::FETCH_ASSOC);
	if (!$bounds)
	{
		api_error('node_not_found', "No node with external_id '$external_id'.",
			array('id' => $external_id), 404);
	}

	$nleft  = (int)$bounds['nleft'];
	$nright = (int)$bounds['nright'];

	$tip_clause = $tips_only ? ' AND t.nright = t.nleft + 1' : '';
	$count_sql  = "SELECT COUNT(*) FROM tree t
	               WHERE t.nleft > :nleft AND t.nright < :nright" . $tip_clause;
	$total_stmt = $db->prepare($count_sql);
	$total_stmt->execute(array(':nleft' => $nleft, ':nright' => $nright));
	$total = (int)$total_stmt->fetchColumn();

	$rows_sql = "SELECT ta.external_id, ta.label, CAST(t.weight AS INTEGER) AS weight
	             FROM tree t INNER JOIN taxa ta USING(id)
	             WHERE t.nleft > :nleft AND t.nright < :nright" . $tip_clause . "
	             ORDER BY t.nleft
	             LIMIT $limit OFFSET $offset";
	$rows_stmt = $db->prepare($rows_sql);
	$rows_stmt->execute(array(':nleft' => $nleft, ':nright' => $nright));
	$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

	$out = new stdClass;
	$out->id          = $external_id;
	$out->total       = $total;
	$out->offset      = $offset;
	$out->limit       = $limit;
	$out->tips_only   = $tips_only;
	$out->descendants = array();
	foreach ($rows as $r)
	{
		$row = new stdClass;
		$row->id      = $r['external_id'];
		$row->display = $r['label'];
		$row->weight  = (int)$r['weight'];
		$out->descendants[] = $row;
	}

	api_json($out);
}

function _node_parent_chain(OttTree $ott, $node)
{
	$chain = array();
	$max_depth = 200;       // sanity guard against any pathological cycle
	while (isset($node->parentTaxon) && $node->parentTaxon && $max_depth-- > 0)
	{
		$pid = is_object($node->parentTaxon) ? $node->parentTaxon->id : $node->parentTaxon;
		$p = $ott->get_node($pid);
		if (!$p || empty($p->id)) break;

		$row = new stdClass;
		$row->id      = isset($p->external_id) ? $p->external_id : (string)$p->id;
		$row->display = isset($p->name) ? $p->name : (string)$p->id;
		$chain[] = $row;

		$node = $p;
	}
	return $chain;
}

function _node_annotations(PDO $db, $external_id)
{
	$out = array(
		'supported_by'    => array(),
		'terminal'        => array(),
		'resolves'        => array(),
		'conflicts_with'  => array(),
		'partial_path_of' => array(),
	);
	$stmt = $db->prepare(
		'SELECT DISTINCT relation, study_tree FROM annotations WHERE node_external_id = ?'
	);
	$stmt->execute(array($external_id));
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
	{
		if (isset($out[$row['relation']])) $out[$row['relation']][] = $row['study_tree'];
	}
	return (object)$out;
}
