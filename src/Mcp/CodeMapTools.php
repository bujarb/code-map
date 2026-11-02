<?php

declare(strict_types=1);

namespace CodeMap\Mcp;

use CodeMap\Query\CodeQuery;

/**
 * MCP tool handlers. The SDK builds each tool's input schema from these signatures and @param docs,
 * so keep parameter names and descriptions agent-friendly.
 *
 * Every tool definition costs context in every session, and every extra round trip re-sends the whole
 * conversation, so the set is kept small and each tool aims to answer its question in one call.
 */
final readonly class CodeMapTools
{
    /**
     * name => [method, description]
     */
    public const array TOOLS = [
        'find_usages' => ['findUsages', 'Who calls / uses a class or one of its members, with file:line. With a member it also shows the member\'s signature, what it calls, and overrides in subtypes, so it answers impact questions ("what breaks if I change X") in one call. Includes calls through parent classes and interfaces and lists calls on receivers of unknown type separately. Short class names work (e.g. "ActivityFacade"); no need to search first.'],
        'class_outline' => ['classOutline', 'Outline of a class, interface, trait or enum: parents, attributes, constants, properties (incl. injected dependencies), method signatures with line numbers. Large classes list method names only; pass member to get full signatures for matching members. Pass a .php path to outline a whole file. Short class names work. Much cheaper than reading the file; not needed after find_usages for a single method.'],
        'find_implementations' => ['findImplementations', 'All subtypes of a class or interface: implementations, subclasses and sub-interfaces, transitively, with file:line. Short names work.'],
        'callees' => ['callees', 'What a method or function references: calls, instantiations, static calls, constants, type hints. Pass "Class::method" (short class name works).'],
        'find_by_attribute' => ['findByAttribute', 'Symbols carrying a PHP attribute, e.g. Route, AsMessageHandler, AsCommand, AsEventListener, ORM\\Entity, optionally filtered by text in the attribute arguments (e.g. a route path "/activity" or "POST"). Use for entry points.'],
        'search_symbols' => ['searchSymbols', 'Find classes, methods, properties, constants by partial name or doc words (camelCase-aware: "OrderRepo", "expire order"). Only needed when you do not know a name; the other tools accept short names directly.'],
        'namespace_tree' => ['namespaceTree', 'Namespace hierarchy with class counts, for orientation in an unfamiliar area.'],
        'index_status' => ['indexStatus', 'Index freshness: file and symbol counts, last scan time, files that failed to parse.'],
    ];

    /**
     * Descriptions that differ when the index holds a TypeScript project (same tools, same parameters).
     */
    public const array TS_DESCRIPTIONS = [
        'find_usages' => 'Who imports / renders / calls / references an exported symbol (component, function, class, interface, type, enum, const) or one of its members, with file:line. JSX usages (<Button>), imports, calls, new, type references and re-exports are all included. With a member it also shows the member\'s signature, what it calls, overrides in subtypes, and calls of the same method name on values of unknown type. Short names work (e.g. "Button", "useCart", "OrderService::create"). Production code is listed first, then tests, stories and generated code; the header gives the total, page with offset, and drop noise with exclude="tests,stories,generated".',
        'class_outline' => 'Outline of a class, interface, type, enum, component or function: extends/implements, decorators, properties (incl. constructor parameter properties), methods with line numbers. Pass a .ts/.tsx path to outline a whole file. Short names work. Much cheaper than reading the file.',
        'find_implementations' => 'All subtypes of a class or interface: implementing classes, subclasses and extending interfaces, transitively, with file:line. Short names work.',
        'callees' => 'What a function, component or method references: calls, rendered JSX components, instantiations, type references. Pass "Name" or "Class::method".',
        'find_by_attribute' => 'Symbols carrying a TypeScript decorator (e.g. injectable, Component, Input), optionally filtered by text in the decorator arguments.',
        'search_symbols' => 'Find components, functions, classes, interfaces, types, enums, consts and members by partial name or doc words (camelCase-aware: "useCart", "order status"). Exact names rank first, then prefixes, then substrings; production code before tests, stories and generated code (exclude="tests,stories,generated" drops them). Only needed when you do not know a name; the other tools accept short names directly.',
        'namespace_tree' => 'Directory (module) hierarchy with top-level symbol counts, for orientation in an unfamiliar area. Accepts "apps/customer/app" or "apps\\customer\\app".',
    ];

    public function __construct(private CodeQuery $query)
    {
    }

    /**
     * @param string      $fqcn   Class, interface, trait, enum or function; short name or fully-qualified
     * @param string|null $member Optional member: method name, constant name or $property
     * @param int         $limit   Maximum results per page (1-500)
     * @param int         $offset  Skip this many results (paging; the header shows the total and the next offset)
     * @param string|null $exclude Leave out files of these kinds, comma-separated: tests, stories, generated
     */
    public function findUsages(string $fqcn, ?string $member = null, int $limit = 100, int $offset = 0, ?string $exclude = null): string
    {
        return $this->query->findUsages($fqcn, $member, $limit, $offset, $exclude);
    }

    /**
     * @param string      $fqcn           Class name (short or fully-qualified), or a file path ending in .php
     * @param string|null $member         Only members whose name contains this text, with full signatures
     * @param bool        $includePrivate Include private members (injected dependencies are always shown)
     */
    public function classOutline(string $fqcn, ?string $member = null, bool $includePrivate = true): string
    {
        return $this->query->classOutline($fqcn, $member, $includePrivate);
    }

    /**
     * @param string $fqcn       Interface or class, short or fully-qualified
     * @param bool   $transitive Include indirect subtypes
     */
    public function findImplementations(string $fqcn, bool $transitive = true): string
    {
        return $this->query->findImplementations($fqcn, $transitive);
    }

    /**
     * @param string $fqn Method as Class::method, or a function name
     */
    public function callees(string $fqn): string
    {
        return $this->query->callees($fqn);
    }

    /**
     * @param string      $name        Attribute name, e.g. Route or Symfony\Component\Routing\Attribute\Route
     * @param string|null $argContains Only attributes whose arguments contain this text, e.g. "/api/orders"
     * @param int         $limit       Maximum results (1-1000)
     */
    public function findByAttribute(string $name, ?string $argContains = null, int $limit = 200): string
    {
        return $this->query->findByAttribute($name, $argContains, $limit);
    }

    /**
     * @param string      $query Words or partial names, e.g. "invoice pdf" or "ContractRepo"
     * @param string|null $kind  Restrict to one kind: class, interface, trait, enum, method, property, constant, case, function
     * @param int         $limit   Maximum results (1-200)
     * @param string|null $exclude Leave out files of these kinds, comma-separated: tests, stories, generated
     */
    public function searchSymbols(string $query, ?string $kind = null, int $limit = 10, ?string $exclude = null): string
    {
        return $this->query->searchSymbols($query, $kind, $limit, $exclude);
    }

    /**
     * @param string $prefix Only show this namespace and below, e.g. App\Domain (TypeScript: a directory path, "/" or "\" separated)
     * @param int    $depth  Levels to show below the prefix
     */
    public function namespaceTree(string $prefix = '', int $depth = 3): string
    {
        return $this->query->namespaceTree($prefix, $depth);
    }

    public function indexStatus(): string
    {
        return $this->query->indexStatus();
    }
}
