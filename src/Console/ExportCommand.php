<?php

declare(strict_types=1);

namespace CodeMap\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('export', 'Write OVERVIEW.md and per-namespace markdown from the current index')]
final class ExportCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::OPTIONAL, 'Output directory (defaults to CODEMAP_OUT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $input->getArgument('dir') ?? $this->app->config->outputDir;
        if (null === $dir) {
            $output->writeln('<error>No output directory: pass one or set CODEMAP_OUT.</error>');

            return self::INVALID;
        }

        $written = $this->app->exporter()->export($dir, $this->app->projectName());
        $output->writeln("{$written} file(s) updated in {$dir}");

        return self::SUCCESS;
    }
}
