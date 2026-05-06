<?php

// Newick emitter for the canonical viewer tree object. Branch lengths are
// `1` for every edge (placeholder; the displayed tree is a cladogram). A
// future `branch_lengths=weight|tip_count|none` API param can drive that.
//
// Internal labels are the node's id, so a Newick consumer that round-trips
// through this can still resolve back to the API. `other_*` placeholders
// are emitted as labelled tips so topology is preserved — non-standard
// but explicit; consumers expecting strict species-only Newick can filter.

function tree_to_newick($payload)
{
	if (!isset($payload->displayed_root_id) || !isset($payload->nodes) || !isset($payload->edges))
	{
		return ';';
	}

	// Adjacency: parent id -> [child ids].
	$children = array();
	foreach ($payload->edges as $e)
	{
		if (!isset($children[$e->source])) $children[$e->source] = array();
		$children[$e->source][] = $e->target;
	}

	// If the displayed root has a stub parent, emit from the stub instead so
	// the upstream context is preserved (matches what the viewer renders).
	$root = $payload->displayed_root_id;
	foreach ($payload->edges as $e)
	{
		if ($e->target === $root
			&& isset($payload->nodes->{$e->source})
			&& isset($payload->nodes->{$e->source}->type)
			&& $payload->nodes->{$e->source}->type === 'stub')
		{
			$root = $e->source;
			break;
		}
	}

	return _newick_emit($root, $children) . ';';
}

function _newick_emit($id, &$children)
{
	$kids = isset($children[$id]) ? $children[$id] : array();
	if (count($kids) === 0)
	{
		return $id . ':1';
	}
	$parts = array();
	foreach ($kids as $kid)
	{
		$parts[] = _newick_emit($kid, $children);
	}
	return '(' . implode(',', $parts) . ')' . $id . ':1';
}
