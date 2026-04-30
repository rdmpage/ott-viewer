# Phylogeny queries — design notes

A working summary of standard query patterns for phylogenetic trees in
relational databases, drawn from two reference papers in `reading/`,
mapped to this project's schema, and used to scope the query layer
that will back the hoptree, info panel, and any future analysis tools.

References:

- Vos, R. A. (2019). *DBTree: Very large phylogenies in portable
  databases*. **Methods Ecol. Evol.** 11, 457–463.
  doi:10.1111/2041-210X.13337
- Nakhleh, L., Miranker, D., Barbancon, F., Piel, W. H., Donoghue, M.
  (2003). *Requirements of phylogenetic databases*. **BIBE 2003**.

## Why a dedicated query layer

Storing phylogenies as Newick strings makes them concise but opaque to
the database — every structural query has to parse the string and walk
a tree in memory. Both papers argue, from different angles, for a
**normalised** representation where each node and edge is its own row,
indexed for fast lookup. Vos focuses on a single-table schema with
pre-computed traversal indices; Nakhleh et al. catalogue the queries
biologists need and show that with the right schema each query
collapses to a single SQL or Datalog statement, including on trees of
millions of nodes.

For us the pay-off is concrete: every "click a node → fetch a fresh
summary" cycle starts with structural questions ("is the new focal
inside or above the old one?", "what's the path between them?",
"give me the focal's full ancestor chain"), and every one of those
should be a single indexed query, not a recursive walk.

## Two indexing tricks worth knowing

### Adjacency list (parent-pointer)

The obvious encoding: each node stores its parent's id. Cheap to
update, cheap to find direct children/parent, but ancestor / descendant
/ MRCA need recursion or DB-engine-specific transitive closure. Fine
for small trees, painful at OTT scale.

### Nested-set encoding (pre/post-order indices)

Each node also stores `nleft` and `nright`, assigned during a depth-
first walk: parents get `nleft` before any child; on the way back up,
each node gets `nright`. The invariant is

```
A is an ancestor of B   iff   A.nleft <= B.nleft  AND  A.nright >= B.nright
```

That single comparison turns most of the recursive queries below into
simple `WHERE` clauses with no joins to walk. Vos calls this the core
performance trick of his DBTree implementation; Nakhleh et al. treat
it as the natural "normalised" form.

The cost is that `nleft` / `nright` have to be recomputed when the
tree changes — fine for read-mostly archives like ours.

## Our schema

The `tree` table already carries everything needed:

```
CREATE TABLE tree (
    id          TEXT,    -- internal id (also primary)
    parent      TEXT,    -- parent's id (root self-references; treat as NULL)
    depth       TEXT,    -- depth from root
    weight      TEXT,    -- number of descendant tips (incl. self for tips)
    nleft       TEXT,    -- pre-order index
    nright      TEXT,    -- post-order index
    score       TEXT     -- summary-tree priority (per-tip-count weighting)
);
CREATE INDEX parent_id ON tree(parent);
CREATE UNIQUE INDEX tree_id ON tree(id);
```

`taxa` carries `external_id` (`ottN` / `mrcaottXottY` / etc.) and
`label`. So we have, for free, both the adjacency list (`parent`) and
the nested-set encoding (`nleft` / `nright`).

A couple of OTT-specific quirks the queries below assume:

- The OTT root self-references its parent (`id = parent = 1`). Any
  ancestor walker must treat `parent == id` as "no parent" or it loops.
  `OttTree::get_node` already does this.
- `nleft` / `nright` are stored as `TEXT`. SQLite compares them
  numerically when both sides of `<` are numeric strings of the same
  length, which they happen to be — but a `CAST(... AS INTEGER)` is
  defensive and nearly free.

## Standard queries

The query layer (proposed: `tree_queries.php`, exposing a
`TreeQueries` class) should provide these. SQL sketches use external
ids as parameters because that's what the JSON / URL layer speaks; the
joins to `taxa` are stripped here for clarity but every query in
practice joins on `external_id`.

### relationship(a, b)

Returns `'self' | 'ancestor' | 'descendant' | 'cousin'`. Single fetch
of both rows, then a comparison in PHP — or one SQL with a `CASE`. The
core invariant from above:

```sql
-- Returns 'a' if a is an ancestor of b, 'd' if descendant, 's' if equal,
-- 'c' otherwise.
SELECT
  CASE
    WHEN a.id = b.id                                    THEN 's'
    WHEN a.nleft <= b.nleft AND a.nright >= b.nright    THEN 'a'
    WHEN b.nleft <= a.nleft AND b.nright >= a.nright    THEN 'd'
    ELSE 'c'
  END AS rel
FROM tree a, tree b
WHERE a.id = ? AND b.id = ?;
```

### mrca(a, b)

Most recent common ancestor — the deepest node whose interval contains
both. Vos's formulation, adapted:

