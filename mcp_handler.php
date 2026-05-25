<?php
// mcp_handler.php
// Shared MCP request handler for both stdio and HTTP transports.
// Requires a TreeQueries instance and PDO connection passed via globals.

require_once dirname(__FILE__) . '/tree_queries.php';

function mcp_get_db()
{
	static $db = null;
	if (!$db) {
		$db = new PDO('sqlite:' . dirname(__FILE__) . '/ott.db');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	return $db;
}

function mcp_get_queries()
{
	static $q = null;
	if (!$q) $q = new TreeQueries(mcp_get_db());
	return $q;
}

// ── Request dispatcher ──────────────────────────────────────────────────

function handleMcpRequest(array $request)
{
	$id     = isset($request['id']) ? $request['id'] : null;
	$method = isset($request['method']) ? $request['method'] : null;
	$params = isset($request['params']) ? $request['params'] : array();

	$response = array('jsonrpc' => '2.0', 'id' => $id);

	switch ($method)
	{
		case 'initialize':
			$clientProtocol = isset($params['protocolVersion'])
				? $params['protocolVersion'] : '2025-06-18';
			$response['result'] = array(
				'protocolVersion' => $clientProtocol,
				'serverInfo' => array(
					'name'    => 'ott-viewer-mcp',
					'version' => '0.1.0',
				),
				'capabilities' => array(
					'tools' => array('list' => true, 'call' => true),
					'resources' => array('list' => false, 'read' => false, 'subscribe' => false),
				),
			);
			break;

		case 'notifications/initialized':
			return null;

		case 'tools/list':
			$response['result'] = array('tools' => getToolDefinitions());
			break;

		case 'tools/call':
			$toolName = isset($params['name']) ? $params['name'] : null;
			$toolArgs = isset($params['arguments']) ? $params['arguments'] : array();
			$result = callTool($toolName, $toolArgs);
			if ($result === null) {
				$response['error'] = array(
					'code'    => -32601,
					'message' => 'Unknown tool: ' . $toolName,
				);
			} else {
				$response['result'] = array(
					'content' => array(
						array('type' => 'text', 'text' => $result),
					),
				);
			}
			break;

		case 'ping':
			$response['result'] = array('ok' => true);
			break;

		default:
			if ($id !== null) {
				$response['error'] = array(
					'code'    => -32601,
					'message' => 'Method not found: ' . $method,
				);
			} else {
				return null;
			}
			break;
	}

	return $response;
}

// ── Tool definitions ────────────────────────────────────────────────────

function getToolDefinitions()
{
	return array(
		array(
			'name'        => 'resolve_name',
			'description' => 'Resolve one or more taxon names to Open Tree of Life identifiers (OTT IDs). Accepts scientific names (e.g. "Elephas maximus") or OTT IDs (e.g. "ott541928").',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'names' => array(
						'type'        => 'string',
						'description' => 'Comma-separated taxon names or OTT IDs.',
					),
				),
				'required' => array('names'),
			),
		),
		array(
			'name'        => 'mrca',
			'description' => 'Find the most recent common ancestor (MRCA) of two or more taxa in the Open Tree of Life synthesis tree. This implements a minimum-clade phyloreference. Accepts taxon names or OTT IDs.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxa' => array(
						'type'        => 'string',
						'description' => 'Comma-separated taxon names or OTT IDs (at least two).',
					),
				),
				'required' => array('taxa'),
			),
		),
		array(
			'name'        => 'max_clade',
			'description' => 'Find the maximum clade: the largest clade in the synthesis tree that contains all "include" taxa but none of the "exclude" taxa. This implements a maximum-clade (stem-based) phyloreference. Example: "the most inclusive clade containing Anolis valencienni but not Anolis sagrei".',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'include' => array(
						'type'        => 'string',
						'description' => 'Comma-separated taxon names or OTT IDs to include.',
					),
					'exclude' => array(
						'type'        => 'string',
						'description' => 'Comma-separated taxon names or OTT IDs to exclude.',
					),
				),
				'required' => array('include', 'exclude'),
			),
		),
		array(
			'name'        => 'sister_group',
			'description' => 'Find the sister group of a taxon in the Open Tree of Life synthesis tree.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxon' => array(
						'type'        => 'string',
						'description' => 'Taxon name or OTT ID.',
					),
				),
				'required' => array('taxon'),
			),
		),
		array(
			'name'        => 'is_monophyletic',
			'description' => 'Test whether a set of taxa form a monophyletic group (exclusive clade) in the synthesis tree.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxa' => array(
						'type'        => 'string',
						'description' => 'Comma-separated taxon names or OTT IDs (at least two).',
					),
				),
				'required' => array('taxa'),
			),
		),
		array(
			'name'        => 'triplet',
			'description' => 'Test a three-taxon relationship: are two taxa more closely related to each other than either is to a third?',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'closer' => array(
						'type'        => 'string',
						'description' => 'Two comma-separated taxon names that should be more closely related.',
					),
					'distant' => array(
						'type'        => 'string',
						'description' => 'The taxon that should be more distantly related.',
					),
				),
				'required' => array('closer', 'distant'),
			),
		),
		array(
			'name'        => 'node_info',
			'description' => 'Get detailed information about a node in the synthesis tree, including phylogenetic annotations (which studies support or conflict with it), sister group, and parent.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxon' => array(
						'type'        => 'string',
						'description' => 'Taxon name or OTT ID.',
					),
				),
				'required' => array('taxon'),
			),
		),
	);
}

