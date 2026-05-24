# Open Tree of Life viewer

An interactive viewer for the [Open Tree of Life](https://tree.opentreeoflife.org/) synthesis tree. Each view is a summary tree fitted to the browser; clicking a node fetches a fresh summary for that node from a local SQLite copy of the OTT data and animates the transition.

## Approach

The synthesis tree has ~2.7 million nodes, most of which are unnamed internal nodes labelled `mrcaottXottY`. Displaying it requires a strategy for showing only the most informative subset at any given time.

A **summary tree** is constructed by expanding a subtree from a given root node outward in priority order (scored by descendant-tip count) until a size budget *k* is reached — where *k* is the number of **leaves** in the summary, not total nodes. Children that aren't individually expanded are collapsed into synthetic `other_*` placeholder nodes. This bounds the visible tree to at most k leaves no matter how large the clade.

When a clicked node's subtree is smaller than k, the server climbs to the smallest ancestor with enough descendants and shows that view, with the entire focal subtree force-expanded. The clicked node is always visible.

The viewer renders the summary as a left-to-right cladogram (root on the left, tips aligned on the right, internal nodes spaced by depth). Click any node to navigate: the server computes a fresh summary rooted on that node, the client builds a transition between the current and new trees, and the layout animates between them — exiting nodes fade toward the left, entering nodes grow in from their nearest shared ancestor, persisting nodes slide to their new positions.

## Running it

Requires PHP 7+ and the bundled `ott.db` (SQLite — taxonomy, synthesis tree, and per-node OTT synthesis annotations).

```
php -S localhost:8000
```

Then open `http://localhost:8000/index.php?taxon=ott452461`. The URL parameter `taxon=<external_id>` selects the focal taxon (default `ott452461` = Procellariiformes; works all the way down to a single species and up to `ott93302` = "cellular organisms" the OTT root). `k=<n>` overrides the leaf budget (default 30).

Browser back / forward step through previous views with full transitions; URLs are shareable.

## Components

End-to-end working pipeline (server → JSON → SVG):

| File                    | Role                                                                                     |
|-------------------------|------------------------------------------------------------------------------------------|
| `index.php`             | Interactive entry point. Reads URL params, includes the shared CSS/JS, calls bootstrap.  |
| `tree.php`              | Server API. `GET tree.php?taxon=<id>&k=<n>` returns the canonical viewer JSON.           |
| `coordinates.php`       | Depth-based cladogram layout. Adds x/y to every node on a 100×100 grid before emit.      |
| `summary.php`           | Summary-tree engine: priority-queue expansion, `other_*` collapsing, force-expand paths. |
| `ott_tree.php`          | OTT-specific access to the local SQLite database (`ott.db`).                             |
| `viewer.js`             | Client-side: scene building, interpolation, SVG rendering, peek overlay, history.        |
| `viewer.css`            | Shared styles.                                                                           |
| `tests/trees.php`       | Layer 1 schema + invariant tests against `tree.php`.                                     |

`transition.html` is a separate two-tree demo page sharing the same `viewer.js` / `viewer.css`.

### JSON schema (server response)

`tree.php` returns:

```json
{
  "focal_id":          "<external_id>",
  "displayed_root_id": "<external_id>",
  "nodes": {
    "<id>": {
      "id":             "<external_id>",
      "display":        "human-readable name",
      "type":           "internal | leaf | other | stub",
      "supertree_leaf": true,
      "weight":         42,
      "x": 14.3, "y": 137.5,
      "annotations": {
        "supported_by":    ["ot_123@tree1", ...],
        "terminal":        [...],
        "resolves":        [...],
        "conflicts_with":  [...],
        "partial_path_of": [...]
      },
      "members": [ /* only on "other" nodes */ ]
    }
  },
  "edges": [{ "source": "<id>", "target": "<id>" }, ...]
}
```

`focal_id` and `displayed_root_id` differ when `focus_on` climbs up to find context. `viewer-pipeline-design.md` documents the schema decisions and gotchas.

## Visual conventions

- **Solid circles**: supertree leaves (real terminal taxa) and internal nodes in the current view.
- **Hollow circles**: tips in this view that are internal in the supertree — there are more descendants below.
- **Annotation numbers** sit just left of internal-node circles: distinct studies that support the node above the edge line, distinct studies that conflict below. Hidden when both are zero (typically taxonomy-only nodes).
- **Hover halo**: a red concentric ring on the hovered node (placeholder colour while we settle on the final palette).
- **`mrcaottXottY` labels** are hidden on internal nodes; on tip-shaped placeholders we show the prettified `tipA + tipB` form. `other_*` placeholders show just `other (N)` when their parent is mrca-named (otherwise `other <parent name>`).
- **Upstream stub**: each view shows one "context" node above the displayed root — the supertree parent — so the root doesn't sit in a vacuum.

## Interaction

- Single click on a node circle = navigate. Click on a label or annotation does nothing.
- Single click on an `other_*` node = open / close its peek list.
- Click on a peek member = navigate to it.
- Click on the background = close peek. Escape works too.
- Wheel over a windowed peek = scroll the visible band.
- Browser back / forward = previous / next view, with full transitions.

## Testing

```
php tests/trees.php
```

Runs `tree.php` against a battery of focal taxa (mid-tree clades, the OTT root with its self-loop, an unknown id, a small clade that climbs, etc.) and asserts JSON shape + graph invariants — node/edge schema, no self-loops, members on `other_*`, focal/root resolution, no orphans. Exits non-zero on any failure. Add cases by appending to `$cases`.

## To do

### Next priorities

- Right-click / long-press context menu for navigation alternatives (focus, copy URL, open on opentreeoflife.org). Single click stays the primary path.
- Per-node info panel showing the full annotations breakdown (supported_by + resolves separately, terminal, partial_path_of, taxonomy-only flag).
- Client-side tree cache keyed by `taxon|k` so back-navigation is instant and re-visiting nodes doesn't re-fetch.
- Search by taxon name → navigate.

### Later

- Vertical clade-name gutter to the right of tips, with brackets covering each named clade's tip range; click-to-focus on the bracket label. Replaces in-tree internal labels for named clades. Requires partitioning so brackets don't nest.
- Solid vs dashed edges for phylogeny-supported vs taxonomy-only paths.
- Node size proportional to descendant count (matching OTT's own viewer).
- Re-fit on window resize. Currently the viewport-fit is computed once at load.
- Score-function tweaks: boost taxa with genome data, or those the user has previously visited, so they survive collapse.

### Further out

- Layer 2 browser smoke tests (Playwright) — load `index.php` for a matrix of taxa, assert no console errors after click-around. Skip pixel-diff goldens (too flaky).

## Database schema (`ott.db`)

SQLite database with nested-set encoding of the OTT synthesis tree, taxon metadata, and per-node phylogenetic annotations.

### `taxa`

Every node in the synthesis tree (internal and leaf).

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Internal row id, used as FK in `tree`. |
| `external_id` | TEXT UNIQUE | Stable OTT identifier: `ottN` for named taxa, `mrcaottXottY` for unnamed internal nodes. |
| `label` | TEXT | Human-readable name (species/clade name, or the raw mrca string for unnamed nodes). |

### `tree`

Nested-set tree structure. One row per node, keyed to `taxa.id`.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK/FK | References `taxa.id`. |
| `parent` | INTEGER FK | Parent node (`taxa.id`). The root's parent is itself. |
| `depth` | INTEGER | Depth from root (root = 0). |
| `weight` | INTEGER | Descendant-tip count; drives priority-queue expansion in the summary engine. |
| `nleft` | INTEGER | Nested-set left bound. |
| `nright` | INTEGER | Nested-set right bound. |
| `score` | REAL | Pre-computed ranking score (currently equal to weight). |

### `annotations`

Per-node phylogenetic-study support from the OTT synthesis provenance data.

| Column | Type | Notes |
|---|---|---|
| `node_external_id` | TEXT FK | Joins to `taxa.external_id`. |
| `relation` | TEXT | One of `supported_by`, `conflicts_with`, `partial_path_of`, `terminal`, `was_uncontested`. |
| `study_tree` | TEXT | Study-tree reference, e.g. `ot_311@tree1`. |
| `source_node_id` | TEXT | Node id within the source study tree. |

### `taxonomy` (unpopulated)

Intended to hold the OTT taxonomy (names, ranks, flags) as a supplement to the synthesis-tree-only data in `taxa`/`tree`.

| Column | Type | Notes |
|---|---|---|
| `ott_id` | TEXT PK | OTT identifier. |
| `parent_ott_id` | TEXT | Parent taxon. |
| `name` | TEXT | Taxon name. |
| `rank` | TEXT | Taxonomic rank. |
| `flags` | TEXT | OTT taxonomy flags. |

### `studies` (unpopulated)

Intended to hold metadata for the phylogenetic studies referenced in `annotations`.

| Column | Type | Notes |
|---|---|---|
| `study_id` | TEXT PK | e.g. `ot_311`. |
| `publication_ref` | TEXT | Bibliographic citation string. |
| `doi` | TEXT | DOI. |
| `year` | INTEGER | Publication year. |
| `focal_clade_name` | TEXT | Focal clade of the study. |
| `curator_names` | TEXT | JSON array of curator names. |

### Views

- **`taxa_v`** — extends `taxa` with an `is_taxonomy_only` flag (1 when the node has no rows in `annotations`, i.e. placed by taxonomy alone with no phylogenetic support).

## Design notes

- `viewer-pipeline-design.md` — JSON schema, transition endpoint, gotchas, deferred items.
- `summary-node-peek-design.md` — peek interaction design discussion.
- `background.md` — references and reading.
