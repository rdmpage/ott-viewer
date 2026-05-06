<?php

// Pure builder for the canonical viewer tree JSON. Extracted from
// tree.php so the new API handler and the legacy CLI/HTTP shim share
// one implementation. Schema follows viewer-pipeline-design.md:
//   { focal_id, displayed_root_id, nodes: { id -> {...} }, edges: [...] }
// Each node carries id, display, type, supertree_leaf, weight,
// annotations, depth, tip_count, x, y; "other" nodes also carry
// members[] (each with full annotations); "stub" nodes mark the
// supertree parent of the displayed root for upstream context.

require_once dirname(__FILE__) . '/../../ott_tree.php';
require_once dirname(__FILE__) . '/../../summary.php';
require_once dirname(__FILE__) . '/../../coordinates.php';

// Build the tree payload for a given external id + leaf budget.
//   $taxon — OTT external id (`ottN`) or anonymous mrca id (`mrcaottXottY`).
//            Falls back to `ott93302` (cellular organisms) if not found.
//   $k     — summary-tree leaf budget. Min 2.
function build_tree_payload(PDO $db, $taxon, $k)
{
	$ott = new OttTree($db);

	$default_taxon = 'ott93302';

	$id = $ott->get_id_by_external($taxon);
	if ($id === null && ctype_digit($taxon)) $id = $taxon;
	if ($id === null) $id = $ott->get_id_by_external($default_taxon);

	$k = max(2, (int)$k);

	$sumtree = new SummaryTree($ott);
	$sumtree->focus_on($id, $k);

	$nodes_map  = $sumtree->get_nodes();
	$edges_map  = $sumtree->get_edges();
	$others_map = $sumtree->get_others();

	$displayed_root_internal = $sumtree->subtree_id;

	// internal_id -> external_id, for every real node in the summary.
	// Translates summary-tree internal ids back to API-facing ids.
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

	// Per-relation annotation lists for one external id. DISTINCT so each
	// study_tree appears once per relation. Returns the full set of keys
	// even when empty so the schema is uniform across nodes.
	$ann_stmt = $db->prepare(
		'SELECT DISTINCT relation, study_tree FROM annotations WHERE node_external_id = ?'
	);
	$fetch_annotations = function ($external_id) use ($ann_stmt) {
		$out = array(
			'supported_by'    => array(),
			'terminal'        => array(),
			'resolves'        => array(),
			'conflicts_with'  => array(),
			'partial_path_of' => array(),
		);
		$ann_stmt->execute(array($external_id));
		while ($row = $ann_stmt->fetch(PDO::FETCH_ASSOC))
		{
			if (isset($out[$row['relation']])) $out[$row['relation']][] = $row['study_tree'];
		}
		return $out;
	};

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

			$parent_is_mrca = (strpos((string)$parent_ext, 'mrca') === 0);
			$obj = new stdClass;
			$obj->id             = $other_id;
			$obj->display        = $parent_is_mrca ? 'other' : ('other ' . $parent_display);
			$obj->type           = 'other';
			$obj->supertree_leaf = false;
			$obj->members        = array();

			$member_internal_ids = isset($others_map[$parent_internal]) ? $others_map[$parent_internal] : array();
			foreach ($member_internal_ids as $mid)
			{
				$mn = $ott->get_node($mid);
				$m  = new stdClass;
				$m->id             = isset($mn->external_id) ? $mn->external_id : (string)$mid;
				$m->display        = isset($mn->name)        ? $mn->name        : (string)$mid;
				$m->type           = 'leaf';
				$m->supertree_leaf = $sumtree->is_supertree_leaf($mid);
				$m->weight         = isset($mn->weight) ? (int)$mn->weight : 0;
				$m->annotations    = $fetch_annotations($m->id);
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
			$obj->annotations    = $fetch_annotations($ext);

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
			$op     = substr($child_internal, strlen('other_'));
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

	// Stub upstream of the displayed root, for context. Skipped when the
	// displayed root is the supertree root.
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
			$stub->type           = 'stub';
			$stub->supertree_leaf = false;
			$stub->weight         = isset($parent_node->weight) ? (int)$parent_node->weight : 0;
			$stub->annotations    = $fetch_annotations($parent_ext);
			$out->nodes->$parent_ext = $stub;

			$stub_edge = new stdClass;
			$stub_edge->source = $parent_ext;
			$stub_edge->target = $out->displayed_root_id;
			$out->edges[] = $stub_edge;
		}
	}

	// depth (BFS from displayed root) + tip_count (post-order DFS).
	$children_adj = array();
	foreach ($out->edges as $e)
	{
		if (!isset($children_adj[$e->source])) $children_adj[$e->source] = array();
		$children_adj[$e->source][] = $e->target;
	}

	foreach (get_object_vars($out->nodes) as $nid => $_node)
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
	foreach (get_object_vars($out->nodes) as $nid => $_node)
	{
		$out->nodes->$nid->tip_count = _build_tree_tip_count($nid, $children_adj, $tip_count_cache);
	}

	get_node_coordinates($out);

	return $out;
}

// Memoised post-order count of leaf / other_ descendants in the displayed
// tree. Tips and other_ placeholders are 1; an internal's tip_count is
// the sum of its children's. Underscore-prefixed because it's a private
// helper only meaningful with the children adjacency built above.
function _build_tree_tip_count($id, &$children, &$cache)
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
		$sum += _build_tree_tip_count($kid, $children, $cache);
	}
	$cache[$id] = $sum;
	return $sum;
}
