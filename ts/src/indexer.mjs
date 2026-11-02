#!/usr/bin/env node
// TypeScript/TSX extractor for code-map.
//
// A long-running child of the PHP indexer. It speaks one JSON object per line on stdin/stdout:
//   -> {"files": ["apps/web/a.tsx", ...], "parse": ["apps/web/a.tsx"]}
//      "files" (every indexed path, relative to the project root) is only sent when the file set may have changed.
//   <- {"results": {"apps/web/a.tsx": {"symbols": [], "relations": [], "refs": [], "attributes": [], "error": null}}}
//      or {"error": "message"} when the whole request failed.
//
// Each tsconfig.json found above an indexed file gets its own LanguageService (so per-app "paths" aliases resolve),
// all sharing one DocumentRegistry. The services keep their programs between requests, so after a change only the
// edited files are re-parsed and the checker resolves references lazily.
//
// Rows mirror src/Indexer/FileSymbols.php. Names: a file's "namespace" is its directory with "\" separators
// (apps\web\pages), and a top-level declaration's fqn is namespace\stem\Name, where the file stem is left out when
// it equals the name or is "index" (apps\web\pages\CheckoutPage). Members are Owner::member.

import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import ts from 'typescript';

const MAX_SIGNATURE = 300;
const MAX_DOC = 300;
const DEFAULT_OPTIONS = {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    jsx: ts.JsxEmit.ReactJSX,
    strict: true,
    esModuleInterop: true,
    resolveJsonModule: true,
    skipLibCheck: true,
    experimentalDecorators: true,
    allowJs: false,
    noEmit: true,
};

export class Workspace {
    constructor(root) {
        this.root = path.resolve(root);
        this.files = new Set();
        this.registry = ts.createDocumentRegistry(ts.sys.useCaseSensitiveFileNames, this.root);
        this.groups = new Map(); // tsconfig path ('' = none) => group
        this.configOf = new Map(); // dir => tsconfig path or ''
        this.externalVersions = new Map();
    }

    setFiles(files) {
        this.files = new Set(files);
        const roots = new Map();
        for (const rel of this.files) {
            const config = this.nearestConfig(path.posix.dirname(rel));
            if (!roots.has(config)) {
                roots.set(config, []);
            }
            roots.get(config).push(path.join(this.root, rel));
        }
        for (const [config, group] of this.groups) {
            if (!roots.has(config)) {
                group.service.dispose();
                this.groups.delete(config);
            }
        }
        for (const [config, rootNames] of roots) {
            const group = this.groups.get(config) ?? this.createGroup(config);
            group.rootNames = rootNames;
            group.program = null;
        }
    }

    nearestConfig(relDir) {
        if (this.configOf.has(relDir)) {
            return this.configOf.get(relDir);
        }
        let config = '';
        const candidate = path.join(this.root, relDir, 'tsconfig.json');
        if (fs.existsSync(candidate)) {
            config = candidate;
        } else if (relDir !== '.' && relDir !== '') {
            config = this.nearestConfig(path.posix.dirname(relDir));
        }
        this.configOf.set(relDir, config);

        return config;
    }

    createGroup(config) {
        let options = { ...DEFAULT_OPTIONS };
        let configError = null;
        if (config !== '') {
            const host = { ...ts.sys, onUnRecoverableConfigFileDiagnostic: (d) => { configError = flatten(d); } };
            const parsed = ts.getParsedCommandLineOfConfigFile(config, {}, host);
            if (parsed) {
                options = { ...parsed.options };
            }
        }
        // We only read the program; never emit or write build info into the (read-only) project.
        Object.assign(options, { noEmit: true, incremental: false, composite: false, skipLibCheck: true, allowJs: false });
        delete options.tsBuildInfoFile;
        const dir = config === '' ? this.root : path.dirname(config);

        const group = { config, options, rootNames: [], program: null, configError };
        const host = {
            getScriptFileNames: () => group.rootNames,
            getScriptVersion: (fileName) => this.version(fileName),
            getScriptSnapshot: (fileName) => {
                const text = ts.sys.readFile(fileName);
                return text === undefined ? undefined : ts.ScriptSnapshot.fromString(text);
            },
            getCurrentDirectory: () => dir,
            getCompilationSettings: () => group.options,
            getDefaultLibFileName: (o) => ts.getDefaultLibFilePath(o),
            fileExists: ts.sys.fileExists,
            readFile: ts.sys.readFile,
            readDirectory: ts.sys.readDirectory,
            directoryExists: ts.sys.directoryExists,
            getDirectories: ts.sys.getDirectories,
            realpath: ts.sys.realpath,
            useCaseSensitiveFileNames: () => ts.sys.useCaseSensitiveFileNames,
        };
        group.service = ts.createLanguageService(host, this.registry);
        this.groups.set(config, group);

        return group;
    }

