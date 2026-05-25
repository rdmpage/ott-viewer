<?php

require_once (dirname(__FILE__) . '/ott_tree.php');

// Structural query layer over the OTT tree. Uses the nested-set encoding
// (nleft / nright) already present on the `tree` table so most queries are
// a single SQL with a comparison rather than a recursive walk.
//
// All node columns are stored as TEXT in the DB so comparisons are
// CAST(... AS INTEGER) to ensure numeric (not lexicographic) order.
//
// See phylogeny-queries.md for the design rationale and the full set of
// queries we expect to need; this file currently implements the subset
// the hoptree needs.

class TreeQueries
{
	var $db;
	var $ott;

	function __construct($db, $ott = null)
	{
		$this->db  = $db;
		$this->ott = $ott ? $ott : new OttTree($db);
	}

	// Resolve a list of external_ids to a list of internal rows
	// {id, external_id, label, depth, nleft, nright, weight}. Missing
	// external_ids are silently dropped (mirrors how the rest of the app
	// handles unknown ids — never block on data noise).
	function lookup_external($ext_ids)
	{
		if (empty($ext_ids)) return array();
		$placeholders = implode(',', array_fill(0, count($ext_ids), '?'));
		$sql = "SELECT t.id,
		               ta.external_id,
		               ta.label,
		               CAST(t.depth  AS INTEGER) AS depth,
		               CAST(t.nleft  AS INTEGER) AS nleft,
		               CAST(t.nright AS INTEGER) AS nright,
		               CAST(t.weight AS INTEGER) AS weight,
		               t.parent
		        FROM tree t
		        INNER JOIN taxa ta USING(id)
		        WHERE ta.external_id IN ($placeholders)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($ext_ids);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// MRCA of two nodes given their nleft / nright bounds. Returns one
	// row {id, external_id, label, depth, nleft, nright, weight}, or NULL.
	function mrca_by_bounds($minL, $maxR)
	{
		$sql = 'SELECT t.id,
		               ta.external_id,
		               ta.label,
		               CAST(t.depth  AS INTEGER) AS depth,
		               CAST(t.nleft  AS INTEGER) AS nleft,
		               CAST(t.nright AS INTEGER) AS nright,
		               CAST(t.weight AS INTEGER) AS weight,
		               t.parent
		        FROM tree t
		        INNER JOIN taxa ta USING(id)
		        WHERE CAST(t.nleft  AS INTEGER) <= :minL
		          AND CAST(t.nright AS INTEGER) >= :maxR
		        ORDER BY CAST(t.depth AS INTEGER) DESC
		        LIMIT 1';
		$stmt = $this->db->prepare($sql);
		$stmt->execute(array(':minL' => $minL, ':maxR' => $maxR));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : null;
	}

	// MRCA of two external_ids, returning the same row shape as
	// lookup_external rows. Convenience wrapper around mrca_by_bounds.
	function mrca($a_ext, $b_ext)
	{
		$rows = $this->lookup_external(array($a_ext, $b_ext));
		if (count($rows) !== 2) return null;
		$minL = min((int)$rows[0]['nleft'],  (int)$rows[1]['nleft']);
		$maxR = max((int)$rows[0]['nright'], (int)$rows[1]['nright']);
		return $this->mrca_by_bounds($minL, $maxR);
	}

	// Relationship of $a to $b. Returns 'self' | 'ancestor' | 'descendant'
	// | 'cousin', or NULL if either id is unknown.
	function relationship($a_ext, $b_ext)
	{
		if ($a_ext === $b_ext) {
			// Verify the id actually exists before claiming self.
			$rows = $this->lookup_external(array($a_ext));
			return count($rows) === 1 ? 'self' : null;
		}
		$rows = $this->lookup_external(array($a_ext, $b_ext));
		if (count($rows) !== 2) return null;
		// Reorder so $a comes first in the result regardless of IN-order.
		$by_ext = array();
		foreach ($rows as $r) $by_ext[$r['external_id']] = $r;
		if (!isset($by_ext[$a_ext], $by_ext[$b_ext])) return null;
		$a = $by_ext[$a_ext];
		$b = $by_ext[$b_ext];

		if ($a['id'] === $b['id'])                                  return 'self';
		if ($a['nleft'] <= $b['nleft'] && $a['nright'] >= $b['nright']) return 'ancestor';
		if ($b['nleft'] <= $a['nleft'] && $b['nright'] >= $a['nright']) return 'descendant';
		return 'cousin';
	}

	// ── Name resolution ──────────────────────────────────────────────

	// Resolve a taxon name to an external_id. Returns the first match
	// or NULL. Accepts names like "Anolis carolinensis" or OTT ids
	// like "ott746703" (passed through unchanged).
	function resolve_name($name)
	{
		$name = trim($name);
		if ($name === '') return null;
		if (preg_match('/^ott\d+$/', $name)) return $name;
		if (preg_match('/^\d+$/', $name)) return 'ott' . $name;

		$stmt = $this->db->prepare(
			'SELECT ta.external_id
			 FROM taxa ta
			 WHERE ta.label = ?
			 LIMIT 1'
		);
		$stmt->execute(array($name));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row['external_id'] : null;
	}

	// Resolve a list of names/ids. Returns an array of external_ids
	// (NULLs for unresolved names).
	function resolve_names($names)
	{
		return array_map(array($this, 'resolve_name'), $names);
	}

	// ── Minimum clade (MRCA of N taxa) ──────────────────────────────

	// MRCA of an arbitrary list of external_ids. Returns the same row
	// shape as lookup_external, or NULL if fewer than two resolve.
	function mrca_of($ext_ids)
	{
		$rows = $this->lookup_external($ext_ids);
		if (count($rows) < 2) return null;
		$minL = PHP_INT_MAX;
		$maxR = PHP_INT_MIN;
		foreach ($rows as $r) {
			if ((int)$r['nleft']  < $minL) $minL = (int)$r['nleft'];
			if ((int)$r['nright'] > $maxR) $maxR = (int)$r['nright'];
		}
		return $this->mrca_by_bounds($minL, $maxR);
	}

	// ── Maximum clade (largest clade containing A but not B) ────────

	// Given one or more internal specifiers and one or more external
	// specifiers, find the largest clade that contains all internals
	// but none of the externals. Algorithm: find MRCA of all specifiers,
	// then walk down to the child that contains the internal specifiers.
	// Returns the same row shape as lookup_external, or NULL.
	function max_clade($include_ext, $exclude_ext)
	{
		$all = array_merge($include_ext, $exclude_ext);
		$rows = $this->lookup_external($all);
		$by_ext = array();
		foreach ($rows as $r) $by_ext[$r['external_id']] = $r;

		foreach ($all as $ext) {
			if (!isset($by_ext[$ext])) return null;
		}

		// MRCA of all specifiers.
		$minL = PHP_INT_MAX;
		$maxR = PHP_INT_MIN;
		foreach ($rows as $r) {
			if ((int)$r['nleft']  < $minL) $minL = (int)$r['nleft'];
			if ((int)$r['nright'] > $maxR) $maxR = (int)$r['nright'];
		}
		$mrca = $this->mrca_by_bounds($minL, $maxR);
		if (!$mrca) return null;

		// Walk from the MRCA down toward the included taxa. At each
		// step, descend into the child that contains all included
		// specifiers. Stop when we reach a node that contains no
		// excluded specifiers — that's the maximum clade.
		$node = $mrca;
		$best = null;
		$max_depth = 500;
		while ($max_depth-- > 0) {
			$nl = (int)$node['nleft'];
			$nr = (int)$node['nright'];

			// Check whether current node excludes all external specifiers.
			$has_exclude = false;
			foreach ($exclude_ext as $exc) {
				$r = $by_ext[$exc];
				if ((int)$r['nleft'] >= $nl && (int)$r['nright'] <= $nr) {
					$has_exclude = true;
					break;
				}
			}
			if (!$has_exclude) { $best = $node; break; }

			// Descend into the child that contains all included specifiers.
			$children = $this->children_of_internal($node['id']);
			if (empty($children)) break;

			$next = null;
			foreach ($children as $child) {
				$cl = (int)$child['nleft'];
				$cr = (int)$child['nright'];
				$has_all = true;
				foreach ($include_ext as $inc) {
					$r = $by_ext[$inc];
					if ((int)$r['nleft'] < $cl || (int)$r['nright'] > $cr) {
						$has_all = false;
						break;
					}
				}
				if ($has_all) { $next = $child; break; }
			}
			if (!$next) break;
			$node = $next;
		}

		return $best;
	}

	// Direct children of an internal node (by internal id).
	function children_of_internal($internal_id)
	{
		$stmt = $this->db->prepare(
			'SELECT t.id,
			        ta.external_id,
			        ta.label,
			        CAST(t.depth  AS INTEGER) AS depth,
			        CAST(t.nleft  AS INTEGER) AS nleft,
			        CAST(t.nright AS INTEGER) AS nright,
			        CAST(t.weight AS INTEGER) AS weight,
			        t.parent
			 FROM tree t
			 INNER JOIN taxa ta USING(id)
			 WHERE t.parent = ? AND t.id != ?'
		);
		$stmt->execute(array($internal_id, $internal_id));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Sister group ────────────────────────────────────────────────

	// Returns the sister group(s) of a node. If the immediate parent
	// is monotypic (single child), walks up through monotypic ancestors
	// until a branching node is found. Returns an associative array:
	//   'sisters'    => array of rows (same shape as lookup_external)
	//   'climbed_to' => row of the ancestor we climbed to (null if none)
	// For backward compatibility, also works when called in a context
	// that just iterates the result (returns the array above, not a
	// flat list).
	function sister_of($ext_id)
	{
		$rows = $this->lookup_external(array($ext_id));
		if (empty($rows)) return null;
		$node = $rows[0];

		$climbed_to = null;
		$max_climb = 50;
		while ($max_climb-- > 0) {
			$parent_id = $node['parent'];
			if ($parent_id === $node['id']) {
				return array('sisters' => array(), 'climbed_to' => $climbed_to);
			}

			$children = $this->children_of_internal($parent_id);
			$siblings = array_values(array_filter($children, function ($c) use ($node) {
				return $c['id'] !== $node['id'];
			}));

			if (!empty($siblings)) {
				return array('sisters' => $siblings, 'climbed_to' => $climbed_to);
			}

			// Parent is monotypic — climb up.
			$parent_row = $this->_lookup_internal($parent_id);
			if (!$parent_row) break;
			$climbed_to = $parent_row;
			$node = $parent_row;
		}

		return array('sisters' => array(), 'climbed_to' => $climbed_to);
	}

	// Fetch a single node by internal id.
	function _lookup_internal($internal_id)
	{
		$stmt = $this->db->prepare(
			'SELECT t.id,
			        ta.external_id,
			        ta.label,
			        CAST(t.depth  AS INTEGER) AS depth,
			        CAST(t.nleft  AS INTEGER) AS nleft,
			        CAST(t.nright AS INTEGER) AS nright,
			        CAST(t.weight AS INTEGER) AS weight,
			        t.parent
			 FROM tree t
			 INNER JOIN taxa ta USING(id)
			 WHERE t.id = ?'
		);
		$stmt->execute(array($internal_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row : null;
	}

	// ── Monophyly test ──────────────────────────────────────────────

	// Tests whether a set of taxa form a monophyletic group in this
	// tree — i.e. their MRCA contains exactly these taxa and no others
	// at the same rank. In practice: the MRCA's descendants include
	// all the listed taxa and no additional tips.
	//
	// Returns ['monophyletic' => bool, 'mrca' => row, 'reason' => string].
	function is_monophyletic($ext_ids)
	{
		$rows = $this->lookup_external($ext_ids);
		if (count($rows) < 2)
			return array('monophyletic' => false, 'mrca' => null,
			             'reason' => 'fewer than two taxa resolved');

		$minL = PHP_INT_MAX;
		$maxR = PHP_INT_MIN;
		foreach ($rows as $r) {
			if ((int)$r['nleft']  < $minL) $minL = (int)$r['nleft'];
			if ((int)$r['nright'] > $maxR) $maxR = (int)$r['nright'];
		}
		$mrca = $this->mrca_by_bounds($minL, $maxR);
		if (!$mrca)
			return array('monophyletic' => false, 'mrca' => null,
			             'reason' => 'MRCA not found');

		// Count tips under the MRCA.
		$mrca_tips = (int)$mrca['weight'];
		// Count how many of the input taxa are tips (leaves in the supertree).
		$input_tips = 0;
		foreach ($rows as $r) {
			if ((int)$r['nright'] - (int)$r['nleft'] === 1) $input_tips++;
		}

		// The input set is monophyletic iff the MRCA contains exactly
		// the tip descendants implied by the input. For a set of tips,
		// that means mrca_tips === count(input). For a mix of tips and
		// clades it's harder — we sum the weights of the input taxa.
		$input_weight = 0;
		foreach ($rows as $r) $input_weight += (int)$r['weight'];

		$mono = ($mrca_tips === $input_weight);
		$reason = $mono
			? 'MRCA contains exactly the specified taxa'
			: 'MRCA contains ' . $mrca_tips . ' tips but input taxa span ' . $input_weight;

		return array(
			'monophyletic' => $mono,
			'mrca'         => array(
				'id'      => $mrca['external_id'],
				'display' => $this->ott->prettify_label($mrca['label']),
				'weight'  => $mrca_tips,
			),
			'reason' => $reason,
		);
	}

	// ── Topology test ───────────────────────────────────────────────

	// Tests whether the tree is consistent with a stated topology, e.g.
	// "((A,B),C)" meaning A and B are more closely related to each
	// other than either is to C. This is equivalent to checking that
	// MRCA(A,B) is a proper descendant of MRCA(A,B,C).
	//
	// Accepts a simple nested array representation:
	//   [[A, B], C] means ((A,B),C)
	// Returns ['consistent' => bool, 'reason' => string].
	function test_topology($topology)
	{
		// Flatten the topology to get all leaf taxa.
		$leaves = array();
		$this->_flatten_topology($topology, $leaves);

		if (count($leaves) < 3)
			return array('consistent' => false,
			             'reason' => 'need at least three taxa');

		$rows = $this->lookup_external($leaves);
		$by_ext = array();
		foreach ($rows as $r) $by_ext[$r['external_id']] = $r;

		if (count($by_ext) < count($leaves))
			return array('consistent' => false,
			             'reason' => 'not all taxa resolved');

		return $this->_check_topology_node($topology, $by_ext);
	}

	private function _flatten_topology($node, &$out)
	{
		if (is_string($node)) { $out[] = $node; return; }
		foreach ($node as $child) $this->_flatten_topology($child, $out);
	}

	private function _check_topology_node($node, &$by_ext)
	{
		if (is_string($node)) return array('consistent' => true, 'reason' => 'leaf');

		// Compute MRCA of this subtree.
		$leaves = array();
		$this->_flatten_topology($node, $leaves);
		$minL = PHP_INT_MAX;
		$maxR = PHP_INT_MIN;
		foreach ($leaves as $ext) {
			$r = $by_ext[$ext];
			if ((int)$r['nleft']  < $minL) $minL = (int)$r['nleft'];
			if ((int)$r['nright'] > $maxR) $maxR = (int)$r['nright'];
		}
		$my_mrca = $this->mrca_by_bounds($minL, $maxR);

		// Each child subtree's MRCA must be a proper descendant of this MRCA
		// (or equal if the child is a leaf). And sibling subtrees' MRCAs
		// must not be nested in each other.
		$child_mrcas = array();
		foreach ($node as $child) {
			$child_leaves = array();
			$this->_flatten_topology($child, $child_leaves);
			if (count($child_leaves) === 1) {
				$child_mrcas[] = $by_ext[$child_leaves[0]];
				continue;
			}
			$cMinL = PHP_INT_MAX;
			$cMaxR = PHP_INT_MIN;
			foreach ($child_leaves as $ext) {
				$r = $by_ext[$ext];
				if ((int)$r['nleft']  < $cMinL) $cMinL = (int)$r['nleft'];
				if ((int)$r['nright'] > $cMaxR) $cMaxR = (int)$r['nright'];
			}
			$cm = $this->mrca_by_bounds($cMinL, $cMaxR);
			if (!$cm)
				return array('consistent' => false, 'reason' => 'child MRCA not found');

			// Child MRCA must be strictly inside the parent MRCA.
			if ((int)$cm['nleft'] <= (int)$my_mrca['nleft'] ||
				(int)$cm['nright'] >= (int)$my_mrca['nright']) {
				if ($cm['id'] !== $my_mrca['id']) {
					return array('consistent' => false,
						'reason' => 'child MRCA not nested in parent MRCA');
				}
			}

			$child_mrcas[] = $cm;

			// Recurse into the child subtree.
			$sub = $this->_check_topology_node($child, $by_ext);
			if (!$sub['consistent']) return $sub;
		}

		// Sibling MRCAs must not be nested within each other.
		for ($i = 0; $i < count($child_mrcas); $i++) {
			for ($j = $i + 1; $j < count($child_mrcas); $j++) {
				$a = $child_mrcas[$i];
				$b = $child_mrcas[$j];
				if ((int)$a['nleft'] <= (int)$b['nleft'] && (int)$a['nright'] >= (int)$b['nright'])
					return array('consistent' => false,
						'reason' => 'sibling clades are nested');
				if ((int)$b['nleft'] <= (int)$a['nleft'] && (int)$b['nright'] >= (int)$a['nright'])
					return array('consistent' => false,
						'reason' => 'sibling clades are nested');
			}
		}

		return array('consistent' => true, 'reason' => 'topology matches');
	}

	// Build the minimum spanning subtree of a set of external_ids (in
	// visit order). Returns
	//   ['nodes' => map keyed by external_id, 'edges' => list of {source, target},
	//    'focal_id' => latest visit, 'displayed_root_id' => root of the spanning tree]
	// The spanning tree contains every visited node plus the MRCAs of
	// consecutive pairs (sorted by nleft) — that's enough to close the set
	// under MRCA, since MRCA(x_i, x_j) for any pair is the lowest-depth
	// node among the consecutive MRCAs in [i..j-1].
	//
	// Each node carries `visited` (bool) and `visit_order` (int|null) so
	// the renderer can highlight the user's path.
	function spanning_subtree($ext_ids)
	{
		$visited_lookup = array();
		foreach ($ext_ids as $i => $ext) $visited_lookup[$ext] = $i;
		$visited_set = $visited_lookup;   // alias for set semantics

		$rows = $this->lookup_external(array_keys($visited_set));
		if (empty($rows)) {
			return array(
				'nodes' => new stdClass,
				'edges' => array(),
				'focal_id'          => null,
				'displayed_root_id' => null,
			);
		}

		// Sort by nleft (pre-order) so consecutive pairs span the tree
		// in document order.
		usort($rows, function ($x, $y) { return $x['nleft'] - $y['nleft']; });

		// Closure: add MRCAs of consecutive pairs.
		$by_internal = array();
		foreach ($rows as $r) $by_internal[$r['id']] = $r;

		for ($i = 0; $i + 1 < count($rows); $i++) {
			$a = $rows[$i];
			$b = $rows[$i + 1];
			$minL = min($a['nleft'],  $b['nleft']);
			$maxR = max($a['nright'], $b['nright']);
			$m = $this->mrca_by_bounds($minL, $maxR);
			if ($m && !isset($by_internal[$m['id']])) {
				$by_internal[$m['id']] = $m;
			}
		}

		// Reduced parents: for each node, its parent in the spanning tree
		// is the deepest other node whose interval contains it.
		$reduced_parent = array();
		foreach ($by_internal as $id => $r) {
			$best = null;
			foreach ($by_internal as $aid => $a) {
				if ($aid === $id) continue;
				if ($a['nleft'] <= $r['nleft'] && $a['nright'] >= $r['nright']) {
					if ($best === null || $a['depth'] > $best['depth']) $best = $a;
				}
			}
			$reduced_parent[$id] = $best ? $best['id'] : null;
		}

		// Build the response — keyed by external_id, with the same field
		// set as tree.php uses so coordinates.php can lay it out.
		$out_nodes = new stdClass;
		foreach ($by_internal as $id => $r) {
			$ext = $r['external_id'];
			$display = $this->ott->prettify_label($r['label']);
			$node = new stdClass;
			$node->id             = $ext;
			$node->display        = $display;
			$node->weight         = (int)$r['weight'];
			$node->depth          = (int)$r['depth'];
			$node->visited        = isset($visited_set[$ext]);
			$node->visit_order    = isset($visited_lookup[$ext]) ? $visited_lookup[$ext] : null;
			// Type / supertree_leaf are computed against the actual tree
			// (not the spanning subtree), so the renderer can decide on
			// solid/hollow/triangle the same way the main viewer does.
			$node->supertree_leaf = $this->is_supertree_leaf_internal($id);
			$node->type           = $node->supertree_leaf ? 'leaf' : 'internal';
			$out_nodes->$ext = $node;
		}

		$edges = array();
		foreach ($reduced_parent as $child_id => $parent_id) {
			if ($parent_id === null) continue;
			$child_ext  = $by_internal[$child_id]['external_id'];
			$parent_ext = $by_internal[$parent_id]['external_id'];
			$e = new stdClass;
			$e->source = $parent_ext;
			$e->target = $child_ext;
			$edges[] = $e;
		}

		// Find the displayed_root_id: the node with no reduced parent.
		$root_internal = null;
		foreach ($reduced_parent as $id => $p) {
			if ($p === null) { $root_internal = $id; break; }
		}
		$root_ext = $root_internal ? $by_internal[$root_internal]['external_id'] : null;

		// Focal = latest visit.
		$focal_ext = end($ext_ids) ?: null;

		return array(
			'nodes'             => $out_nodes,
			'edges'             => $edges,
			'focal_id'          => $focal_ext,
			'displayed_root_id' => $root_ext,
		);
	}

	// Cheap supertree-leaf check: a node is a supertree leaf iff it has
	// no children. Uses the index on tree.parent.
	function is_supertree_leaf_internal($internal_id)
	{
		$stmt = $this->db->prepare(
			'SELECT 1 FROM tree WHERE parent = :p AND id != :p LIMIT 1'
		);
		$stmt->execute(array(':p' => $internal_id));
		return $stmt->fetch() === false;
	}

	// Lay out a spanning_subtree result in a 0..100 grid (x by relative
	// depth; y by leaf order via DFS, with internals at the midpoint of
	// their children). Mutates $result['nodes'] in place to add x / y.
	// Robust for the trivial cases (1-node, single-chain) that
	// coordinates.php's divide-by-zero doesn't handle.
	static function layout_spanning_subtree(&$result)
	{
		$nodes = $result['nodes'];                  // stdClass map
		$ids   = array_keys((array)$nodes);
		if (empty($ids)) return;

		// Adjacency.
		$children   = array();
		$has_parent = array();
		foreach ($result['edges'] as $e) {
			if (!isset($children[$e->source])) $children[$e->source] = array();
			$children[$e->source][] = $e->target;
			$has_parent[$e->target] = true;
		}

		// Depth range → x.
		$minD = PHP_INT_MAX;
		$maxD = PHP_INT_MIN;
		foreach ($ids as $id) {
			$d = $nodes->$id->depth;
			if ($d < $minD) $minD = $d;
			if ($d > $maxD) $maxD = $d;
		}
		$dR = max(1, $maxD - $minD);
		foreach ($ids as $id) {
			$nodes->$id->x = round(($nodes->$id->depth - $minD) * 100.0 / $dR, 2);
		}

		// Roots of the spanning tree (nodes with no parent in $edges).
		$roots = array();
		foreach ($ids as $id) {
			if (!isset($has_parent[$id])) $roots[] = $id;
		}

		// Visual leaf order via DFS.
		$leaves_in_order = array();
		$dfs = function ($id) use (&$dfs, &$children, &$leaves_in_order) {
			if (empty($children[$id])) { $leaves_in_order[] = $id; return; }
			foreach ($children[$id] as $c) $dfs($c);
		};
		foreach ($roots as $r) $dfs($r);

		// Assign y to leaves.
		$n_leaves = count($leaves_in_order);
		if ($n_leaves === 1) {
			$nodes->{$leaves_in_order[0]}->y = 50;
		} else {
			$y_gap = 100.0 / ($n_leaves - 1);
			foreach ($leaves_in_order as $i => $id) {
				$nodes->$id->y = round($i * $y_gap, 2);
			}
		}

		// Internals: y = midpoint of children's y, post-order.
		$assign_y = function ($id) use (&$assign_y, &$children, &$nodes) {
			if (empty($children[$id])) return $nodes->$id->y;
			$ys = array();
			foreach ($children[$id] as $c) $ys[] = $assign_y($c);
			$nodes->$id->y = round((min($ys) + max($ys)) / 2, 2);
			return $nodes->$id->y;
		};
		foreach ($roots as $r) $assign_y($r);
	}
}
?>
