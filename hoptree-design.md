# Hoptree — design notes

A summary of the hoptree feature: what it is, why we have it, how the
current implementation works, and what's worth iterating on next.

## What the hoptree is

A small cladogram drawn at the top of the viewer that shows every taxon
the user has navigated to, plus the branching points needed to connect
them. As they click around, the diagram grows left-to-right and the
current focal moves with each navigation.

Each entry is a rounded rectangle with the taxon's name (or its
external id when the node is an anonymous mrca). Edges are smooth
Bezier curves between rectangles. The current focal is filled solid;
previously-visited nodes are tinted; the structural mrca branching
points (added so the tree is connected but never visited by the user)
get a plain background.

The whole strip is clickable — selecting any node navigates the main
tree to it.

## Why

A linear breadcrumb (`Procellariiformes › Pterodroma › ...`) tells you
*what* you visited but not *how the visits relate*. Two clicks can
either be drilling deeper, jumping to a cousin, or stepping back up,
and nothing in the breadcrumb distinguishes them. The hoptree puts the
visits back in their phylogenetic context: a click into a descendant
extends the diagram downward, a jump to a cousin adds a new leaf at
the same depth, a step up to an ancestor doesn't change shape but
moves the focal pin.

Reference: Brooks et al.'s "hoptrees" idea (paper in `reading/`) is
the visual ancestor — it argues that breadcrumbs lose information that
a small inset tree preserves.

## Data flow

```
navigationTrail   ──►  hoptree.php?ids=…    ──►  viewer.js
   (client)             (server endpoint)         (renderer)
                            │
                            ▼
                       TreeQueries::spanning_subtree
                            │
                            ▼
                       layout_spanning_subtree
```

1. Every focal navigation appends to `navigationTrail` (in
   `viewer.js`). Trail is deduplicated against the previous entry and
   soft-capped at `TRAIL_CAP = 8` (constant near the top of the file).
2. After the navigation is committed, `renderHoptree()` fires
   `fetch('hoptree.php?ids=…')` with the trail's external ids in
   visit order.
3. `hoptree.php` calls `TreeQueries::spanning_subtree(ids)` (in
   `tree_queries.php`) and `TreeQueries::layout_spanning_subtree`,
   then emits the JSON.
4. The client renders the JSON via `buildHoptreeSvg` — pixel-scale
   layout, rectangles, Bezier edges. SVG width/height are set inline
   so the global `svg { width: 100% }` rule doesn't stretch the
   hoptree.

## Spanning-subtree algorithm

For a list of visited external ids:

1. Resolve them to internal rows (`tree.id`, `nleft`, `nright`,
   `depth`, `weight`, `parent`). Drop any that aren't in the DB.
2. Sort by `nleft` (pre-order). The MRCA of two visited nodes is
   always between them in pre-order, so the closure under MRCA needs
   only one pass over consecutive pairs.
3. For each consecutive pair `(x_i, x_{i+1})`, compute their MRCA via
   the nested-set lookup `nleft <= MIN(...) AND nright >= MAX(...)
   ORDER BY depth DESC LIMIT 1`. Add to the working set if not
   already present.
4. For every node in the resulting set, find its **reduced parent**
   — the deepest other node in the set whose interval contains it.
   `O(N²)` over visited nodes; `N ≤ TRAIL_CAP = 8` so this is fine.
5. Build the result: `nodes` keyed by external_id, `edges` linking
   each non-root to its reduced parent, `focal_id` = last visit,
   `displayed_root_id` = node with no reduced parent.

Each node carries `visited` (bool) and `visit_order` (int|null) so
the renderer can tell which are user-visited vs which are structural
mrca branching points.

## Layout

Pixel-based, computed in JS:

- **x** = `(depth − minDepth) × COL_W` — depth-aligned columns.
- **y** for leaves = sequential rows via DFS; internals get the
  midpoint of their children's y values.
- Constants near the top of `buildHoptreeSvg`:
  `COL_W = 130`, `RECT_W = 110`, `RECT_H = 26`, `ROW_H = 36`.
  `MAX_CHARS = 16` (truncation cap on labels).