    version(fileName) {
        const rel = this.relative(fileName);
        if (rel === null || !this.files.has(rel)) {
            // Libraries and node_modules typings don't change while we run; stat them once.
            if (!this.externalVersions.has(fileName)) {
                this.externalVersions.set(fileName, statVersion(fileName));
            }
            return this.externalVersions.get(fileName);
        }
        return statVersion(fileName);
    }

    relative(fileName) {
        const rel = path.relative(this.root, fileName);
        if (rel === '' || rel.startsWith('..') || path.isAbsolute(rel)) {
            return null;
        }
        return rel.split(path.sep).join('/');
    }

    parse(paths) {
        const results = {};
        const programs = new Map();
        for (const rel of paths) {
            try {
                const group = this.groups.get(this.nearestConfig(path.posix.dirname(rel)));
                if (!group) {
                    results[rel] = emptyResult(`not in the indexed file set`);
                    continue;
                }
                if (!programs.has(group)) {
                    programs.set(group, group.service.getProgram());
                }
                const program = programs.get(group);
                const sourceFile = program?.getSourceFile(path.join(this.root, rel));
                if (!sourceFile) {
                    results[rel] = emptyResult('file is not part of the TypeScript program');
                    continue;
                }
                results[rel] = new Extractor(this, program, sourceFile, rel).run();
            } catch (e) {
                results[rel] = emptyResult(`indexer error: ${e?.message ?? e}`);
            }
        }
        return results;
    }
}

function statVersion(fileName) {
    try {
        const s = fs.statSync(fileName);
        return `${s.mtimeMs}:${s.size}`;
    } catch {
        return 'missing';
    }
}

function emptyResult(error) {
    return { symbols: [], relations: [], refs: [], attributes: [], error };
}

function flatten(diagnostic) {
    return ts.flattenDiagnosticMessageText(diagnostic.messageText, ' ');
}

function oneLine(text, max = MAX_SIGNATURE) {
    const s = text.replace(/\s+/g, ' ').trim();
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

export function moduleInfo(rel) {
    const dir = path.posix.dirname(rel);
    return {
        namespace: dir === '.' ? '' : dir.split('/').join('\\'),
        stem: path.posix.basename(rel).replace(/(\.d)?\.[mc]?tsx?$/, ''),
    };
}

export function topFqn(rel, name) {
    const { namespace, stem } = moduleInfo(rel);
    return [namespace, stem === name || stem === 'index' ? '' : stem, name].filter((p) => p !== '').join('\\');
}

const hasModifier = (node, kind) => (ts.canHaveModifiers(node) ? ts.getModifiers(node) ?? [] : []).some((m) => m.kind === kind);
const isExported = (node) => hasModifier(node, ts.SyntaxKind.ExportKeyword);
const isDefault = (node) => hasModifier(node, ts.SyntaxKind.DefaultKeyword);

/** Wrappers whose first argument is the actual component, e.g. memo(() => ...), forwardRef(...). */
const COMPONENT_WRAPPER = /(^|\.)(memo|forwardRef|lazy|observer|withRouter|styled\.\w+|styled)$/;
const COMPONENT_TYPE = /\b(FC|FunctionComponent|VFC|ComponentType|ForwardRefExoticComponent|MemoExoticComponent|NamedExoticComponent|LazyExoticComponent)\b/;
const JSX_RETURN = /\b(JSX\.Element|ReactNode|ReactElement|ReactPortal)\b/;

function containsJsx(node) {
    let found = false;
    const visit = (n) => {
        if (found) return;
        if (ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n) || ts.isJsxFragment(n)) {
            found = true;
            return;
        }
        ts.forEachChild(n, visit);
    };
    visit(node);
    return found;
}

/** Unwraps `memo(forwardRef((props) => ...))` to the inner function, if any. */
function unwrapFunction(expr) {
    let wrapper = null;
    let e = expr;
    for (let i = 0; i < 4 && e; i++) {
        while (ts.isParenthesizedExpression(e) || ts.isAsExpression(e) || ts.isSatisfiesExpression?.(e)) e = e.expression;
        if (ts.isArrowFunction(e) || ts.isFunctionExpression(e)) return { fn: e, wrapper };
        if (ts.isCallExpression(e) && e.arguments.length > 0) {
            wrapper ??= e.expression.getText();
            e = e.arguments[0];
            continue;
        }
        break;
    }
    return { fn: null, wrapper };
}

