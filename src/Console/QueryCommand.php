<?php

declare(strict_types=1);

namespace CodeMap\Console;

use CodeMap\Mcp\CodeMapTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the exact same handlers the MCP server exposes, for debugging without an MCP client:
 *   codemap query class_outline 'App\Service\Foo'
 *   codemap query find_usages 'App\Service\Foo' save
 *   codemap query find_usages Button --limit=50 --offset=50 --exclude=tests,stories,generated
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
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Positional tool arguments')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum results (tools with a limit parameter)')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip this many results (find_usages paging)')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Leave out file kinds: tests,stories,generated (find_usages, search_symbols)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Symbol kind filter (search_symbols)')
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Levels to show (namespace_tree)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool = (string) $input->getArgument('tool');
        if (!isset(CodeMapTools::TOOLS[$tool])) {
            $output->writeln("<error>Unknown tool \"{$tool}\".</error> Available: ".implode(', ', array_keys(CodeMapTools::TOOLS)));

            return self::INVALID;
        }

        $method = new \ReflectionMethod(CodeMapTools::class, CodeMapTools::TOOLS[$tool][0]);
        $params = $method->getParameters();
        $cast = static function (\ReflectionParameter $param, string $raw): mixed {
            $type = $param->getType() instanceof \ReflectionNamedType ? $param->getType()->getName() : 'string';

            return match ($type) {
                'int' => (int) $raw,
                'bool' => filter_var($raw, \FILTER_VALIDATE_BOOL),
                default => $raw,
            };
        };
        $args = [];
        foreach (array_values($input->getArgument('args')) as $i => $raw) {
            if (!isset($params[$i])) {
                break;
            }
            $args[$params[$i]->getName()] = $cast($params[$i], $raw);
        }
        // Named options fill (or override) the parameter of the same name, so later parameters need no placeholders.
        foreach ($params as $param) {
            $value = $input->hasOption($param->getName()) ? $input->getOption($param->getName()) : null;
            if (null !== $value) {
                $args[$param->getName()] = $cast($param, (string) $value);
            }
        }
        foreach (['limit', 'offset', 'exclude', 'kind', 'depth'] as $option) {
            if (null !== $input->getOption($option) && !\in_array($option, array_map(static fn ($p) => $p->getName(), $params), true)) {
                $output->writeln("<error>--{$option} does not apply to {$tool}.</error>");

                return self::INVALID;
            }
        }

        $output->writeln($method->invokeArgs(new CodeMapTools($this->app->query()), $args));

        return self::SUCCESS;
    }
}
