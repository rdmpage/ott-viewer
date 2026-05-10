<?php

// GET /api/v1/tree — focal subtree, summary-pruned. Replaces the old
// top-level tree.php for clients reading via /api/v1/.

require_once dirname(__FILE__) . '/../lib/response.php';
require_once dirname(__FILE__) . '/../lib/tree_builder.php';
require_once dirname(__FILE__) . '/../lib/format.php';

function api_handle_tree(PDO $db, array $params)
{
	$taxon = isset($params['taxon']) ? trim((string)$params['taxon']) : 'ott93302';
	api_validate_external_id($taxon, 'taxon');

	$k      = isset($params['k'])      ? max(2, (int)$params['k']) : 30;
	$format = isset($params['format']) ? strtolower(trim((string)$params['format'])) : 'json';

	if ($format !== 'json' && $format !== 'newick')
	{
		api_error(
			'unsupported_format',
			"format must be one of: json, newick",
			array('param' => 'format', 'value' => $format),
			400
		);
	}

	$payload = build_tree_payload($db, $taxon, $k);

	if ($format === 'newick')
	{
		$labels = isset($params['labels']) ? strtolower(trim((string)$params['labels'])) : 'ids';
		$bl     = isset($params['branch_lengths']) ? strtolower(trim((string)$params['branch_lengths'])) : 'none';

		if (!in_array($labels, array('ids', 'names'), true))
		{
			api_error('bad_request', "labels must be one of: ids, names",
				array('param' => 'labels', 'value' => $labels), 400);
		}
		if (!in_array($bl, array('none', 'ones'), true))
		{
			api_error('bad_request', "branch_lengths must be one of: none, ones",
				array('param' => 'branch_lengths', 'value' => $bl), 400);
		}

		api_text(tree_to_newick($payload, array(
			'labels'         => $labels,
			'branch_lengths' => $bl,
		)));
		return;
	}

	api_json($payload);
}
