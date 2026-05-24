<?php

require_once dirname(__FILE__) . '/../lib/response.php';

const PHYLOPIC_RESOLVE = 'https://api.phylopic.org/resolve/opentreeoflife.org/taxonomy/';
const PHYLOPIC_CACHE_DAYS = 30;

function api_handle_phylopic(PDO $db, array $params)
{
	$raw = isset($params['ott_ids']) ? trim((string)$params['ott_ids']) : '';
	if ($raw === '' && isset($params['ott_id'])) $raw = trim((string)$params['ott_id']);
	if ($raw === '') api_error('bad_request', 'Provide ott_id or ott_ids.', null, 400);

	$ids = array_unique(array_filter(array_map(function ($v) {
		return preg_replace('/^ott/', '', trim($v));
	}, explode(',', $raw))));

	if (count($ids) === 0 || count($ids) > 50)
		api_error('bad_request', 'Provide 1-50 OTT ids.', null, 400);

	foreach ($ids as $id) {
		if (!preg_match('/^\d+$/', $id))
			api_error('bad_request', "Invalid ott_id: $id", null, 400);
	}

	$cutoff = date('Y-m-d H:i:s', strtotime('-' . PHYLOPIC_CACHE_DAYS . ' days'));
	$cache_stmt = $db->prepare(
		'SELECT ott_id, image_uuid, thumbnail_url, contributor, license_url
		 FROM phylopic_cache WHERE ott_id = ? AND fetched_at > ?'
	);

	$results = array();
	$uncached = array();

	foreach ($ids as $id) {
		$cache_stmt->execute(array($id, $cutoff));
		$row = $cache_stmt->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			$results[$id] = $row;
		} else {
			$uncached[] = $id;
		}
	}

	$insert = $db->prepare(
		'INSERT OR REPLACE INTO phylopic_cache
		 (ott_id, image_uuid, thumbnail_url, contributor, license_url, fetched_at)
		 VALUES (?, ?, ?, ?, ?, datetime("now"))'
	);

	foreach ($uncached as $id) {
		$data = _phylopic_resolve($id);
		$insert->execute(array(
			$id,
			$data['image_uuid'],
			$data['thumbnail_url'],
			$data['contributor'],
			$data['license_url'],
		));
		$results[$id] = array(
			'ott_id'        => $id,
			'image_uuid'    => $data['image_uuid'],
			'thumbnail_url' => $data['thumbnail_url'],
			'contributor'   => $data['contributor'],
			'license_url'   => $data['license_url'],
		);
	}

	$out = array();
	foreach ($ids as $id) {
		$out[] = $results[$id];
	}
	api_json(array('results' => $out));
}

function _phylopic_resolve($ott_id)
{
	$empty = array(
		'image_uuid'    => null,
		'thumbnail_url' => null,
		'contributor'   => null,
		'license_url'   => null,
	);

	$url = PHYLOPIC_RESOLVE . urlencode($ott_id) . '?embed_primaryImage=true';
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 8,
		CURLOPT_HTTPHEADER     => array('Accept: application/json'),
	));
	$body = curl_exec($ch);
	$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($http !== 200 || $body === false) return $empty;

	$data = json_decode($body, true);
	if (!$data) return $empty;

	$img = isset($data['_embedded']['primaryImage']) ? $data['_embedded']['primaryImage'] : null;
	if (!$img) return $empty;

	$links = isset($img['_links']) ? $img['_links'] : array();

	$thumb = null;
	if (isset($links['thumbnailFiles']) && is_array($links['thumbnailFiles'])) {
		foreach ($links['thumbnailFiles'] as $t) {
			if (isset($t['sizes']) && $t['sizes'] === '64x64') {
				$thumb = $t['href'];
				break;
			}
		}
		if (!$thumb) {
			$thumb = $links['thumbnailFiles'][0]['href'] ?? null;
		}
	}

	return array(
		'image_uuid'    => $img['uuid'] ?? null,
		'thumbnail_url' => $thumb,
		'contributor'   => $links['contributor']['title'] ?? ($img['attribution'] ?? null),
		'license_url'   => $links['license']['href'] ?? null,
	);
}
