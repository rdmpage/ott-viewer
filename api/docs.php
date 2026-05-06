<?php

// Browser-facing API reference. Rendered by api/index.php when the URL
// is /api/ (no rewrite parameter). Machine clients keep using
// /api/v1/<resource> directly. Live values for the about block come
// from the about handler so the dataset numbers in the page are
// always current.

require_once dirname(__FILE__) . '/lib/response.php';

$db = new PDO('sqlite:' . dirname(__FILE__) . '/../ott.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$node_count = (int)$db->query('SELECT COUNT(*) FROM tree')->fetchColumn();
$generated  = gmdate('Y-m-d\TH:i:s\Z');

// Helper for fenced code blocks below.
function code_block($lang, $code)
{
	return '<pre class="api-code"><code data-lang="' . htmlspecialchars($lang) . '">'
		. htmlspecialchars($code) . '</code></pre>';
}

// Build "Try it" URLs relative to the page so the docs work whether
// the project is at /ott-viewer or elsewhere.
$base = '../api/v1';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>API — OTT Viewer</title>
<link rel="stylesheet" href="../viewer.css">
<style>
/* viewer.css clamps html/body to viewport height with overflow:hidden
   and flex layout — perfect for the tree page, fatal for a long docs
   page (pre blocks collapse to 0 height inside flex). Undo those for
   this page. */
html, body.api-docs-body {
	height: auto;
	overflow: visible;
	display: block;
}
body.api-docs-body {
	max-width: 64em;
	margin: 0 auto;
	padding: 1em 1.5em 4em;
	line-height: 1.55;
	font-size: 0.95em;
}
.api-docs-body h1 {
	font-size: 1.6em;
	margin: 0.6em 0 0.1em;
}
.api-docs-body h2 {
	font-size: 1.05em;
	margin: 1.6em 0 0.4em;
	font-weight: 600;
	color: var(--text-dim);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	border-bottom: 1px solid var(--border);
	padding-bottom: 0.2em;
}
.api-docs-body h3 {
	font-size: 1em;
	margin: 1.4em 0 0.3em;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-weight: 600;
}
.api-docs-body .verb {
	display: inline-block;
	background: var(--bg-elevated);
	border: 1px solid var(--border);
	border-radius: 3px;
	padding: 0 0.3em;
	font-size: 0.85em;
	margin-right: 0.4em;
	color: var(--text-dim);
}
.api-docs-body p { margin: 0.5em 0; }
.api-docs-body code {
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 0.9em;
	background: var(--bg-elevated);
	padding: 0.05em 0.3em;
	border-radius: 2px;
}
.api-docs-body pre.api-code {
	background: var(--bg-elevated);
	border: 1px solid var(--border);
	border-radius: 3px;
	padding: 0.7em 0.9em;
	overflow-x: auto;
	font-size: 0.85em;
	line-height: 1.45;
	margin: 0.5em 0;
}
.api-docs-body pre.api-code code {
	background: none;
	padding: 0;
}
.api-docs-body table.params {
	border-collapse: collapse;
	margin: 0.4em 0 0.6em;
	font-size: 0.9em;
}
.api-docs-body table.params th,
.api-docs-body table.params td {
	border: 1px solid var(--border);
	padding: 0.25em 0.6em;
	text-align: left;
	vertical-align: top;
}
.api-docs-body table.params th {
	background: var(--bg-elevated);
	font-weight: 600;
	color: var(--text-dim);
}
.api-docs-body .try {
	font-size: 0.85em;
	color: var(--text-dim);
	margin-top: 0.3em;
}
.api-docs-body .try a { color: var(--text-link); }
.api-docs-body .lede {
	font-size: 1em;
	color: var(--text-dim);
	margin-bottom: 0.5em;
}
.api-docs-body .home-link {
	display: inline-block;
	margin-bottom: 1em;
	color: var(--text-link);
	text-decoration: none;
}
.api-docs-body .home-link:hover { text-decoration: underline; }
.api-docs-body .toc {
	font-size: 0.9em;
	margin: 0.5em 0 1.5em;
	color: var(--text-dim);
}
.api-docs-body .toc a { color: var(--text-link); margin-right: 0.7em; white-space: nowrap; }
</style>
</head>
<body class="api-docs-body">

<a href="../" class="home-link">&larr; back to viewer</a>

<h1>OTT Viewer API <span style="font-size:0.7em;color:var(--text-dim);font-weight:normal">v1</span></h1>

<p class="lede">
Read-only HTTP API over the Open Tree of Life synthesis tree. All
responses are JSON unless noted; all endpoints accept GET only.
</p>

<p class="toc">
	<a href="#conventions">Conventions</a>
	<a href="#about">about</a>
	<a href="#tree">tree</a>
	<a href="#nodes">nodes</a>
	<a href="#hoptree">hoptree</a>
	<a href="#mrca">mrca</a>
	<a href="#path">path</a>
	<a href="#search">search</a>
</p>

<h2 id="conventions">Conventions</h2>

<p>
	URL scheme is <code>/api/v1/&lt;resource&gt;[/&lt;id&gt;][?params]</code>.
	The version is in the path; bumping to <code>v2</code> means a breaking
	change.
</p>

<p>
	<strong>Errors</strong> share one envelope with a stable
	<code>error.code</code> string clients can switch on:
</p>

<?php echo code_block('json', '{
  "error": {
    "code":    "node_not_found",
    "message": "No node with external_id \'ottBOGUS\'.",
    "details": { "id": "ottBOGUS" }
  }
}'); ?>

<p>
	<strong>Caching:</strong> successful responses set
	<code>Cache-Control: public, max-age=86400</code>. The dataset only
	changes when OTT republishes.
</p>

<p>
	<strong>CORS:</strong> <code>Access-Control-Allow-Origin: *</code> on
	every endpoint. The data is public.
</p>

<p>
	<strong>Ids:</strong> taxon ids are <code>ottN</code> (e.g.
	<code>ott93302</code>) or anonymous mrca ids
	<code>mrcaottXottY</code>. Anything else is rejected with
	<code>bad_request</code> for the relevant param.
</p>

<h2 id="about">/about</h2>
<h3><span class="verb">GET</span><code>/api/v1/about</code></h3>
<p>Capabilities + dataset metadata. Useful for cache-busting clients (the
<code>generated_at</code> field changes on each request, but the
<code>node_count</code> is stable for a given dataset).</p>

<?php echo code_block('json', json_encode(array(
	'api_version'     => 'v1',
	'node_count'      => $node_count,
	'summary_methods' => array('leaves'),
	'tree_formats'    => array('json', 'newick'),
	'generated_at'    => $generated,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?>

<p class="try"><a href="<?=$base?>/about" target="_blank">Try it &rarr;</a></p>

<h2 id="tree">/tree</h2>
<h3><span class="verb">GET</span><code>/api/v1/tree?taxon=&hellip;&amp;k=&hellip;</code></h3>
<p>The focal subtree, summary-pruned to <code>k</code> leaves. Each node
carries display name, type, depth, tip count, supertree-leaf flag, weight
(supertree descendant count), full annotation lists, and pre-computed
<code>x</code> / <code>y</code> coordinates suitable for direct rendering.
This is what the viewer fetches on every navigation.</p>

<table class="params">
<tr><th>param</th><th>type</th><th>default</th><th>notes</th></tr>
<tr><td><code>taxon</code></td><td>string</td><td><code>ott93302</code></td><td>OTT external id or mrca id.</td></tr>
<tr><td><code>k</code></td><td>int</td><td><code>30</code></td><td>Summary leaf budget. Min 2.</td></tr>
<tr><td><code>format</code></td><td>enum</td><td><code>json</code></td><td><code>json</code> or <code>newick</code> (placeholder branch lengths of 1).</td></tr>
</table>

<p>JSON shape (truncated):</p>

<?php echo code_block('json', '{
  "focal_id":          "ott452461",
  "displayed_root_id": "ott452461",
  "nodes": {
    "ott452461": {
      "id":             "ott452461",
      "display":        "Procellariiformes",
      "type":           "internal",
      "supertree_leaf": false,
      "weight":         231,
      "annotations":    { "supported_by": [...], "terminal": [...], ... },
      "depth":          0,
      "tip_count":      28,
      "x":              0,
      "y":              50
    },
    ...
  },
  "edges": [
    { "source": "ott452461", "target": "ott85277" },
    ...
  ]
}'); ?>

<p class="try">
	<a href="<?=$base?>/tree?taxon=ott93302&amp;k=20" target="_blank">Try JSON &rarr;</a>
	&nbsp;·&nbsp;
	<a href="<?=$base?>/tree?taxon=ott93302&amp;k=8&amp;format=newick" target="_blank">Try Newick &rarr;</a>
</p>

<h2 id="nodes">/nodes</h2>

<h3><span class="verb">GET</span><code>/api/v1/nodes/{id}</code></h3>
<p>Per-node detail. Walks the parent chain to the OTT root and includes a
sample of up to 50 children (use <code>/children</code> for full
pagination).</p>

<?php echo code_block('json', '{
  "id":              "ott452461",
  "display":         "Procellariiformes",
  "type":            "internal",
  "weight":          231,
  "parents":         [{ "id": "...", "display": "..." }, ...],
  "child_count":     4,
  "children_sample": [{ "id": "ott85277", "display": "Diomedeidae" }, ...],
  "annotations":     { "supported_by": [...], ... },
  "external_links":  { "opentree": "https://tree.opentreeoflife.org/...", "wikidata": null, "ncbi": null }
}'); ?>

<p class="try"><a href="<?=$base?>/nodes/ott93302" target="_blank">Try it &rarr;</a></p>

<h3><span class="verb">GET</span><code>/api/v1/nodes/{id}/children</code></h3>
<p>Paginated children of a node.</p>
<table class="params">
<tr><th>param</th><th>type</th><th>default</th><th>notes</th></tr>
<tr><td><code>offset</code></td><td>int</td><td>0</td><td></td></tr>
<tr><td><code>limit</code></td><td>int</td><td>50</td><td>Cap 500.</td></tr>
</table>
<p class="try"><a href="<?=$base?>/nodes/ott93302/children" target="_blank">Try it &rarr;</a></p>

<h3><span class="verb">GET</span><code>/api/v1/nodes/{id}/descendants</code></h3>
<p>All descendants of a node, flat. Optional <code>tips_only=true</code>
filter restricts to leaves of the OTT supertree.</p>
<table class="params">
<tr><th>param</th><th>type</th><th>default</th><th>notes</th></tr>
<tr><td><code>tips_only</code></td><td>bool</td><td>false</td><td></td></tr>
<tr><td><code>offset</code></td><td>int</td><td>0</td><td></td></tr>
<tr><td><code>limit</code></td><td>int</td><td>500</td><td>Cap 5000.</td></tr>
</table>
<p class="try"><a href="<?=$base?>/nodes/ott917716/descendants?tips_only=true&amp;limit=20" target="_blank">Try it &rarr;</a></p>

<h2 id="hoptree">/hoptree</h2>
<h3><span class="verb">GET</span><code>/api/v1/hoptree?ids=A,B,C&hellip;</code></h3>
<p>Minimum spanning subtree of a list of visited nodes (in visit order).
Same JSON shape as <code>/tree</code>; each node carries
<code>visited</code> and <code>visit_order</code> so a client can
highlight the user's path. Used to render the navigation breadcrumb in
the viewer.</p>
<p class="try"><a href="<?=$base?>/hoptree?ids=ott93302,ott304358,ott352914" target="_blank">Try it &rarr;</a></p>

<h2 id="mrca">/mrca</h2>
<h3><span class="verb">GET</span><code>/api/v1/mrca?ids=A,B&hellip;</code></h3>
<p>Most-recent common ancestor of a set of nodes. At least two ids
required.</p>

<?php echo code_block('json', '{
  "mrca":   { "id": "...", "display": "..." },
  "inputs": [
    { "id": "ott917716", "display": "Goniurosaurus" },
    { "id": "ott342539", "display": "Hemitheconyx" }
  ]
}'); ?>

<p class="try"><a href="<?=$base?>/mrca?ids=ott917716,ott342539" target="_blank">Try it &rarr;</a></p>

<h2 id="path">/path</h2>
<h3><span class="verb">GET</span><code>/api/v1/path?from=A&amp;to=B</code></h3>
<p>Topological path between two nodes via their LCA. <code>length</code>
counts edges; <code>via</code> is the ordered node id list from
<code>from</code> up to LCA and back down to <code>to</code>.</p>

<?php echo code_block('json', '{
  "from":   "ott917716",
  "to":     "ott342539",
  "lca":    { "id": "...", "display": "..." },
  "length": 4,
  "via":    ["ott917716", "...", "...", "...", "ott342539"]
}'); ?>

<p class="try"><a href="<?=$base?>/path?from=ott917716&amp;to=ott342539" target="_blank">Try it &rarr;</a></p>

<h2 id="search">/search</h2>
<h3><span class="verb">GET</span><code>/api/v1/search?q=&hellip;</code></h3>
<p>Taxon name search.</p>

<table class="params">
<tr><th>param</th><th>type</th><th>default</th><th>notes</th></tr>
<tr><td><code>q</code></td><td>string</td><td>—</td><td>Required.</td></tr>
<tr><td><code>mode</code></td><td>enum</td><td><code>exact</code></td><td><code>exact</code>, <code>prefix</code>, or <code>substring</code>.</td></tr>
<tr><td><code>limit</code></td><td>int</td><td>50</td><td>Cap 200.</td></tr>
</table>

<?php echo code_block('json', '{
  "query":   "Goniurosaurus",
  "mode":    "prefix",
  "limit":   10,
  "results": [
    { "id": "ott917716", "display": "Goniurosaurus" },
    { "id": "ott665630", "display": "Goniurosaurus araneus" },
    ...
  ]
}'); ?>

<p class="try">
	<a href="<?=$base?>/search?q=Eukaryota" target="_blank">Try exact &rarr;</a>
	&nbsp;·&nbsp;
	<a href="<?=$base?>/search?q=Goniurosaurus&amp;mode=prefix&amp;limit=10" target="_blank">Try prefix &rarr;</a>
</p>

<h2>Source</h2>
<p>
	Reference implementation in PHP, served by
	<code>api/index.php</code> with the per-resource handlers under
	<code>api/handlers/</code>. Source on
	<a href="https://github.com/rdmpage/ott-viewer" target="_blank" rel="noopener">GitHub</a>;
	design rationale in <code>api-design.md</code>.
</p>

</body>
</html>