/** A top-level variable we index: exported, or holding a function / component. */
function isIndexedVariable(decl) {
    if (!ts.isIdentifier(decl.name) || !decl.parent?.parent || !ts.isVariableStatement(decl.parent.parent)) return false;
    if (!ts.isSourceFile(decl.parent.parent.parent)) return false;
    if (isExported(decl.parent.parent)) return true;
    if (!decl.initializer) return false;
    const { fn, wrapper } = unwrapFunction(decl.initializer);
    return fn !== null || (wrapper !== null && COMPONENT_WRAPPER.test(wrapper));
}

const TOP_LEVEL_KINDS = new Set([
    ts.SyntaxKind.FunctionDeclaration,
    ts.SyntaxKind.ClassDeclaration,
    ts.SyntaxKind.InterfaceDeclaration,
    ts.SyntaxKind.TypeAliasDeclaration,
    ts.SyntaxKind.EnumDeclaration,
]);

class Extractor {
    constructor(workspace, program, sourceFile, rel) {
        this.ws = workspace;
        this.program = program;
        this.checker = program.getTypeChecker();
        this.sf = sourceFile;
        this.rel = rel;
        this.isTsx = rel.endsWith('.tsx');
        this.out = emptyResult(null);
        this.stem = moduleInfo(rel).stem;
        this.namespace = moduleInfo(rel).namespace;
    }

    run() {
        const diagnostics = this.program.getSyntacticDiagnostics(this.sf);
        if (diagnostics.length > 0) {
            const d = diagnostics[0];
            const { line } = this.sf.getLineAndCharacterOfPosition(d.start ?? 0);
            this.out.error = `${flatten(d)} on line ${line + 1}`;
        }
        for (const statement of this.sf.statements) {
            this.statement(statement);
        }
        return this.out;
    }

    line(node) {
        return this.sf.getLineAndCharacterOfPosition(node.getStart(this.sf)).line + 1;
    }

    endLine(node) {
        return this.sf.getLineAndCharacterOfPosition(node.getEnd()).line + 1;
    }

    addSymbol(s) {
        this.out.symbols.push({
            kind: s.kind,
            fqn: s.fqn,
            name: s.name,
            namespace: this.namespace,
            signature: oneLine(s.signature),
            visibility: s.visibility ?? null,
            flags: (s.flags ?? []).filter(Boolean).join(' '),
            doc: s.doc ?? null,
            start_line: this.line(s.node),
            end_line: this.endLine(s.node),
            parent: s.parent ?? null,
        });
        return this.out.symbols.length - 1;
    }

    doc(node) {
        const docs = ts.getJSDocCommentsAndTags(node).filter(ts.isJSDoc);
        const comment = docs.length > 0 ? ts.getTextOfJSDocComment(docs[docs.length - 1].comment) : undefined;
        if (!comment) return null;
        const summary = comment.split(/\n\s*\n/)[0].replace(/\s+/g, ' ').trim();
        return summary === '' ? null : summary.slice(0, MAX_DOC);
    }

    exportPrefix(node) {
        return (isExported(node) ? 'export ' : '') + (isDefault(node) ? 'default ' : '');
    }

    exportFlags(node) {
        return [isExported(node) ? 'export' : null, isDefault(node) ? 'default' : null];
    }

    text(node) {
        return node ? node.getText(this.sf) : '';
    }

    typeParams(node) {
        return node.typeParameters ? `<${node.typeParameters.map((p) => this.text(p)).join(', ')}>` : '';
    }

    params(fn) {
        return `(${fn.parameters.map((p) => this.text(p)).join(', ')})`;
    }

    returnType(fn) {
        return fn.type ? `: ${this.text(fn.type)}` : '';
    }

