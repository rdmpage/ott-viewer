-- ott.db schema for the ott-viewer SQLite store.
--
-- Built from tree-parser TSV outputs:
--   taxa.tsv         → taxa
--   nodes.tsv        → tree
--   annotations.tsv  → annotations
--
-- Apply via build.sh, or manually:
--   sqlite3 ott.db < schema.sql
--   (then .import the TSVs)
--   sqlite3 ott.db < indexes.sql
--
-- Indexes live in indexes.sql so they can be created post-import,
-- which is materially faster than maintaining them during bulk insert.

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ---------- Tables ----------

-- Synthesis-tree nodes (one row per node, internal or leaf).
CREATE TABLE IF NOT EXISTS taxa (
    id          INTEGER PRIMARY KEY,
    external_id TEXT NOT NULL UNIQUE,    -- 'ottN' or 'mrcaottXottY'
    label       TEXT
);

-- Synthesis-tree topology, in nested-set encoding.
-- A node B is a descendant of A iff A.nleft <= B.nleft AND B.nright <= A.nright.
-- The root has nleft = 1 and nright = 2 * (number of nodes) - 1.
CREATE TABLE IF NOT EXISTS tree (
    id     INTEGER PRIMARY KEY REFERENCES taxa(id),
    parent INTEGER REFERENCES taxa(id),
    depth  INTEGER,
    weight INTEGER,
    nleft  INTEGER NOT NULL,
    nright INTEGER NOT NULL,
    score  REAL
);

-- Per-node provenance: source phylogenies that support / conflict with each placement.
-- Synthesis nodes with no annotation row are taxonomy-only placements (no source phylogeny).
CREATE TABLE IF NOT EXISTS annotations (
    node_external_id TEXT NOT NULL,     -- joins to taxa.external_id
    relation         TEXT NOT NULL,     -- 'supported_by' | 'conflicts_with' | 'partial_path_of' | 'terminal' | 'was_uncontested'
    study_tree       TEXT NOT NULL,     -- e.g. 'ot_311@tree1'
    source_node_id   TEXT NOT NULL,
    FOREIGN KEY (node_external_id) REFERENCES taxa(external_id)
);

-- ---------- Parked tables (declared empty; populated by separate loaders) ----------

-- OTT taxonomy. Source: taxonomy/ott<version>/taxonomy.tsv ('\t|\t'-delimited).
-- Loader: TBD.
-- ott_id is stored bare ('NNN'), not 'ottNNN'; join via taxa.external_id with an 'ott' prefix.
CREATE TABLE IF NOT EXISTS taxonomy (
    ott_id        TEXT PRIMARY KEY,
    parent_ott_id TEXT,
    name          TEXT,
    rank          TEXT,
    flags         TEXT
);

-- Source-study metadata. Source: phylesystem-1 study/ot_<NN>/<id>/<id>.json (NexSON).
-- Loader: TBD.
CREATE TABLE IF NOT EXISTS studies (
    study_id         TEXT PRIMARY KEY,  -- e.g. 'ot_311'
    publication_ref  TEXT,
    doi              TEXT,
    year             INTEGER,
    focal_clade_name TEXT,
    curator_names    TEXT               -- JSON array
);

-- ---------- Views ----------

-- taxa_v: taxa plus an is_taxonomy_only flag derived from annotations membership.
-- A synthesis node with zero annotation rows is a taxonomy-only placement.
DROP VIEW IF EXISTS taxa_v;
CREATE VIEW taxa_v AS
SELECT
  t.id,
  t.external_id,
  t.label,
  CASE WHEN EXISTS (
    SELECT 1 FROM annotations a WHERE a.node_external_id = t.external_id
  ) THEN 0 ELSE 1 END AS is_taxonomy_only
FROM taxa t;
