<?php

declare(strict_types=1);

namespace CodeMap\Tests;

use CodeMap\Config\Config;
use CodeMap\Indexer\Indexer;
use CodeMap\Query\CodeQuery;
use CodeMap\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Copies the fixture project to a temp dir so tests can modify files, and indexes it into a temp database.
 */
abstract class IndexTestCase extends TestCase
{
    protected string $root;
    protected Config $config;
    protected Database $db;
    protected Indexer $indexer;
    protected CodeQuery $query;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/codemap-test-'.bin2hex(random_bytes(4));
        $this->copy(__DIR__.'/fixtures/'.$this->fixture(), $this->root);

        $this->config = new Config(projectRoot: $this->root, databasePath: $this->root.'/.data/index.sqlite', outputDir: $this->root.'/.code-map', exclude: Config::defaultExcludes($this->lang()), lang: $this->lang());
        $this->db = new Database($this->config->databasePath);
        $this->indexer = new Indexer($this->config, $this->db);
        $this->query = new CodeQuery($this->db);
        $this->indexer->sync();
    }

    protected function fixture(): string
    {
        return 'sample-project';
    }

    protected function lang(): string
    {
        return 'php';
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    protected function writeFile(string $relative, string $content): void
    {
        $path = $this->root.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        // mtime has 1s resolution; bump it so the change is visible to the poller.
        touch($path, time() + 5);
        clearstatcache();
    }

    private function copy(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to.'/'.substr($item->getPathname(), \strlen($from) + 1);
            $item->isDir() ? mkdir($target) : copy($item->getPathname(), $target);
        }
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
