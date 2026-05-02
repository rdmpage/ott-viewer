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

// Default focal: ott93302 = "cellular organisms" (the OTT root). Picked
// so a parameter-less request lands at the top of the tree and the user
// can browse downward, mirroring how OTT's own viewer opens.
$default_taxon = 'ott93302';

if (php_sapi_name() === 'cli')
{
	$taxon_param = isset($argv[1]) ? $argv[1] : $default_taxon;
	$k_param     = isset($argv[2]) ? (int)$argv[2] : 30;
}
else
{
	$taxon_param = isset($_GET['taxon']) ? trim($_GET['taxon']) : $default_taxon;
	$k_param     = isset($_GET['k'])     ? max(2, (int)$_GET['k']) : 30;
}

$id = $ott->get_id_by_external($taxon_param);
if ($id === null && ctype_digit($taxon_param)) $id = $taxon_param;
if ($id === null) $id = $ott->get_id_by_external($default_taxon);

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

// Memoised post-order DFS for the descendant-tip count in the displayed
// tree (i.e. summary tree, not the full supertree — the supertree count
// lives on `weight`). Tips and other_* placeholders both count as 1; an
// internal's tip_count is the sum of its children's. The stub above the
// displayed root inherits the displayed root's count.
function compute_tip_count($id, &$children, &$cache)
{
	if (isset($cache[$id])) return $cache[$id];
	if (!isset($children[$id]) || count($children[$id]) === 0)
	{
		$cache[$id] = 1;
		return 1;
	}
	$sum = 0;
	foreach ($children[$id] as $kid)
	{
		$sum += compute_tip_count($kid, $children, $cache);
	}
	$cache[$id] = $sum;
	return $sum;
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
		$obj->id             = $other_id;
		$obj->display        = $parent_is_mrca ? 'other' : ('other ' . $parent_display);
		$obj->type           = 'other';
		// other_ placeholders are synthetic — never supertree leaves; the
		// renderer keys its solid/hollow/triangle decision off this flag.
		$obj->supertree_leaf = false;
		$obj->members        = array();

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

// Per-node depth + tip_count, computed from the finalised edge list (incl.
// stub edge if present). Both are convenience fields so external clients
// don't have to re-derive them — `depth` saves a BFS, `tip_count` saves
// a post-order DFS, and unlike `weight` it reflects the displayed tree
// rather than the full supertree.
//
//   depth      = distance from displayed_root_id (root = 0, descendants
//                1, 2, …). The stub upstream of the root is -1 because
//                it sits one step to the left in the layout.
//   tip_count  = number of leaf / other_ descendants in the displayed
//                tree. Tips and other_* placeholders are 1.
$children_adj = array();
foreach ($out->edges as $e)
{
	if (!isset($children_adj[$e->source])) $children_adj[$e->source] = array();
	$children_adj[$e->source][] = $e->target;
}

foreach (get_object_vars($out->nodes) as $nid => $node)
{
	$out->nodes->$nid->depth = null;
}
if (isset($out->nodes->{$out->displayed_root_id}))
{
	$out->nodes->{$out->displayed_root_id}->depth = 0;
	$queue = array($out->displayed_root_id);
	while (count($queue) > 0)
	{
		$cur = array_shift($queue);
		$cur_depth = $out->nodes->$cur->depth;
		if (!isset($children_adj[$cur])) continue;
		foreach ($children_adj[$cur] as $kid)
		{
			if (isset($out->nodes->$kid) && $out->nodes->$kid->depth === null)
			{
				$out->nodes->$kid->depth = $cur_depth + 1;
				$queue[] = $kid;
			}
		}
	}
}
foreach ($out->edges as $e)
{
	if ($e->target === $out->displayed_root_id
		&& isset($out->nodes->{$e->source})
		&& isset($out->nodes->{$e->source}->type)
		&& $out->nodes->{$e->source}->type === 'stub')
	{
		$out->nodes->{$e->source}->depth = -1;
	}
}

$tip_count_cache = array();
foreach (get_object_vars($out->nodes) as $nid => $node)
{
	$out->nodes->$nid->tip_count = compute_tip_count($nid, $children_adj, $tip_count_cache);
}

get_node_coordinates($out);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
