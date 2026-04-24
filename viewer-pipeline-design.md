# Viewer Pipeline — Design Notes

Captured during early-stage exploration, 2026-04-23. Not a spec. Decisions
here should be revisited when we actually start building.

## Context

Two HTML entry points today:

- `test.php` — static list-shaped summary view, server-rendered from
  `SummaryTree::to_native()`. Click-to-navigate via `?taxon=…&k=…` reload.
- `transition.html` — standalone SVG animation between two pre-built JSON
  layouts (`tree1.json`, `tree2.json`), built from Newick via `treetest.php`
  + the `tree/` toolkit.

The Newick round-trip (PHP SummaryTree → Newick text → `tree-parse.php` →
layout → JSON) works for demos but discards NHX annotations (`[&…]`) in
the parser, so the viewer JSON currently lacks `supertree_leaf`, `type`,
`weight`, and collapsed-`other_` member lists. The peek panel works
around this with a side-car file (`others*.json`).

## Target architecture

**The transition viewer becomes the tree viewer.** One page, one SVG. The
Newick intermediate goes away. Clicking a node:

1. Client calls a server endpoint (e.g. `tree.json?taxon=<ext>&k=<n>`)
   that computes the new summary *and* its layout in one pass.
2. Response is a self-contained JSON payload — nodes, edges, coordinates,
   flags, `other_` member lists.
3. Client runs `buildScene(currentTreeData, newTreeData)` and animates the
   transition with the same machinery as today.

Server-side, this means lifting what's currently in `treetest.php` +
`tree/` (layout computation) inline with `SummaryTree`, rather than going
via Newick. That's the "Direct summary-to-transition pipeline" item
already on the README's to-do.

## JSON schema

`nodes` is a map keyed by node id (the OTT external_id for real taxa, a
synthetic `other_<parent_id>` for collapsed-sibling placeholders). Map
form makes edge-source/target lookups direct (no index build) and JS
preserves insertion order on non-integer keys, so canonical (nleft) tip
order is implied by emission order. `edges` is a flat list of
`{source, target}` pairs referencing those keys.

```json
{
  "focal_id":          "ott49103",
  "displayed_root_id": "mrcaott18206ott31011",
  "nodes": {
    "ott452461": {
      "id":             "ott452461",
      "display":        "Procellariiformes",
      "type":           "internal",
      "supertree_leaf": false,
      "weight":         231,
      "x":              14.3,
      "y":              137.5,
      "annotations": {
        "supported_by":    ["ot_123@tree1", "ot_456@tree2"],
        "terminal":        [],
        "resolves":        ["ot_789@tree3"],
        "conflicts_with":  [],
        "partial_path_of": ["ot_123@tree4"]
      }
    },
    "mrcaott18206ott18209": {
      "id":             "mrcaott18206ott18209",
      "display":        "Oceanites gracilis galapagoensis + Fregetta grallaria titan",
      "type":           "internal",
      "supertree_leaf": false,
      "weight":         21,
      "x":              171.4,
      "y":              8.6,
      "annotations":    { "supported_by": [], "terminal": [], "resolves": [],
                          "conflicts_with": [], "partial_path_of": [] }
    },
    "other_mrcaott18206ott18209": {
      "id":      "other_mrcaott18206ott18209",
      "display": "other Oceanites gracilis galapagoensis + Fregetta grallaria titan",
      "type":    "other",
      "x":       200,
      "y":       13.8,
      "members": [
        {
          "id":             "ott6155057",
          "display":        "Oceanites pincoyae",
          "type":           "leaf",
          "supertree_leaf": true,
          "weight":         1,
          "annotations":    { "supported_by": [], "terminal": [...], "resolves": [],
                              "conflicts_with": [], "partial_path_of": [] }
        }
      ]
    }
  },
  "edges": [
    { "source": "mrcaott18206ott31011", "target": "mrcaott18206ott18209" }
  ]
}
```

### Key decisions

1. **`id` is the OTT external_id.** Stable, unique, already used in URLs
   and in the DB. Edge `source`/`target` reference these directly. No
   separate internal numeric id surfaces in the JSON. Synthetic
   `other_<parent_id>` keys for collapsed-sibling placeholders are the
   one exception — the viewer special-cases them (you can't `focus_on`
   an `other_*`; clicking inside the peek navigates to a member).

2. **No `label`. Just `id` + `display`.** `id` is the canonical short
   form (e.g. `ott452461`, `mrcaott18206ott18209`). `display` is the
   human-friendly text — the prettified `<X> + <Y>` for mrca nodes, the
   taxon name otherwise. Heuristics that previously checked `label`
   (e.g. `startsWith('mrca')`) check `id` instead.

