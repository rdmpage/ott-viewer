<?php
// mcp_http_server.php
// MCP server over HTTP — handles JSON-RPC requests via POST.
// Deploy behind Apache (same as the rest of the viewer) or run
// standalone with: php -S localhost:3000 mcp_http_server.php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

require_once dirname(__FILE__) . '/mcp_handler.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
	http_response_code(200);
	exit;
}

if ($method === 'POST') {
	$input = file_get_contents('php://input');
	$request = json_decode($input, true);

	if ($request === null) {
		http_response_code(400);
		header('Content-Type: application/json');
		echo json_encode(array(
			'jsonrpc' => '2.0',
			'id' => null,
			'error' => array('code' => -32700, 'message' => 'Parse error: Invalid JSON'),
		));
		exit;
	}

	$response = handleMcpRequest($request);

	header('Content-Type: application/json');
	if ($response !== null) {
		echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	} else {
		http_response_code(204);
	}
	exit;
}

if ($method === 'GET') {
	header('Content-Type: text/plain');
	$tools = getToolDefinitions();
	$toolNames = array_map(function ($t) { return '  - ' . $t['name'] . ' — ' . $t['description']; }, $tools);
	echo "OTT Viewer MCP Server — HTTP Endpoint\n\n";
	echo "POST JSON-RPC requests to this URL.\n\n";
	echo "Example:\n";
	echo "  curl -X POST " . ($_SERVER['REQUEST_URI'] ?? '/') . " \\\n";
	echo "    -H 'Content-Type: application/json' \\\n";
	echo "    -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\",\"params\":{}}'\n\n";
	echo "Tools:\n" . implode("\n", $toolNames) . "\n";
	exit;
}

http_response_code(405);
header('Allow: GET, POST, OPTIONS');
echo "Method Not Allowed\n";
