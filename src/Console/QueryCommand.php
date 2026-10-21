<?php

declare(strict_types=1);

namespace CodeMap\Console;

use CodeMap\Mcp\CodeMapTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the exact same handlers the MCP server exposes, for debugging without an MCP client:
 *   codemap query class_outline 'App\Service\Foo'
 *   codemap query find_usages 'App\Service\Foo' save
 */
#[AsCommand('query', 'Call an MCP tool from the command line')]
final class QueryCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tool', InputArgument::REQUIRED, 'One of: '.implode(', ', array_keys(CodeMapTools::TOOLS)))
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Positional tool arguments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool = (string) $input->getArgument('tool');
        if (!isset(CodeMapTools::TOOLS[$tool])) {
            $output->writeln("<error>Unknown tool \"{$tool}\".</error> Available: ".implode(', ', array_keys(CodeMapTools::TOOLS)));

            return self::INVALID;
        }

        $method = new \ReflectionMethod(CodeMapTools::class, CodeMapTools::TOOLS[$tool][0]);
        $args = [];
        foreach (array_values($input->getArgument('args')) as $i => $raw) {
            $param = $method->getParameters()[$i] ?? null;
            if (null === $param) {
                break;
            }
            $type = $param->getType() instanceof \ReflectionNamedType ? $param->getType()->getName() : 'string';
            $args[] = match ($type) {
                'int' => (int) $raw,
                'bool' => filter_var($raw, \FILTER_VALIDATE_BOOL),
                default => $raw,
            };
        }

        $output->writeln($method->invokeArgs(new CodeMapTools($this->app->query()), $args));

        return self::SUCCESS;
    }
}
