<?php

// GET /api/v1/about — capabilities + dataset metadata.

require_once dirname(__FILE__) . '/../lib/response.php';

function api_handle_about(PDO $db)
{
	$node_count = (int)$db->query('SELECT COUNT(*) FROM tree')->fetchColumn();

	$out = new stdClass;
	$out->api_version     = 'v1';
	$out->node_count      = $node_count;
	$out->summary_methods = array('leaves');         // 'nodes' mode is a future addition
	$out->tree_formats    = array('json', 'newick');
	$out->generated_at    = gmdate('Y-m-d\TH:i:s\Z');

	api_json($out);
}