```sql
SELECT m.*
FROM tree m, tree a, tree b
WHERE a.id = ? AND b.id = ?
  AND m.nleft  <= MIN(a.nleft,  b.nleft)
  AND m.nright >= MAX(a.nright, b.nright)
ORDER BY m.depth DESC
LIMIT 1;
```

When `a == b` this returns the node itself; when one is an ancestor
of the other it returns the ancestor.

### ancestors_of(x)

All ancestors from root down to (but excluding) `x`, ordered by depth.
With the nested-set encoding this is a single non-recursive query:

```sql
SELECT a.*
FROM tree a, tree x
WHERE x.id = ?
  AND a.nleft  < x.nleft
  AND a.nright > x.nright
ORDER BY a.depth ASC;
```

### descendants_of(x)

Same idea, mirrored:

```sql
SELECT d.*
FROM tree d, tree x
WHERE x.id = ?
  AND d.nleft  > x.nleft
  AND d.nright < x.nright
ORDER BY d.depth ASC;
```

For very large clades (e.g. a descendant query on the root) the result
set is the whole tree — typically you want a depth bound or a tip-only
filter (`d.nleft = d.nright`).

### path_between(a, b)

Sequence of ids from `a` up to `mrca(a, b)` and down to `b`. Compose
from the primitives:

1. `m = mrca(a, b)`.
2. `up   = ancestors_of(a)` filtered by `depth >= m.depth`, descending
   from a's parent — gives `a → ...→ m`.
3. `down = ancestors_of(b)` filtered by `depth >= m.depth`, descending
   from m to b's parent.
4. Concatenate `[a, ...up, m, ...reverse(down), b]` (deduping `m`).

This is the primary input the hoptree consumes: each consecutive pair
of focal taxa in the user's session expands to a sequence of nodes
with a clear direction (down past `m` and back up).

### tip_count(x), tip_count_in_view(x, summary)

`tree.weight` already carries the supertree-wide tip count. Tip count
restricted to a current summary view is a different query — it's the
intersection of `descendants_of(x)` with the summary's nodes — and is
needed, e.g., by the future "show how much of this clade is on screen"
indicator.

### depth(x), patristic_distance(a, b)

`depth` is a column. Patristic distance (Vos's example) is
`(a.height - mrca.height) + (b.height - mrca.height)` once `height`
exists; we don't carry branch lengths today, so this stays speculative
until we do.

## Mapping to the user-categories (Nakhleh et al.)

For our project, only a small slice of Nakhleh's user matrix applies
right now — we're firmly in the **Visualization** / **General Use**
columns. That means Q1 (minimum spanning clade), Q3 (find phylogenies
containing a set of taxa, which for us reduces to "is this taxon in
the supertree at all"), and bits of structural queries underpinning
Q2 (relationships between three sets — useful when comparing multiple
clades on screen). The bigger metadata queries (Q4–Q11: by author,
date, method, evolutionary model) aren't in scope until we have more
than one tree.

## What this implies for our codebase

- A new thin class `TreeQueries` (probably `tree_queries.php`) on top
  of the existing `OttTree`. Methods: `relationship`, `mrca`,
  `ancestors_of`, `descendants_of`, `path_between`, `depth`. Each is
  one or two SQL statements; no recursion in PHP.
- Layer 1 tests covering the obvious cases: root vs. tip, two cousins,
  a node vs. itself, an ancestor vs. its own descendant, climb-out
  cases (`focus_on(small_clade)` then call `path_between` on the
  previous and new focals).
- The hoptree gets its data from `path_between(prev, curr)` per
  consecutive history pair. The breadcrumb is the degenerate case
  (path is just `[prev, curr]` directly).

## Open questions

- **Branch lengths / heights.** OTT's synthesis tree has them in the
  source; our `tree` table doesn't carry them. Worth adding when we
  have a use case (patristic distance, time-calibrated views).
- **Multi-tree storage.** Both papers assume the schema may hold many
  trees (TreeBASE-style, or DBTree's collection of phylogenies). We
  hold one. Adding a `tree_id` column up front is cheap if there's any
  chance of expanding (e.g. supertree alongside source trees).
- **Annotations layer.** We already have `annotations(node_external_id,
  relation, study_tree, source_node_id)`. None of the structural
  queries above touch it; it's the natural place to layer "for this
  clade, which studies disagree" queries on top of structural ones.
- **Cache.** Vos found ~1 hr to ingest OTT; the benefit is at query
  time. We don't pay the indexing cost (it's done) but we should be
  cautious about queries that fan out to many rows
  (`descendants_of(root)`); always have a depth bound or a count
  guard.

## Status

This document is descriptive, not prescriptive. The actual class +
SQL implementation is pending — the in-flight other-chat investigation
should land first so we adopt one method shape rather than diverging.
Update this file as decisions land.
