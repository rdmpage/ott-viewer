# Responsive / mobile design — notes to come back to

A holding doc for the small-viewport question. The viewer reflows correctly
when the browser is resized, but the *visual result* on small or
unusual-aspect screens is unsatisfying. Decisions deferred until there's
hands-on time with concrete examples on real devices.

## Where we are now

Three pieces collaborate on layout:

- **`buildScene`** (`viewer.js`) computes a `yScale` that stretches the
  source 100×100 grid so the content's aspect matches the browser's pixel
  aspect. Floored at 1 (never compress), so portrait windows stretch the
  tree vertically and landscape windows leave it unstretched.
- **`fitViewBox`** sizes the `viewBox` to wrap all node positions plus
  label margin and bracket gutter; `preserveAspectRatio="xMinYMid meet"`
  centres vertically without distortion.
- **`capSizesForViewport`** caps `STYLE.labelFontSize`, `circleR`, and the
  stroke widths against absolute pixel ceilings (16 px / 6 px / 3 px / 2
  px) derived from the live viewBox→pixel scale. Below the cap threshold
  the in-coord-space defaults (`LABEL_FONT`, `NODE_R`, …) take over.

A `resize` listener (debounced at 120 ms) re-runs all three on window
changes — so the layout settles cleanly after a drag-resize and adapts
to a new aspect rather than uniformly squashing.

This part is correct. The issues below are about *what those mechanics
produce visually*, not about whether they fire.

## What looks ugly today

- **Font sizes shift between cap and floor.** As the browser shrinks past
  the threshold where `userUnits(16)` exceeds `LABEL_FONT`, the rendered
  font size stops being capped and starts being driven by the geometry.
  The transition is abrupt and labels can change visibly mid-resize.
- **Vertically-narrow windows squash the tree.** When the SVG height
  drops, `xMinYMid meet` keeps the aspect, so the pixel-scale tanks and
  every label, circle, and stroke shrinks proportionally. The tree
  technically fills the available rectangle but reads as miniature.
- **Portrait windows look denser than they should.** `yScale` happily
  stretches to a treeH×3 or more in a tall narrow column. Labels remain
  full-size but rows are tightly packed, and the right-side bracket
  gutter eats a noticeable share of the width.

## Impact of the navigation-history panel

The `#nav-history` `<details>` element below the navbar takes roughly
**150 px of vertical space** even when collapsed (header + the current
clade name on its own line) and more once the breadcrumb chip row is
populated. The body is `display:flex; flex-direction:column` and `#main`
(SVG container) is `flex:1 1 0`, so the SVG only gets whatever is left.

On a 300-px-tall window that means the tree gets ~80 px — which is the
single biggest reason short windows look bad. The resize logic is doing
the right thing; it just isn't given any room to work with.

**Likely fix when we get to it:** hide or auto-collapse `#nav-history`
below a height/width breakpoint, or replace it with a one-line breadcrumb
(text only, no chips) on small screens. The hoptree widget itself can
keep its full presentation when the viewport is large enough to deserve
it.

## Other things to consider

- **Aspect-aware caps.** The current font / circle ceilings are absolute
  pixel targets. A small phone screen at 2× DPR may want a different
  ceiling than a 27" monitor at 1× — and the cap could vary by aspect
  (more permissive in portrait where rows are sparse, tighter in
  landscape where rows are dense).
- **SVG `min-height`.** Could prevent the squash case by forcing the SVG
  to claim a minimum vertical share, at the cost of letting it overflow
  and scroll on very short windows. Trade-off worth seeing on real
  examples before deciding.
- **Bracket gutter on narrow widths.** `BRACKET_GUTTER_PAD +
  trackCount * BRACKET_TRACK_W` can take 20-30% of the width on a
  phone-width screen. Consider dropping the bracket column below a width
  breakpoint, or reducing `BRACKET_TRACKS_DEFAULT` adaptively.
- **Tip-label truncation.** Long tip names (`Candidatus
  Magnetomorum litorale + Thermotoga neapolitana DSM 4359 (113325)`)
  drive `labelMargin` even when most labels are short. On a phone this
  one outlier sets the gutter for everyone. Could clip with ellipsis +
  reveal-on-tap, or wrap into the info panel only.
- **Touch interaction.** The double-click-to-navigate model
  (`SINGLE_CLICK_DELAY = 260 ms`) translates to double-tap on touch.
  Worth checking that the timing feels right on a phone — the desktop
  value was tuned for mouse clicks, not finger taps.

## Decisions to make (later)

In rough order of how much they unlock:

1. **Nav-history collapse rule.** Single biggest visual win; mostly a
   CSS / `details[open]` toggle keyed on a media query.
2. **Mobile breakpoint policy.** Pick target widths (e.g. ≤480 px
   phone, ≤900 px tablet) and decide what changes at each. Currently
   nothing changes — the same layout scales everywhere.
3. **Font-cap behaviour at the threshold.** Smooth interpolation vs
   the current step, or accept the step and move the threshold.
4. **Bracket gutter / label truncation rules** for narrow widths.
5. **Touch tuning** — double-tap delay, hit-target sizes.

Nothing here is blocking; the resize mechanics work. Revisit once
there's a real phone in hand and a few representative trees to compare.
