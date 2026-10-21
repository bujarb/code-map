<?php

declare(strict_types=1);

namespace CodeMap\Mcp;

use CodeMap\Query\CodeQuery;
use CodeMap\Storage\Database;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

final class ServerFactory
{
    public const string VERSION = '0.1.0';

    public static function create(Database $db, ?string $sessionDir = null): Server
    {
        $tools = new CodeMapTools(new CodeQuery($db));

        $builder = Server::builder()
            ->setServerInfo('php-code-map', self::VERSION)
            ->setInstructions(
                'Structural index of this PHP codebase, built from the AST and refreshed within seconds of file changes. '
                .'Prefer these tools over grep for "who calls / where is X used / what implements X / which routes" and for class outlines. '
                .'Short class names work everywhere, so call the tool directly instead of searching first; one call is usually the full answer. '
                .'Results include path:line, so read only those lines and do not re-check the same question with grep.',
            );

        if (null !== $sessionDir) {
            // HTTP requests are served by short-lived PHP processes, so sessions must outlive a single request.
            $builder->setSession(new FileSessionStore($sessionDir));
        }

        $readOnly = new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false);
        // Claude Code otherwise defers MCP tools behind its ToolSearch tool, which costs a whole extra round trip
        // (re-sending the conversation) before the first code-map call. The 8 small definitions are cheaper.
        $meta = false === filter_var(getenv('CODEMAP_ALWAYS_LOAD') ?: 'true', \FILTER_VALIDATE_BOOL)
            ? null
            : ['anthropic/alwaysLoad' => true];
        foreach (CodeMapTools::TOOLS as $name => [$method, $description]) {
            $builder->addTool([$tools, $method], $name, description: $description, annotations: $readOnly, meta: $meta);
        }

        return $builder->build();
    }
}
