#!/usr/bin/env bash
#
# Rebuild ott.db from tree-parser TSVs.
#
# Inputs (overridable via env):
#   TSV_DIR  — directory containing taxa.tsv, nodes.tsv, annotations.tsv
#              default: $HOME/Development/tree-parser/out
#   DB_PATH  — output SQLite database
#              default: <this script's dir>/ott.db
#
# Usage:
#   ./build.sh
#   TSV_DIR=/some/other/out ./build.sh
#
# Requires sqlite3 ≥ 3.32 (for `.import --skip 1`).

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
TSV_DIR="${TSV_DIR:-$HOME/Development/tree-parser/out}"
DB_PATH="${DB_PATH:-$HERE/ott.db}"
SCHEMA="$HERE/schema.sql"
INDEXES="$HERE/indexes.sql"

# ---------- Preflight ----------

[[ -d "$TSV_DIR" ]] || { echo "TSV_DIR not found: $TSV_DIR" >&2; exit 1; }
for f in taxa.tsv nodes.tsv annotations.tsv; do
  [[ -f "$TSV_DIR/$f" ]] || { echo "Missing $TSV_DIR/$f" >&2; exit 1; }
done
[[ -f "$SCHEMA"  ]] || { echo "Missing schema:  $SCHEMA"  >&2; exit 1; }
[[ -f "$INDEXES" ]] || { echo "Missing indexes: $INDEXES" >&2; exit 1; }

# ---------- Wipe existing db ----------

if [[ -e "$DB_PATH" ]]; then
  echo "Removing existing $DB_PATH"
  rm -f "$DB_PATH" "$DB_PATH-wal" "$DB_PATH-shm"
fi

# ---------- Apply schema (tables + views, no indexes) ----------

echo "Applying schema → $DB_PATH"
sqlite3 "$DB_PATH" < "$SCHEMA"

# ---------- Import TSVs ----------
#
# foreign_keys=OFF so the FK on annotations.node_external_id doesn't slow
# the bulk insert; re-enabled afterwards. Single transaction per import for speed.

echo "Importing taxa.tsv"
# Two wrinkles:
#  - 126K rows have an empty external_id but a 'mrcaott…' label that *is* the
#    canonical id for that node — stage and COALESCE so external_id stays
#    UNIQUE-and-NOT-NULL.
#  - .mode tabs uses CSV-style quoting, so the one label containing literal
#    double-quotes ("Coelophysis" kayentakatae) breaks the reader. Strip them
#    via a temp file (only that one row is affected).
TMP_TAXA="$(mktemp -t ott_taxa.XXXXXX)"
trap 'rm -f "$TMP_TAXA"' EXIT
tr -d '"' < "$TSV_DIR/taxa.tsv" > "$TMP_TAXA"

sqlite3 "$DB_PATH" <<SQL
PRAGMA foreign_keys = OFF;
CREATE TEMP TABLE _taxa_staging (id INTEGER, external_id TEXT, label TEXT);
.mode tabs
.import --skip 1 "$TMP_TAXA" _taxa_staging
INSERT INTO taxa (id, external_id, label)
  SELECT id, COALESCE(NULLIF(external_id, ''), label), label FROM _taxa_staging;
SQL

echo "Importing nodes.tsv → tree"
sqlite3 "$DB_PATH" <<SQL
PRAGMA foreign_keys = OFF;
.mode tabs
.import --skip 1 "$TSV_DIR/nodes.tsv" tree
SQL

echo "Importing annotations.tsv"
sqlite3 "$DB_PATH" <<SQL
PRAGMA foreign_keys = OFF;
.mode tabs
.import --skip 1 "$TSV_DIR/annotations.tsv" annotations
SQL

# ---------- Indexes + ANALYZE ----------

echo "Creating indexes"
sqlite3 "$DB_PATH" < "$INDEXES"

# ---------- Sanity report ----------

echo "Done. Row counts:"
sqlite3 -header -column "$DB_PATH" <<'SQL'
SELECT 'taxa'        AS table_name, COUNT(*) AS rows FROM taxa
UNION ALL SELECT 'tree',        COUNT(*) FROM tree
UNION ALL SELECT 'annotations', COUNT(*) FROM annotations
UNION ALL SELECT 'taxonomy',    COUNT(*) FROM taxonomy
UNION ALL SELECT 'studies',     COUNT(*) FROM studies;
SQL
