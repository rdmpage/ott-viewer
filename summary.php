<?php

require_once (dirname(__FILE__) . '/database-tree.php');
require_once (dirname(__FILE__) . '/pq.php');

//----------------------------------------------------------------------------------------
// A "summary tree" is a subtree of the supertree, expanded from a given node outward
// in priority (score) order until a size budget is hit. Unplaced children at each
// expansion frontier are collapsed into a synthetic "other_<parent>" leaf.
//
// Each node in the summary carries enough metadata to distinguish:
//   * genuine leaves of the supertree (no children in the DB)
//   * internal nodes in the summary (have at least one outgoing edge in $edges)
//   * "other_" placeholders (count of collapsed siblings, and their summed weight)

class SummaryTree
{
	// High priority given to nodes on a forced-expansion path. Chosen so the
	// PQ-derived priority (= 10000 * score) stays well above any natural score
	// but well below PHP_INT_MAX.
	const FORCE_SCORE = 1e9;

	var $dbtree;
	var $subtree_id;
	var $focal_id = null;         // set by focus_on(); id of the node the user focused on

	var $nodes        = array();  // id => label
	var $edges        = array();  // child_id => parent_id
	var $other        = array();  // parent_id => [pending child ids]
	var $weights      = array();  // id => int  (from tree.weight)
	var $nleft        = array();  // id => int  (from tree.nleft; canonical supertree order)
	var $has_children = array();  // id => bool (true iff node has children in supertree)
	var $forced_ids   = array();  // id (as string) => true; nodes on a forced-expansion path

	//------------------------------------------------------------------------------------
	function __construct($dbtree)
	{
		$this->dbtree = $dbtree;
	}

	//------------------------------------------------------------------------------------
	function get_edges()  { return $this->edges;  }
	function get_nodes()  { return $this->nodes;  }
	function get_others() { return $this->other;  }

	//------------------------------------------------------------------------------------
	// Summarise subtree rooted at $subtree_id with at most $k TOTAL nodes
	// (including "other_" placeholders).
	function summarise_by_nodes($subtree_id, $k)
	{
		$this->summarise($subtree_id, $k, 'nodes');
	}

	//------------------------------------------------------------------------------------
	// Summarise subtree rooted at $subtree_id with at most $k LEAVES in the result.
	// A leaf is a node with no outgoing edge in the summary graph; "other_"
	// placeholders are leaves by construction.
	function summarise_by_leaves($subtree_id, $k)
	{
		$this->summarise($subtree_id, $k, 'leaves');
	}

	//------------------------------------------------------------------------------------
	// Shared expansion loop. If $forced_ids is non-empty, nodes whose id is in
	// the set are enqueued with FORCE_SCORE so they jump to the head of the PQ;
	// this is what guarantees a focal node (and all ancestors of it under the
	// chosen root) is present in the summary.
	function summarise($subtree_id, $k, $mode = 'leaves', $forced_ids = array())
	{
		$this->subtree_id   = $subtree_id;
		$this->nodes        = array();
		$this->edges        = array();
		$this->other        = array();
		$this->weights      = array();
		$this->nleft        = array();
		$this->has_children = array();
		$this->forced_ids   = $forced_ids;

		$pq = new PQ();

		$root = $this->dbtree->get_node($subtree_id);
		$pq->en_queue($root->id, $root->name, $this->score_for($subtree_id));

		$size = 0;
		while ($size < $k && $pq->valid())
		{
			$current_item = $pq->de_queue();

			if (count($this->nodes) == 0)
			{
				// root
				$this->record_real_node($root);
			}
			else
			{
				$node   = $this->dbtree->get_node($current_item->id);
				$id     = $node->id;
				$anc_id = is_object($node->parentTaxon) ? $node->parentTaxon->id : $node->parentTaxon;

				$this->record_real_node($node);
				$this->edges[$id] = $anc_id;

				// remove this node from ancestor's pending-"others" list
				$key = array_search($id, $this->other[$anc_id]);
				unset($this->other[$anc_id][$key]);

				$other_node_id = 'other_' . $anc_id;
				$num_others    = count($this->other[$anc_id]);

				switch ($num_others)
				{
					case 0:
						// ancestor was monotypic — no "other_" needed
						if (isset($this->nodes[$other_node_id]))
						{
							unset($this->edges[$other_node_id]);
							unset($this->nodes[$other_node_id]);
						}
						break;

					case 1:
						// binary, or last remaining sibling of a polytomy: promote it
						if (isset($this->nodes[$other_node_id]))
						{
							unset($this->edges[$other_node_id]);
							unset($this->nodes[$other_node_id]);
						}

						$last_child_id = array_pop($this->other[$anc_id]);
						$pq->delete_from_queue($last_child_id);

						$last_child = $this->dbtree->get_node($last_child_id);
						$this->record_real_node($last_child);
						$this->edges[$last_child_id] = $anc_id;

						$this->enqueue_children_of($last_child_id, $pq);
						break;

					default:
						// ≥2 siblings still pending — ensure placeholder leaf exists
						if (!isset($this->nodes[$other_node_id]))
						{
							$this->nodes[$other_node_id] = 'other_' . $this->nodes[$anc_id];
							$this->edges[$other_node_id] = $anc_id;
						}
						break;
				}
			}

			$this->enqueue_children_of($current_item->id, $pq);

			$size = ($mode === 'leaves') ? $this->count_leaves() : count($this->nodes);
		}
	}

