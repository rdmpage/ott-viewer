<?php

require_once (dirname(__FILE__) . '/ott_tree.php');
require_once (dirname(__FILE__) . '/summary.php');

// Emit a JSON map of { "other_<parent_name>": [ {id, label, weight}, ... ] }
// for the same focal/k as test.php. Keyed so that transition.html can look up
// members by the node id it already has (which is the prettified label).
//
// Usage: others.php?taxon=ott838239&k=20
//        curl "http://localhost/others.php?taxon=ott838239&k=20" > others1.json

header('Content-Type: application/json');

$db  = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$ott = new OttTree($db);

$default_external = 'ott838239';
$taxon_param = isset($_GET['taxon']) ? trim($_GET['taxon']) : $default_external;

$id = $ott->get_id_by_external($taxon_param);
if ($id === null && ctype_digit($taxon_param)) $id = $taxon_param;
if ($id === null) $id = $ott->get_id_by_external($default_external);

$k = isset($_GET['k']) ? max(2, (int)$_GET['k']) : 20;

$sumtree = new SummaryTree($ott);
$sumtree->focus_on($id, $k);

$nodes  = $sumtree->get_nodes();
$others = $sumtree->get_others();

$out = new stdClass;

foreach ($others as $parent_id => $member_ids)
{
	if (empty($member_ids)) continue;

	// Only emit entries that actually ended up as an "other_" placeholder in
	// the summary (parents whose children all dequeued have no placeholder).
	$placeholder_id = 'other_' . $parent_id;
	if (!isset($nodes[$placeholder_id])) continue;
	if (!isset($nodes[$parent_id]))      continue;

	// Key must match the node id that transition.html sees in tree1.json /
	// tree2.json — that id is "other_" + the prettified parent label.
	$key = 'other_' . $nodes[$parent_id];

	$members = array();
	foreach ($member_ids as $mid)
	{
		$node = $ott->get_node($mid);
		$m = new stdClass;
		$m->id             = isset($node->external_id) ? $node->external_id : (string)$mid;
		$m->label          = isset($node->name)        ? $node->name        : (string)$mid;
		$m->weight         = isset($node->weight)      ? (int)$node->weight : 0;
		// True iff this taxon is a terminal in the supertree (no children in DB).
		// Drives the solid-vs-hollow circle convention in the viewer.
		$m->supertree_leaf = $sumtree->is_supertree_leaf($mid);
		$members[]         = $m;
	}

	usort($members, function ($a, $b) { return strnatcmp($a->label, $b->label); });

	$out->$key = $members;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
