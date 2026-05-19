# Vertical orientation — design notes (prototype)

A second display style for the cladogram: root at the bottom, tips at
the top, with tip labels rotated −45° (MacClade convention). Motivation
is that wide monitors have lots of horizontal space the left-to-right
tree can't use; rotating the tree turns the wide axis into the leaf
axis so we can fit more leaves at the same readable density.

Behind a URL flag: append `?orient=v` to any viewer URL. Default is
`h` (the existing left-to-right layout), preserved byte-for-byte.
The flag is read once at page load (`IS_VERTICAL` constant near the
top of `viewer.js`) and is sticky across `navigateTo` because
`history.pushState` only rewrites the `taxon` parameter.

This is a prototype. Several knobs are first-guess values rather than
considered design decisions — see *Known limitations / iteration
knobs* at the end.

## Architecture

**Single boundary axis-swap.** Server-side coords (`coordinates.php`)
remain unchanged: x ∈ [0,100] = depth, y ∈ [0,100] = leaf-DFS order.
The viewer calls `rotateTreeCoords(tree)` once on every JSON it
receives (`fetchTree` and `loadTrees`), swapping x/y and negating the
new y so the root lands at large display-y (bottom of SVG, since y
points down) and tips at small display-y (top).

```js
function rotateTreeCoords(tree) {
  Object.values(tree.nodes).forEach(n => {
    const sx = n.x;
    n.x = n.y;
    n.y = -sx;
  });
}
```

After this boundary, every downstream component — the transition
machinery in `buildScene`, the animation lerps, hit-testing, brackets,
peek — operates on display coords and is largely orientation-agnostic.
The only orientation-aware code paths are:

