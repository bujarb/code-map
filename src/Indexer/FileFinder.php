<?php

declare(strict_types=1);

namespace CodeMap\Indexer;

use CodeMap\Config\Config;

final readonly class FileFinder
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Cheap pass used on every poll: only stat() calls, no file reads.
     *
     * @return array<string, array{mtime: int, size: int}> keyed by path relative to the project root
     */
    public function find(): array
    {
        $root = $this->config->projectRoot;
        $files = [];

        foreach ($this->config->include as $dir) {
            $base = '.' === $dir ? $root : $root.'/'.trim($dir, '/');
            if (is_file($base)) {
                $this->add($files, $base, $root);
                continue;
            }
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
                    fn (\SplFileInfo $file) => $file->isDir()
                        ? !$file->isLink() && !$this->isExcluded($this->relative($file->getPathname(), $root), $file->getFilename())
                        : 'php' === $file->getExtension(),
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD, // unreadable dirs are skipped instead of aborting the scan
            );

            foreach ($iterator as $file) {
                /* @var \SplFileInfo $file */
                $this->add($files, $file->getPathname(), $root);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param array<string, array{mtime: int, size: int}> $files
     */
    private function add(array &$files, string $path, string $root): void
    {
        $relative = $this->relative($path, $root);
        if ($this->isExcluded($relative, basename($relative))) {
            return;
        }
        $stat = @stat($path);
        if (false !== $stat) {
            $files[$relative] = ['mtime' => $stat['mtime'], 'size' => $stat['size']];
        }
    }

    private function isExcluded(string $relativePath, string $basename): bool
    {
        foreach ($this->config->exclude as $pattern) {
            if (str_contains($pattern, '/') || str_contains($pattern, '*')) {
                $pattern = trim($pattern, '/');
                if (fnmatch($pattern, $relativePath) || str_starts_with($relativePath, $pattern.'/')) {
                    return true;
                }
            } elseif ($basename === $pattern) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $path, string $root): string
    {
        return ltrim(substr($path, \strlen($root)), '/');
    }
}