3. **`type` enum + `supertree_leaf` flag, not three booleans.**
   - `type`: `"internal" | "leaf" | "other"` — the node's role in the
     current view. Drives label visibility, circle solidity rules, peek
     availability.
   - `supertree_leaf`: `bool` — is this a true terminal in the
     supertree (no children in the DB)? Drives solid vs hollow.

   The four states the user might think in (internal-in-view,
   supertree-leaf, collapsed-internal, other) decompose cleanly:
   `type=internal`, `type=leaf & supertree_leaf=true`, `type=leaf &
   supertree_leaf=false`, `type=other`. No invariant ("exactly one is
   true") needed per consumer.

4. **Inline `other_` members on the node itself.** `members: [...]`
   lives on the `other_` node object, not in a side-car map. Same field
   shape as a top-level node minus `x`/`y` (members aren't laid out).
   Members carry their own annotations so the peek panel can show
   per-taxon evidence, eventually.

5. **Annotations: full lists of tree ids per relation.** Five
   relations: `supported_by`, `terminal`, `resolves`, `conflicts_with`,
   `partial_path_of`. Display counts (distinct study) are computed
   client-side. Lists let detail views show tree provenance later. At
   very large scale these inflate the payload — when that becomes a
   problem, switch to pre-aggregated counts plus an on-demand endpoint
   for the lists. Not v1's problem. `other_` nodes themselves carry no
   annotations (synthetic placeholders); their members do.

6. **`focal_id` and `displayed_root_id` at the top level.** The two
   diverge when `focus_on` climbs up because `tips(focal) < k`. The
   viewer needs both: `focal` for the user-aimed-at point (used as the
   enter-anchor in transitions, and for highlighting); `displayed_root`
   for layout / breadcrumb / "tree rooted at" context.

### Circle rule (already documented)

- `solid`  = internal in this view OR supertree leaf
- `hollow` = tip in this view AND not a supertree leaf

Which is exactly: `hollow iff type != "internal" && !supertree_leaf`.

## Transition endpoint

Shape: `GET tree.json?taxon=<external_id>&k=<n>&mode=leaves|nodes`.

Returns the full JSON payload above. Same query params as `test.php`.

Client flow on click:

```
click(nodeId) →
  fetch(tree.json?taxon=nodeId&k=currentK) →
  newTreeData →
  buildScene(currentTreeData, newTreeData) →
  playAnim(1) →
  currentTreeData := newTreeData
```

## Gotchas to remember

- **Focus-on climbs.** When `tips(x) < k`, the server's `focus_on`
  returns a tree whose root is an ancestor of the clicked node. For the
  animation, the enter-fade-in anchor should probably be the clicked
  node (not the displayed root), since that's what the user "aimed at".
  Worth handling explicitly in the click handler: pass both `focal` and
  `displayed_root` into `buildScene`, or expose `focal_id` in the JSON
  and let `buildScene` use it as the anchor hint.

- **Members-as-internals.** `other_` members aren't all supertree leaves;
  some carry subtrees of their own. Clicking one should just be
  `focus_on(member.id, k)` — consistent with how clicking anywhere else
  works. Already the chosen semantic (see
  `summary-node-peek-design.md`).

- **Layout stability across views.** Currently sibling ordering is fixed
  by `nleft` (supertree pre-order index), so the same parent's children
  appear in the same order in any view. Keep this invariant when the
  layout pass moves server-side.

## Deferred

- **NHX parser fix** — teach `tree-parse.php` to attach `[&…]` comments
  to the node they follow and let `treetest.php` unpack them. Drops out
  for free once (2) replaces the Newick round-trip entirely; no point
  investing in (1).

- **Annotations ETL.** Per earlier discussion: the 60 MB synthesis
  annotations JSON is a one-time ETL into sqlite (`jq` preprocess or
  `JsonMachine` stream, load into a sibling table, join at query time).
  Annotations then just drop into the `annotations` sub-object in the
  viewer JSON. Keep the viewer decoupled from the source format.

- **Vertical group bars.** Clade span rendering (min/max y of descendants
  at a given x, with an optional label). Layout pass can emit these as
  auxiliary objects; transition logic handles them like nodes
  (interpolate endpoints). Likely primary use is a gutter column to the
  right of the tip labels, one bracket + clade name per selected internal
  node, replacing in-tree internal labels. Requires a partitioning step so
  brackets don't nest — candidates are named clades, filtered by a
  weight band (e.g. 3–50 tips) by default so singletons and mega-clades
  drop out. Multiple partitions (e.g. genus + family) = multiple columns.
  Labels should be click-to-focus — biggest UX win, turns the gutter into
  a navigation index.

## Open questions

- **K across navigations.** Does `k` stay constant when the user clicks
  into a subtree, or does it adapt (e.g. scale with viewport)? Currently
  it's a URL parameter — fine for now.

- **Back navigation.** If clicking produces a transition forward, is
  there a "back" that reverses it? History stack of `(focal, k)` pairs
  is probably enough. Ties into the peek-panel design's "breadcrumb"
  open question.

- **Single JSON per request vs. delta.** For large trees, sending the
  full JSON every click is wasteful when only a subset changes.
  Premature optimisation for now — ship full-payload first.

- **Layout recomputation cost.** `SummaryTree::focus_on` plus cladogram
  layout per request. Should be cheap at current sizes (hundreds of
  nodes) but worth profiling before committing to "click → full
  server round-trip".
