# MCP Server — design and usage

An [MCP](https://modelcontextprotocol.io/) (Model Context Protocol) server that lets LLMs interrogate the Open Tree of Life synthesis tree. Built so you can "talk to the tree" — ask questions about relationships, resolve phylogenetic definitions, and look up study contributions.

## Architecture

Three files, two transports, one shared handler:

```
mcp_handler.php       Shared tool definitions, dispatch, and implementations
mcp_server.php        Stdio transport (Claude Code / Claude Desktop)
mcp_http_server.php   HTTP transport (POST JSON-RPC to /mcp)
```

Both transports call the same `handleMcpRequest()` function. The handler uses `TreeQueries` (in `tree_queries.php`) for all tree operations against the local `ott.db` SQLite database.

## Setup

### Claude Desktop (stdio)

Add to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "ott-viewer": {
      "command": "php",
      "args": ["/path/to/ott-viewer/mcp_server.php"]
    }
  }
}
```

Restart Claude Desktop. The tools appear under the hammer icon.

### Claude Code

Add to `.claude/settings.json` or project settings:

```json
{
  "mcpServers": {
    "ott-viewer": {
      "command": "php",
      "args": ["/path/to/ott-viewer/mcp_server.php"]
    }
  }
}
```

### HTTP (remote clients)

The HTTP endpoint is available at `/mcp` via the Apache rewrite in `.htaccess`. POST JSON-RPC requests:

```bash
curl -X POST http://localhost/ott-viewer/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"sister_group","arguments":{"taxon":"Afrotheria"}}}'
```

GET on the same URL returns a plain-text info page listing available tools.

## Tools

All tools accept taxon names (e.g. "Elephas maximus") or OTT IDs (e.g. "ott541928"). Name resolution uses the `taxa.label` column in `ott.db`.

### resolve_name

Resolve taxon names to OTT IDs.

```
names: "Anolis carolinensis, Anolis sagrei"
```

### mrca

Find the most recent common ancestor of two or more taxa. Implements a **minimum-clade phyloreference** (PhyloCode).

```
taxa: "Elephas, Loxodonta, Mammuthus"
→ Elephantidae (ott541924), 12 tips
```

### max_clade

Find the largest clade containing specified taxa but excluding others. Implements a **maximum-clade (stem-based) phyloreference**.

```
include: "Anolis valencienni"
exclude: "Anolis sagrei, Anolis chrysolepis"
→ Anolis valencienni + Anolis reconditus (7 tips)
```

This directly resolves phylogenetic definitions like those in the PhyloCode and in papers using phylogenetic nomenclature (e.g. Poe et al. 2017 on Anolis).

### sister_group

Find the sister group of a taxon. Automatically climbs through monotypic ancestors (e.g. *Mylodon darwinii* → *Mylodon* → *Mylodontidae*) to find the first branching node, and reports which ancestor it climbed to.

```
taxon: "Mylodon darwinii"
→ climbed to Mylodontidae; sister is Megalonychidae (2 tips)
```

### is_monophyletic

Test whether a set of taxa form an exclusive clade.

```
taxa: "Elephas, Loxodonta, Mammuthus"
→ YES — monophyletic (MRCA = Elephantidae)
```

### triplet

Test a three-taxon relationship: are A and B more closely related to each other than either is to C?

```
closer: "Elephas, Mammuthus"
distant: "Procavia"
→ YES — Elephas and Mammuthus are more closely related
```

### node_info

Full details for a node: weight, depth, parent, sister group, and per-relation annotation breakdown (supported_by, conflicts_with, resolves, partial_path_of, terminal) with study citations and DOIs.

```
taxon: "Elephantidae"
```

### study_info

Look up a phylogenetic study by its Open Tree study ID or by DOI. Returns publication metadata, which trees are used in the synthesis, and how many nodes the study supports, conflicts with, or resolves.

```
study_id: "pg_1428"
doi: "10.1126/science.1211028"
```

## Phyloreference support

The `mrca` and `max_clade` tools directly implement the two main types of phylogenetic clade definition from the PhyloCode:

- **Minimum clade** ("the least inclusive clade containing A and B"): use `mrca`
- **Maximum clade** ("the most inclusive clade containing A but not B"): use `max_clade`

Both accept species names as published in phylogenetic definitions, resolving them against the OTT taxonomy. This means you can take a definition from a paper — e.g. "The most inclusive crown clade containing *Anolis cuvieri* but not *A. auratus*, *A. bimaculatus*, *A. armouri*, *A. carolinensis*, *A. semilineatus*, *A. vermiculatus*, and *A. punctatus*" — and resolve it directly.

## Protocol details

The server implements MCP over JSON-RPC 2.0. Methods:

- `initialize` — returns server info and capabilities
- `tools/list` — returns tool definitions with JSON Schema input specs
- `tools/call` — executes a tool, returns `{content: [{type: "text", text: "..."}]}`
- `ping` — health check

The stdio transport auto-detects line-delimited JSON (used by Claude) vs Content-Length framing (used by test clients).

## Known limitations

- **Name resolution** uses exact match on `taxa.label`. Synonyms, misspellings, and subspecies-only names (e.g. "Anolis distichus" when only subspecies exist) will fail. The `taxonomy` table is currently unpopulated — filling it would enable fuzzy matching and synonym resolution.
- **No tree-reading guide yet.** LLMs can misinterpret annotation counts and node semantics. See issue #6 for planned instructional resources.
- **Topology testing** is limited to triplet queries (three taxa). A general tree-vs-supertree comparison is a future goal.

## Relationship to the query API

The MCP tools and the HTTP query API (`/api/v1/query?op=...`) expose the same underlying `TreeQueries` operations. The query API returns structured JSON suitable for programmatic use; the MCP tools return human-readable text suitable for LLM consumption. Both accept taxon names or OTT IDs.
