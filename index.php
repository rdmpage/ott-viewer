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

// 0 = not specified — viewer.js's browseInit will then pick k from
// idealK(window height) so the leaf budget tracks the available room.
$k = isset($_GET['k']) ? max(2, (int)$_GET['k']) : 0;

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
	<a href="#about" class="nav-link nav-about" onclick="event.preventDefault(); document.getElementById('about-dialog').showModal();">About</a>
</nav>

<!-- About dialog. Native <dialog> gives ESC-to-close and focus trapping
     for free; the click-on-backdrop handler below is the only thing we
     have to wire ourselves. autofocus + tabindex="-1" on the dialog
     itself stops showModal() from auto-focusing the first link inside,
     which Safari renders with a visible focus outline that looks like
     a stray hover box. -->
<dialog id="about-dialog" autofocus tabindex="-1" onclick="if(event.target===this)this.close()">
	<span class="close" onclick="this.closest('dialog').close()" aria-hidden="true">&times;</span>
	<p>OTT Viewer is an alternative way to view the <a href="https://tree.opentreeoflife.org/" target="_blank" rel="noopener">Open Tree of Life</a>. It uses a combination of summary trees to compress the tree, and hoptrees to navigate browsing history.</p>
	<p>This is a project by Rod Page; source code at <a href="https://github.com/rdmpage/ott-viewer" target="_blank" rel="noopener">github.com/rdmpage/ott-viewer</a>.</p>
</dialog>

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
