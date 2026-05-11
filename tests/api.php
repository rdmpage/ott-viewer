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
	array('name' => 'tree:newick-names',                  'fn' => 'case_tree_newick_names'),
	array('name' => 'tree:newick-bad-labels-rejected',    'fn' => 'case_tree_newick_bad_labels'),
	array('name' => 'subtree:small',                      'fn' => 'case_subtree_small'),
	array('name' => 'subtree:oversize-413',               'fn' => 'case_subtree_oversize'),
	array('name' => 'subtree:unknown-taxon-404',          'fn' => 'case_subtree_unknown'),
	array('name' => 'tree:bad-format',                    'fn' => 'case_tree_bad_format'),
	array('name' => 'tree:injection-rejected',            'fn' => 'case_tree_injection'),
	array('name' => 'nodes:root',                         'fn' => 'case_nodes_root'),
	array('name' => 'nodes:unknown-id-404',               'fn' => 'case_nodes_unknown'),
	array('name' => 'nodes:children-paginated',           'fn' => 'case_nodes_children'),
	array('name' => 'router:unknown-resource-404',        'fn' => 'case_router_unknown'),
	array('name' => 'hoptree:two-ids',                    'fn' => 'case_hoptree_two_ids'),
	array('name' => 'hoptree:empty-ids',                  'fn' => 'case_hoptree_empty'),
	array('name' => 'mrca:pair',                          'fn' => 'case_mrca_pair'),
	array('name' => 'mrca:single-id-rejected',            'fn' => 'case_mrca_single'),
	array('name' => 'path:two-nodes',                     'fn' => 'case_path_two_nodes'),
	array('name' => 'search:exact',                       'fn' => 'case_search_exact'),
	array('name' => 'search:prefix',                      'fn' => 'case_search_prefix'),
	array('name' => 'search:bad-mode',                    'fn' => 'case_search_bad_mode'),
	array('name' => 'nodes:descendants-tips-only',        'fn' => 'case_nodes_descendants'),
	array('name' => 'invariant:tree-nodes-resolve-on-/nodes', 'fn' => 'case_invariant_tree_nodes_resolve'),
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

function case_tree_newick_names($base)
{
	$url = "$base/tree?taxon=mrcaott103870ott121872&k=20&format=newick&labels=names";
	list($s, $ct, $body) = http_get($url);
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	if (stripos($ct, 'text/plain') === false) $err[] = "expected text/plain";
	$body = trim($body);
	if (substr($body, -1) !== ';') $err[] = "missing trailing ';'";
	if (substr_count($body, '(') !== substr_count($body, ')'))
	{
		$err[] = "unbalanced parens";
	}
	// Real names should appear unquoted; mrca synthetic labels suppressed
	// on internal nodes (so the substring should NOT appear adjacent to ')').
	if (strpos($body, 'Apomys') === false)            $err[] = "expected 'Apomys' in body";
	if (strpos($body, 'mrcaott103870ott121872') !== false)
	{
		$err[] = "synthetic root label should be omitted under labels=names";
	}
	// Default is no branch lengths — no `:1` should appear.
	if (strpos($body, ':1') !== false) $err[] = "default output should not include branch lengths";
	return $err;
}

function case_tree_newick_bad_labels($base)
{
	list($s, $_ct, $body) = http_get("$base/tree?taxon=ott93302&format=newick&labels=botanical");
	$err = array();
	if ($s !== 400) $err[] = "expected 400, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'bad_request')
	{
		$err[] = "expected error.code='bad_request'";
	}
	return $err;
}

