<?php

namespace HichemTabTech\Pomposer;

use HichemTabTech\Pomposer\Concerns\CommandsUtils;
use RuntimeException;

/**
 * Links packages from the global store into the project's vendor directory
 * (vendor/<vendor>/<package> -> ~/.pomposer-store/<vendor>/<package>/<version>),
 * the same way pnpm links node_modules entries, so IDEs can index vendor/.
 *
 * Packages with binaries are the exception: PHP resolves symlinks, so a bin executed from a
 * symlinked package would see __DIR__ inside the store and could not find vendor/autoload.php
 * (e.g. __DIR__ . '/../../../autoload.php'). For those, vendor/<vendor>/<package> is a real
 * directory, the directories leading to each bin are real too, the bin files are hard links
 * (copies across filesystems) and everything else is symlinked into the store.
 */
class VendorLinker
{
    use CommandsUtils;

    /** Marks a materialized package directory and records the store path it mirrors. */
    public const MARKER_FILE = '.pomposer-link';

    protected string $vendorDir;

    public function __construct(
        protected PackageStore $store,
        string $vendorDir = 'vendor'
    ) {
        $this->vendorDir = rtrim($vendorDir, '/\\');
    }

    public function link(array $packages): void
    {
        $expected = [];

        foreach ($packages as $package) {
            [$vendor, $name] = explode('/', $package['name']);
            $target = $this->store->getPackagePath($vendor, $name, $package['version']);
            $linkPath = "$this->vendorDir/$vendor/$name";

            $expected[$linkPath] = true;

            if (!is_dir($target)) {
                echo "⚠️  Skipping link for {$package['name']} - not found in store at $target\n";
                continue;
            }

            $bins = array_map(fn ($bin) => trim($bin, '/'), (array) ($package['bin'] ?? []));

            if ($bins) {
                $this->createMaterialized($target, $linkPath, $bins);
            } else {
                $this->createLink($target, $linkPath);
            }
        }

        $this->pruneStaleLinks($expected);
    }

    protected function createLink(string $target, string $linkPath): void
    {
        if (is_link($linkPath)) {
            if ($this->readLink($linkPath) === $target) {
                return;
            }
            $this->removeLink($linkPath);
        } elseif (file_exists($linkPath)) {
            // A real directory left behind (e.g. by Composer, or a materialized package) - replace it with a link.
            $this->removeRecursive($linkPath);
        }

        $parent = dirname($linkPath);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $this->symlink($target, $linkPath);
    }

    /**
     * @param string[] $bins Bin paths relative to the package root.
     */
    protected function createMaterialized(string $target, string $linkPath, array $bins): void
    {
        $marker = "$linkPath/" . self::MARKER_FILE;

        if (!is_link($linkPath) && is_file($marker) && file_get_contents($marker) === $target) {
            return;
        }

        if (is_link($linkPath)) {
            $this->removeLink($linkPath);
        } elseif (file_exists($linkPath)) {
            $this->removeRecursive($linkPath);
        }

        mkdir($linkPath, 0755, true);
        $this->mirror($target, $linkPath, $bins);
        file_put_contents($marker, $target);
    }

    /**
     * Mirrors $source into $dest: entries on the way to a bin are real (dirs created, files hard-linked),
     * everything else is a symlink into the store.
     *
     * @param string[] $realPaths Paths relative to $source that must be real.
     */
    protected function mirror(string $source, string $dest, array $realPaths): void
    {
        foreach (scandir($source) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = "$source/$entry";
            $dst = "$dest/$entry";
            $isRealFile = false;
            $nested = [];

            foreach ($realPaths as $path) {
                if ($path === $entry) {
                    $isRealFile = true;
                } elseif (str_starts_with($path, "$entry/")) {
                    $nested[] = substr($path, strlen($entry) + 1);
                }
            }

            if ($isRealFile && is_file($src)) {
                if (!@link($src, $dst)) {
                    copy($src, $dst);
                }
            } elseif ($nested && is_dir($src) && !is_link($src)) {
                mkdir($dst, 0755);
                $this->mirror($src, $dst, $nested);
            } else {
                $this->symlink($src, $dst);
            }
        }
    }

    protected function symlink(string $target, string $linkPath): void
    {
        if (@symlink($target, $linkPath)) {
            return;
        }

        // Windows without symlink privileges: a file can be copied.
        if (is_file($target)) {
            copy($target, $linkPath);
            return;
        }

        // Windows without symlink privileges: fall back to a directory junction.
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = sprintf('mklink /J %s %s', escapeshellarg(str_replace('/', '\\', $linkPath)), escapeshellarg(str_replace('/', '\\', $target)));
            exec($cmd, $out, $code);
            if ($code === 0) {
                return;
            }
        }

        throw new RuntimeException("Failed to link $linkPath -> $target");
    }

    /**
     * Removes vendor/<vendor>/<package> links (or materialized directories) into the store that are no longer required.
     */
    protected function pruneStaleLinks(array $expected): void
    {
        $storePath = $this->store->getStorePath();

        foreach (glob("$this->vendorDir/*/*") ?: [] as $linkPath) {
            if (isset($expected[$linkPath])) {
                continue;
            }

            $isStoreLink = is_link($linkPath) && str_starts_with($this->readLink($linkPath), $storePath);
            $isMaterialized = !is_link($linkPath) && is_file("$linkPath/" . self::MARKER_FILE);

            if ($isStoreLink || $isMaterialized) {
                $isStoreLink ? $this->removeLink($linkPath) : $this->removeRecursive($linkPath);

                $parent = dirname($linkPath);
                if (count(scandir($parent)) === 2) {
                    rmdir($parent);
                }
            }
        }
    }

    protected function readLink(string $linkPath): string
    {
        return (string) readlink($linkPath);
    }

    protected function removeLink(string $linkPath): void
    {
        // On Windows, directory links/junctions must be removed with rmdir().
        if (DIRECTORY_SEPARATOR === '\\' && is_dir($linkPath)) {
            rmdir($linkPath);
            return;
        }

        unlink($linkPath);
    }
}
