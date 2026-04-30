// ─── Trees ──────────────────────────────────────────────────────────────────
// Each tree is the canonical viewer JSON: { focal_id, displayed_root_id,
// nodes: { <id>: {...}, ... }, edges: [...] }. Members of "other_" nodes are
// inline on the node.
//
// t1 / t2 are the "old" and "new" trees driving the current transition.
// loadTrees() is the demo bootstrap (transition.html); browseInit() is the
// interactive bootstrap (index.php), and navigateTo() is what every node
// click flows through — it fetches a new tree and animates to it.

let t1 = null, t2 = null;
let currentTree = null;
let currentK    = 30;

const TREE_API = 'tree.php';

// Counter so concurrent calls (shouldn't happen, but defensive) don't drop
// the loading state prematurely.
let pendingFetches = 0;
function ensureLoadingEl() {
	let el = document.getElementById('loading');
	if (!el) {
		el = document.createElement('div');
		el.id = 'loading';
		document.body.appendChild(el);
	}
	return el;
}

async function fetchTree(taxon, k) {
	ensureLoadingEl();
	if (pendingFetches++ === 0) document.body.classList.add('loading');
	try {
		const r = await fetch(TREE_API + '?taxon=' + encodeURIComponent(taxon) + '&k=' + (k|0));
		return await r.json();
	} finally {
		if (--pendingFetches === 0) document.body.classList.remove('loading');
	}
}

// Demo bootstrap (transition.html): load two specific trees.
async function loadTrees() {
	const [r1, r2] = await Promise.all([
		fetch('tree.php?taxon=ott452461&k=30'),
		fetch('tree.php?taxon=mrcaott18206ott18209&k=30'),
	]);
	t1 = await r1.json();
	t2 = await r2.json();
	init();
}

// Interactive bootstrap (index.php): load a single tree as a degenerate
// transition (old == new) so the viewer renders statically until the user
// clicks something.
async function browseInit(taxon, k) {
	currentK = k || 30;
	const tree = await fetchTree(taxon, currentK);
	currentTree = tree;
	t1 = tree;
	t2 = tree;
	init();
	afterNavigationLanded(tree);
}

// Click-to-navigate: fetch the tree rooted on the clicked node, build the
// transition between the current tree and the new one, animate to it.
async function navigateTo(taxon, addToHistory) {
	if (animId) return;          // ignore clicks while a transition is playing
	if (taxon == null) return;
	if (addToHistory === undefined) addToHistory = true;

	let newTree;
	try {
		newTree = await fetchTree(taxon, currentK);
	} catch (e) {
		console.error('navigateTo: fetch failed', e);
		return;
	}

	if (addToHistory) {
		const url = new URL(window.location);
		url.searchParams.set('taxon', taxon);
		history.pushState({ taxon }, '', url.toString());
	}

	closePeek();                 // any open peek belongs to the previous tree
	t1 = currentTree;
	t2 = newTree;
	scene = buildScene(t1, t2);
	fitViewBox(scene);
	capSizesForViewport();
	currentT = 0;
	setT(0);
	currentTree = newTree;
	afterNavigationLanded(newTree);
	playAnim(1);
}

window.addEventListener('popstate', () => {
	const params = new URLSearchParams(window.location.search);
	const taxon = params.get('taxon');
	if (taxon) navigateTo(taxon, false);
});

// Hard "jump to" — replace the displayed tree with a fresh layout for the
// new taxon, no transition. Used by the search dropdown so picking an
// unrelated taxon shows a fully-formed tree from t=0 instead of trying to
// animate between two unrelated layouts. Resets stableYScale so the fit
// is recomputed for the new tree's geometry.
async function replaceTree(taxon, addToHistory) {
	if (animId) { cancelAnimationFrame(animId); animId = null; }
	if (taxon == null) return;
	if (addToHistory === undefined) addToHistory = true;

	let newTree;
	try {
		newTree = await fetchTree(taxon, currentK);
	} catch (e) {
		console.error('replaceTree: fetch failed', e);
		return;
	}

	if (addToHistory) {
		const url = new URL(window.location);
		url.searchParams.set('taxon', taxon);
		history.pushState({ taxon }, '', url.toString());
	}

	closePeek();
	stableYScale = null;        // refit for the new tree
	currentTree = newTree;
	t1 = newTree;
	t2 = newTree;               // degenerate transition: just renders newTree
	init();
	afterNavigationLanded(newTree);
}

// ─── Right-hand info panel + breadcrumb trail ───────────────────────────────
// Panel: openInfoPanel(html) replaces contents and shows; closeInfoPanel()
// hides. Breadcrumb: a sequential list of every focal the user has landed
// on this session, deduplicated against the previous entry. Both are
// driven by afterNavigationLanded(), called once per navigation (initial
// load, click navigation, and back/forward).
function openInfoPanel(html) {
	const panel   = document.getElementById('info-panel');
	const content = document.getElementById('info-content');
	if (!panel || !content) return;
	if (typeof html === 'string') content.innerHTML = html;
	panel.classList.add('open');
}

function closeInfoPanel() {
	const panel = document.getElementById('info-panel');
	if (panel) panel.classList.remove('open');
}

// Show the info panel for an arbitrary node (used by single-click). Closes
// any open peek so the two overlays don't fight for the user's attention.
// On a double-click, this fires twice in quick succession before dblclick
// handles the actual navigation.
function showNodeInfo(node) {
	if (!node) return;
	closePeek();
	openInfoPanel(renderFocalInfo(node));
}

