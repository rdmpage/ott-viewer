<?php
// tests/api.php — Layer-1 schema + behavioural tests for /api/v1/*.
//
// Hits the live API over HTTP (so routing, .htaccess, headers, CORS,
// and error envelope all participate) and asserts each response is
// well-formed against the contract in api-design.md. Style mirrors
// tests/trees.php: no framework, plain printf, exit non-zero on failure.
//
// Usage:  php tests/api.php          (verbose; one line per case)
//         php tests/api.php --quiet  (only print failures)
//
// Override the base URL with API_BASE if Apache isn't at the default.
//   API_BASE=http://localhost/ott-viewer/api/v1php tests/api.php

$BASE = getenv('API_BASE');
if (!$BASE) $BASE = 'http://localhost/ott-viewer/api/v1';

$quiet = in_array('--quiet', $argv ?? array(), true);

$cases = array(
	array('name' => 'about',                              'fn' => 'case_about'),
	array('name' => 'tree:default',                       'fn' => 'case_tree_default'),
	array('name' => 'tree:focal=mrca',                    'fn' => 'case_tree_mrca'),
	array('name' => 'tree:newick',                        'fn' => 'case_tree_newick'),
	array('name' => 'tree:bad-format',                    'fn' => 'case_tree_bad_format'),
	array('name' => 'tree:injection-rejected',            'fn' => 'case_tree_injection'),
	array('name' => 'nodes:root',                         'fn' => 'case_nodes_root'),
	array('name' => 'nodes:unknown-id-404',               'fn' => 'case_nodes_unknown'),
	array('name' => 'nodes:children-paginated',           'fn' => 'case_nodes_children'),
	array('name' => 'router:unknown-resource-404',        'fn' => 'case_router_unknown'),
);

$failures = 0;
foreach ($cases as $c)
{
	$errors = $c['fn']($BASE);
	if (empty($errors))
	{
		if (!$quiet) printf("PASS  %s\n", $c['name']);
	}
	else
	{
		printf("FAIL  %s\n", $c['name']);
		foreach ($errors as $e) echo "        - $e\n";
		$failures++;
	}
}

echo "\n";
echo $failures === 0
	? sprintf("All %d cases passed.\n", count($cases))
	: sprintf("%d / %d cases failed.\n", $failures, count($cases));

exit($failures === 0 ? 0 : 1);


// ─── HTTP helper ────────────────────────────────────────────────────────────

// Returns [http_status, content_type, body]. Errors set status=0 with body
// containing the failure message.
function http_get($url)
{
	$ctx = stream_context_create(array('http' => array(
		'method'        => 'GET',
		'ignore_errors' => true,        // surface 4xx/5xx bodies, not warnings
		'timeout'       => 5,
	)));
	$body = @file_get_contents($url, false, $ctx);
	if ($body === false)
	{
		return array(0, '', 'fetch failed: ' . error_get_last()['message']);
	}
	$status = 0;
	$ctype = '';
	foreach ($http_response_header ?? array() as $h)
	{
		if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
		if (stripos($h, 'content-type:') === 0) $ctype = trim(substr($h, strlen('content-type:')));
	}
	return array($status, $ctype, $body);
}

function decode_json($body)
{
	$d = json_decode($body);
	if ($d === null && trim($body) !== 'null')
	{
		return null;
	}
	return $d;
}


// ─── Cases ──────────────────────────────────────────────────────────────────

function case_about($base)
{
	list($s, $ct, $body) = http_get("$base/about");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	if (stripos($ct, 'application/json') === false) $err[] = "expected json content-type, got '$ct'";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('api_version', 'node_count', 'tree_formats', 'generated_at') as $f)
	{
		if (!isset($d->$f)) $err[] = "missing field '$f'";
	}
	if (isset($d->api_version) && $d->api_version !== 'v1') $err[] = "api_version != 'v1'";
	if (isset($d->node_count) && (!is_int($d->node_count) || $d->node_count <= 0))
	{
		$err[] = "node_count must be a positive integer";
	}
	return $err;
}