// ── Tool dispatch ───────────────────────────────────────────────────────

function callTool($name, $args)
{
	$q  = mcp_get_queries();
	$db = mcp_get_db();

	switch ($name)
	{
		case 'resolve_name':   return tool_resolve($q, $args);
		case 'mrca':           return tool_mrca($q, $args);
		case 'max_clade':      return tool_maxclade($q, $args);
		case 'sister_group':   return tool_sister($q, $args);
		case 'is_monophyletic':return tool_monophyly($q, $args);
		case 'triplet':        return tool_triplet($q, $args);
		case 'node_info':      return tool_node($q, $db, $args);
		default:               return null;
	}
}

// ── Helpers ──────────────────────────────────────────────────────────────

function _parse_csv($s) { return array_filter(array_map('trim', explode(',', $s))); }

function _resolve_csv(TreeQueries $q, $csv)
{
	$names = _parse_csv($csv);
	$resolved = array();
	$failed = array();
	foreach ($names as $n) {
		$ext = $q->resolve_name($n);
		if ($ext) $resolved[] = $ext;
		else      $failed[] = $n;
	}
	return array('resolved' => $resolved, 'failed' => $failed, 'input' => $names);
}

function _fmt($row, TreeQueries $q)
{
	return $row['external_id'] . ' (' . $q->ott->prettify_label($row['label']) . ', ' . $row['weight'] . ' tips)';
}

// ── Tool implementations ────────────────────────────────────────────────

function tool_resolve(TreeQueries $q, $args)
{
	$names = _parse_csv($args['names'] ?? '');
	$lines = array();
	foreach ($names as $n) {
		$ext = $q->resolve_name($n);
		$lines[] = $ext ? "$n -> $ext" : "$n -> NOT FOUND";
	}
	return implode("\n", $lines);
}

function tool_mrca(TreeQueries $q, $args)
{
	$r = _resolve_csv($q, $args['taxa'] ?? '');
	if (count($r['resolved']) < 2) return 'Need at least two resolved taxa. Failed: ' . implode(', ', $r['failed']);
	$mrca = $q->mrca_of($r['resolved']);
	if (!$mrca) return 'MRCA computation failed.';
	$lines = array('MRCA: ' . _fmt($mrca, $q));
	foreach ($q->lookup_external($r['resolved']) as $row) $lines[] = '  input: ' . _fmt($row, $q);
	if (!empty($r['failed'])) $lines[] = 'Unresolved: ' . implode(', ', $r['failed']);
	return implode("\n", $lines);
}

function tool_maxclade(TreeQueries $q, $args)
{
	$inc = _resolve_csv($q, $args['include'] ?? '');
	$exc = _resolve_csv($q, $args['exclude'] ?? '');
	if (empty($inc['resolved'])) return 'No included taxa resolved. Failed: ' . implode(', ', $inc['failed']);
	if (empty($exc['resolved'])) return 'No excluded taxa resolved. Failed: ' . implode(', ', $exc['failed']);
	$node = $q->max_clade($inc['resolved'], $exc['resolved']);
	if (!$node) return 'Maximum clade definition does not resolve on this tree.';
	$lines = array('Maximum clade: ' . _fmt($node, $q));
	foreach ($q->lookup_external($inc['resolved']) as $row) $lines[] = '  include: ' . _fmt($row, $q);
	foreach ($q->lookup_external($exc['resolved']) as $row) $lines[] = '  exclude: ' . _fmt($row, $q);
	$failed = array_merge($inc['failed'], $exc['failed']);
	if (!empty($failed)) $lines[] = 'Unresolved: ' . implode(', ', $failed);
	return implode("\n", $lines);
}

function tool_sister(TreeQueries $q, $args)
{
	$ext = $q->resolve_name($args['taxon'] ?? '');
	if (!$ext) return 'Taxon not found: ' . ($args['taxon'] ?? '');
	$sisters = $q->sister_of($ext);
	if ($sisters === null) return 'Taxon not found in tree.';
	if (empty($sisters)) return 'Root node has no sister group.';
	$rows = $q->lookup_external(array($ext));
	$lines = array('Taxon: ' . _fmt($rows[0], $q));
	$lines[] = 'Sister group' . (count($sisters) > 1 ? 's' : '') . ':';
	foreach ($sisters as $s) $lines[] = '  ' . _fmt($s, $q);
	return implode("\n", $lines);
}

