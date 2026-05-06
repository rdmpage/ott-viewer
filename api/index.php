<?php

// Front controller for /api/v1/*. Apache's RewriteRule (../.htaccess)
// rewrites any URL under /api/v1/ to /api/index.php?_path=<rest>. We
// parse _path, dispatch to a handler, and let the handler serialise.
//
// Keeping this file small (mostly switch + requires) so adding a new
// endpoint is a one-line dispatch entry, not a rewrite of routing.

require_once dirname(__FILE__) . '/lib/response.php';

// Only GET is supported; everything is read-only.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
{
	api_error('method_not_allowed', 'Only GET is supported.', null, 405);
}

// Path tail relative to /api/v1/. Empty path falls through to "about".
$path_raw = isset($_GET['_path']) ? trim((string)$_GET['_path']) : '';
$path = trim($path_raw, '/');
$segments = $path === '' ? array() : explode('/', $path);

// Strip any inadvertent v1 prefix (in case rewrite passes it through),
// so a request to /api/v1/tree maps to ['tree'] either way.
if (isset($segments[0]) && $segments[0] === 'v1') array_shift($segments);

$db = new PDO('sqlite:' . dirname(__FILE__) . '/../ott.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$resource = $segments[0] ?? 'about';

try
{
	switch ($resource)
	{
		case '':
		case 'about':
			require_once dirname(__FILE__) . '/handlers/about.php';
			api_handle_about($db);
			break;

		case 'tree':
			require_once dirname(__FILE__) . '/handlers/tree.php';
			api_handle_tree($db, $_GET);
			break;

		case 'nodes':
			require_once dirname(__FILE__) . '/handlers/nodes.php';
			$id     = $segments[1] ?? null;
			$action = $segments[2] ?? null;
			if ($id === null)
			{
				api_error('bad_request', "Missing node id; use /api/v1/nodes/{id}.", null, 400);
			}
			if ($action === null)         api_handle_node($db, $id);
			elseif ($action === 'children') api_handle_node_children($db, $id, $_GET);
			else api_error(
				'not_found',
				"Unknown nodes sub-resource '$action'.",
				array('id' => $id, 'sub' => $action),
				404
			);
			break;

		default:
			api_error('not_found', "No such resource: '$resource'.",
				array('resource' => $resource, 'path' => $path), 404);
	}
}
catch (Throwable $e)
{
	api_error(
		'internal_error',
		'The handler raised an exception.',
		array('message' => $e->getMessage()),
		500
	);
}
