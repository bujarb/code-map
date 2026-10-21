<?php

declare(strict_types=1);

namespace CodeMap\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('run', 'Container entrypoint: MCP HTTP server in the background, watcher in the foreground')]
final class RunCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $server = ServeCommand::startHttpServer($this->app);

        try {
            return $this->app->find('watch')->run(new ArrayInput([]), $output);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
