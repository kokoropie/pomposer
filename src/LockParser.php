<?php

namespace HichemTabTech\Pomposer;

use RuntimeException;
use Composer\Semver\Semver;

class LockParser
{
    protected string $composerPath;
    protected string $lockPath;

    protected array $resolved = [];
    /** @var array<string, array> Locked packages to keep during a partial update, by name. */
    protected array $locked = [];
    protected array $devPackageNames = [];
    protected bool $wasResolved = false;
    protected PackagistGateway $packagist;

    public function __construct(PackagistGateway $packagist, string $composerPath = 'composer.json', string $lockPath = 'composer.lock')
    {
        $this->packagist = $packagist;
        $this->composerPath = $composerPath;
        $this->lockPath = $lockPath;
    }

    /**
     * Packages to install: from composer.lock when present, otherwise resolved from composer.json.
     */
    public function getPackages(): array
    {
        $composer = $this->readComposerJson();

        if (file_exists($this->lockPath)) {
            $lock = $this->readLock();

            if (isset($lock['content-hash']) && $lock['content-hash'] !== LockFile::contentHash($composer)) {
                echo "⚠️  composer.lock is not up to date with the latest changes in composer.json. Run `pomposer update` to re-resolve.\n";
            }

            $packages = $lock['packages'] ?? [];
            $packagesDev = $lock['packages-dev'] ?? [];
            return [$composer, array_merge($packages, $packagesDev)];
        }

        return $this->resolveAll($composer);
    }

    /**
     * Re-resolves dependencies from composer.json, ignoring the lock.
     *
     * @param string[] $only Packages to update. Other locked packages keep their version while it still
     *                       satisfies the constraints. Empty = update everything.
     */
    public function update(array $only = []): array
    {
        $composer = $this->readComposerJson();

        if ($only && file_exists($this->lockPath)) {
            $lock = $this->readLock();

            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
                if (!in_array(strtolower($package['name']), $only, true)) {
                    $this->locked[$package['name']] = $package;
                }
            }

            foreach ($only as $name) {
                if (!isset($composer['require'][$name]) && !isset($composer['require-dev'][$name]) && !$this->isInLock($lock, $name)) {
                    echo "⚠️  Package $name is not required in composer.json nor locked, ignoring.\n";
                }
            }
        }

        return $this->resolveAll($composer);
    }

    protected function readComposerJson(): array
    {
        if (!file_exists($this->composerPath)) {
            throw new RuntimeException("composer.json not found.");
        }

        return json_decode(file_get_contents($this->composerPath), true);
    }

    protected function readLock(): array
    {
        return json_decode(file_get_contents($this->lockPath), true);
    }

    protected function isInLock(array $lock, string $name): bool
    {
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (strtolower($package['name']) === $name) {
                return true;
            }
        }

        return false;
    }

    protected function resolveAll(array $composer): array
    {
        $requires = $composer['require'] ?? [];
        $requiresDev = $composer['require-dev'] ?? [];

        foreach ($requires as $name => $constraint) {
            if (!str_contains($name, '/')) continue; // skip php, ext-*, lib-*
            $this->resolve($name, $constraint);
        }

        // Anything first reached from require-dev only belongs in packages-dev.
        $nonDevNames = array_keys($this->resolved);

        foreach ($requiresDev as $name => $constraint) {
            if (!str_contains($name, '/')) continue;
            $this->resolve($name, $constraint);
        }

        $this->devPackageNames = array_values(array_diff(array_keys($this->resolved), $nonDevNames));
        $this->wasResolved = true;

        return [$composer, array_values($this->resolved)];
    }

    /**
     * Whether packages were resolved from composer.json (no lock was used), so composer.lock must be written.
     */
    public function wasResolved(): bool
    {
        return $this->wasResolved;
    }

    /**
     * @return array{0: array, 1: array} The resolved [packages, packages-dev].
     */
    public function getResolvedPackages(): array
    {
        $packages = [];
        $packagesDev = [];

        foreach ($this->resolved as $name => $package) {
            if (in_array($name, $this->devPackageNames, true)) {
                $packagesDev[] = $package;
            } else {
                $packages[] = $package;
            }
        }

        return [$packages, $packagesDev];
    }

    protected function resolve(string $package, string $constraint): void
    {
        if (isset($this->resolved[$package])) {
            return;
        }

        $locked = $this->locked[$package] ?? null;
        if ($locked && Semver::satisfies($locked['version'], $constraint)) {
            echo "🔒 Keeping $package at {$locked['version']}\n";
            $this->resolved[$package] = $locked;

            foreach ($locked['require'] ?? [] as $dep => $depConstraint) {
                if (!str_contains($dep, '/')) continue;
                $this->resolve($dep, $depConstraint);
            }

            return;
        }

        echo "🔍 Resolving $package ($constraint)...\n";

        $metadata = $this->packagist->getPackageMetadata($package);
        $bestVersion = $this->pickBestVersion($metadata, $constraint);
        echo "    ➡️  Best version found: {$bestVersion['version']}\n";

        if (!$bestVersion) {
            throw new RuntimeException("Could not resolve version for $package");
        }

        $this->resolved[$package] = [
            'name' => $package,
            'version' => $bestVersion['version'],
            'source' => $bestVersion['source'] ?? [],
            'dist' => $bestVersion['dist'] ?? [],
            ...$bestVersion,
        ];

        foreach ($bestVersion['require'] ?? [] as $dep => $depConstraint) {
            if (!str_contains($dep, '/')) continue; // skip ext-* or php
            $this->resolve($dep, $depConstraint);
        }
    }

    protected function pickBestVersion(array $versions, string $constraint): ?array
    {
        $currentPhpVersion = phpversion();
        foreach ($versions as $v) {
            if (str_starts_with($v['version'], 'dev')) {
                continue;
            }

            $version = $v['version_normalized'] ?? $v['version'];

            // If the package requires PHP, check compatibility
            if (isset($requires['php']) && !Semver::satisfies($currentPhpVersion, $requires['php'])) {
                continue;
            }

            // Check if this version satisfies the constraint
            if (Semver::satisfies($version, $constraint)) {
                echo "    ➡️  Version {$v['version']} satisfies constraint $constraint\n";
                return $v;
            }
        }

        // Fallback to the first stable
        foreach ($versions as $v) {
            if (!str_starts_with($v['version'], 'dev')) {
                echo "    ➡️  Fallback to first stable version: {$v['version']}\n";
                return $v;
            }
        }

        return $versions[0] ?? null;
    }
}