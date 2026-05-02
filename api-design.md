# API design — sketch

Right now the viewer talks directly to ad-hoc PHP files
(`tree.php`, `hoptree.php`, `search.php`). This sketch proposes a
clean `/api/...` surface so:

- Third parties can build their own viewers on the same data.
- The viewer code itself becomes a regular API client (easier to test,
  easier to replace).
- We can evolve viewer rendering without churning URLs.

This is a *design* document — nothing here is implemented yet.

## Goals

1. **One stable surface.** A consumer reading these docs can build a
   competing viewer without reading our source.
2. **Layout-aware, not just taxonomy-aware.** Existing OTT API gives
   topology + names; we give the same plus precomputed coordinates,
   summary-tree pruning, and per-node depth/tip-count for the
   *displayed* view. That's the reason this exists alongside OTT's API.
3. **Multi-format.** JSON is canonical, but trees should be
   downloadable as Newick for use in other tools.
4. **Predictable shape.** All responses are JSON objects (never bare
   arrays), all errors share one envelope, all timestamps are ISO 8601.

## URL scheme

```
/api/v1/<resource>[/<id>][?params]
```

- Versioned in the path. Bumping to `v2` means a breaking change;
  additive changes stay on `v1`.
- Plural-noun resources where the resource is a collection
  (`/api/v1/nodes/{id}`); singular when the resource is conceptual
  (`/api/v1/tree`, `/api/v1/hoptree`).
- Lowercase, kebab-case if multi-word.

## Endpoints

### `GET /api/v1/about`

Capabilities + dataset metadata. Matches OTT's convention.

```json
{
  "api_version":     "v1",
  "ott_version":     "3.7",
  "node_count":      4523109,
  "summary_methods": ["leaves", "nodes"],
  "tree_formats":    ["json", "newick"],
  "generated_at":    "2026-05-02T10:23:00Z"
}
```

### `GET /api/v1/tree`

The focal subtree, summary-pruned. Replaces `tree.php`.

| Param   | Type    | Default        | Notes |
|---------|---------|----------------|-------|
| `taxon` | string  | `ott93302`     | OTT external id (`ottN`) or anonymous mrca id (`mrcaottXottY`). |
| `k`     | int     | `30`           | Summary-tree size budget. |
| `mode`  | enum    | `leaves`       | `leaves` or `nodes` — see `summary.md`. |
| `format`| enum    | `json`         | `json` or `newick`. |
| `include` | csv   | (all)          | Subset emitted fields: any of `coordinates,annotations,depth,tip_count,supertree_leaf,members`. Use to slim payload. |

JSON response is the schema in `viewer-pipeline-design.md` (`focal_id`,
`displayed_root_id`, `nodes` map, `edges` list).

`format=newick` returns `text/plain`:

```
((ott1:1,ott2:1)mrcaott1ott2:1,ott3:1)ott452461;
```

Branch lengths are `1` for now (placeholder; the displayed tree is a
cladogram). Internal labels are the node's `id`. A future
`branch_lengths=weight|tip_count|none` param can change that.

### `GET /api/v1/hoptree`

Spanning subtree of a list of visited nodes. Replaces `hoptree.php`.

| Param  | Type   | Default | Notes |
|--------|--------|---------|-------|
| `ids`  | csv    | —       | Required. Visit order. Anonymous mrca ids are accepted. |
| `format` | enum | `json`  | `json` or `newick`. |

Same JSON shape as `/api/v1/tree` (focal_id = last visited; nodes
carry `visited` + `visit_order`).

### `GET /api/v1/nodes/{id}`

Per-node info — feeds the right-hand info panel and gives third-party
viewers a "node detail" lookup. Replaces the inline annotation block
currently piggybacking on `tree.php`.