function case_subtree_small($base)
{
	$url = "$base/subtree?taxon=mrcaott103870ott121872&labels=names";
	list($s, $ct, $body) = http_get($url);
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	if (stripos($ct, 'text/plain') === false) $err[] = "expected text/plain";
	$body = trim($body);
	if (substr($body, -1) !== ';') $err[] = "missing trailing ';'";
	if (substr_count($body, '(') !== substr_count($body, ')'))
	{
		$err[] = "unbalanced parens";
	}
	// No upstream stub: shouldn't see anything outside the Apomys+rats clade.
	if (strpos($body, 'Leporillus') !== false)
	{
		$err[] = "subtree must not include upstream stub (Leporillus is the focal's parent)";
	}
	// Full subtree should include species NOT in the summary-pruned /tree.
	if (strpos($body, 'Apomys zambalensis') === false)
	{
		$err[] = "expected 'Apomys zambalensis' (only present in the full subtree)";
	}
	if (strpos($body, ':1') !== false) $err[] = "default output must not include branch lengths";
	return $err;
}

function case_subtree_oversize($base)
{
	list($s, $_ct, $body) = http_get("$base/subtree?taxon=ott93302");
	$err = array();
	if ($s !== 413) $err[] = "expected 413, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'subtree_too_large')
	{
		$err[] = "expected error.code='subtree_too_large'";
	}
	if (!isset($d->error->details->node_count) || !is_int($d->error->details->node_count))
	{
		$err[] = "expected error.details.node_count to be an integer";
	}
	if (!isset($d->error->details->max_nodes))
	{
		$err[] = "expected error.details.max_nodes";
	}
	return $err;
}

function case_subtree_unknown($base)
{
	list($s, $_ct, $body) = http_get("$base/subtree?taxon=ottDOESNOTEXIST");
	$err = array();
	if ($s !== 404) $err[] = "expected 404, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'node_not_found')
	{
		$err[] = "expected error.code='node_not_found'";
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

function case_hoptree_two_ids($base)
{
	list($s, $_ct, $body) = http_get("$base/hoptree?ids=ott93302,ott304358");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	if (!isset($d->nodes->ott93302))   $err[] = "missing visited node ott93302";
	if (!isset($d->nodes->ott304358))  $err[] = "missing visited node ott304358";
	if (isset($d->nodes->ott93302) && empty($d->nodes->ott93302->visited))
	{
		$err[] = "ott93302 should have visited=true";
	}
	if (isset($d->focal_id) && $d->focal_id !== 'ott304358')
	{
		$err[] = "expected focal_id=ott304358 (last visited), got '" . ($d->focal_id ?? '<missing>') . "'";
	}
	return $err;
}

function case_hoptree_empty($base)
{
	list($s, $_ct, $body) = http_get("$base/hoptree?ids=");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if (!isset($d->edges) || !is_array($d->edges) || count($d->edges) !== 0)
	{
		$err[] = "expected empty edges[] for ids=''";
	}
	return $err;
}

function case_mrca_pair($base)
{
	list($s, $_ct, $body) = http_get("$base/mrca?ids=ott917716,ott342539");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	if (!isset($d->mrca->id))                  $err[] = "missing mrca.id";
	if (!isset($d->inputs) || count($d->inputs) !== 2) $err[] = "inputs should have 2 entries";
	if (isset($d->mrca->id) && strpos($d->mrca->id, 'mrca') !== 0
		&& strpos($d->mrca->id, 'ott') !== 0)
	{
		$err[] = "mrca.id should be an ott or mrca id, got '$d->mrca->id'";
	}
	return $err;
}

function case_mrca_single($base)
{
	list($s, $_ct, $body) = http_get("$base/mrca?ids=ott93302");
	$err = array();
	if ($s !== 400) $err[] = "expected 400 for single id, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'bad_request')
	{
		$err[] = "expected error.code='bad_request'";
	}
	return $err;
}

function case_path_two_nodes($base)
{
	list($s, $_ct, $body) = http_get("$base/path?from=ott917716&to=ott342539");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('from', 'to', 'lca', 'length', 'via') as $f)
	{
		if (!isset($d->$f)) $err[] = "missing field '$f'";
	}
	if (isset($d->via))
	{
		if (count($d->via) === 0)        $err[] = "via must be non-empty";
		if ($d->via[0] !== 'ott917716')  $err[] = "via must start with from id";
		$last = $d->via[count($d->via) - 1];
		if ($last !== 'ott342539')       $err[] = "via must end with to id";
	}
	if (isset($d->length, $d->via) && $d->length !== count($d->via) - 1)
	{
		$err[] = "length ($d->length) doesn't match via length - 1 (" . (count($d->via) - 1) . ")";
	}
	return $err;
}

function case_search_exact($base)
{
	list($s, $_ct, $body) = http_get("$base/search?q=Eukaryota");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('query', 'mode', 'results') as $f)
	{
		if (!property_exists($d, $f)) $err[] = "missing field '$f'";
	}
	if (isset($d->results) && count($d->results) === 0)
	{
		$err[] = "expected at least one result for 'Eukaryota'";
	}
	if (isset($d->results) && count($d->results) > 0)
	{
		$r0 = $d->results[0];
		if (!isset($r0->id) || !isset($r0->display))
		{
			$err[] = "result missing id/display";
		}
	}
	return $err;
}

