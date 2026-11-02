# php-code-map

Turns a PHP codebase into a structural index (an AST-derived symbol graph) and keeps it fresh, so an
AI agent can ask "who implements X / who calls Y / what does this class look like" instead of grepping
and reading whole files.

It runs as one Docker container next to any PHP project and offers two outputs:

- **MCP server** (`http://localhost:8765/mcp`) with precise query tools. The agent calls them on demand.
- **Markdown** in `<project>/.code-map/`: an `OVERVIEW.md` for orientation (namespace tree, most-used
  attributes, capped at ~24k chars) and one file per namespace with class signatures and public methods.

Nothing in it is framework-specific: Symfony routes, Messenger handlers, Doctrine entities, etc. are all
reachable through the generic `find_by_attribute` tool.

## How it works

```
/project (ro) ──poll mtime/size every 5s──► changed files ──► nikic/php-parser ──► NameResolver pass
                                                                                  └─► SymbolCollector pass
                                                                                         │
                         /data/index.sqlite (WAL, FTS5) ◄────── symbols, relations, refs, attributes
                                │
                 ┌──────────────┴───────────────┐
         MCP over HTTP (php -S)        /out/OVERVIEW.md + namespaces/*.md
```

- **Incremental**: only files whose mtime/size changed are read; only files whose sha1 changed are re-parsed.
  A full re-hash runs once a day (`CODEMAP_FULL_RESCAN_INTERVAL`) as a safety net.
- **Polling, not inotify**: inotify events don't reliably cross Docker Desktop bind mounts on macOS, and
  stat-ing ~8k files takes a few milliseconds.
- **Receiver types** for `$x->method()` are inferred only from what's explicit in the code: `$this`, typed
  properties (incl. promoted constructor params, i.e. injected services), typed parameters and
  `$x = new Foo()`. Calls on anything else are stored with an unknown class and reported separately, so
  `find_usages` never silently hides them.
- **Parse errors** don't stop the indexer: the error is recorded per file and whatever parsed is kept.

On nrg-core's `api/` (8.4k files) a full index takes ~21s in the container. Incremental updates take milliseconds.

## Use it in a project

The quickest way is the [compose.yaml](compose.yaml) in this repo. It builds the image and starts the container,
and restarts it with Docker:

```bash
docker compose up -d --build
```

Its defaults point at `../nrg-core/api` on port 8765. For another project, set `CODEMAP_NAME`, `CODEMAP_PROJECT_PATH`,
`CODEMAP_INCLUDE` and `CODEMAP_PORT`; each name gets its own container, index volume and `var/<name>-out` folder.

To run it from the project's own compose file instead, build the image once:

```bash
docker build -t php-code-map:latest ~/Docker/php-code-map
```

Add the service from [compose.example.yaml](compose.example.yaml) to the project's compose file, adjusting
`CODEMAP_INCLUDE` (e.g. `src,tests`) and the mount (e.g. `./api:/project:ro` when the PHP app lives in `api/`).
Or run it ad hoc:

```bash
docker run -d --name code-map-myproject -p 127.0.0.1:8765:8765 -v "$PWD":/project:ro -v "$PWD/.code-map":/out -v code-map-myproject:/data -e CODEMAP_INCLUDE=src php-code-map:latest
```

Register the MCP server with Claude Code (from the project directory):

```bash
claude mcp add --transport http --scope project code-map http://localhost:8765/mcp
```

Then tell the agent about it in `AGENTS.md` / `CLAUDE.md`:

```markdown
## Code map
Start with `.code-map/OVERVIEW.md`. For structure questions (definitions, implementations, usages, routes,
handlers) prefer the `code-map` MCP tools over grep; their results include `path:line`.
```

Add `.code-map/` to the project's `.gitignore`.

Several projects mean one container each, on different host ports and with different data volumes.

## MCP tools

| Tool | What it answers |
|---|---|
| `find_usages(fqcn, member?, limit?)` | Calls, `new`, `::class`, type hints, `instanceof`, `catch`, inheritance. With a member: its signature, what it calls, overrides in subtypes, calls through parent types and interfaces, and calls on receivers of unknown type |
| `class_outline(fqcn, member?, includePrivate?)` | Parents, attributes, constants, properties/dependencies, methods with lines. Classes with more than 40 methods list names only; `member` filters to full signatures. A `.php` path outlines a whole file |
| `find_implementations(fqcn, transitive?)` | All subtypes: implementations, subclasses, sub-interfaces |
| `callees(Class::method)` | What a method references |
| `find_by_attribute(name, argContains?)` | e.g. `Route` + `/api/orders`, `AsMessageHandler`, `ORM\Entity` |
| `search_symbols(query, kind?, limit?)` | Find symbols by partial name or doc words (`"OrderRepo"`, `"invoice pdf"`) |
| `namespace_tree(prefix?, depth?)` | Namespace hierarchy with class counts |
| `index_status()` | Freshness, counts and parse errors |

Short class names work everywhere (`class_outline OrderController`). An ambiguous short name returns the candidates.

The tool set is deliberately small and each tool tries to answer in one call: every tool definition is loaded
into the agent's context, and every extra round trip re-sends the whole conversation. The `bench/` script
measures this (see `bench/bench.sh --help`).

## TypeScript / React projects

The same image indexes TypeScript and TSX when `CODEMAP_LANG=ts` (or `auto`, which picks `ts` for a root with a
`tsconfig.json`/`package.json` and no `composer.json`). Everything except the parser is shared: the polling
watcher, the SQLite schema, the MCP tools, `codemap query` and the markdown export.