    statement(node) {
        if (ts.isFunctionDeclaration(node)) {
            if (!node.body && !node.name) return;
            if (!node.body && this.sf.statements.some((s) => s !== node && ts.isFunctionDeclaration(s) && s.body && s.name?.text === node.name?.text)) {
                return; // overload signature; the implementation is indexed
            }
            const name = node.name?.text ?? this.stem;
            const component = this.looksLikeComponent(name, node);
            const id = this.addSymbol({
                kind: component ? 'component' : 'function',
                fqn: topFqn(this.rel, name),
                name,
                node,
                signature: `${this.exportPrefix(node)}${hasModifier(node, ts.SyntaxKind.AsyncKeyword) ? 'async ' : ''}function ${name}${this.typeParams(node)}${this.params(node)}${this.returnType(node)}`,
                flags: [...this.exportFlags(node), /^use[A-Z0-9]/.test(name) ? 'hook' : null],
                doc: this.doc(node),
            });
            this.collectRefs(node, id);
        } else if (ts.isClassDeclaration(node)) {
            this.classLike(node);
        } else if (ts.isInterfaceDeclaration(node)) {
            this.interfaceLike(node);
        } else if (ts.isTypeAliasDeclaration(node)) {
            const name = node.name.text;
            const id = this.addSymbol({
                kind: 'type',
                fqn: topFqn(this.rel, name),
                name,
                node,
                signature: `${this.exportPrefix(node)}type ${name}${this.typeParams(node)} = ${oneLine(this.text(node.type), 200)}`,
                flags: this.exportFlags(node),
                doc: this.doc(node),
            });
            if (ts.isTypeLiteralNode(node.type)) {
                this.members(node.type.members, id, topFqn(this.rel, name), false);
            } else {
                this.collectRefs(node.type, id);
            }
            node.typeParameters?.forEach((p) => this.collectRefs(p, id));
        } else if (ts.isEnumDeclaration(node)) {
            const name = node.name.text;
            const fqn = topFqn(this.rel, name);
            const id = this.addSymbol({
                kind: 'enum',
                fqn,
                name,
                node,
                signature: `${this.exportPrefix(node)}${hasModifier(node, ts.SyntaxKind.ConstKeyword) ? 'const ' : ''}enum ${name}`,
                flags: this.exportFlags(node),
                doc: this.doc(node),
            });
            for (const member of node.members) {
                const memberName = this.memberName(member) ?? this.text(member.name);
                this.addSymbol({
                    kind: 'case',
                    fqn: `${fqn}::${memberName}`,
                    name: memberName,
                    node: member,
                    signature: member.initializer ? `${memberName} = ${this.text(member.initializer)}` : memberName,
                    visibility: 'public',
                    doc: this.doc(member),
                    parent: id,
                });
            }
        } else if (ts.isVariableStatement(node)) {
            for (const decl of node.declarationList.declarations) {
                if (isIndexedVariable(decl)) {
                    this.variable(node, decl);
                } else {
                    this.collectRefs(decl, null);
                }
            }
        } else if (ts.isModuleDeclaration(node)) {
            // `declare module` / namespaces: only their references are recorded.
            this.collectRefs(node, null);
        } else {
            // Imports, re-exports, `export default X`, top-level calls...
            this.collectRefs(node, null);
        }
    }

    looksLikeComponent(name, fn) {
        if (!/^[A-Z]/.test(name)) return false;
        if (fn.type && JSX_RETURN.test(this.text(fn.type))) return true;
        return this.isTsx && fn.body !== undefined && containsJsx(fn.body);
    }

    variable(statement, decl) {
        const name = decl.name.text;
        const kindWord = (statement.declarationList.flags & ts.NodeFlags.Const) ? 'const' : (statement.declarationList.flags & ts.NodeFlags.Let) ? 'let' : 'var';
        const annotation = decl.type ? this.text(decl.type) : '';
        const { fn, wrapper } = decl.initializer ? unwrapFunction(decl.initializer) : { fn: null, wrapper: null };
        const prefix = `${this.exportPrefix(statement)}${kindWord} ${name}${annotation ? `: ${annotation}` : ''}`;

        let kind = 'const';
        let signature;
        if (fn) {
            const component = /^[A-Z]/.test(name)
                && (COMPONENT_TYPE.test(annotation) || (wrapper !== null && COMPONENT_WRAPPER.test(wrapper)) || this.looksLikeComponent(name, fn));
            kind = component ? 'component' : 'function';
            const async = hasModifier(fn, ts.SyntaxKind.AsyncKeyword) ? 'async ' : '';
            const inner = ts.isArrowFunction(fn)
                ? `${async}${this.typeParams(fn)}${this.params(fn)}${this.returnType(fn)} => …`
                : `${async}function${this.typeParams(fn)}${this.params(fn)}${this.returnType(fn)} { … }`;
            signature = `${prefix} = ${wrapper ? `${wrapper}(${inner})` : inner}`;
        } else if (/^[A-Z]/.test(name) && ((wrapper !== null && COMPONENT_WRAPPER.test(wrapper)) || COMPONENT_TYPE.test(annotation))) {
            kind = 'component';
            signature = `${prefix} = ${oneLine(this.text(decl.initializer), 120)}`;
        } else {
            let type = annotation;
            if (!type) {
                try {
                    type = this.checker.typeToString(this.checker.getTypeAtLocation(decl.name), undefined, ts.TypeFormatFlags.NoTruncation);
                } catch {
                    type = '';
                }
            }
            signature = `${this.exportPrefix(statement)}${kindWord} ${name}${type ? `: ${oneLine(type, 160)}` : ''}`;
        }

        const id = this.addSymbol({
            kind,
            fqn: topFqn(this.rel, name),
            name,
            node: decl,
            signature,
            flags: [...this.exportFlags(statement), kind === 'function' && /^use[A-Z0-9]/.test(name) ? 'hook' : null],
            doc: this.doc(decl),
        });
        this.collectRefs(decl, id);
    }

