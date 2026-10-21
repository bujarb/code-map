<?php

declare(strict_types=1);

namespace CodeMap\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('index', 'Index the project once (incremental unless --full)')]
final class IndexCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Re-hash every file instead of trusting mtime/size');
        $this->addOption('no-export', null, InputOption::VALUE_NONE, 'Skip writing the markdown overview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $progress = $output->isVerbose()
            ? static fn (int $done, int $total) => $output->writeln("  {$done}/{$total}")
            : null;

        $result = $this->app->indexer()->sync((bool) $input->getOption('full'), $progress);
        $output->writeln($result->summary());
        foreach (\array_slice($result->errors, 0, 20, true) as $path => $error) {
            $output->writeln("  <comment>{$path}</comment>: {$error}");
        }

        $dir = $this->app->config->outputDir;
        if (null !== $dir && !$input->getOption('no-export')) {
            $written = $this->app->exporter()->export($dir, $this->app->projectName());
            $output->writeln("Markdown: {$written} file(s) updated in {$dir}");
        }

        return self::SUCCESS;
    }
}