function case_tree_default($base)
{
	list($s, $ct, $body) = http_get("$base/tree");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	if (stripos($ct, 'application/json') === false) $err[] = "expected json content-type";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('focal_id', 'displayed_root_id', 'nodes', 'edges') as $f)
	{
		if (!isset($d->$f)) $err[] = "missing top-level '$f'";
	}
	if (!empty($err)) return $err;
	if (!is_object($d->nodes)) $err[] = "nodes must be a JSON object";
	if (!is_array($d->edges))  $err[] = "edges must be a JSON array";
	if (!isset($d->nodes->{$d->focal_id}))
	{
		$err[] = "focal_id '{$d->focal_id}' is not a node key";
	}
	// Every edge endpoint should resolve to a node in the map.
	foreach ($d->edges as $i => $e)
	{
		if (!isset($d->nodes->{$e->source})) $err[] = "edge[$i] source '{$e->source}' missing from nodes";
		if (!isset($d->nodes->{$e->target})) $err[] = "edge[$i] target '{$e->target}' missing from nodes";
		if ($e->source === $e->target)       $err[] = "edge[$i] is a self-loop on '{$e->source}'";
	}
	// Per-node required fields.
	foreach ($d->nodes as $key => $n)
	{
		foreach (array('id','display','type','depth','tip_count','x','y') as $f)
		{
			if (!isset($n->$f) && $n->$f !== null) $err[] = "node $key missing '$f'";
		}
		if (isset($n->id) && $n->id !== $key) $err[] = "node $key: id mismatch ('$n->id')";
	}
	return $err;
}

function case_tree_mrca($base)
{
	$mrca = 'mrcaott78156ott91459';
	list($s, $_ct, $body) = http_get("$base/tree?taxon=$mrca&k=10");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	if (!isset($d->focal_id) || $d->focal_id !== $mrca)
	{
		$err[] = "expected focal_id=$mrca, got '" . ($d->focal_id ?? '<missing>') . "'";
	}
	return $err;
}

function case_tree_newick($base)
{
	list($s, $ct, $body) = http_get("$base/tree?taxon=ott93302&k=8&format=newick");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	if (stripos($ct, 'text/plain') === false) $err[] = "expected text/plain content-type, got '$ct'";
	$body = trim($body);
	if ($body === '' || substr($body, -1) !== ';')
	{
		$err[] = "response is not Newick (must end with ';')";
	}
	if (substr_count($body, '(') !== substr_count($body, ')'))
	{
		$err[] = "unbalanced parens in Newick output";
	}
	return $err;
}

function case_tree_bad_format($base)
{
	list($s, $_ct, $body) = http_get("$base/tree?taxon=ott93302&format=xml");
	$err = array();
	if ($s !== 400) $err[] = "expected 400, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'unsupported_format')
	{
		$err[] = "expected error.code='unsupported_format'";
	}
	return $err;
}

function case_tree_injection($base)
{
	$bad = 'ott93302%3BDROP';      // ott93302;DROP — semicolon disallowed
	list($s, $_ct, $body) = http_get("$base/tree?taxon=$bad");
	$err = array();
	if ($s !== 400) $err[] = "expected 400, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'bad_request')
	{
		$err[] = "expected error.code='bad_request'";
	}
	return $err;
}

function case_nodes_root($base)
{
	list($s, $_ct, $body) = http_get("$base/nodes/ott93302");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('id','display','type','child_count','children_sample','parents','annotations','external_links') as $f)
	{
		if (!property_exists($d, $f)) $err[] = "missing field '$f'";
	}
	if (isset($d->id) && $d->id !== 'ott93302') $err[] = "id mismatch: expected ott93302";
	if (isset($d->parents) && count($d->parents) !== 0)
	{
		$err[] = "OTT root should have no parents, got " . count($d->parents);
	}
	if (isset($d->child_count) && isset($d->children_sample))
	{
		if ($d->child_count <= 50 && count($d->children_sample) !== $d->child_count)
		{
			$err[] = "child_count ($d->child_count) doesn't match sample length (" .
				count($d->children_sample) . ") for small fanout";
		}
	}
	return $err;
}

function case_nodes_unknown($base)
{
	list($s, $_ct, $body) = http_get("$base/nodes/ottDOESNOTEXIST");
	$err = array();
	if ($s !== 404) $err[] = "expected 404, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'node_not_found')
	{
		$err[] = "expected error.code='node_not_found'";
	}
	return $err;
}

function case_nodes_children($base)
{
	list($s, $_ct, $body) = http_get("$base/nodes/ott93302/children?limit=2");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('id','total','offset','limit','children') as $f)
	{
		if (!property_exists($d, $f)) $err[] = "missing field '$f'";
	}
	if (isset($d->limit) && $d->limit !== 2) $err[] = "limit echo mismatch (expected 2, got $d->limit)";
	if (isset($d->children) && count($d->children) > 2)
	{
		$err[] = "returned " . count($d->children) . " children but limit was 2";
	}
	return $err;
}

function case_router_unknown($base)
{
	list($s, $_ct, $body) = http_get("$base/banana");
	$err = array();
	if ($s !== 404) $err[] = "expected 404, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'not_found')
	{
		$err[] = "expected error.code='not_found'";
	}
	return $err;
}
