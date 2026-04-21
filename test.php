<?php

require_once (dirname(__FILE__) . '/ott_tree.php');
require_once (dirname(__FILE__) . '/summary.php');

//----------------------------------------------------------------------------------------
function node_badge($node)
{
	$type = isset($node->type) ? $node->type : '';

	if ($type === 'other')
	{
		$s = $node->count . ' taxa';
		if ($node->weight != $node->count)
		{
			$s .= ', ' . $node->weight . ' tips';
		}
		return ' <small class="badge">[' . $s . ']</small>';
	}

	$is_tip = isset($node->supertree_leaf) && $node->supertree_leaf;
	if (!$is_tip && isset($node->weight) && $node->weight > 1)
	{
		return ' <small class="badge">[' . $node->weight . ' tips]</small>';
	}
	return '';
}

//----------------------------------------------------------------------------------------
function node_class($node, $has_children_in_summary)
{
	$type = isset($node->type) ? $node->type : 'leaf';
	if ($type === 'other') return 'sumtree-other';
	if ($has_children_in_summary) return 'sumtree-expanded';
	if (isset($node->supertree_leaf) && $node->supertree_leaf) return 'sumtree-tip';
	return 'sumtree-collapsed'; // summary leaf but supertree internal
}

//----------------------------------------------------------------------------------------
function preorder($node, $key = 'children', $focal_id = null)
{
	$is_other = (isset($node->type) && $node->type === 'other');
	$has_kids = isset($node->{$key}) && count($node->{$key}) > 0;
	$class    = node_class($node, $has_kids);
	if ($focal_id !== null && (string)$node->id === (string)$focal_id)
	{
		$class .= ' sumtree-focal';
	}

	echo '<li class="' . $class . '">' . "\n";

	if ($is_other)
	{
		echo '<details>' . "\n";
		echo '<summary>' . htmlspecialchars($node->name) . node_badge($node) . '</summary>' . "\n";
		echo '<ul>' . "\n";
		foreach ($node->others as $other)
		{
			$oclass = node_class($other, false);
			echo '<li class="' . $oclass . '">' . "\n";
			echo '<span id="node' . htmlspecialchars($other->id) . '"'
				. ' onclick="go(\'' . htmlspecialchars($other->id) . '\')"'
				. ' ondblclick="reload(\'' . htmlspecialchars($other->id) . '\')">';
			echo htmlspecialchars($other->name);
			echo node_badge($other);
			echo '</span>' . "\n";
			echo '</li>' . "\n";
		}
		echo '</ul>' . "\n";
		echo '</details>' . "\n";
	}
	else
	{
		echo '<span id="node' . htmlspecialchars($node->id) . '"'
			. ' onclick="go(\'' . htmlspecialchars($node->id) . '\')"'
			. ' ondblclick="reload(\'' . htmlspecialchars($node->id) . '\')">';
		echo htmlspecialchars($node->name);
		echo node_badge($node);
		echo '</span>' . "\n";
	}

	if ($has_kids)
	{
		echo '<ul>' . "\n";
		foreach ($node->{$key} as $child)
		{
			preorder($child, $key, $focal_id);
		}
		echo '</ul>' . "\n";
	}

	echo '</li>' . "\n";
}

//----------------------------------------------------------------------------------------

$db  = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
$ott = new OttTree($db);

$id   = isset($_GET['taxon']) ? (int)$_GET['taxon'] : 631538; // Pterodroma
$k    = isset($_GET['k'])     ? max(2, (int)$_GET['k']) : 20;
$mode = (isset($_GET['mode']) && $_GET['mode'] === 'nodes') ? 'nodes' : 'leaves';

$sumtree = new SummaryTree($ott);
if ($mode === 'nodes')
{
	$sumtree->summarise_by_nodes($id, $k);
}
else
{
	// focus_on climbs up when the focal subtree is too small, so even clicks on
	// tiny clades get a full k-leaf view with the focal node force-expanded.
	$sumtree->focus_on($id, $k);
}

$focal_id       = (string)$id;
$displayed_root = (string)$sumtree->subtree_id;

// The displayed tree is rooted at $displayed_root (which may be an ancestor of
// the focal node when we climbed up to get enough context).
$root_ctx                 = $ott->get_node_context($displayed_root);
$root_ctx->summary        = $sumtree->to_native();
$root_ctx->type           = 'internal'; // the displayed root always has children
$root_ctx->supertree_leaf = false;
$focal_node               = $ott->get_node($focal_id);

