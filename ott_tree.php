<?php

require_once (dirname(__FILE__) . '/database-tree.php');
require_once (dirname(__FILE__) . '/summary.php');

//----------------------------------------------------------------------------------------
// OTT taxonomy, based on export of iBOL BINs from GBIF
class OttTree extends DbTree
{

	// Cache: external_id => label, populated by get_label_by_external so that
	// prettifying many mrca labels in one request doesn't re-hit the DB for
	// the same span tips repeatedly.
	var $label_cache = array();

	//------------------------------------------------------------------------------------
	function get_children($id)
	{
		$children = array();

		// id != parent excludes the OTT root's self-row (it stores
		// parent = id rather than NULL), which would otherwise show up as
		// its own child and put summarise() into an infinite expansion loop.
		$sql = 'SELECT * FROM tree
		INNER JOIN taxa USING(id)
		WHERE parent="' . $id . '" AND id != parent';

		$data = $this->do_query($sql);

		foreach ($data as $row)
		{
			$node = new stdclass;
			$node->id = $row->id;

			// OTT's exported root row points at itself (parent = id) rather
			// than NULL; treat that as "no parent" so traversals don't loop.
			if (isset($row->parent) && $row->parent !== $row->id)
			{
				$node->parentTaxon = $row->parent;
			}

			if (isset($row->label))
			{
				$node->label = $row->label;
				$node->name  = $this->prettify_label($row->label);
			}

			if (isset($row->weight))
			{
				$node->weight = (int)$row->weight;
			}

			if (isset($row->nleft))
			{
				$node->nleft = (int)$row->nleft;
			}

			if (isset($row->external_id))
			{
				$node->external_id = $row->external_id;
			}

			$children[$node->id] = $node;
		}

		// return array not map (do this to make it easy to treat JSON as JSON-LD...?)
		return array_values($children);
	}


	//------------------------------------------------------------------------------------
	function get_node($id)
	{
		$node = new stdclass;
		$node->id = $id;
		$node->name = '';

		$sql = 'SELECT * FROM tree
		INNER JOIN taxa USING(id)
		WHERE id="' . $id . '"';


		$data = $this->do_query($sql);
		foreach ($data as $row)
		{
			// OTT's exported root row points at itself (parent = id) rather
			// than NULL; treat that as "no parent" so traversals don't loop.
			if (isset($row->parent) && $row->parent !== $row->id)
			{
				$node->parentTaxon = $row->parent;
			}

			if (isset($row->label))
			{
				$node->label = $row->label;
				$node->name  = $this->prettify_label($row->label);
			}

			if (isset($row->weight))
			{
				$node->weight = (int)$row->weight;
			}

			if (isset($row->nleft))
			{
				$node->nleft = (int)$row->nleft;
			}

			if (isset($row->external_id))
			{
				$node->external_id = $row->external_id;
			}
		}

		return $node;
	}

	//------------------------------------------------------------------------------------
	// Turn an OTT label into a display-friendly name. For mrca labels of the
	// form "mrcaottXottY" (the OTT convention for an unnamed internal node
	// bracketed by two tip external_ids) returns "<name_X> + <name_Y>". All
	// other labels pass through unchanged.
	function prettify_label($label)
	{
		if (preg_match('/^mrca(ott\d+)(ott\d+)$/', $label, $m))
		{
			$a = $this->get_label_by_external($m[1]);
			$b = $this->get_label_by_external($m[2]);
			return $a . ' + ' . $b;
		}
		return $label;
	}

	//------------------------------------------------------------------------------------
	// Look up the display label for a taxon by its external_id, with an
	// in-memory cache. Falls back to the external_id itself if not found.
	function get_label_by_external($external_id)
	{
		if (isset($this->label_cache[$external_id])) return $this->label_cache[$external_id];
		if (!preg_match('/^[A-Za-z0-9_]+$/', $external_id))
		{
			return $this->label_cache[$external_id] = $external_id;
		}
		$sql = 'SELECT label FROM taxa WHERE external_id="' . $external_id . '" LIMIT 1';
		$data = $this->do_query($sql);
		if (count($data) > 0 && isset($data[0]->label))
		{
			return $this->label_cache[$external_id] = $data[0]->label;
		}
		return $this->label_cache[$external_id] = $external_id;
	}

	//------------------------------------------------------------------------------------
	// Translate an OTT external_id (e.g. "ott452461") to the internal tree id.
	// Returns null if no taxon with that external_id exists or the input is malformed.
	function get_id_by_external($external_id)
	{
		if (!preg_match('/^[A-Za-z0-9_]+$/', $external_id)) return null;
		$sql = 'SELECT id FROM taxa WHERE external_id="' . $external_id . '" LIMIT 1';
		$data = $this->do_query($sql);
		if (count($data) > 0) return $data[0]->id;
		return null;
	}

	//------------------------------------------------------------------------------------
	function get_node_score($id)
	{
		$score = 0;
		
		$sql = 'SELECT * FROM tree WHERE id="' . $id . '" LIMIT 1';
		
		$data = $this->do_query($sql);
		
		if (count($data) == 1)
		{
			$score = $data[0]->score;
		}
		
		return $score;
	}
	
}

?>
