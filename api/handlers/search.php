<?php

// GET /api/v1/search — taxon search.
//
// Modes:
//   exact     — case-insensitive equality (the legacy default)
//   prefix    — label LIKE 'q%'
//   substring — label LIKE '%q%' (slowest; intended for interactive
//                                  search-as-you-type; capped tighter)

require_once dirname(__FILE__) . '/../lib/response.php';

const SEARCH_LIMIT_CAP = 200;

function api_handle_search(PDO $db, array $params)
{
	$q     = isset($params['q'])     ? trim((string)$params['q'])     : '';
	$mode  = isset($params['mode'])  ? strtolower(trim((string)$params['mode'])) : 'exact';
	$limit = isset($params['limit']) ? max(1, (int)$params['limit']) : 50;
	if ($limit > SEARCH_LIMIT_CAP) $limit = SEARCH_LIMIT_CAP;

	if ($q === '')
	{
		$out = new stdClass;
		$out->query   = $q;
		$out->mode    = $mode;
		$out->results = array();
		api_json($out);
		return;
	}

	if (!in_array($mode, array('exact', 'prefix', 'substring'), true))
	{
		api_error('bad_request', "Unknown search mode '$mode'.",
			array('param' => 'mode', 'value' => $mode), 400);
	}

	switch ($mode)
	{
		case 'prefix':
			$where  = 'label LIKE :q ESCAPE \'\\\' COLLATE NOCASE';
			$bind   = _search_escape_like($q) . '%';
			break;
		case 'substring':
			$where  = 'label LIKE :q ESCAPE \'\\\' COLLATE NOCASE';
			$bind   = '%' . _search_escape_like($q) . '%';
			break;
		case 'exact':
		default:
			$where  = 'label = :q COLLATE NOCASE';
			$bind   = $q;
			break;
	}

	$stmt = $db->prepare(
		"SELECT external_id, label
		 FROM taxa
		 WHERE $where
		 ORDER BY label
		 LIMIT $limit"
	);
	$stmt->execute(array(':q' => $bind));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$out = new stdClass;
	$out->query   = $q;
	$out->mode    = $mode;
	$out->limit   = $limit;
	$out->results = array();
	foreach ($rows as $r)
	{
		$out->results[] = (object)array(
			'id'      => $r['external_id'],
			'display' => $r['label'],
		);
	}

	api_json($out);
}

// Escape SQL LIKE wildcards so user-supplied % and _ match literally.
function _search_escape_like($s)
{
	return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
}
