<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class PhpSourceParser implements SourceParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function beginSync(array $files): void
    {
    }

    public function version(): string
    {
        return '';
    }

    public function parseFiles(array $sources): array
    {
        $results = [];
        foreach ($sources as $path => $code) {
            $results[$path] = $this->parse($code);
        }

        return $results;
    }

    /**
     * @return array{0: FileSymbols, 1: ?string}
     */
    public function parse(string $code): array
    {
        // Collect errors instead of throwing so a half-written file still yields whatever parsed cleanly.
        $errors = new Collecting();
        try {
            $ast = $this->parser->parse($code, $errors) ?? [];
        } catch (Error $e) {
            return [new FileSymbols(), $e->getMessage()];
        }

        // Two passes: the collector looks ahead at property/constructor types when entering a class,
        // so every name in the file must already be resolved by then.
        $ast = (new NodeTraverser(new NameResolver()))->traverse($ast);
        $collector = new SymbolCollector();
        (new NodeTraverser($collector))->traverse($ast);

        $error = $errors->hasErrors() ? $errors->getErrors()[0]->getMessage() : null;

        return [$collector->result, $error];
    }
}
