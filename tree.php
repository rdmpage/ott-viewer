<?php

require_once (dirname(__FILE__) . '/ott_tree.php');
require_once (dirname(__FILE__) . '/summary.php');
require_once (dirname(__FILE__) . '/coordinates.php');

// Emit the canonical viewer JSON for a focal taxon + k. Schema follows
// viewer-pipeline-design.md: nodes is a map keyed by id (OTT external_id,
// or "other_<parent_external_id>" for synthetic placeholders); edges is a
// flat list of {source, target}; annotations are full lists of study_tree
// ids per relation, looked up from the annotations table.
//
// Coordinates (x, y) are intentionally NOT included — the layout pass
// adds them. This script produces the layout-input shape.
//
// Usage: tree.php?taxon=ott452461&k=30
//        php tree.php ott452461 30 > tree_procellariiformes.json

if (php_sapi_name() !== 'cli')
{
	header('Content-Type: application/json');
}

$db  = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$ott = new OttTree($db);

if (php_sapi_name() === 'cli')
{
	$taxon_param = isset($argv[1]) ? $argv[1] : 'ott452461';
	$k_param     = isset($argv[2]) ? (int)$argv[2] : 30;
}
else
{
	$taxon_param = isset($_GET['taxon']) ? trim($_GET['taxon']) : 'ott452461';
	$k_param     = isset($_GET['k'])     ? max(2, (int)$_GET['k']) : 30;
}

$id = $ott->get_id_by_external($taxon_param);
if ($id === null && ctype_digit($taxon_param)) $id = $taxon_param;
if ($id === null) $id = $ott->get_id_by_external('ott452461');

$sumtree = new SummaryTree($ott);
$sumtree->focus_on($id, $k_param);

$nodes_map  = $sumtree->get_nodes();
$edges_map  = $sumtree->get_edges();
$others_map = $sumtree->get_others();

$displayed_root_internal = $sumtree->subtree_id;

// internal_id -> external_id, plus the inverse, for every real node in
// the summary. Lets us translate edges to external-id pairs.
$int_to_ext = array();
foreach ($nodes_map as $internal_id => $_name)
{
	if (strpos($internal_id, 'other_') === 0) continue;
	$n = $ott->get_node($internal_id);
	$int_to_ext[$internal_id] = isset($n->external_id) ? $n->external_id : (string)$internal_id;
}

// "Internal in this view" = appears as a parent in some edge.
$internal_in_view = array();
foreach ($edges_map as $_child => $parent) $internal_in_view[$parent] = true;

// Annotations rows per (node, relation, study_tree, source_node_id) —
// the same (relation, study_tree) can repeat with different source_node_id
// values. Use DISTINCT so each study_tree appears at most once per
// relation in the JSON. Returns empty arrays for relations the node has
// no rows for, so the schema is uniform.
$ann_stmt = $db->prepare(
	'SELECT DISTINCT relation, study_tree FROM annotations WHERE node_external_id = ?'
);
function fetch_annotations($stmt, $external_id)
{
	$out = array(
		'supported_by'    => array(),
		'terminal'        => array(),
		'resolves'        => array(),
		'conflicts_with'  => array(),
		'partial_path_of' => array(),
	);
	$stmt->execute(array($external_id));
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
	{
		if (isset($out[$row['relation']])) $out[$row['relation']][] = $row['study_tree'];
	}
	return $out;
}

// Top-level container. We use stdClass + dynamic keys so the nodes map
// serializes as a JSON object (not an array), in canonical (insertion)
// order.
$out = new stdClass;

$focal_node = $ott->get_node($id);
$out->focal_id          = isset($focal_node->external_id) ? $focal_node->external_id : (string)$id;
$out->displayed_root_id = isset($int_to_ext[$displayed_root_internal])
	? $int_to_ext[$displayed_root_internal]
	: (string)$displayed_root_internal;

$out->nodes = new stdClass;

