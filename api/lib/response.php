<?php

// JSON, plain-text, and error response helpers shared by every handler.
// Keep handlers free of header/serialisation noise so they read as
// "validate params, call the lib, send the result".

function api_json($data, $status = 200, $cache_seconds = 86400)
{
	if (php_sapi_name() === 'cli')
	{
		echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
		return;
	}
	http_response_code($status);
	header('Content-Type: application/json');
	header('Access-Control-Allow-Origin: *');
	if ($status === 200 && $cache_seconds > 0)
	{
		header('Cache-Control: public, max-age=' . (int)$cache_seconds);
	}
	echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function api_text($text, $status = 200, $cache_seconds = 86400)
{
	if (php_sapi_name() === 'cli')
	{
		echo $text;
		return;
	}
	http_response_code($status);
	header('Content-Type: text/plain; charset=utf-8');
	header('Access-Control-Allow-Origin: *');
	if ($status === 200 && $cache_seconds > 0)
	{
		header('Cache-Control: public, max-age=' . (int)$cache_seconds);
	}
	echo $text;
}

// Single error envelope shape used everywhere: { error: { code, message, details? } }.
// `code` is a stable client-switchable string; `message` is human-readable;
// `details` carries structured context (the offending param name etc.).
function api_error($code, $message, $details = null, $status = 400)
{
	$err = new stdClass;
	$err->error = new stdClass;
	$err->error->code    = $code;
	$err->error->message = $message;
	if ($details !== null) $err->error->details = $details;
	api_json($err, $status, 0);
	exit;
}

// External ids are alphanumeric + underscore (`ottN`, `mrcaottXottY`,
// `other_ottN`). Reject anything else with a 400 — keeps the SQL-injection
// surface to nothing and gives the caller a clear error.
function api_validate_external_id($id, $param_name = 'taxon')
{
	if (!is_string($id) || $id === '' || !preg_match('/^[A-Za-z0-9_]+$/', $id))
	{
		api_error(
			'bad_request',
			"Invalid id for parameter '$param_name': must be alphanumeric + underscore.",
			array('param' => $param_name, 'value' => $id),
			400
		);
	}
	return $id;
}
