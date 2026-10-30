<?php

namespace HichemTabTech\Pomposer;

use HichemTabTech\Pomposer\Concerns\CommandsUtils;
use RuntimeException;

/**
 * Links packages from the global store into the project's vendor directory
 * (vendor/<vendor>/<package> -> ~/.pomposer-store/<vendor>/<package>/<version>),
 * the same way pnpm links node_modules entries, so IDEs can index vendor/.
 */
class VendorLinker
{
    use CommandsUtils;

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

            $this->createLink($target, $linkPath);
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
            // A real directory left behind (e.g. by Composer) - replace it with a link.
            $this->removeRecursive($linkPath);
        }

        $parent = dirname($linkPath);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        if (@symlink($target, $linkPath)) {
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
     * Removes vendor/<vendor>/<package> links that point into the store but are no longer required.
     */
    protected function pruneStaleLinks(array $expected): void
    {
        $storePath = $this->store->getStorePath();

        foreach (glob("$this->vendorDir/*/*") ?: [] as $linkPath) {
            if (isset($expected[$linkPath]) || !is_link($linkPath)) {
                continue;
            }

            if (str_starts_with($this->readLink($linkPath), $storePath)) {
                $this->removeLink($linkPath);

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
