# Clade brackets — design notes

Vertical bars to the right of the tip labels marking named multi-tip
internal clades (genera, families, …). Each bracket spans the y-range
of its descendants in the rendered tree and carries the clade's name.

The first prototype lives in `brackets.html`; the integrated version is
in `viewer.js` (search for `BRACKET_` and `computeBracketState`) with
CSS in `viewer.css` (`.bracket-line`, `.bracket-label`).

## What it solves

The viewer shows tip names and node circles. Internal-node *names* —
the things readers usually care about ("this clade is *Pterodroma*") —
used to be drawn beside each named internal node on the tree, but
they crowded the layout and competed with tip labels for the same
gutter. Brackets move those names into a dedicated column on the
right, freeing the tree itself.

**The bracket gutter is now the single source of truth for internal
clade names.** `render()` no longer draws labels for any internal
node — only tips are labelled in the tree. Any internal whose
bracket gets dropped (overlap with a higher-priority bracket, or
filtered out by the candidate rules) loses its label entirely. That's
deliberate: the alternative is to have a label in two places at
once.

## How it works

For the destination tree of each navigation:

1. **Candidates.** Filter `nodes` to internal nodes whose id matches
   `^ott\d+$` (drops anonymous mrca nodes, `other_*` placeholders, the
   synthetic `stub`), excluding the displayed root (its bracket would
   span every tip), excluding monotypic internals (single child = no
   information), excluding clades with fewer than `BRACKET_MIN_TIPS`
   descendant tips.
2. **Sort** by the chosen priority (`BRACKET_SORT` — see below).
3. **Greedy interval colouring.** For each candidate in sort order,
   pick the leftmost track whose existing brackets don't overlap on y;
   open a new track if none works and we're under the budget; drop the
   candidate otherwise. Track count is capped at
   `BRACKET_TRACKS_DEFAULT`.
4. **Render** at rest only — brackets fade in over the last
   `(1 - BRACKET_REST_THRESHOLD)` of the transition. During motion
   the placement is meaningless because the y-range only describes the
   destination tree.

The per-node y-range and tip count come from a memoised DFS over
`tree.edges`; the sort key (depth) comes from a BFS from
`tree.displayed_root_id` (because `tree.php` doesn't emit a depth
field). All ranges are computed against `scene.nodes[].to.y` so the
brackets line up with exactly where the tips will render at
`currentT = 1`.

## Configurable knobs

All currently hardcoded near the top of the rendering section in
`viewer.js`. None are exposed in the UI — the goal right now is to
pick one set of defaults the user can react to. If we want to surface
controls later, this is the list:

| Constant                   | Default      | What it controls |
|----------------------------|--------------|------------------|
| `BRACKET_TRACKS_DEFAULT`   | `1`          | Max number of vertical track columns. Higher = more brackets fit, wider gutter, more visual noise. |
| `BRACKET_MIN_TIPS`         | `2`          | Skip clades smaller than this. `1` would include anything (including monotypic genera if any survive the multi-child filter); `3+` aggressively prunes small clades. |
| `BRACKET_SORT`             | `'depth-asc'`| Priority order for placement. `'depth-asc'` = shallowest first (gives the broadest grouping), `'depth-desc'` = deepest first (more nested labels survive), `'size-desc'` = largest clade first, `'size-asc'` = smallest first. |
| `BRACKET_REST_THRESHOLD`   | `0.97`       | `currentT` at which brackets start fading in. Lower = brackets visible during more of the transition (but stale-looking). |
| `BRACKET_TRACK_W`          | `LABEL_FONT * 12` | Width of one track column in user-space units. Driven by expected label length × char width. |
| `BRACKET_LABEL_GAP`        | `LABEL_FONT * 0.6` | Gap between the bracket bar and the start of its label. |
| `BRACKET_GUTTER_PAD`       | `LABEL_FONT` | Padding between the rightmost tip label and the first track. |

Other knobs we considered and *didn't* expose:

- **Drop-monotypic toggle.** Filtering monotypic internals is a hard
  rule, not an option — a single-child node with the same descendants
  as its child is just visual noise.
- **Bracket style** (round vs square caps; with vs without short
  horizontal ticks at top/bottom). The prototype had ticks; we
  removed them per user request, leaving plain vertical bars. The
  current rule is "vertical line only".
- **Per-clade colour.** All brackets share `var(--text-link)`. CSS
  classes per-track (`t0/t1/t2`) exist in the `brackets.html`
  prototype but were dropped from the integrated version since
  there's only one track by default.

## Trade-offs of the current defaults

`BRACKET_TRACKS_DEFAULT = 1` + `BRACKET_SORT = 'depth-asc'` means: the
broadest named clade always wins, and any nested clades inside it are
dropped. This is the right call when the user wants a single "what
group am I looking at" cue and the tree only has a few candidates
(`Procellariiformes` shows 4 disjoint family-level brackets and all
fit). It's the *wrong* call for trees where the broadest clade spans
all tips — e.g. *Mammalia* at k=60 has *Theria* as a candidate, and
*Theria* spans every tip, so every nested clade (Eutheria,
Boreoeutheria, Primatomorpha, …) is dropped and the user sees one
bracket. If that becomes a complaint, options:

1. Increase `BRACKET_TRACKS_DEFAULT` to 2 or 3 — restores nested
   clades at the cost of gutter width.
2. Switch `BRACKET_SORT` to `'depth-desc'` — deepest clades placed
   first, broader ones drop. Reads more like "what genera do I see".
3. Compute "displayed coverage" — drop candidates whose tip set is the
   same as some other already-placed candidate's tip set (i.e.
   collapse Theria + Eutheria + Boreoeutheria when they all span
   every tip in this view).

## Files

- `viewer.js` — `computeBracketState`, render hook in `render()`,
  viewBox extension in `fitViewBox`, calls in `navigateTo` / `init`.
- `viewer.css` — `.bracket-line`, `.bracket-label` rules.
- `brackets.html` — original standalone prototype with all the
  controls (tracks / sort / min-tips / candidate taxon) exposed as
  form inputs. Useful as a sandbox for tuning the defaults.

## Status

Working end-to-end. Brackets appear after every navigation, fade in
with the tree, and follow theme tokens for light/dark mode. No
controls in the UI — the prototype `brackets.html` remains the place
to experiment with parameter values.