function case_search_prefix($base)
{
	list($s, $_ct, $body) = http_get("$base/search?q=Goniurosaurus&mode=prefix&limit=5");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	if (isset($d->results) && count($d->results) === 0)
	{
		$err[] = "expected results for prefix 'Goniurosaurus'";
	}
	if (isset($d->results) && count($d->results) > 5)
	{
		$err[] = "limit=5 not enforced (got " . count($d->results) . ")";
	}
	return $err;
}

function case_search_bad_mode($base)
{
	list($s, $_ct, $body) = http_get("$base/search?q=Anything&mode=fuzzy");
	$err = array();
	if ($s !== 400) $err[] = "expected 400 for unknown mode, got $s";
	$d = decode_json($body);
	if (!isset($d->error->code) || $d->error->code !== 'bad_request')
	{
		$err[] = "expected error.code='bad_request'";
	}
	return $err;
}

function case_nodes_descendants($base)
{
	list($s, $_ct, $body) = http_get("$base/nodes/ott917716/descendants?tips_only=true&limit=5");
	$err = array();
	if ($s !== 200) $err[] = "expected 200, got $s";
	$d = decode_json($body);
	if ($d === null) { $err[] = 'response not valid JSON'; return $err; }
	foreach (array('id','total','offset','limit','tips_only','descendants') as $f)
	{
		if (!property_exists($d, $f)) $err[] = "missing field '$f'";
	}
	if (isset($d->descendants) && count($d->descendants) > 5)
	{
		$err[] = "limit=5 not enforced";
	}
	if (isset($d->tips_only) && $d->tips_only !== true)
	{
		$err[] = "tips_only echo should be true";
	}
	return $err;
}

// Cross-endpoint invariant: every node id /tree returns must resolve on
// /nodes/{id} (filtering synthetic other_ / stub ids that are
// computed-only and don't have their own /nodes/{id} entry).
function case_invariant_tree_nodes_resolve($base)
{
	list($_s, $_ct, $body) = http_get("$base/tree?taxon=mrcaott78156ott91459&k=8");
	$d = decode_json($body);
	if ($d === null || !isset($d->nodes)) return array('tree response unusable');
	$err = array();
	$checked = 0;
	foreach ($d->nodes as $id => $n)
	{
		if (strpos($id, 'other_') === 0)            continue;   // synthetic
		if (isset($n->type) && $n->type === 'stub') continue;   // upstream marker
		if ($checked >= 4) break;                                // sample, don't hammer
		list($status) = http_get("$base/nodes/$id");
		if ($status !== 200) $err[] = "/nodes/$id from /tree did not resolve (HTTP $status)";
		$checked++;
	}
	if ($checked === 0) $err[] = "no nodes were sampled — tree response was empty?";
	return $err;
}
