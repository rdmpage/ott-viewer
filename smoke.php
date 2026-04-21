<?php
require_once(dirname(__FILE__) . '/ott_tree.php');
require_once(dirname(__FILE__) . '/summary.php');

$db  = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$ott = new OttTree($db);

function probe($ott, $focal_id, $k) {
	$node   = $ott->get_node($focal_id);
	$weight = isset($node->weight) ? $node->weight : '?';
	echo "-- focus_on(" . $focal_id . ' "' . $node->name . '" w=' . $weight . ", k=$k) --\n";

	$s = new SummaryTree($ott);
	$s->focus_on($focal_id, $k);
	echo "displayed root = " . $s->subtree_id
		. '  nodes=' . count($s->get_nodes())
		. '  leaves=' . $s->count_leaves()
		. "\n";
	$contains_focal = isset($s->get_nodes()[(string)$focal_id]) ? 'YES' : 'NO';
	echo "focal present in summary? $contains_focal\n";
	echo $s->to_newick() . "\n\n";
}

// 1. Drill-in case: Pterodroma has weight 42 >= k=20, focal == root.
probe($ott, 631538, 20);

// 2. Climb case: a real tip species, weight 1. Root should climb up to an ancestor.
probe($ott, 631563, 20); // Pterodroma axillaris

// 3. Small internal node: weight < k, but not a tip.
probe($ott, 631565, 20); // mrcaott73703ott271044 (weight 10)