// ─── Taxon search (exact match) ─────────────────────────────────────────────
// Wires the #search-input + #search-results dropdown. Each input change
// fires one fetch to search.php; results render as clickable rows; click
// (or Enter when there's a single hit) navigates the tree to that taxon.
// Stale-response guard ignores a response whose query no longer matches
// the current input value.
function setupSearch() {
	const input   = document.getElementById('search-input');
	const results = document.getElementById('search-results');
	if (!input || !results) return;

	let lastQuery = '';

	async function doSearch() {
		const q = input.value.trim();
		if (q === lastQuery) return;
		lastQuery = q;

		if (q === '') {
			results.classList.remove('open');
			results.innerHTML = '';
			return;
		}

		let hits;
		try {
			const r = await fetch('search.php?q=' + encodeURIComponent(q));
			hits = await r.json();
		} catch (e) {
			console.error('search failed', e);
			return;
		}
		if (q !== input.value.trim()) return;   // user kept typing — drop stale

		results.innerHTML = '';
		if (!Array.isArray(hits) || hits.length === 0) {
			const li = document.createElement('li');
			li.className = 'empty';
			li.textContent = 'no exact match for "' + q + '"';
			results.appendChild(li);
		} else {
			hits.forEach(h => {
				const li  = document.createElement('li');
				const lab = document.createElement('span');
				lab.className   = 'label-text';
				lab.textContent = h.label;
				const ext = document.createElement('span');
				ext.className   = 'ext-id';
				ext.textContent = h.external_id;
				li.appendChild(lab);
				li.appendChild(ext);
				li.addEventListener('click', () => pickResult(h));
				results.appendChild(li);
			});
		}
		results.classList.add('open');
	}

	function pickResult(h) {
		results.classList.remove('open');
		input.value = h.label;
		lastQuery = h.label;
		// Use replaceTree (not navigateTo) so the search jump is a clean
		// reset to the fully-formed new tree rather than a transition
		// between two potentially-unrelated layouts.
		replaceTree(h.external_id);
	}

	input.addEventListener('input', doSearch);
	input.addEventListener('keydown', (ev) => {
		if (ev.key === 'Enter') {
			ev.preventDefault();
			const first = results.querySelector('li:not(.empty)');
			if (first) first.click();
		} else if (ev.key === 'Escape') {
			results.classList.remove('open');
			input.blur();
		}
	});

	// Close dropdown on click outside the search bar.
	document.addEventListener('click', (ev) => {
		if (!ev.target.closest('#search-bar')) results.classList.remove('open');
	});
}

setupSearch();

const navigationTrail = [];

function afterNavigationLanded(tree) {
	if (!tree || !tree.focal_id) return;
	const focal = tree.nodes && tree.nodes[tree.focal_id];
	if (!focal) return;

	pushTrail(focal);
	renderTrail();
	openInfoPanel(renderFocalInfo(focal));
}

function pushTrail(focal) {
	const last = navigationTrail[navigationTrail.length - 1];
	if (last && last.id === focal.id) return;          // dedupe consecutive
	navigationTrail.push({ id: focal.id, display: focal.display });
	if (navigationTrail.length > 30) navigationTrail.shift();   // soft cap
}

function renderTrail() {
	const c = document.getElementById('hoptree-container');
	if (!c) return;
	if (navigationTrail.length === 0) {
		c.classList.add('empty');
		c.textContent = '(no history yet)';
		return;
	}
	c.classList.remove('empty');
	c.innerHTML = '';
	navigationTrail.forEach((entry, i) => {
		const isCurrent = (i === navigationTrail.length - 1);
		const a = document.createElement('a');
		a.className = isCurrent ? 'crumb current' : 'crumb';
		a.textContent = entry.display;
		a.title = entry.id;
		if (!isCurrent) {
			a.href = '#';
			a.addEventListener('click', (ev) => {
				ev.preventDefault();
				navigateTo(entry.id);
			});
		}
		c.appendChild(a);
		if (i < navigationTrail.length - 1) {
			const sep = document.createElement('span');
			sep.className = 'crumb-sep';
			sep.textContent = '›';   // ›
			c.appendChild(sep);
		}
	});
}

function renderFocalInfo(focal) {
	const id        = focal.id || '';
	const display   = focal.display || id;
	const weight    = focal.weight;
	const isOttTaxon = /^ott\d+$/.test(id);
	const ottHref   = isOttTaxon
		? 'https://tree.opentreeoflife.org/opentree/argus/ottol@' + id.slice(3)
		: null;

	const parts = [];
	parts.push('<h3>' + escapeHtml(display) + '</h3>');
	parts.push('<p><span class="key">id</span>' + escapeHtml(id));
	if (ottHref) {
		parts.push(' <a href="' + ottHref + '" target="_blank" rel="noopener">view on OTT &uarr;</a>');
	}
	parts.push('</p>');
	if (weight != null) {
		parts.push('<p><span class="key">weight</span>' + weight + ' descendant ' + (weight === 1 ? 'tip' : 'tips') + '</p>');
	}
	if ('supertree_leaf' in focal) {
		parts.push('<p><span class="key">kind</span>' + (focal.supertree_leaf ? 'supertree leaf' : 'internal node') + '</p>');
	}
	parts.push(renderAnnotations(focal.annotations));
	return parts.join('');
}

// Render the five OTT annotation relations as collapsible sections, one per
// non-empty relation. Each list item links to the study's curator page.
// Order favours the user's likely interest: support / conflict first, then
// the more nuanced relations.
function renderAnnotations(ann) {
	if (!ann) return '';

	const order = [
		['supported_by',    'supported by'],
		['conflicts_with',  'conflicts with'],
		['resolves',        'resolves'],
		['partial_path_of', 'partial path of'],
		['terminal',        'terminal'],
	];

	const sections = [];
	order.forEach(([key, label]) => {
		const list = ann[key];
		if (!Array.isArray(list) || list.length === 0) return;
		const distinct = new Set();
		list.forEach(t => distinct.add(String(t).split('@')[0]));
		const openByDefault = (key === 'supported_by' || key === 'conflicts_with');
		const items = list.map(renderStudyTree).join('');
		sections.push(
			'<details class="ann-section"' + (openByDefault ? ' open' : '') + '>' +
				'<summary>' + escapeHtml(label) + ' (' + distinct.size + ')</summary>' +
				'<ul class="ann-list">' + items + '</ul>' +
			'</details>'
		);
	});

	if (sections.length === 0) {
		return '<p class="ann-empty">no source-tree annotations</p>';
	}
	return '<div class="annotations">' + sections.join('') + '</div>';
}