foreach ($nodes_map as $internal_id => $_name)
{
	if (strpos($internal_id, 'other_') === 0)
	{
		$parent_internal = substr($internal_id, strlen('other_'));
		$parent_ext      = isset($int_to_ext[$parent_internal]) ? $int_to_ext[$parent_internal] : $parent_internal;
		$parent_display  = isset($nodes_map[$parent_internal]) ? $nodes_map[$parent_internal] : $parent_internal;

		$other_id = 'other_' . $parent_ext;

		// If the parent is an anonymous mrca node, its prettified label is
		// "<tipA> + <tipB>" — and the same tipA can appear across many mrca
		// nodes (the convention picks arbitrary descendants). That makes
		// multiple "other_" placeholders look near-identical in the tree
		// even though they sit at different levels. So we drop the parent
		// name in that case and just show "other"; the peek list reveals
		// what's actually inside. For named parents (Hirundinidae,
		// Pterodroma, etc.) the parent name is genuinely informative and
		// is kept.
		$parent_is_mrca = (strpos((string)$parent_ext, 'mrca') === 0);
		$obj = new stdClass;
		$obj->id      = $other_id;
		$obj->display = $parent_is_mrca ? 'other' : ('other ' . $parent_display);
		$obj->type    = 'other';
		$obj->members = array();

		$member_internal_ids = isset($others_map[$parent_internal]) ? $others_map[$parent_internal] : array();
		foreach ($member_internal_ids as $mid)
		{
			$mn = $ott->get_node($mid);
			$m  = new stdClass;
			$m->id             = isset($mn->external_id) ? $mn->external_id : (string)$mid;
			$m->display        = isset($mn->name)        ? $mn->name        : (string)$mid;
			// Members are always rendered as tips of the peek, even if they
			// are internal in the supertree. supertree_leaf disambiguates.
			$m->type           = 'leaf';
			$m->supertree_leaf = $sumtree->is_supertree_leaf($mid);
			$m->weight         = isset($mn->weight) ? (int)$mn->weight : 0;
			$m->annotations    = fetch_annotations($ann_stmt, $m->id);
			$obj->members[]    = $m;
		}

		$out->nodes->$other_id = $obj;
	}
	else
	{
		$ext  = $int_to_ext[$internal_id];
		$node = $ott->get_node($internal_id);

		$obj = new stdClass;
		$obj->id             = $ext;
		$obj->display        = isset($node->name) ? $node->name : (string)$internal_id;
		$obj->type           = isset($internal_in_view[$internal_id]) ? 'internal' : 'leaf';
		$obj->supertree_leaf = $sumtree->is_supertree_leaf($internal_id);
		$obj->weight         = isset($node->weight) ? (int)$node->weight : 0;
		$obj->annotations    = fetch_annotations($ann_stmt, $ext);

		$out->nodes->$ext = $obj;
	}
}

// Edges keyed by external_id (or other_<external_id> for synthetic targets).
$out->edges = array();
foreach ($edges_map as $child_internal => $parent_internal)
{
	$source = isset($int_to_ext[$parent_internal]) ? $int_to_ext[$parent_internal] : $parent_internal;

	if (strpos($child_internal, 'other_') === 0)
	{
		$op = substr($child_internal, strlen('other_'));
		$op_ext = isset($int_to_ext[$op]) ? $int_to_ext[$op] : $op;
		$target = 'other_' . $op_ext;
	}
	else
	{
		$target = isset($int_to_ext[$child_internal]) ? $int_to_ext[$child_internal] : $child_internal;
	}

	$e = new stdClass;
	$e->source = $source;
	$e->target = $target;
	$out->edges[] = $e;
}

// Stub node representing the supertree parent of the displayed root, for
// upstream context. Added before layout so coordinates.php places it one
// depth-step to the left of the displayed root with the same y (it has
// only the displayed root as its child here, so the y inherits). Skipped
// when the displayed root has no parent (i.e. is the supertree root).
$root_supertree_node = $ott->get_node($displayed_root_internal);
if (isset($root_supertree_node->parentTaxon) && $root_supertree_node->parentTaxon)
{
	$parent_internal = is_object($root_supertree_node->parentTaxon)
		? $root_supertree_node->parentTaxon->id
		: $root_supertree_node->parentTaxon;
	$parent_node = $ott->get_node($parent_internal);
	$parent_ext  = isset($parent_node->external_id)
		? $parent_node->external_id
		: (string)$parent_internal;

	if (!isset($out->nodes->$parent_ext))
	{
		$stub = new stdClass;
		$stub->id             = $parent_ext;
		$stub->display        = isset($parent_node->name) ? $parent_node->name : $parent_ext;
		// "stub" rather than "leaf" so the renderer's tip-spacing
		// calculation (which only inspects "leaf" / "other") doesn't
		// see the stub's y — the stub inherits its y from the displayed
		// root and would otherwise collapse minStepY.
		$stub->type           = 'stub';
		$stub->supertree_leaf = false;
		$stub->weight         = isset($parent_node->weight) ? (int)$parent_node->weight : 0;
		$stub->annotations    = fetch_annotations($ann_stmt, $parent_ext);
		$out->nodes->$parent_ext = $stub;

		$stub_edge = new stdClass;
		$stub_edge->source = $parent_ext;
		$stub_edge->target = $out->displayed_root_id;
		$out->edges[] = $stub_edge;
	}
}

get_node_coordinates($out);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