    classLike(node) {
        const name = node.name?.text ?? this.stem;
        const fqn = topFqn(this.rel, name);
        const heritage = (node.heritageClauses ?? []).map((h) => this.text(h)).join(' ');
        const extendsReact = (node.heritageClauses ?? []).some((h) => h.token === ts.SyntaxKind.ExtendsKeyword
            && h.types.some((t) => /(^|\.)(Pure)?Component$/.test(this.text(t.expression))));
        const id = this.addSymbol({
            kind: 'class',
            fqn,
            name,
            node,
            signature: `${this.exportPrefix(node)}${hasModifier(node, ts.SyntaxKind.AbstractKeyword) ? 'abstract ' : ''}class ${name}${this.typeParams(node)}${heritage ? ' ' + heritage : ''}`,
            flags: [...this.exportFlags(node), hasModifier(node, ts.SyntaxKind.AbstractKeyword) ? 'abstract' : null, extendsReact ? 'component' : null],
            doc: this.doc(node),
        });
        this.heritage(node, id);
        this.decorators(node, id);
        this.members(node.members, id, fqn, true);
    }

    interfaceLike(node) {
        const name = node.name.text;
        const fqn = topFqn(this.rel, name);
        const heritage = (node.heritageClauses ?? []).map((h) => this.text(h)).join(' ');
        const id = this.addSymbol({
            kind: 'interface',
            fqn,
            name,
            node,
            signature: `${this.exportPrefix(node)}interface ${name}${this.typeParams(node)}${heritage ? ' ' + heritage : ''}`,
            flags: this.exportFlags(node),
            doc: this.doc(node),
        });
        this.heritage(node, id);
        this.members(node.members, id, fqn, false);
    }

    heritage(node, id) {
        for (const clause of node.heritageClauses ?? []) {
            const kind = clause.token === ts.SyntaxKind.ImplementsKeyword ? 'implements' : 'extends';
            for (const type of clause.types) {
                const target = this.resolveTop(type.expression);
                this.out.relations.push({ symbol: id, kind, target: target ?? this.text(type.expression) });
                type.typeArguments?.forEach((a) => this.collectRefs(a, id));
            }
        }
    }

    decorators(node, id) {
        const decorators = ts.canHaveDecorators(node) ? ts.getDecorators(node) ?? [] : [];
        for (const d of decorators) {
            const e = d.expression;
            const name = ts.isCallExpression(e) ? this.text(e.expression) : this.text(e);
            const args = ts.isCallExpression(e) ? e.arguments.map((a) => oneLine(this.text(a), 200)).join(', ') : '';
            this.out.attributes.push({ symbol: id, name, args });
            this.collectRefs(d, id);
        }
    }

    memberName(member) {
        // member may come from another file (when resolving references), so never slice this.sf's text here.
        const name = member.name;
        if (!name) return null;
        if (ts.isIdentifier(name) || ts.isPrivateIdentifier(name) || ts.isStringLiteral(name) || ts.isNumericLiteral(name)) return name.text;
        return null;
    }

    visibility(member) {
        if (hasModifier(member, ts.SyntaxKind.PrivateKeyword) || (member.name && ts.isPrivateIdentifier(member.name))) return 'private';
        if (hasModifier(member, ts.SyntaxKind.ProtectedKeyword)) return 'protected';
        return 'public';
    }

    modifierWords(member) {
        return [
            hasModifier(member, ts.SyntaxKind.StaticKeyword) ? 'static' : null,
            hasModifier(member, ts.SyntaxKind.AbstractKeyword) ? 'abstract' : null,
            hasModifier(member, ts.SyntaxKind.ReadonlyKeyword) ? 'readonly' : null,
            hasModifier(member, ts.SyntaxKind.AsyncKeyword) ? 'async' : null,
        ].filter(Boolean);
    }