// Render one study_tree id ("ot_123@tree4") as a list item linking to the
// study's page on tree.opentreeoflife.org. The "@treeN" suffix is shown as
// a small dimmed tag so the user can tell which tree within the study.
function renderStudyTree(studyTree) {
	const s   = String(studyTree);
	const at  = s.indexOf('@');
	const sid = at > 0 ? s.slice(0, at) : s;
	const tid = at > 0 ? s.slice(at + 1) : '';
	const url = 'https://tree.opentreeoflife.org/curator/study/view/' + encodeURIComponent(sid);
	const treeTag = tid ? ' <span class="tree-id">' + escapeHtml(tid) + '</span>' : '';
	return '<li><a href="' + url + '" target="_blank" rel="noopener">' + escapeHtml(sid) + '</a>' + treeTag + '</li>';
}

function escapeHtml(s) {
	return String(s)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;');
}

// ─── Build Transition Scene ─────────────────────────────────────────────────

// yScale is computed lazily on the first buildScene call and reused for
// every subsequent transition. If we recomputed it each time, the new
// tree's labels or y-range could nudge yScale by a few percent — and any
// node persisting between trees would render at a slightly different
// y-position than it had a frame ago, producing a visible flicker right
// before the transition starts. Reusing the first value pins persisters
// in place, at the cost of slightly less optimal fit if a later tree is
// very differently shaped.
let stableYScale = null;

function buildScene(oldTree, newTree) {
	// Coordinates come in a 100x100 grid from coordinates.php; the natural
	// content aspect (treeW + labelMargin) : treeH is usually ~3:1, much
	// wider than a typical browser. Stretch y so the viewBox aspect matches
	// the SVG element's pixel aspect — that's the "fit the browser window"
	// step. SVG circles stay circular because we're scaling within coord
	// space, not via preserveAspectRatio.
	let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
	let longestLabel = 0;
	[oldTree, newTree].forEach(tree => {
		Object.values(tree.nodes).forEach(n => {
			if (n.x < minX) minX = n.x;
			if (n.x > maxX) maxX = n.x;
			if (n.y < minY) minY = n.y;
			if (n.y > maxY) maxY = n.y;
			if (n.display.length > longestLabel) longestLabel = n.display.length;
		});
	});
	const charW       = STYLE.labelFontSize * 0.5;
	const labelMargin = longestLabel * charW;
	const treeW       = maxX - minX;
	const treeH       = maxY - minY;
	const totalW      = treeW + labelMargin;

	const px            = svg.clientWidth  || 1000;
	const py            = svg.clientHeight || 600;
	const browserAspect = px / py;

	// Stretch y up to fill, never compress (compression makes tip rows
	// unreadable). yScale=1 keeps the source 100×100 grid intact. Cached
	// on the first call so subsequent transitions don't shift persisters.
	let yScale;
	if (stableYScale !== null) {
		yScale = stableYScale;
	} else {
		yScale = 1;
		if (treeH > 0) {
			yScale = Math.max(1, (totalW / browserAspect) / treeH);
		}
		stableYScale = yScale;
	}

	const oldById = {};
	Object.values(oldTree.nodes).forEach(n => { oldById[n.id] = { ...n, y: n.y * yScale }; });
	const newById = {};
	Object.values(newTree.nodes).forEach(n => { newById[n.id] = { ...n, y: n.y * yScale }; });

	// Parent lookup (child → parent id)
	const oldParent = {};
	oldTree.edges.forEach(e => { oldParent[e.target] = e.source; });
	const newParent = {};
	newTree.edges.forEach(e => { newParent[e.target] = e.source; });

	// Find nearest persistent ancestor for an exit node — returns anchor's NEW pos.
	// If no persistent ancestor exists, the node slides off to the left (toward
	// the root side of the cladogram) at its own vertical position.
	function exitAnchor(id) {
		let cur = oldParent[id];
		while (cur) {
			if (cur in newById) return { x: newById[cur].x, y: newById[cur].y };
			cur = oldParent[cur];
		}
		return { x: 0, y: oldById[id].y };
	}

	// Find nearest persistent ancestor for an enter node — returns anchor's OLD pos.
	// If no persistent ancestor exists, the node grows in from the left edge.
	function enterAnchor(id) {
		let cur = newParent[id];
		while (cur) {
			if (cur in oldById) return { x: oldById[cur].x, y: oldById[cur].y };
			cur = newParent[cur];
		}
		return { x: 0, y: newById[id].y };
	}

	// ── Nodes ──
	const allIds = new Set([
		...Object.keys(oldTree.nodes),
		...Object.keys(newTree.nodes)
	]);

	const nodes = [];
	allIds.forEach(id => {
		const inOld = id in oldById;
		const inNew = id in newById;
		// Carry over all fields (display, type, supertree_leaf, weight,
		// annotations, members, ...) from the source node so render() can
		// read them without touching t1 / t2 again. Use the new-tree side
		// for persist nodes so the rendered state matches the destination.
		const src = inNew ? newById[id] : oldById[id];

		let kind, from, to, fromOpacity, toOpacity;
		if (inOld && inNew) {
			kind = 'persist';
			from = { x: oldById[id].x, y: oldById[id].y };
			to   = { x: newById[id].x, y: newById[id].y };
			fromOpacity = 1; toOpacity = 1;
		} else if (inOld) {
			kind = 'exit';
			from = { x: oldById[id].x, y: oldById[id].y };
			to   = exitAnchor(id);
			fromOpacity = 1; toOpacity = 0;
		} else {
			kind = 'enter';
			from = enterAnchor(id);
			to   = { x: newById[id].x, y: newById[id].y };
			fromOpacity = 0; toOpacity = 1;
		}

		nodes.push({
			...src,
			kind,
			from, to,
			current: { x: from.x, y: from.y },
			fromOpacity, toOpacity,
			currentOpacity: fromOpacity
		});
	});

	// ── Tip vs internal classification ──
	// A node is a tip if it doesn't appear as a source (parent) in its tree.
	// For persist nodes we record both sides — a collapsed subtree root that
	// gets expanded is a tip in one tree and internal in the other, so the
	// renderer picks the classification matching the current animation phase.
	const oldInternal = new Set();
	oldTree.edges.forEach(e => { oldInternal.add(e.source); });
	const newInternal = new Set();
	newTree.edges.forEach(e => { newInternal.add(e.source); });
	nodes.forEach(n => {
		n.isTipOld = (n.id in oldById) && !oldInternal.has(n.id);
		n.isTipNew = (n.id in newById) && !newInternal.has(n.id);
	});

	// ── Edges ──
	const edgeKey = e => e.source + '->' + e.target;
	const oldEdgeMap = {};
	oldTree.edges.forEach(e => { oldEdgeMap[edgeKey(e)] = e; });
	const newEdgeMap = {};
	newTree.edges.forEach(e => { newEdgeMap[edgeKey(e)] = e; });

	const allEdgeKeys = new Set([...Object.keys(oldEdgeMap), ...Object.keys(newEdgeMap)]);
	const edges = [];
	allEdgeKeys.forEach(key => {
		const inOld = key in oldEdgeMap;
		const inNew = key in newEdgeMap;
		const e = inNew ? newEdgeMap[key] : oldEdgeMap[key];
		let kind;
		if (inOld && inNew) kind = 'persist';
		else if (inOld)     kind = 'exit';
		else                kind = 'enter';
		edges.push({ source: e.source, target: e.target, kind });
	});

	// Smallest x-step across all parent→child edges in either tree. Used as
	// the annotation slot WIDTH so the support / conflict numbers occupy a
	// consistent position next to every node, even where the actual incoming
	// edge is much longer.
	let minStepX = Infinity;
	[[oldTree, oldById], [newTree, newById]].forEach(([tree, byId]) => {
		tree.edges.forEach(e => {
			const src = byId[e.source];
			const tgt = byId[e.target];
			if (src && tgt) {
				const dx = Math.abs(tgt.x - src.x);
				if (dx > 0.001 && dx < minStepX) minStepX = dx;
			}
		});
	});
	if (minStepX === Infinity) minStepX = STYLE.labelFontSize;

	// Smallest y-step between adjacent TIP rows in either tree (leaves and
	// other_ placeholders — the things drawn at the right edge). Internal
	// nodes are positioned at midpoints of their children, so including
	// them would give a much smaller minimum and shrink the annotation
	// slot below text-readable size. Computed per tree, then we take the
	// smaller of the two so the slot is stable across the animation.
	function tipMinStepY(byId) {
		const ys = [];
		Object.values(byId).forEach(n => {
			if (n.type === 'leaf' || n.type === 'other') ys.push(n.y);
		});
		ys.sort((a, b) => a - b);
		let m = Infinity;
		for (let i = 1; i < ys.length; i++) {
			const dy = ys[i] - ys[i - 1];
			if (dy > 0.001 && dy < m) m = dy;
		}
		return m;
	}
	let minStepY = Math.min(tipMinStepY(oldById), tipMinStepY(newById));
	if (!isFinite(minStepY)) minStepY = STYLE.labelFontSize;

	return { nodes, edges, minStepX, minStepY };
}

