<?php

declare(strict_types=1);

namespace CodeMap\Console;

use CodeMap\Mcp\ServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('serve', 'Serve the MCP tools over streamable HTTP (default) or stdio')]
final class ServeCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdio', null, InputOption::VALUE_NONE, 'Speak MCP over stdin/stdout, e.g. for `docker exec -i`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('stdio')) {
            ServerFactory::create($this->app->db())->run(new StdioTransport());

            return self::SUCCESS;
        }

        $process = self::startHttpServer($this->app);

        return proc_close($process);
    }

    /**
     * @return resource
     */
    public static function startHttpServer(Application $app)
    {
        $config = $app->config;
        $address = $config->httpHost.':'.$config->httpPort;
        $router = \dirname(__DIR__, 2).'/public/mcp.php';

        // Workers let one slow request not block others; the built-in server only supports this on POSIX.
        $env = getenv() + ['PHP_CLI_SERVER_WORKERS' => '4'];
        fwrite(\STDERR, "MCP endpoint listening on http://{$address}/mcp\n");

        $process = proc_open([\PHP_BINARY, '-S', $address, $router], [0 => ['file', '/dev/null', 'r'], 1 => \STDOUT, 2 => \STDERR], $pipes, null, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Could not start the HTTP server on {$address}");
        }

        return $process;
    }
}