    members(members, parentId, ownerFqn, isClass) {
        for (const member of members) {
            if (ts.isConstructorDeclaration(member)) {
                const id = this.addSymbol({
                    kind: 'method',
                    fqn: `${ownerFqn}::constructor`,
                    name: 'constructor',
                    node: member,
                    signature: `${this.visibility(member)} constructor${this.params(member)}`,
                    visibility: this.visibility(member),
                    doc: this.doc(member),
                    parent: parentId,
                });
                for (const param of member.parameters) {
                    // Parameter properties (constructor(private readonly repo: Repo)) are the class's injected dependencies.
                    if (ts.isIdentifier(param.name) && ts.getModifiers(param)?.some((m) => [ts.SyntaxKind.PublicKeyword, ts.SyntaxKind.PrivateKeyword, ts.SyntaxKind.ProtectedKeyword, ts.SyntaxKind.ReadonlyKeyword].includes(m.kind))) {
                        const visibility = this.visibility(param);
                        const readonly = hasModifier(param, ts.SyntaxKind.ReadonlyKeyword);
                        const propId = this.addSymbol({
                            kind: 'property',
                            fqn: `${ownerFqn}::${param.name.text}`,
                            name: param.name.text,
                            node: param,
                            signature: `${visibility}${readonly ? ' readonly' : ''} ${param.name.text}${param.questionToken ? '?' : ''}${param.type ? `: ${this.text(param.type)}` : ''}`,
                            visibility,
                            flags: ['promoted', readonly ? 'readonly' : null],
                            parent: parentId,
                        });
                        this.decorators(param, propId);
                    } else {
                        const decorators = ts.getDecorators(param) ?? [];
                        decorators.forEach((d) => this.collectRefs(d, id));
                    }
                }
                member.parameters.forEach((p) => p.type && this.collectRefs(p.type, id));
                member.parameters.forEach((p) => p.initializer && this.collectRefs(p.initializer, id));
                if (member.body) this.collectRefs(member.body, id);
                continue;
            }

            const name = this.memberName(member);
            if (name === null) {
                this.collectRefs(member, parentId);
                continue;
            }
            const isMethod = ts.isMethodDeclaration(member) || ts.isMethodSignature(member);
            const isProperty = ts.isPropertyDeclaration(member) || ts.isPropertySignature(member);
            const isAccessor = ts.isGetAccessorDeclaration(member) || ts.isSetAccessorDeclaration(member);
            if (!isMethod && !isProperty && !isAccessor) {
                this.collectRefs(member, parentId);
                continue;
            }
            if (isAccessor && ts.isSetAccessorDeclaration(member) && members.some((m) => m !== member && ts.isGetAccessorDeclaration(m) && this.memberName(m) === name)) {
                this.collectRefs(member, parentId); // one symbol per get/set pair
                continue;
            }
            const visibility = this.visibility(member);
            const words = this.modifierWords(member);
            const optional = member.questionToken ? '?' : '';
            let signature;
            if (isMethod) {
                signature = `${name}${optional}${this.typeParams(member)}${this.params(member)}${this.returnType(member)}`;
            } else if (isAccessor) {
                const type = member.type ? `: ${this.text(member.type)}` : '';
                signature = `${ts.isGetAccessorDeclaration(member) ? 'get' : 'set'} ${name}${type}`;
            } else {
                signature = `${name}${optional}${member.type ? `: ${this.text(member.type)}` : ''}`;
            }
            const prefix = [isClass ? visibility : null, ...words].filter(Boolean).join(' ');
            const id = this.addSymbol({
                kind: isMethod ? 'method' : 'property',
                fqn: `${ownerFqn}::${name}`,
                name,
                node: member,
                signature: `${prefix ? prefix + ' ' : ''}${signature}`,
                visibility,
                flags: [...words.filter((w) => w !== 'async'), optional ? 'optional' : null, isAccessor ? 'accessor' : null],
                doc: this.doc(member),
                parent: parentId,
            });
            this.decorators(member, id);
            ts.forEachChild(member, (child) => {
                if (child !== member.name && !ts.isDecorator(child)) this.collectRefs(child, id);
            });
        }
    }

    // ---- references -------------------------------------------------------------------------------------------

    /** fqn of a top-level declaration an expression or type name refers to, when it is indexed. */
    resolveTop(expr) {
        const target = this.resolve(ts.isPropertyAccessExpression(expr) ? expr.name : expr);
        return target && target.member === null ? target.cls : null;
    }

    /** The indexed declaration a name refers to: {cls, member} or null (locals, libraries, node_modules). */
    resolve(nameNode) {
        let symbol;
        try {
            symbol = this.checker.getSymbolAtLocation(nameNode);
        } catch {
            return null;
        }
        return this.targetOfSymbol(symbol);
    }

    targetOfSymbol(symbol, depth = 0) {
        if (!symbol || depth > 5) return null;
        if (symbol.flags & ts.SymbolFlags.Alias) {
            try {
                symbol = this.checker.getAliasedSymbol(symbol);
            } catch {
                return null;
            }
        }
        for (const decl of symbol.declarations ?? []) {
            const target = this.targetOfDeclaration(decl, depth);
            if (target) return target;
        }
        return null;
    }