The SVG is sized to its content (no stretching). `#hoptree-container`
has `overflow-x: auto` so a long hoptree gets a horizontal scrollbar
instead of compression. Font stays at 11 px regardless of viewport
because the viewBox dimensions match the inline width/height.

## Visual conventions

| State        | Fill                | Stroke              | Text         |
|--------------|---------------------|---------------------|--------------|
| `focal`      | `var(--text-link)`  | `var(--text-link)`  | white, bold  |
| `visited`    | `var(--hover-bg)`   | `var(--text-link)`  | `var(--text)`|
| (mrca only)  | `var(--bg)`         | `var(--text-dim)`   | `var(--text)`|
| hover        | (any)               | thicker `--text-link` | (unchanged) |

All colour tokens come from the theme `:root` block in `viewer.css`,
so dark mode swaps without any JS-side logic.

## Label policy

- **Named taxa** (id like `ottN`): show the prettified `display`
  string (e.g. "Procellariiformes").
- **Anonymous internals** (id starts with `mrca`): show the raw
  `mrcaottXottY` id rather than the prettified `<tipA> + <tipB>` pair.
  The pair form has a single bracketing tip in common across many
  unrelated mrca nodes, so a chain of them all reads "Tip + …" and
  becomes indistinguishable. The raw id is at least unique per node.

Both forms are truncated to `MAX_CHARS` characters with an ellipsis.

## Trail cap

`TRAIL_CAP = 8` — visits beyond this drop the oldest entry. Eight is
small enough that the hoptree stays readable but large enough to
remember a useful session's worth of navigation. Tuneable.

The Brooks et al. prototype caps at **3 leaves** rather than 3 visits,
which is a different semantic — a chain of "drill down" clicks all
counts as one leaf there. Worth considering if 8 visits feels like
too many in practice.

## Files involved

- `tree_queries.php` — `TreeQueries::spanning_subtree`,
  `TreeQueries::layout_spanning_subtree`, plus the underlying
  primitives (`relationship`, `mrca`, `mrca_by_bounds`,
  `lookup_external`).
- `hoptree.php` — `GET ?ids=ott1,ott2,…` HTTP endpoint, returns the
  laid-out spanning-subtree JSON.
- `viewer.js` — `renderHoptree` + `buildHoptreeSvg`, called from
  `afterNavigationLanded` after every navigation.
- `viewer.css` — theme tokens, `.hoptree-rect` / `.hoptree-label` /
  `.hoptree-edge` rules, container `overflow-x: auto`.
- `phylogeny-queries.md` — rationale for the structural query layer
  the hoptree is built on.
- `hoptree.html` — the original prototype this implementation is
  ported from.

## Open questions / future work

- **Trail semantics.** Currently we track *every visit*; the prototype
  tracked *visited leaves*. A "visit-leaf" model collapses long
  drill-down chains into a single entry — might feel more like a
  bookmark than a trail. Worth trying.
- **Truncation strategy.** 16 chars + ellipsis works for most named
  taxa but cuts mrca ids mid-segment (`mrcaott18206ott…`). For mrca
  ids specifically a smarter truncation (e.g. show both bracketing
  tip ids in shortened form) might be more useful.
- **Direction cues.** The hoptree shows *what* the user visited but
  not *the order*. A small numeric badge on each visited rectangle
  showing visit order would put time back in. Same for highlighting
  the most-recent edge traversal.
- **Tooltips.** Hovering a rectangle could surface the full label
  when truncated, the full id, or the visit timestamp.
- **Compact mode.** When the trail spans a large depth range the
  hoptree gets quite wide. A "fold" indicator on long edges (e.g. a
  number-of-skipped-levels badge) could keep the diagram compact.
- **Interaction with the main tree's transition.** Right now the
  hoptree updates after `afterNavigationLanded` fires — i.e. after
  the main transition has already been kicked off. For a richer
  effect we could highlight in the hoptree the path being traversed
  during the transition itself.

## Status

Working end-to-end: search / click / breadcrumb-click / browser
back-forward all feed into the same trail and re-render the hoptree.
Visual is the rectangle-and-bezier prototype, ported with theme
tokens for dark-mode support. The query layer (`TreeQueries`) is
ready to grow into the broader set described in
`phylogeny-queries.md` whenever we need ancestor / descendant /
patristic-distance queries.