1. The edge L-shape direction (vertical mode draws "horizontal at
   parent y, then vertical to child" instead of the reverse).
2. Tip-label rotation (−45° transform).
3. Triangle marker for collapsed-subtree tips (apex rotates from
   left-pointing to down-pointing).
4. Bracket gutter direction (horizontal bars above tips vs. vertical
   bars right of tips).
5. Annotation slot position (support/conflict numbers flank the new
   edge orientation).
6. The peek overlay (separate `renderPeekVertical` function).
7. `fitViewBox` and `idealK` (which axis carries the leaf budget).
8. Exit/enter fallback anchor (slide toward y=0 instead of x=0 for
   nodes without a persistent ancestor).

## Per-component changes

### Edges (`render`)

Cladogram L-shape:
- Horizontal: vertical at parent x, then horizontal to child.
- Vertical: horizontal at parent y, then vertical to child — so
  children "rise" off a shoulder rather than branching off a spine.

### Tip labels (`render`)

`transform="rotate(−45 0 ly)"` where `ly = -labelDx`. The rotation
centre is the label START point (directly above the node), not the
node centre. **This was the bug-prone bit:** rotating around (0,0)
put the label origin diagonally off-centre — labels appeared
right-shifted from their tips. Rotating around the label start keeps
the column visually aligned: labels emerge straight up from each tip
and lean up-right at 45°.

`labelDx` itself was a latent issue. It used to be a fixed user-space
constant (`NODE_R + 2.5`) while `capSizesForViewport` pinned font,
circle radius, and stroke widths to pixels — so the visual gap from
circle edge to label start drifted with viewBox scale. It's now
derived in `capSizesForViewport` as `scene.minStepTip + circleR`, so
the edge-to-label gap equals one inter-leaf step. Same fix benefits
the horizontal view; the visual difference is small there but real.

### Collapsed-subtree triangles

Apex points "into the tree" (toward root). In horizontal that's left;
in vertical that's down. A `transform="rotate(−90)"` on the polygon
sends apex point `(−r, 0) → (0, r)`.

### Brackets

Two-part change:

1. `computeBracketState` reads the leaf axis via a small helper:
   `p.x` in vertical mode (post-rotation, leaves run along x), `p.y`
   in horizontal. The bracket "ranges" are now ranges on the leaf
   axis, mode-agnostic.
2. `render`'s bracket layer branches on orientation:
   - Horizontal: vertical bars right of the rendered label band,
     bracket labels centred to the right of each bar.
   - Vertical: horizontal bars above the rendered diagonal label
     band (offset by `labelTopMin − BRACKET_GUTTER_PAD`), bracket
     labels horizontal text centred above each bar. Additional
     tracks stack upward.

The label-band-top calculation uses `n.to.y − (labelDx + visibleLen·charW)·cos(45°)`
since 45° labels project upward by `cos(45°)` of their length.

### Annotation numbers (support / conflict)

The two slots flank the incoming edge. Renamed `minStepX/minStepY` to
`minStepDepth/minStepTip` (with backwards-compat aliases). In
horizontal mode the slot is to the left of the node with the two
numbers stacked vertically; in vertical mode the slot is below the
node with the numbers side-by-side.

### Peek overlay (`renderPeek` → `renderPeekVertical`)

Horizontal peek expands rightward from the anchor with a vertical
trunk, branches extending right, and horizontal labels. Vertical
peek mirrors this across the diagonal: lead extends upward from the
anchor, trunk is horizontal, branches rise to tip circles, labels
rotate −45°.

**Backdrop is a hexagonal polygon, not a rect** — a rectangle sat
across the main tree's tip labels and obscured them. The hexagon
joins a rectangle over the trunk+branches+circles to a 45°
parallelogram over the diagonal label band:

```
   TL ●─────────────● TR        ← labelStartY − diag
     ╲             ╲
      ╲  labels   ╲              ← 45° slant matches label rotation
       ╲           ╲
   ML  ●────────────● MR        ← labelStartY
       │            │
       │  head box  │            ← trunk + branches + circles
   BL  ●────────────● BR        ← trunkY + padY
```

`diag = longest_label · cos(45°)` so every label sits inside its own
diagonal column. Single closed polygon (not two overlapping rects)
so the semi-transparent fill doesn't double-darken at the seam.
Animation lerps each corner from the anchor point independently —
the polygon grows out from a point.

### Sizing

Three orientation-aware sizing decisions, in order of how much they
matter:

**`yScale` in `buildScene` — viewport-aware depth compression.** The
key trick. Vertical mode picks `yScale` so that `fitViewBox` ends up
*leaf-bound* (`sLeaf ≤ sDepth`), which is the regime where leaves
fill the full SVG width. Without this, the system goes depth-bound on
wide monitors and centres a too-tall tree with empty space on both
sides. The formula falls out of setting the two scales equal:

```
sLeaf  = (W − L − 2m) / treeW
sDepth = (H − L − m)  / (treeH·yScale + B)
sLeaf ≤ sDepth  →  yScale ≤ ((H − L − m)·treeW / (W − L − 2m) − B) / treeH
```

Where W,H are SVG dimensions, m is the side margin, L is the
longest-label diagonal projection (`longest·FONT_PX·0.5·cos(45°)`),
B is the bracket gutter height in user-units. Take 95 % of that
upper bound for slack so we're firmly leaf-bound. Clamped to
`[0.2, 1]`.

For a typical 1500×800 monitor with treeW = treeH ≈ 100 and
longest = 16 chars, this yields `yScale ≈ 0.30`. On a near-square
window it clamps closer to 1 and the tree keeps its natural depth.

**`fitViewBox` vertical branch.** Picks `scale = min(sLeaf, sDepth)`
explicitly. After the `yScale` fix above we're always leaf-bound,
but the depth bound is still computed as a guardrail. Diagnostic
log reports `bound=leaf` vs `bound=depth`.

**Leaf-axis viewBox anchoring.** `vx = minX − marginPx/scale` (left-anchored).
With `sLeaf` scaling, this places the leftmost tip ~16 px from the
SVG left edge and lets all the label-margin slack sit on the right
where the rightward-projecting labels actually need it. The
alternative (centring the tree) leaves wasted space on the left and
clips the rightmost labels past the SVG right edge.

**`idealK`** uses `ROW_PX·1.5` per leaf in vertical mode (vs `ROW_PX`
in horizontal) because adjacent 45°-rotated labels are separated
perpendicularly by `pitch·cos(45°)`, so they need ~√2× the pitch for
the same visual gap. Reads `svg.clientWidth` instead of
`clientHeight`.

**`preserveAspectRatio`** is set to `xMidYMax meet` at `init` time
so the root stays glued to the bottom and any extra height
(narrower-than-expected windows) accumulates above.

## Tradeoffs explicitly chosen

- **Tree sits slightly left of centre in vertical mode.** Function of
  longest-label length — deeper trees full of long names feel more
  off-centre. The alternative is to reserve `2L` of side margin so the
  tree can be visually centred, costing ~6 % of width-fill. Discussed
  and chose width-fill.
- **Bracket labels horizontal text centred above each bar.** Long
  clade names with two adjacent brackets could collide. Acceptable
  for the prototype because there's usually one bracket track and
  named clades sit on distinct leaf-axis ranges.
- **Peek wheel scrolling uses `deltaY` even though vertical-mode peek
  scrolls through columns horizontally.** Slightly unintuitive but
  matches the trackpad gesture most users actually use.
- **Per-leaf pitch in vertical = ROW_PX × 1.5.** First-guess
  heuristic for diagonal stacking. Could be tightened.
- **No additional padding on the backdrop's diagonal edge** — labels
  sit tight against the parallelogram top/bottom. Visually clean but
  could be a hair generous.

## Known limitations / iteration knobs

In rough order of likelihood we'll want to revisit:

1. **Bracket label collisions** when multiple named clades sit close
   on the leaf axis. Options: rotate bracket labels themselves, push
   to bar start instead of centre, drop more aggressively.
2. **Per-leaf pitch (`ROW_PX·1.5` in `idealK`)** — adjust the `1.5`
   if leaves feel too sparse or too dense.
3. **yScale slack factor (`0.95` in `buildScene`)** — lower for more
   headroom above tips, higher to use more vertical space for tree
   depth.
4. **`K_MAX = 80`** caps leaf count. On very wide monitors (≳ 2400 px)
   `idealK` plateaus at 80 and per-leaf pitch grows beyond 30 px;
   tree fills width via `sLeaf` regardless, but the leaves get
   visually sparse. Raise `K_MAX` if needed.
5. **Backdrop diagonal padding.** A `padDiag` perpendicular offset
   would give breathing room between labels and the parallelogram
   edge.
6. **Hoptree (navigation breadcrumb mini-tree) is unchanged.**
   Always renders horizontal regardless of `IS_VERTICAL`. Probably
   correct for now — it's a small overview, not part of the main
   layout — but the prototype hasn't considered it.
7. **No in-UI toggle.** URL-only flag for prototyping. If we keep
   this, we'd want a button somewhere obvious.

## Files touched

- `viewer.js` — all logic. Search for `IS_VERTICAL` and
  `rotateTreeCoords` for orientation handling; `renderPeekVertical`
  for the peek branch.
- `viewer.css` — none. All existing classes (`.peek-backdrop`,
  `.bracket-label`, etc.) work in both orientations since they're
  positional/colour-only, not layout-anchored.
- Server-side (`coordinates.php`, `tree.php`, etc.) — none. The
  rotation is purely client-side.

## Testing

`http://<viewer>/?taxon=ott452461&orient=v`. Try:
- Tip-label gap should equal leaf spacing at any zoom level
  (test by resizing the window).
- Clicking an `other_…` placeholder should pop a peek upward with
  the hexagonal backdrop sitting behind the labels (not over the
  main-tree tip labels below).
- Clicking a non-tip should navigate; URL gets `?taxon=…&orient=v`
  preserved.
- Search, breadcrumb, info panel — all should still work since they
  don't touch geometry.