	//------------------------------------------------------------------------------------
	function record_real_node($node)
	{
		$this->nodes[$node->id]   = $node->name;
		$this->weights[$node->id] = isset($node->weight) ? (int)$node->weight : 0;
		if (isset($node->nleft))
		{
			$this->nleft[$node->id] = (int)$node->nleft;
		}
	}

	//------------------------------------------------------------------------------------
	// Fetch $parent_id's children, record them as its pending-"others" set, enqueue them.
	function enqueue_children_of($parent_id, $pq)
	{
		$children = $this->dbtree->get_children($parent_id);
		$this->has_children[$parent_id] = (count($children) > 0);
		$this->other[$parent_id]        = array();
		foreach ($children as $child)
		{
			$this->other[$parent_id][] = $child->id;
			if (isset($child->weight))
			{
				$this->weights[$child->id] = (int)$child->weight;
			}
			if (isset($child->nleft))
			{
				$this->nleft[$child->id] = (int)$child->nleft;
			}
			$pq->en_queue($child->id, $child->name, $this->score_for($child->id));
		}
	}

	//------------------------------------------------------------------------------------
	// Returns the priority-queue score to use for $id, substituting FORCE_SCORE
	// if $id is in the forced-expansion set.
	function score_for($id)
	{
		if (isset($this->forced_ids[(string)$id])) return self::FORCE_SCORE;
		return $this->dbtree->get_node_score($id);
	}

	//------------------------------------------------------------------------------------
	// Focus the summary on $focal_id with $k leaves.
	//   * If weight($focal_id) >= $k the focal node is used as the root (drill in).
	//   * Otherwise climb to the smallest ancestor A with weight(A) >= $k (or the
	//     tree root if none reaches $k), and summarise from A with every node on
	//     the path A -> focal AND every descendant of focal force-expanded. Forcing
	//     the descendants guarantees the focal subtree is never partially collapsed
	//     (otherwise a high-scoring sibling clade of a path node could steal budget
	//     from the focal's own children, and a clicked leaf could end up drawn as a
	//     collapsed tip while its cousins were expanded).
	function focus_on($focal_id, $k)
	{
		$this->focal_id = (string)$focal_id;

		$focal        = $this->dbtree->get_node($focal_id);
		$focal_weight = isset($focal->weight) ? (int)$focal->weight : 0;

		if ($focal_weight >= $k)
		{
			$this->summarise($focal_id, $k, 'leaves');
			return;
		}

		// Climb: build path focal -> ... -> A
		$path         = array((string)$focal_id);
		$cursor       = $focal;
		$ancestor_id  = (string)$focal_id;

		while (true)
		{
			$parent_id = null;
			if (isset($cursor->parentTaxon) && $cursor->parentTaxon)
			{
				$parent_id = is_object($cursor->parentTaxon) ? $cursor->parentTaxon->id : $cursor->parentTaxon;
			}
			if (!$parent_id)
			{
				$ancestor_id = (string)$cursor->id;
				break;
			}
			$parent        = $this->dbtree->get_node($parent_id);
			$parent_weight = isset($parent->weight) ? (int)$parent->weight : 0;
			$path[]        = (string)$parent_id;
			if ($parent_weight >= $k)
			{
				$ancestor_id = (string)$parent_id;
				break;
			}
			$cursor = $parent;
		}

		$forced = array();
		foreach ($path as $pid) $forced[$pid] = true;
		foreach ($this->dbtree->get_all_descendant_ids($focal_id) as $did)
		{
			$forced[(string)$did] = true;
		}

		$this->summarise($ancestor_id, $k, 'leaves', $forced);
	}

