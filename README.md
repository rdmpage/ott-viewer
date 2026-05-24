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
| `import_studies.php`    | Populates the `studies` table from phylesystem JSON files.                               |
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
        "supported_by":    [{ "study_tree": "ot_123@tree1", "publication_ref": "...", "doi": "..." }, ...],
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

### `studies`

Metadata for the phylogenetic studies referenced in `annotations`. Populated by `import_studies.php` from the [phylesystem](https://github.com/OpenTreeOfLife/phylesystem-1) JSON files.

| Column | Type | Notes |
|---|---|---|
| `study_id` | TEXT PK | e.g. `ot_311`. |
| `publication_ref` | TEXT | Bibliographic citation string. |
| `doi` | TEXT | Bare DOI (no URL prefix). |
| `year` | INTEGER | Publication year. |
| `focal_clade_name` | TEXT | Focal clade of the study. |
| `curator_names` | TEXT | JSON array of curator names. |

### `phylopic_cache`

Caches [PhyloPic](https://www.phylopic.org/) silhouette lookups so the viewer doesn't re-query the PhyloPic API for every page load. Populated automatically by the `/api/v1/phylopic` proxy endpoint. Negative results (no image for a taxon) are cached too, with `thumbnail_url = NULL`.

| Column | Type | Notes |
|---|---|---|
| `ott_id` | TEXT PK | Bare OTT number (e.g. `746703`), no `ott` prefix. |
| `image_uuid` | TEXT | PhyloPic image UUID, or NULL if no image exists. |
| `thumbnail_url` | TEXT | Full URL to the 64×64 PNG thumbnail, or NULL. |
| `contributor` | TEXT | Image contributor / attribution name. |
| `license_url` | TEXT | License URL (typically CC0 or CC-BY). |
| `fetched_at` | TEXT | ISO datetime of when the row was fetched; entries older than 30 days are re-queried. |

### Views

- **`taxa_v`** — extends `taxa` with an `is_taxonomy_only` flag (1 when the node has no rows in `annotations`, i.e. placed by taxonomy alone with no phylogenetic support).

## PhyloPic silhouettes

Bracket labels for named clades display a [PhyloPic](https://www.phylopic.org/) silhouette when one is available. The lookup uses OTT identifiers (not taxon names) to avoid homonym ambiguity.

### How it works

1. After a tree loads, the client collects the OTT IDs of all bracketed clades and sends them in a single batch request to `/api/v1/phylopic?ott_ids=746703,541928,...`.
2. The PHP proxy (`api/handlers/phylopic.php`) checks the `phylopic_cache` table in `ott.db`. Cached results (less than 30 days old) are returned immediately.
3. For cache misses, the proxy calls the PhyloPic API: `GET https://api.phylopic.org/resolve/opentreeoflife.org/taxonomy/{ott_number}?embed_primaryImage=true`. This resolves the OTT ID to a PhyloPic node and returns the primary image in a single request.
4. The proxy extracts the 64×64 thumbnail URL, contributor name, and license, stores them in `phylopic_cache`, and returns the result. Taxa with no PhyloPic image are cached with `thumbnail_url = NULL` to prevent repeated lookups.
5. The client renders each thumbnail as an SVG `<image>` element to the right of the bracket label. Images that haven't loaded yet are simply absent — the bracket and label render immediately without waiting.

### Caching layers

- **Server (SQLite)**: `phylopic_cache` table. Persists across sessions. 30-day TTL. Negative results cached.
- **Client (in-memory)**: JavaScript object keyed by OTT number. Lives for the browser session. Prevents redundant proxy calls when navigating between nodes.
- **Browser HTTP cache**: The 64×64 PNGs from `images.phylopic.org` are subject to standard browser caching (PhyloPic serves `Cache-Control` headers).

### Dark mode

PhyloPic thumbnails are black silhouettes on a transparent background. In dark mode, CSS `filter: invert(1)` flips them to white.

## Design notes

- `viewer-pipeline-design.md` — JSON schema, transition endpoint, gotchas, deferred items.
- `summary-node-peek-design.md` — peek interaction design discussion.
- `background.md` — references and reading.
