# Background

Three papers on navigating and visualising large hierarchies. Each addresses a different facet of the same core problem: a tree has more nodes than the screen can show, so the system must choose what to display, what to hide, and how to help the user move between views without getting lost.

## Brooks, West, Aragon & Bergstrom (2013) — *Hoptrees: Branching History Navigation for Hierarchies*

A widget for navigating large hierarchies that keeps a small, branching graphical history of recently visited nodes laid out according to the tree's own structure (not the user's visit order). Whenever the user moves to a new location, the path to that node is added to the hoptree. An automatic pruning rule caps the display at three leaves: when a fourth would be added, the oldest leaf and any of its now-orphaned ancestors are removed. The pruning is fully automatic — no maintenance burden on the user, at the cost of occasionally dropping a node the user wanted to revisit.

The motivating contrast is with breadcrumb trails, which only show the path to the *current* node and lose any other history once the user branches sideways. The hoptree's claim is that by preserving multiple paths, users build a better mental model of the hierarchy and can hop between recently visited nodes in one click.

Implemented as a jQuery plugin layered on top of the JavaScript InfoVis Toolkit's SpaceTree, integrated with the *Gender Browser* (an icicle plot of ~450 academic disciplines coloured by gender ratio). Source: <https://github.com/michaelbrooks/hoptree>. A within-subjects study (n = 18) compared three versions — plain, breadcrumb, hoptree — on retrieval and comparison tasks. The hoptree was preferred 16/18, judged easiest 16/18, judged fastest 16/18; total time and click counts were significantly lower than the plain interface, and total clicks were lower than the breadcrumb. Error rates did not differ significantly. An interesting behavioural finding: hoptree users adopted a "measure twice" strategy — visiting all relevant nodes first, then quickly hopping back to read off values — instead of memorising values on the first visit.

## Karloff & Shirley — *Maximum Entropy Summary Trees*

A data-reduction method for visualising very large node-weighted trees. Given an *n*-node tree and a budget of *k* ≤ *n* display nodes, the goal is to pick the most informative *k*-node aggregation. The paper formalises this as a **summary tree**: a partition of the original node set in which each summary-tree node represents either a single original node, an entire subtree, or — and this is the novel piece — an "other" node that aggregates a chosen subset of siblings *together with all their descendants*. At most one "other" child is allowed per parent.

Informativeness is measured by the information-theoretic entropy of the weighted distribution induced by the summary tree's nodes. Maximising this entropy avoids "lopsided" summaries where one supernode swallows most of the weight. The contributions are:

1. **Definition** of summary trees and their entropy.
2. **An exact pseudopolynomial dynamic-programming algorithm** (O(K²nW)) for integer weights, parameterised by the weight of the candidate "other" child at each subtree.
3. **An additive ε-approximation** for real-valued weights, using a discrepancy-based rounding scheme so subtree-weight sums are preserved within ±1, then running the exact algorithm on the rounded tree. Running time O((K³/ε) n log max{K, 1/ε}), independent of W.
4. **A fast greedy heuristic** in O(K²n) that, on every real dataset tested, returned ≥94% of the optimal entropy in under six seconds.

Demonstrated on five datasets — web-portal click traffic (19,335 nodes, weights summing to 260M), a hard drive, the Tree of Life Web Project (94,080 nodes), the Mathematics Genealogy subtree rooted at Gauss (43,527 nodes), and a 43,134-employee org chart. Recommended layout is a layered node-link drawing with node area proportional to weight, viewed as a sequence k = 1, 2, …, K to provide an analogue of zooming.

## McGuffin, Davison & Balakrishnan — *Expand-Ahead: A Space-Filling Strategy for Browsing Trees*

A strategy that automatically expands additional descendants of the focal node to fill leftover screen space, *without* scaling or distortion: text labels stay at constant size and the viewport never overflows. The user only ever picks a focal node *F*; the algorithm then greedily expands children of *F*, then children of those, breadth-first, until no further expansion fits. Where multiple candidates compete for space, a weighting function *w(n)* picks the winner — e.g. *w(n) = 1/numChildren(n)* maximises the count of expanded nodes, or *w(n) = frequency* favours historically popular nodes.

Positioned against alternatives that pack large trees by shrinking nodes (Treemaps, space-optimized trees) — these scale further but sacrifice label legibility. Positioned against SpaceTrees as a generalisation: SpaceTrees only expand a level if *every* node on it fits ("uniform expand-ahead"), whereas this algorithm expands selectively, filling space more aggressively but producing less regular layouts.

Two prototypes: a 1D outline view, and a 2D nested-containment view with row/column tiling and five-phase animated transitions (fade out, collapse, move/resize, expand, fade in) lasting at most one second. The 2D prototype also supports "zooming down" — incrementally shrinking the font reveals deeper levels everywhere at once without changing the focal node, giving a bird's-eye view.

A controlled drill-down experiment (n = 12, two trees, three task types — random paths, repeated paths, repeated paths with perturbation):

- **Random paths:** 2D expand-ahead ≈ no expand-ahead; 1D was significantly *worse*.
- **Practised, unperturbed paths:** 2D expand-ahead was ~12.7% faster than no expand-ahead.
- **Practised paths with perturbation** (random subtree swaps between trials): no expand-ahead was ~6.5% faster than 2D.

The pattern matches the usual adaptive-UI tradeoff: expand-ahead helps when users learn the layout, but layout instability — when the system rearranges things the user expected to stay put — erases the gain. A Fitts'-law model in §4.1 frames this in terms of effective branching factor: expand-ahead increases *B*, reducing the click count log_B *N* but increasing the per-click search and pointing time *T_F + T*.

## Why these three together

All three address the same screen-budget problem from complementary angles:

- **Hoptrees** answers *"how does the user keep track of where they have been?"* by structuring history along the tree, not along time.
- **Maximum Entropy Summary Trees** answers *"given a node budget, which nodes carry the most information?"* with a principled, automatable answer.
- **Expand-Ahead** answers *"how should the system use leftover screen space around the user's current focus?"* with a heuristic-driven space-filling rule.

Together they cover history (Brooks), summary (Karloff & Shirley), and adaptive expansion (McGuffin et al.) — three lenses on the same underlying constraint that this viewer also sits inside.