?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>OTT viewer — <?php echo htmlspecialchars($focal_node->name); ?></title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 1em 2em; color: #222; }
h1 { font-size: 1.4em; margin: 0 0 0.3em; }
ul { list-style: none; padding-left: 1em; border-left: 1px dotted #ccc; margin: 0; }
li { padding: 0.05em 0; line-height: 1.4; }
.badge { color: #888; font-weight: normal; margin-left: 0.3em; }
.sumtree-expanded  > span { font-weight: 600; }
.sumtree-tip       > span { color: #065; }
.sumtree-collapsed > span { color: #666; font-style: italic; }
.sumtree-other     > details > summary { color: #b36b00; cursor: pointer; }
.sumtree-other     > details > ul      { background: #fff8ec; }
.sumtree-focal     > span, .sumtree-focal > details > summary {
	background: #fff3b0; padding: 0 0.25em; border-radius: 3px; outline: 1px solid #e6c24b;
}
pre { background: #f5f5f5; padding: 0.5em; overflow: auto; font-size: 0.75em; white-space: pre-wrap; word-break: break-all; }
form { margin: 0.5em 0; }
.legend span { display: inline-block; padding: 0 0.4em; margin-right: 0.4em; border-radius: 3px; font-size: 0.85em; }
.legend .lg-exp  { background:#eef; }
.legend .lg-tip  { background:#e6f6ee; color:#065; }
.legend .lg-col  { background:#eee; color:#666; font-style:italic; }
.legend .lg-oth  { background:#fff8ec; color:#b36b00; }
.legend .lg-foc  { background:#fff3b0; outline:1px solid #e6c24b; }
</style>
<script>
function go(id) { /* hook for future: highlight, select */ }
function reload(id) {
	var params = new URLSearchParams(window.location.search);
	params.set('taxon', id);
	params.set('k', '<?php echo (int)$k; ?>');
	params.set('mode', '<?php echo $mode; ?>');
	window.location.search = params.toString();
}
</script>
</head>
<body>
<h1><?php echo htmlspecialchars($focal_node->name); ?></h1>
<?php if ($focal_id !== $displayed_root): ?>
<p><small>focal taxon <?php echo htmlspecialchars($focal_node->name); ?>
(weight <?php echo isset($focal_node->weight) ? (int)$focal_node->weight : '?'; ?>)
is smaller than k = <?php echo (int)$k; ?>; tree rooted at ancestor
<em><?php echo htmlspecialchars($root_ctx->name); ?></em>.</small></p>
<?php endif; ?>

<form method="get">
	<label>taxon id: <input type="number" name="taxon" value="<?php echo (int)$id; ?>" style="width:8em"></label>
	<label>k: <input type="number" name="k" value="<?php echo (int)$k; ?>" min="2" max="500" style="width:5em"></label>
	<label>mode:
		<select name="mode">
			<option value="leaves" <?php echo ($mode==='leaves' ? 'selected' : ''); ?>>by leaves</option>
			<option value="nodes"  <?php echo ($mode==='nodes'  ? 'selected' : ''); ?>>by nodes</option>
		</select>
	</label>
	<button>update</button>
</form>

<p class="legend">
	<span class="lg-exp">expanded internal</span>
	<span class="lg-tip">supertree leaf</span>
	<span class="lg-col">collapsed (internal but drawn as tip)</span>
	<span class="lg-oth">other_ placeholder</span>
	<span class="lg-foc">focal</span>
</p>

<p>Summary: <?php echo $sumtree->count_leaves(); ?> leaves, <?php echo count($sumtree->get_nodes()); ?> nodes (<?php echo htmlspecialchars($mode); ?> mode, k=<?php echo (int)$k; ?>)</p>

<details open><summary>Newick</summary>
<pre><?php echo htmlspecialchars($sumtree->to_newick()); ?></pre>
</details>

<?php
// breadcrumb: ancestors above the displayed root
$num_levels_back = 3;
$num_levels_back = min($num_levels_back, count($root_ctx->path));

$stack = array();
for ($i = $num_levels_back - 1; $i >= 0; $i--)
{
	$stack[] = $root_ctx->path[$i];
}

foreach ($stack as $path_node)
{
	echo '<ul>' . "\n";
	echo '<li><a href="?taxon=' . (int)$path_node->id . '&k=' . (int)$k . '&mode=' . htmlspecialchars($mode) . '">'
		. htmlspecialchars($path_node->name) . '</a>' . "\n";
}

echo '<ul>' . "\n";
preorder($root_ctx, 'summary', $focal_id);
echo '</ul>' . "\n";

foreach ($stack as $path_node)
{
	echo '</li></ul>' . "\n";
}
?>

</body>
</html>
