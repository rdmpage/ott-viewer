# Viewer interaction design

A running snapshot of the user-interaction questions the viewer is working
through, and where each one stands. Five buckets, ordered by how much they
depend on each other.

## 1. Navigation history

Two layers, both already in place:

- **`history.pushState`** in `navigateTo` / `replaceTree` and a `popstate`
  handler — gives browser back/forward, Cmd-click-new-tab, and shareable
  deep-linkable URLs (`?taxon=ott…`).
- **Hoptree-style history widget** wired via `renderHoptree()` from
  `afterNavigationLanded`. Originally prototyped in the standalone
  `hoptree.html`; the algorithm follows Brooks et al. 2013 (see
  `background.md`) — keep up to N visited leaves, prune oldest when
  exceeded, draw the spanning subtree of the visited set.

**Open**: pruning cap (`TRAIL_CAP`) is currently 8 — Brooks recommends 3.
Worth a tuning pass once the comparison workflow (#4) is exercised; the
right cap depends on how often users actually hop between locations vs.
just walk linearly.

## 2. Distinguishing synthesis vs taxonomy-only nodes

We have the data: `taxa_v.is_taxonomy_only` flags ~87% of synthesis nodes
as taxonomy-only placements (no source phylogeny supports them). The
viewer doesn't currently surface this.

**Open** — design choices, none made yet:

- **Where to show it**: in the node label itself (colour, icon,
  outline style), in the info panel only (text field), or as a
  filterable toggle ("hide taxonomy-only").
- **Visual weight**: should taxonomy-only nodes be visually de-
  emphasised, marked but equal-weight, or untouched-by-default-with-
  an-opt-in toggle? Affects how the tree "reads" at a glance.
- **At what zoom levels** the distinction is shown — for a deep
  family-level view, painting 87% of nodes differently is noisy.

Recently settled in this bucket: see commit *Hide info panel on
navigation; show only on single-click* — keeps the info panel out of the
way so the visual encoding (whatever it ends up being) isn't covered.

## 3. Surfacing phylogenetic queries

We've built the SQL primitives (`schema.sql`, `taxonomy.md`) for:

- "is X monophyletic?" (cheap test: is `ott<id>` present as a node)
- MRCA of an arbitrary set
- descendants / leaf count under a clade
- supporting / conflicting source phylogenies per node (annotations)

There's no UI affordance for any of these yet. Possible homes:

- **Info-panel sections** — for single-click context, automatic
  ("supported by 3 studies, conflicts with 1", "monophyletic in
  synthesis: yes").
- **Right-click / context menu** on a node — "is this monophyletic?",
  "compare with…".
- **A query bar** separate from search — typed/structured queries
  ("monophyly Cyrtopodion", "mrca Aves Mammalia").
- **A separate query page** that re-uses the same `ott.db`.

The other Claude session is currently working on API design, which
will feed this. Until that lands, this bucket sits.

## 4. Comparison workflow

Two locations, side by side, reading values back and forth — the
"measure twice" pattern Brooks et al. observed when their study
participants used a hoptree.

What we have: the hoptree (#1) supports it implicitly — the user can
hop between recently-visited nodes in one click. Not yet:

- **Pinning** a node so it persists in the history regardless of
  recency (Brooks raised this as a known limitation of FIFO eviction).
- **Multi-pane / split view** showing two clades simultaneously.
- **Pairwise comparison primitives** (e.g. "are these two sister
  clades?", "MRCA of this and that").

**Open** — does the hoptree alone do enough, or do we need a pinning
mechanism + a comparison panel? Likely answered empirically once
phylogenetic queries (#3) are surfaced and we see how users actually
compose them.

## 5. Scale / level-of-detail

The OTT synthesis tree is 2.7M nodes; the viewer necessarily shows
summaries. Existing viewer handles depth control via the `K` parameter
on `tree.php`, which is largely settled.

**Open**, lower-priority:

- Whether **maximum entropy summary trees** (Karloff & Shirley, see
  `background.md`) would give better-looking summaries than the current
  layered budget approach.
- Whether **expand-ahead** (McGuffin et al., see `background.md`)
  belongs anywhere in the navigation model — likely overlaps with the
  current click-to-zoom behaviour.

Worth a sanity pass once the rest of the buckets are further along;
not blocking anything now.

## Recommended ordering

Roughly:

1. **#3 (phylogenetic queries)** once the API design lands — that
   gives the viewer something genuinely novel to surface.
2. **#2 (synthesis vs taxonomy-only)** in parallel — the visual
   encoding question stands on its own and informs the info-panel
   design from #3.
3. **#4 (comparison)** after #3 is in users' hands — empirical
   answer to whether the hoptree alone is sufficient.
4. **#5 (level-of-detail)** as a sanity pass at the end.

#1 is essentially done; this doc tracks the rest.