```json
{
  "id":              "ott452461",
  "display":         "Procellariiformes",
  "rank":            "order",
  "type":            "internal",
  "supertree_leaf":  false,
  "weight":          231,
  "parents":         [
    { "id": "mrcaott18206ott60413", "display": "Aequorlitornithes" }
  ],
  "children_sample": [
    { "id": "ott85277",  "display": "Diomedeidae", "weight": 31 },
    { "id": "ott838239", "display": "Pterodroma",  "weight": 6 }
  ],
  "child_count":     4,
  "annotations": {
    "supported_by":    [...],
    "terminal":        [...],
    "resolves":        [...],
    "conflicts_with":  [...],
    "partial_path_of": [...]
  },
  "external_links": {
    "opentree":  "https://tree.opentreeoflife.org/...",
    "wikidata":  null,
    "ncbi":      null
  }
}
```

`children_sample` caps at 50; `child_count` is the true count. A
separate `/api/v1/nodes/{id}/children?offset=...&limit=...` paginates
when needed.

### `GET /api/v1/nodes/{id}/children`

Paginated children of a node. Useful when `/nodes/{id}` truncates.

| Param    | Type | Default | Notes |
|----------|------|---------|-------|
| `offset` | int  | 0       | |
| `limit`  | int  | 50      | Cap 500. |

```json
{
  "id":     "ott452461",
  "total":  4,
  "offset": 0,
  "limit":  50,
  "children": [
    { "id": "...", "display": "...", "weight": 31, "supertree_leaf": false }
  ]
}
```

### `GET /api/v1/mrca`

MRCA of a set of nodes. Wraps `TreeQueries::mrca`.

| Param | Type | Notes |
|-------|------|-------|
| `ids` | csv  | Two or more OTT ids. |

```json
{
  "mrca": { "id": "ott452461", "display": "Procellariiformes" },
  "inputs": [
    { "id": "ott85277",  "display": "Diomedeidae" },
    { "id": "ott838239", "display": "Pterodroma" }
  ]
}
```

### `GET /api/v1/search`

Taxon search. Replaces `search.php`.

| Param  | Type    | Default | Notes |
|--------|---------|---------|-------|
| `q`    | string  | —       | Required. |
| `mode` | enum    | `exact` | `exact`, `prefix`, `substring`. |
| `limit`| int     | 50      | Cap 200. |

```json
{
  "query":   "Pterodroma",
  "mode":    "exact",
  "results": [
    { "id": "ott838239", "display": "Pterodroma", "rank": "genus", "weight": 42 }
  ]
}
```

## Cross-cutting

### Errors

Every error is JSON with the same shape and an HTTP status:

```json
{
  "error": {
    "code":    "node_not_found",
    "message": "No node with external_id 'ottBOGUS' in OTT 3.7",
    "details": { "id": "ottBOGUS" }
  }
}
```

`code` is a stable string clients can switch on (`bad_request`,
`node_not_found`, `unsupported_format`, `internal_error`, ...).

### Caching

All endpoints set `Cache-Control: public, max-age=86400` and an
`ETag`. The dataset only changes when OTT republishes, so a day is
safe. `/api/v1/about` returns the dataset hash; clients can use it
to bust local caches when it changes.

### CORS

`Access-Control-Allow-Origin: *` for all `/api/v1/*` GETs. The data
is public; opening it up is the whole point.

### Pagination

When a response could grow unbounded (`children`, `search`,
hypothetical bulk endpoints), use `offset` + `limit` and echo both
back in the body. Cursor-based pagination is unnecessary for a
read-only static dataset.

## What goes away

- `tree.php`, `hoptree.php`, `search.php`, `others.php` — replaced.
  Keep them as 30-line shims that 301-redirect (or just-call) the new
  handlers for one release, then remove.
- `test.php`, `treetest.php`, `smoke.php` — internal probes; stay as
  scripts, not part of the API.

## Implementation sketch

Single front controller, file-per-handler:

```
/api/index.php             ← router (parses path, dispatches)
/api/handlers/tree.php
/api/handlers/hoptree.php
/api/handlers/nodes.php
/api/handlers/mrca.php
/api/handlers/search.php
/api/handlers/about.php
/api/lib/response.php      ← json_send(), error(), cache_headers()
/api/lib/format.php        ← newick_emit() and any future format helpers
```