```bash
docker compose up -d --build code-map-fe       # ../nrg-core/frontend, read-only, MCP on http://localhost:8766/mcp
docker exec code-map-nrg-fe codemap query index_status
docker exec code-map-nrg-fe codemap query find_usages Button
```

Override the mount with `CODEMAP_FE_PROJECT_PATH`, the port with `CODEMAP_FE_PORT`, and the scan scope with
`CODEMAP_FE_INCLUDE` / `CODEMAP_FE_EXCLUDE` (default excludes the generated `.react-router` route types).
Markdown goes to `var/nrg-fe-out/` (`OVERVIEW.md` + `modules/<dir.with.dots>.md`).

How it works:

- `ts/src/indexer.mjs` is a long-running Node child process that the PHP indexer talks to over JSON lines. It uses
  the TypeScript compiler API (pinned `typescript` 6.0.3) with one LanguageService per nearest `tsconfig.json`, so
  per-app `paths` aliases (`~/*`, `@shared/*`), `baseUrl` and workspace packages resolve like they do in the editor.
  Only changed files are re-parsed; the services stay warm between polls.
- Always excluded on top of the PHP defaults: `dist, build, .next, coverage, .nx, .turbo, .cache, .pnpm-store, .yarn`.
- Names: the namespace is the file's directory (`apps\customer\app\Checkout`), top-level symbols are
  `<dir>\<file>\<Name>` with the file part dropped for `index.ts` and when it equals the name
  (`packages\shared\components\Button`), members are `Name::member`. Short names work in every tool.
- Kinds: `component` (functions/arrow functions/`memo`/`forwardRef` returning JSX with a capitalised name),
  `function` (hooks carry the `hook` flag), `class`, `interface`, `type`, `enum` (members are `case`), `const`;
  members are `method` and `property` (constructor parameter properties included, like PHP promoted properties).
- Refs: `import`, `export` (re-exports), `jsx` (`<Button>`), `func` (calls of functions/hooks), `call` (method calls,
  resolved through the type checker; unresolved ones are kept as "receivers of unknown type"), `new`, `type`
  (type positions), `prop`, `const` (enum members), `ref` (other value references). Relations are `extends` /
  `implements`; decorators are stored as attributes, so `find_by_attribute injectable` works.

Memory: the frontend of nrg-core (~7.6k files) indexes in ~35 s and the container settles at about 3 GB, because the
type checker stays loaded. `CODEMAP_NODE_MEMORY_MB` (default 4096) caps Node's heap.

## Configuration

Env vars (or the same keys in snake_case in an optional `codemap.yaml` in the project root):

| Variable | Default | |
|---|---|---|
| `CODEMAP_LANG` | `php` | `php`, `ts` (TypeScript/TSX) or `auto` |
| `CODEMAP_INCLUDE` | `.` | Comma-separated dirs relative to the project root |
| `CODEMAP_EXCLUDE` | – | Added to the defaults: `vendor, node_modules, var, cache, .git, .idea, .claude, .code-map`. Bare names match any directory; values with `/` or `*` are path globs |
| `CODEMAP_POLL_INTERVAL` | `5` | Seconds between change checks |
| `CODEMAP_FULL_RESCAN_INTERVAL` | `86400` | Seconds between full re-hashes |
| `CODEMAP_OVERVIEW_MAX_CHARS` | `24000` | Size budget for `OVERVIEW.md` |
| `CODEMAP_OUT` | `/out` | Markdown output dir; empty disables it |
| `CODEMAP_PROJECT_NAME` | dir name | Title in `OVERVIEW.md` |
| `CODEMAP_ALLOWED_HOSTS` | `localhost,127.0.0.1,[::1]` | Host headers the MCP endpoint accepts (DNS-rebinding protection) |
| `CODEMAP_HTTP_PORT` | `8765` | Port inside the container |

## CLI

The image's entrypoint is `codemap`. Default command is `run` (watcher + MCP server).

```bash
docker exec code-map-myproject codemap query class_outline 'App\Service\Foo'   # any MCP tool, positional args
docker exec code-map-myproject codemap query find_usages 'App\Service\Foo' save
docker exec code-map-myproject codemap index --full                             # force a full re-hash
docker exec -i code-map-myproject codemap serve --stdio                         # MCP over stdio instead of HTTP
```

## Development

```bash
docker build --target test -t php-code-map:test . && docker run --rm php-code-map:test   # PHP + TypeScript suites
# or locally (TypeScript tests are skipped without node and `npm ci --prefix ts`):
composer install && npm ci --prefix ts
vendor/bin/phpunit
CODEMAP_PROJECT=$PWD/tests/fixtures/sample-project CODEMAP_DB=var/dev.sqlite CODEMAP_OUT=var/out bin/codemap index
```

Code layout:

- `src/Indexer/SymbolCollector.php`: the AST visitor, where all extraction happens
- `src/Indexer/Indexer.php`: change detection, parsing and storage
- `src/Indexer/SourceParser.php`: parser interface; `PhpSourceParser` (in-process) and `TsSourceParser` (Node child process)
- `ts/src/indexer.mjs`: the TypeScript extractor
- `src/Storage/schema.sql`: tables (`files`, `symbols`, `relations`, `refs`, `attributes`, `symbols_fts`)
- `src/Query/CodeQuery.php`: every query; MCP tools and the `query` command both call it
- `src/Mcp/`: tool registration (schemas come from the method signatures and `@param` docs)
- `src/Export/MarkdownExporter.php`: overview generation

Ideas for extending it:
- Use method return types to infer more receivers (`$this->repo->find($id)->cancel()`).
- Add phpdoc `@var` / `@return` types.
- Add Symfony container awareness (which concrete class a service ID resolves to).
- Add a `diff_since(commit)` tool listing changed symbols.
