<?php

declare(strict_types=1);

namespace CodeMap\Query;

final class Names
{
    /**
     * App\Domain\Foo → Foo.
     */
    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Replaces every fully-qualified class name inside a signature with its short name. Long namespaces
     * dominate the size of outlines, and every tool accepts short names, so nothing is lost.
     */
    public static function shortenAll(string $text): string
    {
        return (string) preg_replace('/(?<![\w\\\\])\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)/', '$1', $text);
    }
}