`.htaccess` rewrite:

```
RewriteRule ^api/v1/(.*)$ api/index.php?_path=$1 [QSA,L]
```

Front controller does ~30 lines: split path, match resource, require
the handler, call its `handle()` function with the parsed params.

The data-loading layer is already factored into reusable classes
(`OttTree`, `SummaryTree`, `TreeQueries`), so handlers are mostly
parameter validation + `json_send($obj)`.

## Testing

The API surface *is* the contract — if a third party reads the docs
and writes a viewer against it, the tests are what guarantee the docs
stay true. Treat tests as a first-class deliverable, not a wrap-up.

Four layers, in roughly the order of importance:

### 1. Schema / contract tests (per endpoint)

For every endpoint, hit it with known-good inputs and assert:

- HTTP 200 + `Content-Type: application/json` (or `text/plain` for
  `format=newick`).
- Top-level keys exist and have the documented types.
- Required per-node fields all present (id, display, type, …).
- Optional fields are either present-with-correct-type or absent
  (never `null` when the docs say string).
- Field enum values are within the documented set
  (`type ∈ {internal, leaf, other, stub}`, etc.).

These are the cheapest tests and the ones that catch most "I broke
the API" regressions. Add a new endpoint → add a schema test in the
same commit.

### 2. Behavioural / semantic tests

Beyond shape, the *meaning* has to be right:

- `/api/v1/tree?taxon=X` — the focal node is in the response;
  `displayed_root_id` is `X` or an ancestor of `X`; every edge
  endpoint resolves to a node in the map.
- `/api/v1/mrca?ids=A,B` — returned mrca is an ancestor of both A
  and B in the supertree; no other ancestor is closer.
- `/api/v1/hoptree?ids=A,B,C` — every input id is present and has
  `visited: true`; the spanning subtree is connected.
- `/api/v1/search?q=Drosophila` — known homonyms (fly + plant)
  appear; result count is at most `limit`.
- `/api/v1/nodes/{id}` — `parents[]` walking up reaches the OTT
  root; `child_count` matches `children_sample.length` when
  `child_count ≤ 50`.

A small fixture set of known taxa (the OTT root, a deep leaf, a
mid-tree clade, a known mrca, a known homonym) covers most of these
in 5–10 cases per endpoint.

### 3. Error envelope tests

The error contract matters as much as the success contract:

- Unknown taxon → 404 with `error.code = "node_not_found"`.
- Malformed id (`?taxon=ott;DROP`) → 400 with
  `error.code = "bad_request"`.
- Unsupported format (`?format=xml`) → 400 with
  `error.code = "unsupported_format"`.
- Missing required param → 400 with the param name in
  `error.details`.

These are quick and lock in the user-facing failure surface.

### 4. Cross-endpoint invariants

Run after the per-endpoint tests pass — pin pairs of endpoints
together so they can't drift:

- Every node id returned by `/api/v1/tree` resolves on
  `/api/v1/nodes/{id}` (round-trip).
- `mrca(focal, focal)` from `/mrca` equals `focal` itself.
- `/api/v1/hoptree?ids=X` (single id) returns the same node as
  `/api/v1/nodes/X` for shared fields.
- `format=newick` parses cleanly with a Newick parser **and**
  yields the same tip-id set as `format=json`.

### How to run them

Two test modes, sharing fixtures:

- **Direct invocation.** Call the handler PHP function with synthetic
  params. Fast. Skips routing + HTTP headers.
- **HTTP integration.** Boot `php -S` against the project, hit real
  URLs with `curl`/`file_get_contents`. Slower but covers routing,
  rewrites, headers, CORS.

Match the existing style in `tests/trees.php`: a single PHP runner,
plain `printf` output, exit non-zero on failure. No framework. New
file `tests/api.php` for the API tests; if it grows, split into
`tests/api/<resource>.php` and have a `tests/api.php` driver run
them all.

