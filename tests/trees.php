<?php
// tests/trees.php — Layer 1 schema/invariant tests for tree.php
//
// Runs `tree.php` for a battery of focal taxa and asserts that the JSON
// response is well-formed, has the expected schema, and satisfies a few
// basic graph invariants. Cheap, deterministic, and catches the bugs
// that have actually hurt us so far (root self-loop, missing fields,
// dangling edge endpoints, etc.).
//
// Usage:  php tests/trees.php          (exits non-zero on any failure)
//         php tests/trees.php --quiet  (only print failures)

$cases = array(
	array('taxon' => 'ott452461',            'k' => 30, 'note' => 'Procellariiformes — mid-tree clade'),
	array('taxon' => 'ott49103',             'k' => 30, 'note' => 'Oceanites gracilis — small clade that climbs'),
	array('taxon' => 'ott93302',             'k' => 30, 'note' => 'cellular organisms — OTT root (self-loop in DB)'),
	array('taxon' => 'mrcaott18206ott18209', 'k' => 30, 'note' => 'mrca-named internal'),
	array('taxon' => 'ott853108',            'k' => 30, 'note' => 'big "other" sibling groups'),
	array('taxon' => 'ott838239',            'k' => 30, 'note' => 'Pterodroma — default focal'),
	array('taxon' => 'definitely_not_a_taxon','k' => 30, 'note' => 'unknown id — must fall back gracefully'),
);

$quiet = in_array('--quiet', $argv ?? array(), true);

$failures = 0;
foreach ($cases as $c)
{
	$errors = run_case($c);
	if (empty($errors))
	{
		if (!$quiet) printf("PASS  %-26s  %s\n", $c['taxon'], $c['note']);
	}
	else
	{
		printf("FAIL  %-26s  %s\n", $c['taxon'], $c['note']);
		foreach ($errors as $e) echo "        - $e\n";
		$failures++;
	}
}

echo "\n";
echo $failures === 0
	? sprintf("All %d cases passed.\n", count($cases))
	: sprintf("%d / %d cases failed.\n", $failures, count($cases));

exit($failures === 0 ? 0 : 1);


// ─── Test runner ────────────────────────────────────────────────────────────

function run_case($c)
{
	$tree_php = realpath(__DIR__ . '/../tree.php');
	if ($tree_php === false) return array('cannot find tree.php');

	$cmd = sprintf('php %s %s %d 2>&1',
		escapeshellarg($tree_php),
		escapeshellarg($c['taxon']),
		(int)$c['k']);

	$output    = array();
	$exit_code = 0;
	exec($cmd, $output, $exit_code);
	$body = implode("\n", $output);

	if ($exit_code !== 0)
	{
		return array(sprintf('tree.php exited with code %d; first line: %s',
			$exit_code, substr($output[0] ?? '', 0, 200)));
	}

	$data = json_decode($body);
	if ($data === null)
	{
		return array(
			'response is not valid JSON',
			'first 200 chars: ' . substr($body, 0, 200),
		);
	}

	return validate_tree($data);
}


// ─── Schema / invariant checks ──────────────────────────────────────────────

function validate_tree($data)
{
	$errors = array();

	// Top-level required fields.
	foreach (array('focal_id', 'displayed_root_id', 'nodes', 'edges') as $field)
	{
		if (!isset($data->$field)) $errors[] = "missing top-level '$field'";
	}
	if (!empty($errors)) return $errors;

	if (!is_object($data->nodes)) $errors[] = "'nodes' must be an object/map";
	if (!is_array($data->edges))  $errors[] = "'edges' must be an array";
	if (!empty($errors)) return $errors;

	// Focal + root present in nodes.
	if (!isset($data->nodes->{$data->focal_id}))
	{
		$errors[] = "focal_id '{$data->focal_id}' is not a node key";
	}
	if (!isset($data->nodes->{$data->displayed_root_id}))
	{
		$errors[] = "displayed_root_id '{$data->displayed_root_id}' is not a node key";
	}

	// Node-shape checks.
	$valid_types = array('internal', 'leaf', 'other', 'stub');
	$node_count_by_type = array_fill_keys($valid_types, 0);

	foreach ($data->nodes as $key => $node)
	{
		$where = "node '$key'";

		if (!isset($node->id))      $errors[] = "$where: missing id";
		elseif ($node->id !== $key) $errors[] = "$where: node.id ('{$node->id}') doesn't match map key";

		if (!isset($node->display)) $errors[] = "$where: missing display";

		if (!isset($node->type))                                $errors[] = "$where: missing type";
		elseif (!in_array($node->type, $valid_types, true))     $errors[] = "$where: unexpected type '{$node->type}'";
		else                                                     $node_count_by_type[$node->type]++;

		if (!isset($node->x) || !is_numeric($node->x)) $errors[] = "$where: missing/non-numeric x";
		if (!isset($node->y) || !is_numeric($node->y)) $errors[] = "$where: missing/non-numeric y";

		// 'other' nodes must carry their members.
		if (isset($node->type) && $node->type === 'other')
		{
			if (!isset($node->members) || !is_array($node->members))
			{
				$errors[] = "$where: 'other' node missing members[]";
			}
			else
			{
				foreach ($node->members as $i => $m)
				{
					$mw = "$where members[$i]";
					if (!isset($m->id))      $errors[] = "$mw: missing id";
					if (!isset($m->display)) $errors[] = "$mw: missing display";
					if (!isset($m->type))    $errors[] = "$mw: missing type";
					if (!isset($m->supertree_leaf)) $errors[] = "$mw: missing supertree_leaf";
				}
			}
		}
	}

	// Edge invariants.
	foreach ($data->edges as $i => $e)
	{
		$where = "edge[$i]";
		if (!isset($e->source)) { $errors[] = "$where: missing source"; continue; }
		if (!isset($e->target)) { $errors[] = "$where: missing target"; continue; }
		if ($e->source === $e->target)
		{
			$errors[] = "$where: self-loop on '{$e->source}'";
		}
		if (!isset($data->nodes->{$e->source}))
		{
			$errors[] = "$where: source '{$e->source}' not in nodes";
		}
		if (!isset($data->nodes->{$e->target}))
		{
			$errors[] = "$where: target '{$e->target}' not in nodes";
		}
	}

	// Connectivity: every non-root node should be a target of some edge.
	$has_incoming = array();
	foreach ($data->edges as $e)
	{
		if (isset($e->target)) $has_incoming[$e->target] = true;
	}
	foreach ($data->nodes as $key => $node)
	{
		if ($key === $data->displayed_root_id) continue;        // displayed root may have a stub above; either way fine
		if (isset($node->type) && $node->type === 'stub') continue; // stub has no parent in the JSON
		if (!isset($has_incoming[$key]))
		{
			$errors[] = "node '$key' has no incoming edge (orphan)";
		}
	}

	return $errors;
}
