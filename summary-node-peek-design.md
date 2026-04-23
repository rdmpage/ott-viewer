# Summary Node Peek Interaction — Design Notes

## Context

The tree viewer renders summary trees of the tree of life and animates transitions between different views. Tips of the tree fall into three categories:

1. Nodes labelled with a real name (e.g. *Puffinus subalaris*)
2. Nodes labelled with a made-up MRCA name (e.g. `mrcaott31017ott134468`)
3. **Summary nodes** — a single node that stands in for a set of underlying nodes

The first two are nodes in the original tree. The third is an abstraction: its purpose is to hide complexity so the summary tree stays legible.

## Problem

How can a user see inside a summary node and select a specific node from within it, without "breaking" the current visualisation?

The core tension: a summary node exists precisely to hide its contents. Any way in must not destroy the abstraction that made the view useful in the first place.

## Options considered

### 1. Peek without commit (chosen)

Tapping a summary node opens a floating panel (popover or side drawer) listing the contained nodes. The main tree stays frozen. The user can scroll, search, and select. Only on selection does the main tree animate to a new view.

Analogous to Google Earth's handling of multiple pins at the same point — the cluster expands into a list/ring rather than forcing a zoom.

**Pros:** least disruptive, reversible, preserves mental model of the current view.
**Cons:** requires panel UI; selection still triggers a transition, so there's a "commit" step.

### 2. Inline expansion with reflow

The summary node expands in place into its constituent subtree, pushing siblings apart via the existing transition animation (summary node as "exit", contents as "enter").

**Pros:** reuses existing transition machinery.
**Cons:** large sets (e.g. the *Puffinus* expansion with 30+ tips) dominate the view; needs max-height and internal scroll to mitigate.

### 3. Drill-in as a new transition

"Look inside summary" becomes a first-class tree transition — the summary node effectively becomes a new root context and the view animates into it. A back button returns.

**Pros:** clean reuse of the transition model.
**Cons:** this *is* a navigation, not a peek. Changes the visualisation by design, which is what we wanted to avoid.

### Hybrid

Tap = peek panel; long-press (or a small chevron affordance) = inline expand. Gives users both "just show me what's in there" and "I want to work with it in the tree."

## Chosen approach: peek panel

Tap a summary node → scrollable panel listing the contained nodes → user picks one → tree transitions with that node as focus.

## Design details to resolve

### Panel contents

- For summary nodes containing many tips (e.g. ~30 *Puffinus* species), a flat alphabetical list is probably more useful than trying to render a sub-cladogram in the panel.
- Consider a toggle between **list view** and **tree view** for cases where the subtree has meaningful internal structure worth seeing.
- For MRCA-named nodes nested inside a summary, show the made-up name alongside a count of descendants, so users know what they are picking into.

### Search / filter

- Once the list exceeds ~20 items, a filter box at the top of the panel becomes essential.
- Especially useful when users are looking for a species they half-remember.

### Meaning of "focus" after selection

Two possible interpretations of the resulting transition:

- **(a)** New view centred on the selected node with its siblings/context pulled from the original tree.
- **(b)** Fresh summary-tree computation with the selected node as the anchor.

Likely **(b)**, for consistency with how the tool works elsewhere. The animation will look quite different between the two, so worth being explicit in implementation.

### Panel dismissal

- Tap outside the panel, and/or an explicit close button (both is ideal).
- If the panel is open on node A and the user taps node B, **swap** the panel contents rather than close-and-reopen — feels smoother.

### Visual affordance

- Summary nodes must telegraph "I'm tappable and contain things."
- The existing "has more descendants" open-circle marker is the natural hook.
- Consider a subtle count badge and/or a chevron on hover to signal that peek is available.

### Navigation history

- After: peek → select → transition, the user may want to go back.
- Do they land back at the original view or somewhere else?
- A breadcrumb trail or a dedicated back button in the peek flow will save a lot of frustration.

## Open questions

- Should the peek panel be position-anchored to the tapped node (popover) or docked (side drawer)? Popover preserves spatial context; drawer handles long lists better.
- On small/mobile viewports, drawer is probably the only sensible choice.
- Does the peek panel need to support multi-select (e.g. "show me all of these in the next view")? Out of scope for v1 but worth not painting into a corner.