    targetOfDeclaration(decl, depth) {
        const sf = decl.getSourceFile();
        const rel = this.ws.relative(sf.fileName);
        if (rel === null || !this.ws.files.has(rel)) return null;
        const stem = moduleInfo(rel).stem;

        if (ts.isExportAssignment(decl)) {
            // export default memo(Foo) / export default Foo
            let e = decl.expression;
            while (ts.isCallExpression(e) && e.arguments.length > 0) e = e.arguments[0];
            if (ts.isIdentifier(e)) {
                try {
                    return this.targetOfSymbol(this.checker.getSymbolAtLocation(e), depth + 1);
                } catch {
                    return null;
                }
            }
            return null;
        }
        if (TOP_LEVEL_KINDS.has(decl.kind) && ts.isSourceFile(decl.parent)) {
            return { cls: topFqn(rel, decl.name?.text ?? stem), member: null };
        }
        if (ts.isVariableDeclaration(decl)) {
            return isIndexedVariable(decl) ? { cls: topFqn(rel, decl.name.text), member: null } : null;
        }
        if (ts.isEnumMember(decl) && ts.isSourceFile(decl.parent.parent)) {
            return { cls: topFqn(rel, decl.parent.name.text), member: this.memberName(decl) };
        }
        if (ts.isParameter(decl) && ts.isConstructorDeclaration(decl.parent) && ts.isIdentifier(decl.name)) {
            const owner = this.ownerFqn(decl.parent.parent, rel, stem);
            return owner ? { cls: owner, member: decl.name.text } : null;
        }
        const isMember = ts.isMethodDeclaration(decl) || ts.isPropertyDeclaration(decl) || ts.isMethodSignature(decl)
            || ts.isPropertySignature(decl) || ts.isGetAccessorDeclaration(decl) || ts.isSetAccessorDeclaration(decl);
        if (isMember) {
            const owner = this.ownerFqn(decl.parent, rel, stem);
            const member = this.memberName(decl);
            return owner && member ? { cls: owner, member } : null;
        }
        return null;
    }

    ownerFqn(owner, rel, stem) {
        if ((ts.isClassDeclaration(owner) || ts.isInterfaceDeclaration(owner)) && ts.isSourceFile(owner.parent)) {
            return topFqn(rel, owner.name?.text ?? stem);
        }
        if (ts.isTypeLiteralNode(owner) && ts.isTypeAliasDeclaration(owner.parent) && ts.isSourceFile(owner.parent.parent)) {
            return topFqn(rel, owner.parent.name.text);
        }
        return null;
    }

    addRef(symbolId, kind, target, node) {
        this.out.refs.push({ symbol: symbolId, kind, target_class: target?.cls ?? null, target_member: target?.member ?? null, line: this.line(node) });
    }

    /** What a reference to a top-level symbol is, judged by where it appears. */
    usageKind(node) {
        let n = node;
        let parent = n.parent;
        while (parent && (ts.isParenthesizedExpression(parent) || ts.isNonNullExpression(parent))) {
            n = parent;
            parent = parent.parent;
        }
        if (!parent) return 'ref';
        if ((ts.isJsxOpeningElement(parent) || ts.isJsxSelfClosingElement(parent)) && parent.tagName === n) return 'jsx';
        if (ts.isJsxClosingElement(parent)) return null;
        if (ts.isCallExpression(parent) && parent.expression === n) return 'func';
        if (ts.isNewExpression(parent) && parent.expression === n) return 'new';
        if (ts.isTypeReferenceNode(parent) || ts.isTypeQueryNode(parent) || ts.isQualifiedName(parent) || ts.isExpressionWithTypeArguments(parent)) {
            return ts.isExpressionWithTypeArguments(parent) && ts.isHeritageClause(parent.parent) ? null : 'type';
        }
        if (ts.isImportSpecifier(parent) || ts.isImportClause(parent) || ts.isNamespaceImport(parent) || ts.isImportEqualsDeclaration(parent)) return 'import';
        if (ts.isExportSpecifier(parent)) return 'export';
        return 'ref';
    }

