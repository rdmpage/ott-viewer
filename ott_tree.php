<?php

require_once (dirname(__FILE__) . '/database-tree.php');
require_once (dirname(__FILE__) . '/summary.php');

//----------------------------------------------------------------------------------------
// OTT taxonomy, based on export of iBOL BINs from GBIF
class OttTree extends DbTree
{

	//------------------------------------------------------------------------------------
	function get_children($id)
	{
		$children = array();
	
		$sql = 'SELECT * FROM tree
		INNER JOIN taxa USING(id)
		WHERE parent="' . $id . '"';
		
		$data = $this->do_query($sql);
		
		foreach ($data as $row)
		{
			$node = new stdclass;
			$node->id = $row->id;
			
			if (isset($row->parent))
			{
				$node->parentTaxon = $row->parent;
			}

			if (isset($row->label))
			{
				$node->name = $row->label;
			}

			if (isset($row->weight))
			{
				$node->weight = (int)$row->weight;
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
			if (isset($row->parent))
			{
				$node->parentTaxon = $row->parent;
			}

			if (isset($row->label))
			{
				$node->name = $row->label;
			}

			if (isset($row->weight))
			{
				$node->weight = (int)$row->weight;
			}

		}

		return $node;
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