// ─── Interpolation ──────────────────────────────────────────────────────────

function lerp(a, b, t) { return a + (b - a) * t; }

// Ease-in-out (cubic)
function ease(t) {
	return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function updateScene(scene, t) {
	const et = ease(t);
	scene.nodes.forEach(n => {
		n.current.x = lerp(n.from.x, n.to.x, et);
		n.current.y = lerp(n.from.y, n.to.y, et);
		n.currentOpacity = lerp(n.fromOpacity, n.toOpacity, et);
	});
}

// ─── SVG Rendering ──────────────────────────────────────────────────────────

const svg = document.getElementById('canvas');
const NS = 'http://www.w3.org/2000/svg';

// ─── Style constants ────────────────────────────────────────────────────────
// Node-label font size is the base; other sizes + offsets are derived from it
// so scaling the labels scales the annotations and their placement in sync.
// Positions are in the node <g>'s local coord space (origin at the node circle).
const LABEL_FONT    = 7;     // tree tip / internal labels (user-space units)
const NODE_R        = 2.5;   // node circle radius (user-space units)
const EDGE_STROKE   = 1.5;   // tree-edge stroke width
const HOLLOW_STROKE = 1;     // hollow-circle outline + peek-edge stroke
const STYLE = {
	labelFontSize: LABEL_FONT,
	annotFontSize: LABEL_FONT - 0.5,        // slightly smaller than labels
	circleR:       NODE_R,
	edgeStrokeWidth:   EDGE_STROKE,
	hollowStrokeWidth: HOLLOW_STROKE,
	labelDx:       NODE_R + 2.5,            // label x: past circle + small gap
	labelDy:       LABEL_FONT * 0.30,       // label baseline offset: vertical centering on circle
	// Annotations occupy two boxes flanking the incoming edge line, both
	// anchored to it: upper box bottom = line, lower box top = line. The
	// slot's right edge is at the node, the width = scene.minStepX, the
	// height = scene.minStepY. Numbers are placed at each box's centre.
	annotSlotRightDx: 0,
};

function clearSVG() {
	while (svg.firstChild) svg.removeChild(svg.firstChild);
}

// Reduce a node's annotation lists (study_tree ids like "ot_123@tree1") to
// distinct-study counts. Study id = the part before "@". Only the two
// counts shown on the tree are extracted here — the more nuanced relations
// (terminal, resolves, partial_path_of) live on the JSON and surface in a
// future per-node info panel. Returns null when the node has neither
// support nor conflict, so the renderer can hide cleanly.
function nodeAnnotation(n) {
	if (!n || !n.annotations) return null;
	const distinct = list => {
		const s = new Set();
		(list || []).forEach(t => s.add(t.split('@')[0]));
		return s.size;
	};
	const supported = distinct(n.annotations.supported_by);
	const conflicts = distinct(n.annotations.conflicts_with);
	if (supported + conflicts === 0) return null;
	return { supported, conflicts };
}

// Set to true to colour-code persist / exit / enter during transitions
// (useful when debugging the animation logic). Default off so the viewer
// renders as a single uniform tree.
const SHOW_TRANSITION_COLORS = false;

// Default returns 'currentColor', which means SVG fill / stroke inherit
// from the SVG element's `color` CSS property (set in viewer.css to
// var(--tree-stroke)). That way light/dark theme switches automatically
// propagate to every node circle, edge, label, and triangle.
function kindColor(kind) {
	if (!SHOW_TRANSITION_COLORS) return 'currentColor';
	if (kind === 'exit')  return '#c33';
	if (kind === 'enter') return '#36a';
	return 'currentColor';
}

function render(scene) {
	clearSVG();
	const nodeById = {};
	scene.nodes.forEach(n => { nodeById[n.id] = n; });

	// Edges first (drawn behind nodes)
	scene.edges.forEach(e => {
		const src = nodeById[e.source];
		const tgt = nodeById[e.target];
		if (!src || !tgt) return;

		const opacity = Math.min(src.currentOpacity, tgt.currentOpacity);
		if (opacity < 0.01) return;

		// Cladogram L-shape: vertical at parent x, then horizontal to child
		const path = document.createElementNS(NS, 'path');
		const d = `M ${src.current.x},${src.current.y}`
			+ ` L ${src.current.x},${tgt.current.y}`
			+ ` L ${tgt.current.x},${tgt.current.y}`;
		path.setAttribute('d', d);
		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', kindColor(e.kind));
		path.setAttribute('stroke-width', STYLE.edgeStrokeWidth);
		path.setAttribute('opacity', opacity);
		svg.appendChild(path);
	});

	// Nodes
	scene.nodes.forEach(n => {
		if (n.currentOpacity < 0.01) return;

		const g = document.createElementNS(NS, 'g');
		g.setAttribute('transform', `translate(${n.current.x},${n.current.y})`);
		g.setAttribute('opacity', n.currentOpacity);
		g.setAttribute('data-node-id', n.id);

		// Pick tip/internal status for the current animation phase: before the
		// midpoint use the old tree's classification, after use the new tree's.
		// For exit/enter nodes only one side is defined.
		let isTip;
		if (n.kind === 'exit')       isTip = n.isTipOld;
		else if (n.kind === 'enter') isTip = n.isTipNew;
		else                         isTip = currentT < 0.5 ? n.isTipOld : n.isTipNew;

		// Marker rule (three symbols):
		//   solid circle   — internal in this view, OR a supertree leaf
		//                    (i.e. genuinely "fully resolved at this point")
		//   hollow circle  — other_* placeholder (collapsed siblings group)
		//   right triangle — tip in this view that's actually an internal
		//                    node in the supertree (collapsed subtree root —
		//                    the apex points right toward the descendants
		//                    that aren't being shown)
		const looksMrca = n.id.startsWith('mrca') || n.id.startsWith('other_mrca');
		const isOther   = n.id.startsWith('other_');
		const isSupertreeLeaf = ('supertree_leaf' in n) ? !!n.supertree_leaf : !looksMrca;

		let marker;
		if (isTip && isOther) {
			// Hollow circle. Fill / stroke come from .tree-node.is-hollow
			// in CSS so they track the theme's background and tree-stroke
			// vars automatically.
			marker = document.createElementNS(NS, 'circle');
			marker.setAttribute('r', STYLE.circleR);
			marker.setAttribute('stroke-width', STYLE.hollowStrokeWidth);
			marker.setAttribute('class', 'tree-node is-hollow');
		} else if (isTip && !isSupertreeLeaf) {
			// Left-pointing triangle. Base aligned to the right with the
			// other tip markers; apex points into the tree, so the marker
			// reads as "stand-in for what's hidden behind here" rather
			// than sticking a tail out past the tip column.
			marker = document.createElementNS(NS, 'polygon');
			const r = STYLE.circleR;
			marker.setAttribute('points',
				r    + ',' + (-r) + ' ' +
				r    + ',' + r    + ' ' +
				(-r) + ',0');
			marker.setAttribute('fill', kindColor(n.kind));
			marker.setAttribute('class', 'tree-node');
		} else {
			// Solid circle (internal in view, or supertree leaf).
			marker = document.createElementNS(NS, 'circle');
			marker.setAttribute('r', STYLE.circleR);
			marker.setAttribute('fill', kindColor(n.kind));
			marker.setAttribute('class', 'tree-node');
		}
		g.appendChild(marker);

		// Hover halo — a concentric ring, fades in via CSS when .tree-node
		// is hovered. Appended AFTER .tree-node so the
		// `.tree-node:hover ~ .tree-node-halo` selector matches. The halo
		// stays a circle even when the marker is a triangle — it's just an
		// "interactive area" indicator, not a shape match.
		const halo = document.createElementNS(NS, 'circle');
		halo.setAttribute('class', 'tree-node-halo');
		halo.setAttribute('r', STYLE.circleR * 1.6);
		halo.setAttribute('stroke-width', STYLE.edgeStrokeWidth);
		g.appendChild(halo);

		// Show labels for all tips; hide mrca labels on internal nodes only.
		// Also hide the other_<...> label while its peek is open — the peek
		// itself already shows what's inside, so the "(N)" suffix would be
		// redundant and the label would collide with the peek's lead line.
		const peekOpenForThisNode = peekState && peekState.nodeId === n.id;
		if ((isTip || !looksMrca) && !peekOpenForThisNode) {
			const text = document.createElementNS(NS, 'text');
			text.setAttribute('x', STYLE.labelDx);
			text.setAttribute('y', STYLE.labelDy);
			text.setAttribute('font-size', STYLE.labelFontSize);
			text.setAttribute('class', 'label');
			text.setAttribute('data-node-id', n.id);
			text.setAttribute('fill', kindColor(n.kind));
			// Append a (count) suffix to communicate "more tips hidden here":
			//   * other_ summary nodes: count of collapsed siblings (members)
			//   * any tip in this view that's actually an internal in the
			//     supertree: descendant-tip count (weight)
			// Both are drawn as hollow circles, so the count completes the
			// "this is a stand-in for many" affordance.
			let display = n.display;
			if (n.id.startsWith('other_')) {
				const members = peekMembers(n.id);
				if (members) display = display + ' (' + members.length + ')';
			} else if (isTip && !isSupertreeLeaf && (n.weight | 0) > 1) {
				display = display + ' (' + (n.weight | 0) + ')';
			}
			text.textContent = display;
			g.appendChild(text);
		}

		// Annotation numbers (support + conflict) on internal, non-other_,
		// non-stub nodes. Stubs are rendered as tip-shaped upstream context
		// only, so their own evidence isn't relevant to this view.
		if (!isTip && !n.id.startsWith('other_') && n.type !== 'stub') {
			const a = nodeAnnotation(n);
			if (a) {
				const slotCenterX = STYLE.annotSlotRightDx - scene.minStepX / 2;
				const halfStepY   = scene.minStepY / 2;
				if (a.supported > 0) {
					const up = document.createElementNS(NS, 'text');
					up.setAttribute('class', 'annot-support');
					up.setAttribute('x', slotCenterX);
					up.setAttribute('y', -halfStepY);
					up.setAttribute('font-size', STYLE.annotFontSize);
					up.setAttribute('text-anchor', 'middle');
					up.setAttribute('dominant-baseline', 'central');
					up.textContent = a.supported;
					g.appendChild(up);
				}
				if (a.conflicts > 0) {
					const dn = document.createElementNS(NS, 'text');
					dn.setAttribute('class', 'annot-conflict');
					dn.setAttribute('x', slotCenterX);
					dn.setAttribute('y', halfStepY);
					dn.setAttribute('font-size', STYLE.annotFontSize);
					dn.setAttribute('text-anchor', 'middle');
					dn.setAttribute('dominant-baseline', 'central');
					dn.textContent = a.conflicts;
					g.appendChild(dn);
				}
			}
		}

		// Click handlers live on the marker itself (whichever shape it is)
		// so the active hit area is just the node symbol — clicking a
		// label or annotation does nothing.
		//
		// Web-map interaction model:
		//   * single click  = show info for the clicked node
		//   * double click  = navigate (re-focus the tree on that node)
		//   * other_ nodes single-click toggles the peek (members list)
		//
		// On a true double-click the browser fires two click events first
		// and then dblclick — so single click flashes the info panel for
		// the clicked node, then dblclick supersedes by navigating, after
		// which afterNavigationLanded() repopulates the panel with the
		// (same) new focal's info. No setTimeout and no perceived lag on
		// the single-click path.
		if (isOther) {
			g.setAttribute('data-peekable', '1');
			marker.addEventListener('click', (ev) => {
				ev.stopPropagation();
				if (peekState && peekState.nodeId === n.id) {
					closePeek();
				} else {
					openPeek(n.id);
				}
			});
		} else {
			marker.addEventListener('click', (ev) => {
				ev.stopPropagation();
				showNodeInfo(n);
			});
			marker.addEventListener('dblclick', (ev) => {
				ev.stopPropagation();
				navigateTo(n.id);
			});
		}

		svg.appendChild(g);
	});

	// Peek overlay is drawn last so it sits on top of the tree.
	if (peekState) renderPeek();
}

// ─── Peek overlay (SVG polytomy) ────────────────────────────────────────────

// Open state: anchor node id + its members + when the peek was opened (used
// to drive the accordion-style expansion animation). Anchor position is
// re-looked-up every frame so the overlay tracks the node during tree motion.
let peekState    = null;
let peekOpenedAt = 0;
let peekAnimId   = null;
let peekScroll   = 0;        // first visible member index when the list is windowed
const PEEK_DURATION = 260;   // ms for the accordion expansion

// Pick the members list for a node id, looked up directly on the node object
// in whichever tree contains it. Prefers the side matching the current
// animation phase; falls back to the other side so enter/exit nodes still peek.
function peekMembers(nodeId) {
	const primary   = currentT < 0.5 ? t1 : t2;
	const secondary = currentT < 0.5 ? t2 : t1;
	const fromPrimary   = primary   && primary.nodes   && primary.nodes[nodeId];
	if (fromPrimary   && fromPrimary.members)   return fromPrimary.members;
	const fromSecondary = secondary && secondary.nodes && secondary.nodes[nodeId];
	if (fromSecondary && fromSecondary.members) return fromSecondary.members;
	return null;
}

function openPeek(nodeId) {
	const members = peekMembers(nodeId);
	if (!members || !members.length) return;
	peekState    = { nodeId, members };
	peekOpenedAt = performance.now();
	peekScroll   = 0;
	if (peekAnimId) cancelAnimationFrame(peekAnimId);
	// Refresh the tree so the other_<...> label is hidden for the now-open
	// node (and shown again for any previously-open one). render() will
	// also draw the initial peek frame at t=0.
	if (scene) render(scene);
	peekTick();
}

function closePeek() {
	peekState = null;
	if (peekAnimId) { cancelAnimationFrame(peekAnimId); peekAnimId = null; }
	const existing = document.getElementById('peek-overlay');
	if (existing) existing.remove();
	// Refresh the tree so the previously-hidden other_<...> label reappears.
	if (scene) render(scene);
}

// Drive the expansion animation. Tree's main render() also calls renderPeek,
// so when the tree is animating the peek stays synced; this ticker keeps it
// alive while the tree is idle.
function peekTick() {
	renderPeek();
	if (!peekState) return;
	const elapsed = performance.now() - peekOpenedAt;
	if (elapsed < PEEK_DURATION) {
		peekAnimId = requestAnimationFrame(peekTick);
	} else {
		peekAnimId = null;
	}
}

// Draw (or redraw) the peek as an SVG group layered on top of the tree.
// Items slide out from the anchor over PEEK_DURATION ms with an ease-out
// cubic. When the full member list would be taller than ~70% of the
// viewBox, we render only a windowed slice: peekScroll is the index of
// the first visible member, the wheel handler shifts it, and small
// chevrons at the top / bottom of the visible band signal that there are
// more rows in either direction.
function renderPeek() {
	if (!peekState) return;
	const { nodeId, members } = peekState;

	const anchor = scene && scene.nodes.find(n => n.id === nodeId);
	if (!anchor || anchor.currentOpacity < 0.01) { closePeek(); return; }

	// Remove any stale overlay and re-render in full.
	const stale = document.getElementById('peek-overlay');
	if (stale) stale.remove();

	// Animation progress: 0 on open, 1 when fully expanded.
	const raw   = Math.min(1, (performance.now() - peekOpenedAt) / PEEK_DURATION);
	const t     = 1 - Math.pow(1 - raw, 3);  // easeOutCubic
	const lerp  = (a, b) => a + (b - a) * t;

	// Layout constants — derived from the viewport-capped font / circle so
	// the peek matches the tree at any zoom level.
	const font         = STYLE.labelFontSize;
	const rowH         = font * 1.4;
	const branchDx     = font;
	const circleR      = STYLE.circleR;
	const circleToText = font * 0.6;
	const padX         = font * 0.5;
	const padY         = font * 0.3;
	const charW        = font * 0.5;

	const trunkX = anchor.current.x + circleR + branchDx;

	// Windowing: how many rows fit into the height budget?
	const vb           = svg.viewBox.baseVal;
	const maxRowsByVB  = Math.max(2, Math.floor((vb.height * 0.70) / rowH));
	const totalRows    = members.length;
	const visibleRows  = Math.min(totalRows, maxRowsByVB);
	const windowed     = visibleRows < totalRows;

	// Clamp scroll offset.
	if (peekScroll < 0) peekScroll = 0;
	if (peekScroll > totalRows - visibleRows) peekScroll = totalRows - visibleRows;

	// Target geometry of the visible band.
	const totalH    = Math.max(0, (visibleRows - 1) * rowH);
	let topTarget   = anchor.current.y - totalH / 2;
	const minY      = vb.y + padY * 2;
	const maxYClamp = vb.y + vb.height - padY * 2 - totalH;
	if (topTarget < minY)      topTarget = minY;
	if (topTarget > maxYClamp) topTarget = maxYClamp;

	const textX = trunkX + branchDx + circleR + circleToText;

	// Backdrop width based on the longest *visible* label (the windowed
	// case might exclude some long labels, but the difference is minor).
	const longest = members.reduce((acc, m) => Math.max(acc, m.display.length), 0);
	const backW   = branchDx + circleR + circleToText + (longest * charW) + padX * 2;

	const g = document.createElementNS(NS, 'g');
	g.setAttribute('id', 'peek-overlay');
	g.setAttribute('opacity', t);

	// Backdrop.
	const backTop    = lerp(anchor.current.y - rowH / 2, topTarget - rowH / 2 - padY);
	const backHeight = lerp(rowH, totalH + padY * 2 + rowH);
	const back = document.createElementNS(NS, 'rect');
	back.setAttribute('class', 'peek-backdrop');
	back.setAttribute('x', trunkX - padX);
	back.setAttribute('y', backTop);
	back.setAttribute('width', backW);
	back.setAttribute('height', backHeight);
	back.setAttribute('rx', '2');
	g.appendChild(back);

	// Lead from the node circle to the trunk.
	const lead = document.createElementNS(NS, 'line');
	lead.setAttribute('class', 'peek-edge');
	lead.setAttribute('x1', anchor.current.x + circleR);
	lead.setAttribute('y1', anchor.current.y);
	lead.setAttribute('x2', trunkX);
	lead.setAttribute('y2', anchor.current.y);
	g.appendChild(lead);

	// Trunk: vertical line spanning the visible rows.
	if (visibleRows > 1) {
		const trunk = document.createElementNS(NS, 'line');
		trunk.setAttribute('class', 'peek-edge');
		trunk.setAttribute('x1', trunkX);
		trunk.setAttribute('y1', lerp(anchor.current.y, topTarget));
		trunk.setAttribute('x2', trunkX);
		trunk.setAttribute('y2', lerp(anchor.current.y, topTarget + totalH));
		g.appendChild(trunk);
	}

	// Render only the visible window of members.
	for (let row = 0; row < visibleRows; row++) {
		const i        = peekScroll + row;
		const m        = members[i];
		const yTarget  = topTarget + row * rowH;
		const y        = lerp(anchor.current.y, yTarget);

		// Branch.
		const branch = document.createElementNS(NS, 'line');
		branch.setAttribute('class', 'peek-edge');
		branch.setAttribute('x1', trunkX);
		branch.setAttribute('y1', y);
		branch.setAttribute('x2', trunkX + branchDx);
		branch.setAttribute('y2', y);
		g.appendChild(branch);

		// Tip circle.
		const circle = document.createElementNS(NS, 'circle');
		const isLeaf = ('supertree_leaf' in m) ? !!m.supertree_leaf : ((m.weight || 0) <= 1);
		circle.setAttribute('class', isLeaf ? 'peek-node-solid' : 'peek-node-hollow');
		circle.setAttribute('cx', trunkX + branchDx + circleR);
		circle.setAttribute('cy', y);
		circle.setAttribute('r',  circleR);
		g.appendChild(circle);

		// Hit rect.
		const hit = document.createElementNS(NS, 'rect');
		hit.setAttribute('class', 'peek-hit');
		hit.setAttribute('x', textX);
		hit.setAttribute('y', y - rowH / 2);
		hit.setAttribute('width',  backW - branchDx - circleR - padX);
		hit.setAttribute('height', rowH);
		hit.addEventListener('click', (ev) => {
			ev.stopPropagation();
			closePeek();
			navigateTo(m.id);
		});
		g.appendChild(hit);

		// Label.
		const text = document.createElementNS(NS, 'text');
		text.setAttribute('class', 'peek-label');
		text.setAttribute('font-size', font);
		text.setAttribute('x', textX);
		text.setAttribute('y', y + font * 0.3);
		text.textContent = m.display;
		g.appendChild(text);
	}

	// Scroll-indicator chevrons (windowed mode only).
	if (windowed) {
		const chevronW = font * 0.7;
		const chevronH = font * 0.5;
		const cx = trunkX + branchDx + circleR;
		if (peekScroll > 0) {
			const up = document.createElementNS(NS, 'path');
			const yTop = topTarget - rowH / 2 - padY;
			up.setAttribute('class', 'peek-scroll-indicator');
			up.setAttribute('d',
				'M ' + (cx - chevronW / 2) + ' ' + (yTop + chevronH) +
				' L ' + cx + ' ' + yTop +
				' L ' + (cx + chevronW / 2) + ' ' + (yTop + chevronH) + ' Z');
			g.appendChild(up);
		}
		if (peekScroll + visibleRows < totalRows) {
			const dn = document.createElementNS(NS, 'path');
			const yBot = topTarget + totalH + rowH / 2 + padY;
			dn.setAttribute('class', 'peek-scroll-indicator');
			dn.setAttribute('d',
				'M ' + (cx - chevronW / 2) + ' ' + (yBot - chevronH) +
				' L ' + cx + ' ' + yBot +
				' L ' + (cx + chevronW / 2) + ' ' + (yBot - chevronH) + ' Z');
			g.appendChild(dn);
		}

	}

	svg.appendChild(g);
}

// Wheel scrolling for a windowed peek. Registered once at the SVG level
// because attaching to the peek's <g> isn't reliable in Safari (wheel
// events don't always bubble to inner SVG groups). We compute the
// windowing on the fly from current STYLE / viewBox so the handler stays
// in step with whatever capSizesForViewport last did.
svg.addEventListener('wheel', (ev) => {
	if (!peekState) return;
	if (!ev.target.closest('#peek-overlay')) return;
	const totalRows = peekState.members.length;
	const font      = STYLE.labelFontSize;
	const rowH      = font * 1.4;
	const vb        = svg.viewBox.baseVal;
	const maxRows   = Math.max(2, Math.floor((vb.height * 0.70) / rowH));
	if (maxRows >= totalRows) return;       // list fits, nothing to scroll
	ev.preventDefault();
	const next = peekScroll + (ev.deltaY > 0 ? 1 : -1);
	if (next < 0 || next > totalRows - maxRows) return;
	peekScroll = next;
	renderPeek();
}, { passive: false });

// Dismiss on outside-click or Escape.
document.addEventListener('click', (ev) => {
	if (!peekState) return;
	if (ev.target.closest('#peek-overlay'))     return;
	if (ev.target.closest('g[data-peekable]'))  return;
	closePeek();
});
document.addEventListener('keydown', (ev) => {
	if (ev.key === 'Escape') closePeek();
});

// ─── Animation Controls ─────────────────────────────────────────────────────

let scene = null;
let animId = null;
let currentT = 0;

function init() {
	scene = buildScene(t1, t2);
	fitViewBox(scene);
	capSizesForViewport();
	setT(0);
}

// SVG sizes (font, circle radius, stroke width) are all in user-space
// units, which the browser scales by the viewBox→pixel ratio. On a wide
// laptop screen this scales everything up — fonts past 16 px, lines past
// 4 px, etc. — making the visualization feel chunky. We compute the
// actual user-space-to-pixel scale and cap each size so the rendered
// pixel value never exceeds the targets below. Defaults still apply
// when the viewport is small enough that no cap is needed.
function capSizesForViewport() {
	const vb = svg.viewBox.baseVal;
	if (!vb || vb.width <= 0 || vb.height <= 0) return;
	const px = Math.min(
		svg.clientWidth  / vb.width,
		svg.clientHeight / vb.height
	);
	if (!isFinite(px) || px <= 0) return;

	// Convert a desired pixel ceiling into the equivalent user-space size.
	const userUnits = pxTarget => pxTarget / px;

	STYLE.labelFontSize     = Math.min(LABEL_FONT,    userUnits(16));   // ~12pt
	STYLE.annotFontSize     = Math.max(STYLE.labelFontSize - 0.5, 1);
	STYLE.labelDy           = STYLE.labelFontSize * 0.30;
	STYLE.circleR           = Math.min(NODE_R,        userUnits(6));    // ~12 px diameter
	STYLE.edgeStrokeWidth   = Math.min(EDGE_STROKE,   userUnits(3));
	STYLE.hollowStrokeWidth = Math.min(HOLLOW_STROKE, userUnits(2));

	// Pipe the stroke widths to peek CSS via custom properties — peek
	// elements pick them up through var() in .peek-edge / .peek-node-hollow.
	// Node radius is also exposed so the .tree-node:hover rule can scale
	// the hovered circle relative to the (possibly capped) base radius.
	svg.style.setProperty('--ott-stroke',        STYLE.edgeStrokeWidth);
	svg.style.setProperty('--ott-hollow-stroke', STYLE.hollowStrokeWidth);
	svg.style.setProperty('--ott-node-r',        STYLE.circleR);
}

// Compute viewBox from all positions the scene will ever visit (from + to),
// plus room for the tip labels on the right (width estimated from the
// longest actual label, so the gutter is data-driven, not hardcoded). The
// browser maps this viewBox onto the SVG element's pixel size via
// preserveAspectRatio="xMinYMid meet" (no distortion, may letterbox).
function fitViewBox(scene) {
	let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
	let longest = 0;
	scene.nodes.forEach(n => {
		for (const p of [n.from, n.to]) {
			if (p.x < minX) minX = p.x;
			if (p.y < minY) minY = p.y;
			if (p.x > maxX) maxX = p.x;
			if (p.y > maxY) maxY = p.y;
		}
		if (n.display.length > longest) longest = n.display.length;
	});
	const charW       = STYLE.labelFontSize * 0.5;
	const labelMargin = longest * charW;
	// Padding around the whole drawing equal to one label-line height — keeps
	// the topmost / bottommost tip labels and annotation numbers from butting
	// up against the SVG border.
	const margin      = STYLE.labelFontSize;
	const vx = minX - margin;
	const vy = minY - margin;
	const vw = (maxX - minX) + labelMargin + margin * 2;
	const vh = (maxY - minY) + margin * 2;
	svg.setAttribute('viewBox', `${vx} ${vy} ${vw} ${vh}`);
}

function setT(t) {
	currentT = Math.max(0, Math.min(1, t));
	updateScene(scene, currentT);
	render(scene);
	const slider = document.getElementById('slider');
	if (slider) slider.value = Math.round(currentT * 1000);
	const tdisp  = document.getElementById('t-display');
	if (tdisp)   tdisp.textContent = currentT.toFixed(2);
}

function jumpTo(t) {
	if (animId) { cancelAnimationFrame(animId); animId = null; }
	setT(t);
}

function scrub(t) { setT(t); }

// direction: +1 = forward (t1→t2), -1 = reverse (t2→t1)
function playAnim(direction) {
	if (animId) { cancelAnimationFrame(animId); animId = null; }
	const duration = 1200; // ms
	const startT = currentT;
	const endT = direction > 0 ? 1 : 0;
	// Scale duration by how far we actually need to travel
	const distance = Math.abs(endT - startT);
	if (distance < 0.001) { setT(endT); return; }
	const scaledDuration = duration * distance;
	const startTime = performance.now();

	function tick(now) {
		const elapsed = now - startTime;
		const frac = Math.min(elapsed / scaledDuration, 1);
		setT(startT + (endT - startT) * frac);
		if (frac < 1) {
			animId = requestAnimationFrame(tick);
		} else {
			animId = null;
		}
	}
	animId = requestAnimationFrame(tick);
}
