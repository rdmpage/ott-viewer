<?php

// Newick emitter for the canonical viewer tree object.
//
// Options (passed via $opts):
//   labels         'ids'  → emit each node's id as the Newick label.
//                  'names'→ emit display names where they're "proper"
//                           taxon labels (Apomys, Chrotomys, …) and
//                           OMIT the label on internal nodes whose
//                           display is synthetic (mrca "X + Y" form,
//                           other_* placeholders). Tips always get a
//                           label so they can be re-identified.
//   branch_lengths 'none' (default) — drop branch lengths entirely.
//                  'ones' — emit `:1` for every edge (cladogram
//                           placeholder). Reserved for callers that
//                           need a tree the recipient parses as
//                           weighted; the value is meaningless.
//
// Labels containing reserved Newick chars (whitespace, `()[]:;,'`) are
// single-quoted; embedded single-quotes are doubled per spec.

function tree_to_newick($payload, $opts = array())
{
	$opts = array_merge(array(
		'labels'         => 'ids',
		'branch_lengths' => 'none',
	), $opts);

	if (!isset($payload->displayed_root_id) || !isset($payload->nodes) || !isset($payload->edges))
	{
		return ';';
	}

	$children = array();
	foreach ($payload->edges as $e)
	{
		if (!isset($children[$e->source])) $children[$e->source] = array();
		$children[$e->source][] = $e->target;
	}

	// If the displayed root has a stub parent, emit from the stub so the
	// upstream context is preserved (matches what the viewer renders).
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

	return _newick_emit($root, $children, $payload->nodes, $opts) . ';';
}

function _newick_emit($id, &$children, $nodes, $opts)
{
	$kids   = isset($children[$id]) ? $children[$id] : array();
	$is_tip = count($kids) === 0;

	$label = _newick_label_for($id, $nodes, $is_tip, $opts);
	$bl    = $opts['branch_lengths'] === 'ones' ? ':1' : '';

	if ($is_tip)
	{
		return $label . $bl;
	}
	$parts = array();
	foreach ($kids as $kid)
	{
		$parts[] = _newick_emit($kid, $children, $nodes, $opts);
	}
	return '(' . implode(',', $parts) . ')' . $label . $bl;
}

function _newick_label_for($id, $nodes, $is_tip, $opts)
{
	if ($opts['labels'] === 'ids')
	{
		return _newick_quote_if_needed($id);
	}

	$node    = isset($nodes->$id) ? $nodes->$id : null;
	$display = ($node && isset($node->display)) ? (string)$node->display : '';

	// "Synthetic" = mrca "X + Y" auto-display, or an other_ placeholder.
	// Anything else with a non-empty display is a real taxon name.
	$is_synthetic =
		   (strpos($id, 'mrca')   === 0 && strpos($display, ' + ') !== false)
		|| (strpos($id, 'other_') === 0);

	if ($is_tip)
	{
		// Tips need to be identifiable — emit display if we have one,
		// fall back to id (covers the rare display-less node).
		$label = $display !== '' ? $display : $id;
		return _newick_quote_if_needed($label);
	}

	// Internal nodes: emit a name only when one is meaningful.
	if ($is_synthetic || $display === '') return '';
	return _newick_quote_if_needed($display);
}

function _newick_quote_if_needed($label)
{
	if ($label === '') return '';
	// Newick reserved characters (whitespace counted): need quoting if
	// any appear, otherwise standard parsers will choke or split.
	if (preg_match('/[\s\(\)\[\],;:\']/', $label))
	{
		return "'" . str_replace("'", "''", $label) . "'";
	}
	return $label;
}
