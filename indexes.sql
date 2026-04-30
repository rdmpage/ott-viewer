-- Indexes for ott.db. Apply AFTER bulk-importing TSVs — building indexes
-- against an already-populated table is much faster than maintaining them
-- during .import.
--
-- Apply: sqlite3 ott.db < indexes.sql

-- taxa: external_id is already UNIQUE (declared in schema), so no separate index needed.
-- label is searched by name lookups (e.g. find_taxon).
CREATE INDEX IF NOT EXISTS idx_taxa_label ON taxa(label);

-- tree: parent for child enumeration; nleft/nright for nested-set range scans.
CREATE INDEX IF NOT EXISTS idx_tree_parent ON tree(parent);
CREATE INDEX IF NOT EXISTS idx_tree_nleft  ON tree(nleft);
CREATE INDEX IF NOT EXISTS idx_tree_nright ON tree(nright);

-- annotations: lookup by node (most common) or by source study.
CREATE INDEX IF NOT EXISTS idx_ann_node  ON annotations(node_external_id);
CREATE INDEX IF NOT EXISTS idx_ann_study ON annotations(study_tree);

-- taxonomy (parked).
CREATE INDEX IF NOT EXISTS idx_taxonomy_parent ON taxonomy(parent_ott_id);
CREATE INDEX IF NOT EXISTS idx_taxonomy_name   ON taxonomy(name);

-- Refresh planner stats.
ANALYZE;