	//------------------------------------------------------------------------------------
	// Sort key for ordering sibling children in output. Uses the supertree's
	// nleft (pre-order) index so the left-to-right order of any given parent's
	// children is independent of the current focal node — preventing visual
	// jumps when navigating between overlapping views. "other_" placeholders
	// always sort last.
	function sort_key($id)
	{
		$sid = (string)$id;
		if (strpos($sid, 'other_') === 0) return PHP_INT_MAX;
		return isset($this->nleft[$sid]) ? $this->nleft[$sid] : PHP_INT_MAX - 1;
	}

	//------------------------------------------------------------------------------------
	// usort/uasort callback: compare two summary ids by sort_key.
	function compare_by_nleft($a_id, $b_id)
	{
		return $this->sort_key($a_id) <=> $this->sort_key($b_id);
	}

	//------------------------------------------------------------------------------------
	// A leaf = node that is never a parent in $edges. "other_" placeholders qualify.
	function count_leaves()
	{
		if (count($this->nodes) == 0) return 0;
		$internal = array_unique(array_values($this->edges));
		return count($this->nodes) - count($internal);
	}

	//------------------------------------------------------------------------------------
	// Classify a node id currently in the summary.
	//   'other'    — collapsed-siblings placeholder
	//   'internal' — has at least one outgoing edge in the summary
	//   'leaf'     — drawn as a tip in the summary
	function node_type($id)
	{
		$sid = (string)$id;
		if (strpos($sid, 'other_') === 0) return 'other';
		// loose comparison: edge values are strings from SQLite but $id may be int
		if (in_array($sid, $this->edges)) return 'internal';
		return 'leaf';
	}

	//------------------------------------------------------------------------------------
	// True iff $id is a genuine leaf of the supertree (no children in the DB).
	// Lazily queries get_children for summary leaves that were never expanded.
	function is_supertree_leaf($id)
	{
		if (strpos($id, 'other_') === 0) return false;
		if (!isset($this->has_children[$id]))
		{
			$children = $this->dbtree->get_children($id);
			$this->has_children[$id] = (count($children) > 0);
		}
		return !$this->has_children[$id];
	}

	//------------------------------------------------------------------------------------
	function other_count($parent_id)
	{
		return isset($this->other[$parent_id]) ? count($this->other[$parent_id]) : 0;
	}

	//------------------------------------------------------------------------------------
	function other_weight($parent_id)
	{
		if (!isset($this->other[$parent_id])) return 0;
		$w = 0;
		foreach ($this->other[$parent_id] as $sib_id)
		{
			$w += isset($this->weights[$sib_id]) ? $this->weights[$sib_id] : 0;
		}
		return $w;
	}

	//------------------------------------------------------------------------------------
	// Newick string with NHX-style [&...] annotations after each label, e.g.
	//   Pterodroma[&type=internal,weight=42]
	//   'other_Pterodroma'[&type=other,count=7,weight=12]
	function to_newick()
	{
		if (count($this->nodes) == 0) return ';';

		$children_of = array();
		foreach ($this->edges as $target => $source)
		{
			if (!isset($children_of[$source])) $children_of[$source] = array();
			$children_of[$source][] = $target;
		}

		$tree_nwk = $this->newick_recurse($this->subtree_id, $children_of);

		// Wrap in the immediate ancestor of the displayed root (if one exists).
		$root_node = $this->dbtree->get_node($this->subtree_id);
		if (isset($root_node->parentTaxon) && $root_node->parentTaxon)
		{
			$parent_id = is_object($root_node->parentTaxon)
				? $root_node->parentTaxon->id : $root_node->parentTaxon;
			$parent = $this->dbtree->get_node($parent_id);
			$parent_label = $this->newick_quote($parent->name);
			return '(' . $tree_nwk . ')' . $parent_label . ';';
		}

		return $tree_nwk . ';';
	}

