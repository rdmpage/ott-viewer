<?php

require_once dirname(__FILE__) . '/../lib/response.php';

const PHYLOPIC_RESOLVE   = 'https://api.phylopic.org/resolve/opentreeoflife.org/taxonomy/';
const PHYLOPIC_IMAGE_CDN = 'https://images.phylopic.org/images/';
const PHYLOPIC_CACHE_DAYS = 30;
define('PHYLOPIC_SVG_DIR', dirname(__FILE__) . '/../../cache/phylopic/');

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
		'SELECT ott_id, image_uuid, thumbnail_url, svg_url, contributor, license_url
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
		 (ott_id, image_uuid, thumbnail_url, svg_url, contributor, license_url, fetched_at)
		 VALUES (?, ?, ?, ?, ?, ?, datetime("now"))'
	);

	foreach ($uncached as $id) {
		$data = _phylopic_resolve($id);
		$insert->execute(array(
			$id,
			$data['image_uuid'],
			$data['thumbnail_url'],
			$data['svg_url'],
			$data['contributor'],
			$data['license_url'],
		));
		$results[$id] = array(
			'ott_id'        => $id,
			'image_uuid'    => $data['image_uuid'],
			'thumbnail_url' => $data['thumbnail_url'],
			'svg_url'       => $data['svg_url'],
			'contributor'   => $data['contributor'],
			'license_url'   => $data['license_url'],
		);
	}

	$out = array();
	foreach ($ids as $id) {
		$out[] = $results[$id];
	}
	api_json(array('results' => $out), 200, 3600);
}

function _phylopic_resolve($ott_id)
{
	$empty = array(
		'image_uuid'    => null,
		'thumbnail_url' => null,
		'svg_url'       => null,
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

	$svg_url = isset($links['vectorFile']['href']) ? $links['vectorFile']['href'] : null;

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
		'svg_url'       => $svg_url,
		'contributor'   => $links['contributor']['title'] ?? ($img['attribution'] ?? null),
		'license_url'   => $links['license']['href'] ?? null,
	);
}

function api_handle_phylopic_svg($uuid)
{
	if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid))
		api_error('bad_request', 'Invalid UUID.', null, 400);

	$local = PHYLOPIC_SVG_DIR . $uuid . '.svg';

	if (!file_exists($local)) {
		$url = PHYLOPIC_IMAGE_CDN . $uuid . '/vector.svg';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 10,
		));
		$svg = curl_exec($ch);
		$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($http !== 200 || $svg === false)
			api_error('not_found', 'SVG not available.', null, 404);

		// Strip width/height so the SVG scales to its container via viewBox.
		// Recolour black fills to mid-gray so silhouettes are visible in both
		// light and dark mode without any client-side filter.
		$svg = preg_replace('/(<svg\b[^>]*?)\s+width="[^"]*"/', '$1', $svg);
		$svg = preg_replace('/(<svg\b[^>]*?)\s+height="[^"]*"/', '$1', $svg);
		$svg = str_replace('fill="#000000"', 'fill="#808080"', $svg);
		$svg = str_replace('fill="black"', 'fill="#808080"', $svg);

		if (!is_dir(PHYLOPIC_SVG_DIR)) mkdir(PHYLOPIC_SVG_DIR, 0755, true);
		file_put_contents($local, $svg);
	}

	header('Content-Type: image/svg+xml');
	header('Cache-Control: public, max-age=2592000');
	header('Access-Control-Allow-Origin: *');
	readfile($local);
	exit;
}
