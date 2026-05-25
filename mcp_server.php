#!/usr/bin/env php
<?php
// mcp_server.php
// MCP stdio server for the Open Tree of Life viewer.
// Uses the shared handler in mcp_handler.php.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

fwrite(STDERR, "[ott-mcp] Starting stdio server\n");

require_once dirname(__FILE__) . '/mcp_handler.php';

// ── MCP framing ─────────────────────────────────────────────────────────

function readMessage()
{
	while (true) {
		$line = fgets(STDIN);
		if ($line === false) return null;
		$line = rtrim($line, "\r\n");
		if ($line === '') continue;

		if ($line[0] === '{' || $line[0] === '[') {
			$data = json_decode($line, true);
			return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
		}

		$headers = array();
		$h = $line;
		while (true) {
			$parts = explode(':', $h, 2);
			if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			$next = fgets(STDIN);
			if ($next === false) return null;
			$h = rtrim($next, "\r\n");
			if ($h === '') break;
		}
		if (!isset($headers['content-length'])) return null;
		$body = '';
		$rem = (int)$headers['content-length'];
		while ($rem > 0) {
			$chunk = fread(STDIN, $rem);
			if ($chunk === false || $chunk === '') return null;
			$body .= $chunk;
			$rem  -= strlen($chunk);
		}
		$data = json_decode($body, true);
		return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
	}
}

function sendMessage(array $msg)
{
	$json = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	fwrite(STDOUT, $json . "\n");
	fflush(STDOUT);
}

// ── Main loop ───────────────────────────────────────────────────────────

while (!feof(STDIN)) {
	$request = readMessage();
	if ($request === null) {
		if (feof(STDIN)) break;
		continue;
	}

	$response = handleMcpRequest($request);
	if ($response !== null) sendMessage($response);
}

fwrite(STDERR, "[ott-mcp] Server stopped\n");
