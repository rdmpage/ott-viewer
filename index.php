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
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OTT viewer</title>
<link rel="stylesheet" href="viewer.css?v=<?= filemtime('viewer.css') ?>">
</head>
<body>

<!-- Top navbar: Home | Featured | search | About. Search hits appear in a
     dropdown beneath the input; Featured opens a 3×2 grid of taxon
     thumbnails. Clicking either navigates the tree. -->
<nav id="navbar">
	<a href="?" class="nav-link nav-home">Home</a>

	<!-- Featured-taxa dropdown. <details> gives native open/close + keyboard
	     support; viewer.js wires outside-click and item-click to close it.
	     Each .featured-item is href + svg + label; replace href with the
	     real ott id and drop the matching SVG into the images/ directory.
	     Filenames are descriptive so reordering slots doesn't break the
	     mapping; add or remove rows freely (CSS grid auto-flows). -->
	<details id="featured" class="nav-dropdown-wrap">
		<summary class="nav-link">Featured</summary>
		<div class="nav-dropdown" role="menu">
			<a class="featured-item" href="?taxon=ott746703"             role="menuitem">
				<img src="images/Afrotheria.svg" alt="">
				<span>Afrotheria</span>
			</a>
			<a class="featured-item" href="?taxon=ott786678"             role="menuitem">
				<img src="images/Araucariaceae.svg" alt="">
				<span>Araucariaceae</span>
			</a>
			<a class="featured-item" href="?taxon=ott17233"              role="menuitem">
				<img src="images/Dacrymycetes.svg" alt="">
				<span>Dacrymycetales</span>
			</a>
			<a class="featured-item" href="?taxon=mrcaott21730ott43178"  role="menuitem">
				<img src="images/Diopsidae.svg" alt="">
				<span>Diopsidae</span>
			</a>
			<a class="featured-item" href="?taxon=ott81443"              role="menuitem">
				<img src="images/Dromaiidae.svg" alt="">
				<span>Palaeognathae</span>
			</a>
			<a class="featured-item" href="?taxon=mrcaott725ott4345"     role="menuitem">
				<img src="images/Streptococcaceae.svg" alt="">
				<span>Streptococcus</span>
			</a>
		</div>
	</details>

	<div id="search-bar">
		<input type="text" id="search-input" placeholder="search taxon name…" autocomplete="off" spellcheck="false">
		<ul id="search-results"></ul>
	</div>
	<a href="api/" class="nav-link nav-api">API</a>
	<a href="#help" class="nav-link nav-help" onclick="event.preventDefault(); document.getElementById('help-dialog').showModal();">Help</a>
</nav>

<!-- Help dialog. Native <dialog> gives ESC-to-close and focus trapping
     for free; the click-on-backdrop handler below is the only thing we
     have to wire ourselves. autofocus + tabindex="-1" on the dialog
     itself stops showModal() from auto-focusing the first link inside,
     which Safari renders with a visible focus outline that looks like
     a stray hover box. -->
<dialog id="help-dialog" autofocus tabindex="-1" onclick="if(event.target===this)this.close()">
	<span class="close" onclick="this.closest('dialog').close()" aria-hidden="true">&times;</span>
	<p>OTT Viewer is an alternative way to view the <a href="https://tree.opentreeoflife.org/" target="_blank" rel="noopener">Open Tree of Life</a>. It uses summary trees to compress the tree and hoptrees to navigate browsing history.</p>
	<p>Project by Rod Page; source at <a href="https://github.com/rdmpage/ott-viewer" target="_blank" rel="noopener">github.com/rdmpage/ott-viewer</a>.</p>

	<h3>Navigation</h3>
	<p>Click a node to open its info panel. Double-click to make that node the new focus and re-root the tree on it.</p>

	<h3>Legend</h3>
	<dl class="legend">
		<dt><svg viewBox="0 0 32 16" class="legend-icon"><line x1="2" y1="8" x2="30" y2="8" stroke="currentColor" stroke-width="1.5"/></svg></dt>
		<dd>solid edge — at least one phylogenetic study supports this clade</dd>

		<dt><svg viewBox="0 0 32 16" class="legend-icon"><line x1="2" y1="8" x2="30" y2="8" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 3"/></svg></dt>
		<dd>dashed edge — taxonomy only; no study touches this clade</dd>

		<dt><svg viewBox="0 0 32 16" class="legend-icon"><circle cx="16" cy="8" r="3" fill="currentColor"/></svg></dt>
		<dd>filled circle — internal node, or a fully-resolved tip in the supertree</dd>

		<dt><svg viewBox="0 0 32 16" class="legend-icon"><polygon points="19,5 19,11 13,8" fill="currentColor"/></svg></dt>
		<dd>triangle — tip standing in for a collapsed subtree (more descendants behind it)</dd>

		<dt><svg viewBox="0 0 32 16" class="legend-icon"><circle class="legend-hollow" cx="16" cy="8" r="3"/></svg></dt>
		<dd>open circle — &ldquo;other&rdquo; summary; sibling tips that didn't fit the leaf budget</dd>

		<dt><svg viewBox="0 0 32 16" class="legend-icon">
			<line x1="6" y1="8" x2="26" y2="8" stroke="currentColor" stroke-width="1.5"/>
			<text x="16" y="4"  class="annot-support"  font-size="6" text-anchor="middle" dominant-baseline="middle">3</text>
			<text x="16" y="13" class="annot-conflict" font-size="6" text-anchor="middle" dominant-baseline="middle">1</text>
		</svg></dt>
		<dd>numbers above (dark) and below (red) an internal-node edge: count of studies supporting and conflicting</dd>
	</dl>
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

<script src="viewer.js?v=<?= filemtime('viewer.js') ?>"></script>
<script>
browseInit(<?php echo json_encode($taxon); ?>, <?php echo (int)$k; ?>);
</script>
</body>
</html>