function tool_monophyly(TreeQueries $q, $args)
{
	$r = _resolve_csv($q, $args['taxa'] ?? '');
	if (count($r['resolved']) < 2) return 'Need at least two resolved taxa. Failed: ' . implode(', ', $r['failed']);
	$result = $q->is_monophyletic($r['resolved']);
	$answer = $result['monophyletic'] ? 'YES — monophyletic' : 'NO — not monophyletic';
	$lines = array($answer, 'Reason: ' . $result['reason']);
	if ($result['mrca']) $lines[] = 'MRCA: ' . $result['mrca']['id'] . ' (' . $result['mrca']['display'] . ')';
	if (!empty($r['failed'])) $lines[] = 'Unresolved: ' . implode(', ', $r['failed']);
	return implode("\n", $lines);
}

function tool_triplet(TreeQueries $q, $args)
{
	$closer = _resolve_csv($q, $args['closer'] ?? '');
	$distant = _resolve_csv($q, $args['distant'] ?? '');
	if (count($closer['resolved']) !== 2) return 'Need exactly two taxa for "closer". Failed: ' . implode(', ', $closer['failed']);
	if (count($distant['resolved']) !== 1) return 'Need exactly one taxon for "distant". Failed: ' . implode(', ', $distant['failed']);
	$a = $closer['resolved'][0]; $b = $closer['resolved'][1]; $c = $distant['resolved'][0];
	$mrca_ab = $q->mrca($a, $b);
	$mrca_abc = $q->mrca_of(array($a, $b, $c));
	if (!$mrca_ab || !$mrca_abc) return 'MRCA computation failed.';
	$consistent = (int)$mrca_ab['nleft'] > (int)$mrca_abc['nleft']
	           && (int)$mrca_ab['nright'] < (int)$mrca_abc['nright'];
	$closer_rows = $q->lookup_external($closer['resolved']);
	$distant_rows = $q->lookup_external($distant['resolved']);
	$answer = $consistent ? 'YES' : 'NO';
	$lines = array(
		$answer . ' — ' . $q->ott->prettify_label($closer_rows[0]['label'])
		. ' and ' . $q->ott->prettify_label($closer_rows[1]['label'])
		. ' are ' . ($consistent ? '' : 'NOT ')
		. 'more closely related to each other than to '
		. $q->ott->prettify_label($distant_rows[0]['label']),
		'MRCA of closer pair: ' . _fmt($mrca_ab, $q),
		'MRCA of all three: ' . _fmt($mrca_abc, $q),
	);
	return implode("\n", $lines);
}

function tool_node(TreeQueries $q, PDO $db, $args)
{
	$ext = $q->resolve_name($args['taxon'] ?? '');
	if (!$ext) return 'Taxon not found: ' . ($args['taxon'] ?? '');
	$rows = $q->lookup_external(array($ext));
	if (empty($rows)) return 'Taxon not found in tree.';
	$row = $rows[0];

	$lines = array();
	$lines[] = $q->ott->prettify_label($row['label']) . ' (' . $row['external_id'] . ')';
	$lines[] = 'Descendant tips: ' . $row['weight'];
	$lines[] = 'Depth: ' . $row['depth'];

	if ($row['parent'] && $row['parent'] !== $row['id']) {
		$p_stmt = $db->prepare('SELECT ta.external_id, ta.label FROM tree t INNER JOIN taxa ta USING(id) WHERE t.id = ?');
		$p_stmt->execute(array($row['parent']));
		$p = $p_stmt->fetch(PDO::FETCH_ASSOC);
		if ($p) $lines[] = 'Parent: ' . $q->ott->prettify_label($p['label']) . ' (' . $p['external_id'] . ')';
	}

	$sisters = $q->sister_of($ext);
	if ($sisters && !empty($sisters)) {
		$lines[] = 'Sister: ' . implode(', ', array_map(function ($s) use ($q) {
			return $q->ott->prettify_label($s['label']);
		}, $sisters));
	}

	$ann_stmt = $db->prepare(
		"SELECT DISTINCT a.relation, a.study_tree, s.publication_ref, s.doi
		 FROM annotations a
		 LEFT JOIN studies s ON s.study_id = substr(a.study_tree, 1, instr(a.study_tree, '@') - 1)
		 WHERE a.node_external_id = ?"
	);
	$ann_stmt->execute(array($ext));
	$annotations = array();
	while ($a = $ann_stmt->fetch(PDO::FETCH_ASSOC)) {
		$rel = $a['relation'];
		if (!isset($annotations[$rel])) $annotations[$rel] = array();
		$study = $a['study_tree'];
		if ($a['publication_ref']) $study .= ' — ' . $a['publication_ref'];
		if ($a['doi']) $study .= ' (doi:' . $a['doi'] . ')';
		$annotations[$rel][] = $study;
	}

	if (empty($annotations)) {
		$lines[] = 'Annotations: none (taxonomy-only node)';
	} else {
		$labels = array('supported_by' => 'Supported by', 'conflicts_with' => 'Conflicts with',
			'resolves' => 'Resolves', 'partial_path_of' => 'Partial path of', 'terminal' => 'Terminal in');
		foreach ($annotations as $rel => $studies) {
			$label = isset($labels[$rel]) ? $labels[$rel] : $rel;
			$lines[] = $label . ' (' . count($studies) . '):';
			foreach ($studies as $s) $lines[] = '  ' . $s;
		}
	}

	return implode("\n", $lines);
}
