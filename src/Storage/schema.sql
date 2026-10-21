-- One row per indexed PHP file. Deleting a file cascades to everything extracted from it.
CREATE TABLE IF NOT EXISTS files (
    id          INTEGER PRIMARY KEY,
    path        TEXT    NOT NULL UNIQUE,
    hash        TEXT    NOT NULL,
    mtime       INTEGER NOT NULL,
    size        INTEGER NOT NULL,
    parse_error TEXT,
    indexed_at  INTEGER NOT NULL
);

-- Classes, interfaces, traits, enums, functions and their members.
-- fqn: App\Foo, App\Foo::bar (method), App\Foo::$baz (property), App\Foo::QUX (constant / enum case), App\fn (function)
CREATE TABLE IF NOT EXISTS symbols (
    id         INTEGER PRIMARY KEY,
    file_id    INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    parent_id  INTEGER REFERENCES symbols(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL,
    fqn        TEXT    NOT NULL,
    name       TEXT    NOT NULL,
    namespace  TEXT    NOT NULL DEFAULT '',
    signature  TEXT    NOT NULL,
    visibility TEXT,
    flags      TEXT    NOT NULL DEFAULT '',
    doc        TEXT,
    start_line INTEGER NOT NULL,
    end_line   INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS symbols_fqn ON symbols(fqn COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS symbols_name ON symbols(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS symbols_parent ON symbols(parent_id);
CREATE INDEX IF NOT EXISTS symbols_file ON symbols(file_id);
CREATE INDEX IF NOT EXISTS symbols_namespace ON symbols(namespace, kind);

-- extends / implements / uses_trait edges from a class-like symbol to a (possibly external) FQCN.
CREATE TABLE IF NOT EXISTS relations (
    id        INTEGER PRIMARY KEY,
    symbol_id INTEGER NOT NULL REFERENCES symbols(id) ON DELETE CASCADE,
    kind      TEXT    NOT NULL,
    target    TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS relations_target ON relations(target COLLATE NOCASE, kind);
CREATE INDEX IF NOT EXISTS relations_symbol ON relations(symbol_id);

-- References made from code: method calls, static calls, instantiations, ::class, type hints, function calls.
-- target_class is NULL when the receiver type could not be resolved statically.
CREATE TABLE IF NOT EXISTS refs (
    id            INTEGER PRIMARY KEY,
    file_id       INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    symbol_id     INTEGER REFERENCES symbols(id) ON DELETE CASCADE,
    kind          TEXT    NOT NULL,
    target_class  TEXT,
    target_member TEXT,
    line          INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS refs_target ON refs(target_class COLLATE NOCASE, target_member);
CREATE INDEX IF NOT EXISTS refs_member ON refs(target_member);
CREATE INDEX IF NOT EXISTS refs_symbol ON refs(symbol_id);

CREATE TABLE IF NOT EXISTS attributes (
    id        INTEGER PRIMARY KEY,
    symbol_id INTEGER NOT NULL REFERENCES symbols(id) ON DELETE CASCADE,
    name      TEXT    NOT NULL,
    args      TEXT    NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS attributes_name ON attributes(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS attributes_symbol ON attributes(symbol_id);

CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

-- Full-text search over symbol names and doc summaries. rowid = symbols.id.
-- "words" holds the camelCase/snake_case split of the name ("OrderRepository" -> "order repository"),
-- which the indexer computes; deletes (incl. cascades from files) are mirrored by the trigger.
CREATE VIRTUAL TABLE IF NOT EXISTS symbols_fts USING fts5(fqn, words, doc, tokenize="unicode61");
CREATE TRIGGER IF NOT EXISTS symbols_ad AFTER DELETE ON symbols BEGIN
    DELETE FROM symbols_fts WHERE rowid = old.id;
END;
