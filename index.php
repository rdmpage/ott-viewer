<?php

// OTT tree viewer — interactive entry point.
//
// URL params:
//   taxon=<external_id>   focal taxon (default: ott452461 — Procellariiformes)
//   k=<int>               summary leaf budget (default: 30)
//
// The page renders the tree rooted on the focal taxon. Clicking a node
// fetches a fresh tree.json for that node and animates the transition.

$default_taxon = 'ott452461';

$taxon = isset($_GET['taxon']) ? trim($_GET['taxon']) : $default_taxon;
if (!preg_match('/^[A-Za-z0-9_]+$/', $taxon)) $taxon = $default_taxon;

$k = isset($_GET['k']) ? max(2, (int)$_GET['k']) : 30;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>OTT viewer</title>
<link rel="stylesheet" href="viewer.css">
</head>
<body>
<svg id="canvas" preserveAspectRatio="xMinYMid meet"></svg>
<script src="viewer.js"></script>
<script>
browseInit(<?php echo json_encode($taxon); ?>, <?php echo (int)$k; ?>);
</script>
</body>
</html>
