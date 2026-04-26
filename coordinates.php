<?php

require_once (dirname(__FILE__) . '/tree/node_iterator.php');
require_once (dirname(__FILE__) . '/tree/tree.php');
require_once (dirname(__FILE__) . '/tree/tree-order.php');

//-------------------------------------------------------------------------------------------------
class IdOrder extends TreeOrder
{

	function MustSwap($p, $q)
	{
		return ($p->GetId() > $q->GetId());
	}
}

//----------------------------------------------------------------------------------------
// compute x,y coordinates for a tree
function treexy(&$t, $width = 100, $height = 100)
{
	$tree_style = 1; // 0 is angled cladogram, 1 is rectangula cladogram
	
	$left   = 0;
	$top    = 0;
		
	// weights
	$n = new NodeIterator ($t->GetRoot());
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->weight = 1;
		}
	
		$anc = $q->GetAncestor();
		if ($anc)
		{
			$anc->weight += $q->weight;
		}
		
		$q = $n->Next();
	}
	
	// depth
	$max_depth = 0;
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->depth = 0;
			$count = 0;
			$p = $q->GetAncestor();
			while ($p)
			{
				$count++;
				if ($count > $p->depth)
				{
					$p->depth = $count;
					$max_depth = max($max_depth, $p->depth);
				}
				
				$p = $p->GetAncestor();
			}
		}
		$q = $n->Next();
	}	
	
	if ($tree_style === 0)
	{
		$x_gap = $width / ($t->GetNumLeaves() - 1);
	}
	else
	{
		$x_gap = $width / $max_depth;
	}
	$y_gap = $height / ($t->GetNumLeaves() - 1);
	$last_y = 0;
	
	$leaf_count = 0;
	
	// coordinates
	$q = $n->Begin();
	while ($q != NULL)
	{	
		if ($q->IsLeaf())
		{
			$q->x = $left + $width;
			$q->y = $top + $leaf_count * $y_gap;
			
			$leaf_count++;
			$last_y = $q->y;
		}
		else
		{
			// internal x
			if ($tree_style === 0)
			{
				$q->x = $left + ($x_gap * ($t->GetNumLeaves() - $q->weight));
			}
			else
			{
				$q->x = $left + ($x_gap * ($max_depth - $q->depth));
			}			
				
			// internal y
			if ($tree_style === 0)
			{
				$q->y = $last_y - ($q->weight - 1) * $y_gap / 2.0;
			}
			else
			{			
				$span = $q->GetChild()->GetRightMostSibling()->y - $q->GetChild()->y;			
				$q->y = $q->GetChild()->y + $span/2;
			}
		}
		
		$q = $n->Next();
	}
}

//----------------------------------------------------------------------------------------
function get_node_coordinates(&$tree_obj)
{
	// Tree object that we will traverse
	$t = new Tree();
	
	// Create nodes, indexed on id
	$node_list = [];
	foreach ($tree_obj->nodes as $id => $node)
	{
		$curnode = $t->NewNode();
		
		$curnode->SetId($id);
		$curnode->SetLabel($id);
		//$curnode->SetLabel($node->display);
		
		$node_list[$id] = $curnode;
	}
	
	// Create edges
	foreach ($tree_obj->edges as $edge)
	{
		$anc     = $node_list[$edge->source];
		$curnode = $node_list[$edge->target];
		
		$curnode->SetAncestor($anc);
		
		$p = $anc->GetChild();	
		if ($p)
		{
			$p = $p->GetRightMostSibling();
			$p->SetSibling($curnode);
		}
		else
		{
			$anc->SetChild($curnode);
		}
	}
	
	// Find root of the tree, and set leaves
	foreach ($tree_obj->nodes as $id => $node)
	{
		$curnode = $node_list[$id];
		
		$anc = $curnode->GetAncestor();
		if (!$anc)
		{
			$t->SetRoot($curnode);
		}
		
		if ($curnode->IsLeaf())
		{
			$t->num_leaves++;
		}
	}
	
	// echo $t->WriteNewick() . "\n";
	
	$order_by_id = new IdOrder($t);
	$order_by_id->Order();
	
	treexy($t);
	
	// store x,y
	foreach ($tree_obj->nodes as $id => &$node)
	{
		$curnode = $node_list[$id];
		
		$node->x = $curnode->x;
		$node->y = $curnode->y;
	}
}

if (0)
{
	
	$filename = 'tree1.json';
	
	$json = file_get_contents($filename);
	$obj = json_decode($json);
	
	get_node_coordinates($obj);
	
	print_r($obj);
	
	/*
	{
		"focal_id": "ott452461",
		"displayed_root_id": "ott452461",
		"nodes": {
			"ott452461": {
			
		"edges": [
			{
				"source": "ott452461",
				"target": "mrcaott18206ott31011"
			},
			
	*/
}

?>
