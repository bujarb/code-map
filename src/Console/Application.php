<?php

declare(strict_types=1);

namespace CodeMap\Console;

use CodeMap\Config\Config;
use CodeMap\Export\MarkdownExporter;
use CodeMap\Indexer\Indexer;
use CodeMap\Mcp\ServerFactory;
use CodeMap\Query\CodeQuery;
use CodeMap\Storage\Database;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application extends ConsoleApplication
{
    private ?Database $db = null;

    public function __construct(public readonly Config $config)
    {
        parent::__construct('php-code-map', ServerFactory::VERSION);

        $this->addCommands([
            new IndexCommand($this),
            new WatchCommand($this),
            new ServeCommand($this),
            new ExportCommand($this),
            new QueryCommand($this),
            new RunCommand($this),
        ]);
    }

    public function db(): Database
    {
        return $this->db ??= new Database($this->config->databasePath);
    }

    public function indexer(): Indexer
    {
        return new Indexer($this->config, $this->db());
    }

    public function query(): CodeQuery
    {
        return new CodeQuery($this->db());
    }

    public function exporter(): MarkdownExporter
    {
        return new MarkdownExporter($this->db(), $this->query(), $this->config->overviewMaxChars);
    }

    public function projectName(): string
    {
        return getenv('CODEMAP_PROJECT_NAME') ?: basename($this->config->projectRoot);
    }
}