    isDeclarationName(id) {
        const parent = id.parent;
        if (!parent || parent.name !== id) return false;
        return ts.isVariableDeclaration(parent) || ts.isFunctionDeclaration(parent) || ts.isClassDeclaration(parent)
            || ts.isInterfaceDeclaration(parent) || ts.isTypeAliasDeclaration(parent) || ts.isEnumDeclaration(parent)
            || ts.isEnumMember(parent) || ts.isParameter(parent) || ts.isPropertyDeclaration(parent) || ts.isMethodDeclaration(parent)
            || ts.isPropertySignature(parent) || ts.isMethodSignature(parent) || ts.isBindingElement(parent)
            || ts.isPropertyAssignment(parent) || ts.isTypeParameterDeclaration(parent) || ts.isGetAccessorDeclaration(parent)
            || ts.isSetAccessorDeclaration(parent) || ts.isFunctionExpression(parent) || ts.isClassExpression(parent)
            || ts.isModuleDeclaration(parent) || ts.isLabeledStatement(parent) || ts.isJsxAttribute(parent)
            || ts.isNamespaceExportDeclaration(parent) || ts.isPropertyAccessExpression(parent) || ts.isQualifiedName(parent) && parent.right === id;
    }

    collectRefs(root, symbolId) {
        if (!root) return;
        const visit = (node) => {
            if (ts.isPropertyAccessExpression(node)) {
                visit(node.expression);
                this.memberAccess(node, node.name, symbolId);
                return;
            }
            if (ts.isQualifiedName(node)) {
                visit(node.left);
                this.memberAccess(node, node.right, symbolId);
                return;
            }
            if (ts.isJsxAttribute(node)) {
                if (ts.isIdentifier(node.name)) {
                    const target = this.resolve(node.name);
                    if (target && target.member !== null) this.addRef(symbolId, 'prop', target, node.name);
                }
                if (node.initializer) visit(node.initializer);
                return;
            }
            if (ts.isImportSpecifier(node) || ts.isExportSpecifier(node)) {
                // `import { A as B }`: the local name resolves to the original declaration.
                this.identifier(node.name, symbolId);
                return;
            }
            if (ts.isIdentifier(node)) {
                this.identifier(node, symbolId);
                return;
            }
            ts.forEachChild(node, visit);
        };
        visit(root);
    }

    identifier(id, symbolId) {
        if (this.isDeclarationName(id) && !ts.isImportSpecifier(id.parent) && !ts.isExportSpecifier(id.parent)) return;
        if (ts.isShorthandPropertyAssignment(id.parent)) {
            // { foo } refers to the variable foo
            let target = null;
            try {
                target = this.targetOfSymbol(this.checker.getShorthandAssignmentValueSymbol(id.parent));
            } catch {
                target = null;
            }
            if (target) this.addRef(symbolId, target.member === null ? 'ref' : 'prop', target, id);
            return;
        }
        const kind = this.usageKind(id);
        if (kind === null) return;
        const target = this.resolve(id);
        if (!target) return;
        if (target.member !== null) {
            this.addRef(symbolId, kind === 'func' ? 'call' : 'prop', target, id);
            return;
        }
        this.addRef(symbolId, kind, target, id);
    }

    memberAccess(access, name, symbolId) {
        if (!ts.isPrivateIdentifier(name) && !ts.isIdentifier(name)) return;
        let symbol;
        try {
            symbol = this.checker.getSymbolAtLocation(name);
        } catch {
            return;
        }
        const parent = access.parent;
        const called = !!parent && ts.isCallExpression(parent) && parent.expression === access;
        if (symbol === undefined) {
            // Like PHP's unknown receivers: calls we can't resolve at all are kept with no class, so find_usages can list them.
            if (called) this.addRef(symbolId, 'call', { cls: null, member: name.text }, name);
            return;
        }
        const target = this.targetOfSymbol(symbol);
        if (target === null) return;
        if (target.member === null) {
            // Namespace import access: S.Button / <S.Button>
            const kind = this.usageKind(access);
            if (kind !== null) this.addRef(symbolId, kind, target, name);
            return;
        }
        const isEnumCase = !!(symbol.flags & ts.SymbolFlags.EnumMember);
        this.addRef(symbolId, called ? 'call' : isEnumCase ? 'const' : 'prop', target, name);
    }
}

// ---- stdio protocol -----------------------------------------------------------------------------------------------

async function main() {
    const root = process.argv[2] ?? process.env.CODEMAP_PROJECT ?? '/project';
    const workspace = new Workspace(root);
    const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
    for await (const line of rl) {
        if (line.trim() === '') continue;
        let response;
        try {
            const request = JSON.parse(line);
            if (Array.isArray(request.files)) {
                workspace.setFiles(request.files);
            }
            response = { results: workspace.parse(request.parse ?? []) };
        } catch (e) {
            response = { error: String(e?.stack ?? e) };
        }
        process.stdout.write(JSON.stringify(response) + '\n');
    }
}

if (import.meta.url === `file://${process.argv[1]}` || process.argv[1]?.endsWith('/indexer.mjs')) {
    main().catch((e) => {
        process.stderr.write(String(e?.stack ?? e) + '\n');
        process.exit(1);
    });
}
