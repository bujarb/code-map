<?php

declare(strict_types=1);

namespace CodeMap\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('watch', 'Keep the index and markdown up to date by polling for file changes')]
final class WatchCommand extends Command
{
    private bool $running = true;

    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(\SIGTERM, fn () => $this->running = false);
            pcntl_signal(\SIGINT, fn () => $this->running = false);
        }

        $config = $this->app->config;
        $indexer = $this->app->indexer();
        $db = $this->app->db();
        $output->writeln(\sprintf(
            '[%s] watching %s (include: %s) every %ds',
            date('H:i:s'), $config->projectRoot, implode(', ', $config->include), $config->pollInterval,
        ));

        $first = true;
        while ($this->running) {
            $lastFull = (int) ($db->getMeta('last_full_scan') ?? 0);
            $full = time() - $lastFull >= $config->fullRescanInterval;

            try {
                $result = $indexer->sync($full);
                if ($result->changed() || $full || $first) {
                    $output->writeln(\sprintf('[%s] %s%s', date('H:i:s'), $full ? 'full rescan: ' : '', $result->summary()));
                    if ($output->isVerbose() || \count($result->indexed) <= 10) {
                        foreach ([...$result->indexed, ...$result->removed] as $path) {
                            $output->writeln("  {$path}".(isset($result->errors[$path]) ? "  <comment>parse error: {$result->errors[$path]}</comment>" : ''));
                        }
                    }
                }
                if (null !== $config->outputDir && ($result->changed() || $first)) {
                    $this->app->exporter()->export($config->outputDir, $this->app->projectName());
                }
            } catch (\Throwable $e) {
                // Keep watching: a transient FS or lock error must not kill the container.
                $output->writeln(\sprintf('[%s] <error>%s</error>', date('H:i:s'), $e->getMessage()));
            }
            $first = false;

            for ($i = 0; $i < $config->pollInterval * 10 && $this->running; ++$i) {
                usleep(100_000);
            }
        }

        return self::SUCCESS;
    }
}
