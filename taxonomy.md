# Taxonomy, annotations, and synthesis membership

Notes on how the OTT taxonomy and synthesis-tree annotations fit into `ott.db`, and how we tell whether a synthesis-tree node was placed by phylogeny or by taxonomy alone.

## The pieces

The synthesis release (`opentree16.1_tree`, processed in `tree-parser`) ships:

- `labelled_supertree/labelled_supertree.tre` — the **full** synthetic tree. Includes taxonomy-only placements.
- `grafted_solution/grafted_solution.tre` — the synthetic tree **without** taxonomy-only placements.
- `annotated_supertree/annotations.json` — per-node provenance (`supported_by`, `conflicts_with`, `partial_path_of`, `terminal`, `was_uncontested`). Only nodes whose placement is justified by ≥1 source phylogeny appear here.

The OTT taxonomy is a separate download at <https://files.opentreeoflife.org/ott/>. Synthesis 16.1 used **OTT 3.7.2**, sitting in `taxonomy/ott3.7.2/`. The relevant file is `taxonomy.tsv`, which is `\t|\t`-delimited (with a trailing `\t|`) and has columns:

```
uid | parent_uid | name | rank | sourceinfo | uniqname | flags
```

## What taxonomy.tsv is — and is not

`taxonomy.tsv` describes the **OTT taxonomy itself** (rank, flags like `extinct` / `incertae_sedis`, parent in the taxonomy hierarchy, NCBI/GBIF/IRMNG/SILVA cross-references in `sourceinfo`). It tells you **nothing** about how a node ended up in the synthesis tree. The synthesis-vs-taxonomy distinction lives in the synthesis outputs, not in the taxonomy.

So the taxonomy file is for **enrichment** of synthesis nodes — adding rank, flags, and source xrefs to nodes already in the viewer — not for deriving membership.

## Detecting taxonomy-only placements

Two equivalent tests, both derivable from data we already have:

1. `id ∈ labelled_supertree` AND `id ∉ grafted_solution`
2. `id ∈ labelled_supertree` AND `id ∉ annotations.json["nodes"]`

Test 2 is the cheapest in our setup, because the annotations are already loaded into the `annotations` table in ott-viewer's `ott.db`. A taxonomy-only node is just a `taxa` row with no matching `annotations` rows.

## ott.db layout (current)

```sql
taxa(id, external_id, label)
  -- id          synthesis-tree node id (1, 2, 3, …)
  -- external_id 'ottN' or 'mrcaottXottY'
  -- label       display name

tree(id, parent, depth, weight, nleft, nright, score)
  -- synthesis tree topology, keyed by taxa.id

annotations(node_external_id, relation, study_tree, source_node_id)
  -- one row per (node, supporting-source) pair
  -- only present for source-supported placements
  -- node_external_id → taxa.external_id
```

## The `taxa_v` view

Materialises the taxonomy-only flag without changing the schema:

```sql
CREATE VIEW IF NOT EXISTS taxa_v AS
SELECT
  t.id,
  t.external_id,
  t.label,
  CASE WHEN EXISTS (
    SELECT 1 FROM annotations a WHERE a.node_external_id = t.external_id
  ) THEN 0 ELSE 1 END AS is_taxonomy_only
FROM taxa t;
```

Anywhere the viewer currently reads `taxa`, read `taxa_v` instead to get the flag for free. The subquery uses `idx_ann_node`, so it's an indexed lookup per row.

### Counts on the current build

| `is_taxonomy_only` | rows |
|---|---|
| 0 (source-supported) | 341,148 |
| 1 (taxonomy-only) | 2,384,534 |

So ~13% of synthesis-tree nodes have a phylogenetic placement; the remaining ~87% are taxonomy-derived.

### Worked example: `ott7662991`

*Thalassidroma macgillivrayi* (a species, GBIF: 9212399).

| source | status |
|---|---|
| `taxonomy.tsv` | present (`7662991 \| 3595646 \| Thalassidroma macgillivrayi \| species \| gbif:9212399`) |
| `labelled_supertree.tre` | present |
| `grafted_solution.tre` | absent |
| `annotations.json["nodes"]` | absent |
| `taxa_v.is_taxonomy_only` | 1 |

Exactly the pattern we expect for a taxonomy-only placement.

## Open / parked

Loading `taxonomy.tsv` into ott-viewer's `ott.db` for **node-label enrichment** (rank, flags, parent_ott_id) — separate from the membership question, which is now answered by `taxa_v`. Sketch agreed:

```sql
CREATE TABLE taxonomy (
    ott_id        TEXT PRIMARY KEY,
    parent_ott_id TEXT,
    name          TEXT,
    rank          TEXT,
    flags         TEXT,
    FOREIGN KEY(ott_id) REFERENCES taxa(external_id)
);
```

Decisions still to make when we pick this back up:

- **Filter at load, or load all of OTT?** Lean = filter to `uid IN (SELECT external_id FROM taxa)`; broad = load everything (~4–5M rows, hundreds of MB) so taxonomy-only siblings can be rendered later without a re-import.
- **Loader location.** Likely a PHP loader matching the `tree.php` / `coordinates.php` style. Has to handle the `\t|\t` separator (either preprocess with `sed 's/\t|\t/\t/g; s/\t|$//'` and `sqlite3 .import`, or stream-parse).
- **Index on `parent_ott_id`** only if/when we render taxonomy-only siblings.