CI runs both modes against the committed `ott.db`. The dataset is
deterministic, so test taxa can be referenced by id without
versioning concerns — but pin the OTT version in the fixture file so
a future DB rebuild that changes a clade's tips fails loudly instead
of silently.

## Effort estimate

Rough breakdown — assumes the existing `OttTree` / `SummaryTree` /
`TreeQueries` layer doesn't need re-architecting (it doesn't).

| Slice                                          | Effort       |
|------------------------------------------------|--------------|
| Router + response/error helpers + `.htaccess`  | 2 h          |
| `/api/v1/about`                                | 30 min       |
| `/api/v1/tree` (port `tree.php` + Newick)      | 3 h          |
| `/api/v1/hoptree` (port `hoptree.php` + Newick)| 2 h          |
| `/api/v1/nodes/{id}` + `/children`             | 4 h          |
| `/api/v1/mrca`                                 | 1 h          |
| `/api/v1/search` (port + add prefix mode)      | 2 h          |
| Newick emitter (shared)                        | 1 h          |
| Migrate `viewer.js` to new URLs                | 1 h          |
| Old endpoints → shims that call handlers       | 1 h          |
| **Tests** — schema (per endpoint)              | 4 h          |
| **Tests** — behavioural (per endpoint)         | 4 h          |
| **Tests** — error envelope                     | 2 h          |
| **Tests** — cross-endpoint invariants + Newick | 3 h          |
| **Tests** — runner + HTTP-mode scaffolding     | 2 h          |
| `api-reference.md` for consumers + examples    | 3 h          |
| Optional: OpenAPI spec (`openapi.yaml`)        | 4 h          |
| **Subtotal (no OpenAPI)**                      | **~4 days**  |
| **With OpenAPI spec**                          | **~4.5 days**|

The test slice is now ~15h on its own (~2 days), which feels honest
for an API meant to be consumed by third parties. If we cut corners,
schema + behavioural tests are the keepers; error envelope and
cross-endpoint invariants can defer.

Splittable as:
- **Day 1.** Router + tree + hoptree + search. Schema tests for each
  as you go. Viewer keeps working through the shims; nothing
  user-visible breaks.
- **Day 2.** New endpoints: `/nodes/{id}`, `/children`, `/mrca`,
  `/about`. Newick emitter. Schema + behavioural tests for the new
  endpoints. Viewer migrated to new URLs.
- **Day 3.** Error envelope, cross-endpoint invariants, Newick
  parser check. HTTP integration mode.
- **Day 4.** `api-reference.md`, cleanup, optional OpenAPI.

Risks / unknowns:

- **Newick for trees with `other_*` placeholders.** Decide whether
  these emit (`other_X:1`) or are dropped silently. Recommend
  emitting with a marker (`?other_X`) so the round-trip preserves
  topology — but that's non-standard Newick.
- **Path rewriting.** If we ever host without Apache (e.g. a static
  build to S3 + Lambda for the API), the rewrite needs to move into
  the host config or the API needs to be at `?endpoint=tree` style.
  Trivial swap; flagging in case of a deploy decision.
- **Annotation list size.** A very high-traffic node may have
  thousands of `supported_by` entries. The current viewer truncates
  client-side; the API should either paginate annotations or move
  them behind `/api/v1/nodes/{id}/annotations`. Decide before
  publishing v1.

## Decisions to make before starting

1. **Versioning style:** path (`/api/v1/...`) or header
   (`Accept: application/vnd.ott-viewer.v1+json`)? Recommend **path**
   — easier to test from a browser, easier to cache.
2. **Newick branch lengths:** all-1 placeholder, or use `tip_count`
   / `weight` / a future divergence-time field? Recommend **all-1**
   for v1; document it; add a `branch_lengths=` param later.
3. **Authentication:** none? rate-limited by IP? API key? Recommend
   **none** for v1, like OTT itself. Add later if abuse appears.
4. **Backwards compatibility window:** how long do `tree.php` etc.
   keep working? Recommend **one release cycle** as shims, then
   remove with a `CHANGELOG` note.
