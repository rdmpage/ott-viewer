# Open Tree of Life viewer

An interactive viewer for the [Open Tree of Life](https://tree.opentreeoflife.org/) synthesis tree, using a "summary tree" approach to fit arbitrarily large clades into a fixed display size while keeping all labels legible.

## Approach

The synthesis tree has ~2.7 million nodes, most of which are unnamed internal nodes (labelled with `mrcaottXottY` placeholders). Displaying this tree requires a strategy for showing only the most informative subset at any given time.

A **summary tree** is constructed by expanding a subtree from a given root node outward in priority order (scored by the number of descendant tips) until a size budget **k** is reached, where **k is the number of leaves in the summary**, not the total number of nodes. Children that are not individually expanded are collapsed into synthetic "other" placeholder nodes. This ensures any node in the tree can be displayed in at most k lines, regardless of how many children it has.

The viewer renders the summary as a left-to-right **cladogram** (root on the left, tips aligned on the right, internal nodes equally spaced horizontally). When the user navigates by clicking on a node:

- If the clicked node has a large subtree (weight >= k), it becomes the new root (drill in).
- If it has a small subtree, the viewer climbs to the smallest ancestor with enough descendants and force-expands the path to the clicked node, keeping it visible in context.

Transitions between successive views are animated: shared nodes slide to their new positions, exiting nodes fade out toward the root (left), and entering nodes grow in from their nearest shared ancestor.

## Current state

- **Summary tree engine** (`summary.php`): computes summaries by node count or leaf count, with optional forced-expansion paths for focus-on navigation. Outputs Newick (with NHX annotations for node type, weight, and leaf status) and a nested PHP data structure for HTML rendering.
- **Tree toolkit** (`tree/`): PHP classes for tree nodes, iterators, Newick parsing, and tree ordering (ladderize by weight).
- **HTML list view** (`test.php`): renders the summary as a nested `<ul>` with CSS classes distinguishing expanded internals, genuine supertree tips, collapsed subtree roots, "other" placeholders, and the focal node. Controls for taxon id, k, and mode (by-leaves / by-nodes).
- **Transition viewer** (`transition.html`): standalone SVG animation that loads two tree layouts from `tree1.json` and `tree2.json` and animates the morph between them (exit/persist/enter with anchor rules, cubic easing, bidirectional playback, scrub slider). Tip nodes always show labels; internal `mrca*` labels are hidden. Tips with `mrca*` labels are drawn as hollow circles to indicate they have more descendants (following the OTT viewer convention). SVG fills the browser window and rescales on resize via viewBox.

## Existing OTT viewer conventions

The current opentreeoflife.org tree viewer (`legend.png`) uses the following visual conventions, which are worth considering for this project:

- **Node size reflects number of descendants** — filled circles scaled by descendant count (small, medium, large).
- **Hollow tips for unexpanded subtrees** — open circles (○) indicate leaves in the current view that are actually internal nodes in the full tree (i.e. they have more descendants not currently shown). Filled circles indicate genuine terminal taxa.
- **Solid vs dashed edges** — solid lines for paths supported by phylogenetic data; dashed lines for paths supported only by taxonomy (no phylogenetic source tree).
- **Ancestor path styling** — nearest ancestors of the current focal node shown in a faded/greyed style.
- **Mouse-over hints and click-to-navigate** — interactive node selection.

## To do

### Next steps

- **Subtree-leaf labels**: the transition viewer now shows `mrca*` labels on tips and hides them on internals, but the labels are still raw OTT identifiers (e.g. `mrcaott31017ott134468`). These need replacing with something meaningful — e.g. weight/descendant count, or a representative descendant name. This requires richer metadata in the input JSON (currently just id, label, x, y).
- **"Other" node transitions**: when a node moves between being collapsed inside an "other" set and being individually visible (or vice versa), the animation needs to handle this gracefully. This requires deciding on a visual representation for the transition (e.g. the node emerging from / merging into the "other" placeholder position).
- **"Other" node contents**: the user needs a way to see what taxa are inside an "other" set, since a taxon of interest may be hidden there. Options include a tooltip, an expandable panel, or a search that highlights which "other" set contains a given name.
- **Direct summary-to-transition pipeline**: currently the transition viewer reads pre-built JSON files (`tree1.json`, `tree2.json`) generated externally via `treetest.php` from Newick strings shown by `test.php`. This needs to be replaced by a direct pipeline: click a node in the viewer, compute the new summary server-side, return the laid-out tree as JSON, and animate the transition client-side.

### Later

- Hollow circles for unexpanded subtree roots are now implemented in the transition viewer (based on `mrca*` label heuristic). Later, extend this to use explicit metadata (node type, weight) from the JSON so any non-leaf tip gets a hollow circle regardless of label.
- Consider further OTT viewer conventions: node size scaled by descendant count; solid vs dashed edges for phylogeny-supported vs taxonomy-only paths.
- Integrate the PHP tree layout code (`tree/`) to compute cladogram coordinates server-side and emit JSON directly from the summary engine.
- Handle the "other" node intersection problem: when transitioning between two trees, determine whether paired "other" nodes (same parent) share enough members to be treated as persistent vs. exit/enter.
- Add search: find a taxon by name and navigate to it (with focus-on).
- Consider alternative score functions (e.g. boost taxa with genomes, or taxa the user has previously visited).
- Persistence of interest: remember previously focused nodes and bias their priority so they remain visible across navigations.
