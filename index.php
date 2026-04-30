<?php

// OTT tree viewer — interactive entry point.
//
// URL params:
//   taxon=<external_id>   focal taxon (default: ott93302 — cellular
//                         organisms, the OTT root)
//   k=<int>               summary leaf budget (default: 30)
//
// The page renders the tree rooted on the focal taxon. Clicking a node
// fetches a fresh tree.json for that node and animates the transition.

$default_taxon = 'ott93302';

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

<!-- Top navbar: Home | search | About. Search hits appear in a dropdown
     beneath the input; clicking one navigates the tree to that taxon. -->
<nav id="navbar">
	<a href="?" class="nav-link nav-home">Home</a>
	<div id="search-bar">
		<input type="text" id="search-input" placeholder="search taxon name…" autocomplete="off" spellcheck="false">
		<ul id="search-results"></ul>
	</div>
	<a href="about.php" class="nav-link nav-about">About</a>
</nav>

<!-- Navigation history (breadcrumb / hoptree). Collapsible via the
     native <details> element; open by default. -->
<details id="nav-history" open>
	<summary>navigation history</summary>
	<div id="hoptree-container">(no history yet)</div>
</details>

<!-- Main: tree on the left, info panel on the right.
     #info-panel starts hidden; openInfoPanel() / closeInfoPanel() in
     viewer.js toggle the .open class. -->
<div id="main">
	<svg id="canvas" preserveAspectRatio="xMinYMid meet"></svg>
	<aside id="info-panel">
		<button class="close" type="button" onclick="closeInfoPanel()" aria-label="close info panel">&times;</button>
		<div id="info-content"></div>
	</aside>
</div>

<script src="viewer.js"></script>
<script>
browseInit(<?php echo json_encode($taxon); ?>, <?php echo (int)$k; ?>);
</script>
</body>
</html>