	//------------------------------------------------------------------------------------
	function newick_recurse($node_id, $children_of)
	{
		$out = '';
		if (isset($children_of[$node_id]) && count($children_of[$node_id]) > 0)
		{
			$kids = $children_of[$node_id];
			usort($kids, array($this, 'compare_by_nleft'));
			$parts = array();
			foreach ($kids as $child_id)
			{
				$parts[] = $this->newick_recurse($child_id, $children_of);
			}
			$out .= '(' . implode(',', $parts) . ')';
		}
		$out .= $this->newick_quote($this->newick_label($node_id));
		$out .= $this->newick_comment($node_id);
		return $out;
	}

	//------------------------------------------------------------------------------------
	function newick_label($node_id)
	{
		if (preg_match('/^other_(.+)$/', $node_id, $m))
		{
			$parent_id   = $m[1];
			$parent_name = isset($this->nodes[$parent_id]) ? $this->nodes[$parent_id] : $parent_id;
			return 'other_' . $parent_name;
		}
		return isset($this->nodes[$node_id]) ? $this->nodes[$node_id] : (string)$node_id;
	}

	//------------------------------------------------------------------------------------
	function newick_comment($node_id)
	{
		$type  = $this->node_type($node_id);
		$parts = array('type=' . $type);

		if ($type === 'other')
		{
			$parent_id = substr($node_id, strlen('other_'));
			$parts[]   = 'count='  . $this->other_count($parent_id);
			$parts[]   = 'weight=' . $this->other_weight($parent_id);
		}
		else
		{
			if (isset($this->weights[$node_id])) $parts[] = 'weight=' . $this->weights[$node_id];
			if ($this->is_supertree_leaf($node_id)) $parts[] = 'supertree_leaf=1';
		}

		return '[&' . implode(',', $parts) . ']';
	}

	//------------------------------------------------------------------------------------
	function newick_quote($label)
	{
		if ($label === '' || $label === null) return '';
		if (preg_match("/[\\s,():;'\\[\\]]/", $label))
		{
			return "'" . str_replace("'", "''", $label) . "'";
		}
		return $label;
	}

	//------------------------------------------------------------------------------------
	// Native nested-object form. Each node carries:
	//   ->id, ->name, ->type, ->weight
	//   'other' nodes also carry ->count, ->others (members list)
	//   real nodes also carry ->supertree_leaf
	function to_native()
	{
		$result = array();
		$cache  = array();

		foreach ($this->edges as $target => $source)
		{
			if (!isset($cache[$source])) $cache[$source] = $this->make_native_node($source);
			if (!isset($cache[$target])) $cache[$target] = $this->make_native_node($target);

			if (!isset($cache[$source]->summary)) $cache[$source]->summary = array();
			if (!isset($cache[$source]->summary[$target]))
			{
				$cache[$source]->summary[$target] = $cache[$target];
			}
		}

		// attach the collapsed-sibling members to each "other_" node
		foreach ($this->other as $node_id => $members)
		{
			$other_node_id = 'other_' . $node_id;
			if (!isset($cache[$other_node_id])) continue;

			foreach ($members as $member_id)
			{
				$cache[$other_node_id]->others[$member_id] = $this->make_native_node($member_id);
			}
		}

		$self = $this;
		$by_nleft = function ($a, $b) use ($self)
		{
			return $self->compare_by_nleft($a->id, $b->id);
		};
		foreach ($cache as $id => &$node)
		{
			if (isset($node->summary)) uasort($node->summary, $by_nleft);
			if (isset($node->others))  uasort($node->others,  $by_nleft);
		}
		unset($node);

		if (isset($cache[$this->subtree_id]->summary))
		{
			$result = $cache[$this->subtree_id]->summary;
		}
		return $result;
	}

	//------------------------------------------------------------------------------------
	function make_native_node($id)
	{
		if (strpos($id, 'other_') === 0)
		{
			$parent_id   = substr($id, strlen('other_'));
			$parent_name = isset($this->nodes[$parent_id]) ? $this->nodes[$parent_id] : $parent_id;

			$node         = new stdclass;
			$node->id     = $id;
			$node->name   = 'other ' . $parent_name;
			$node->type   = 'other';
			$node->count  = $this->other_count($parent_id);
			$node->weight = $this->other_weight($parent_id);
			$node->others = array();
			return $node;
		}

		$node = $this->dbtree->get_node($id);
		$node->type           = $this->node_type($id);
		$node->weight         = isset($this->weights[$id]) ? $this->weights[$id]
		                        : (isset($node->weight) ? (int)$node->weight : 0);
		$node->supertree_leaf = $this->is_supertree_leaf($id);
		return $node;
	}
}

?>
