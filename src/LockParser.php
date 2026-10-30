<?php

namespace HichemTabTech\Pomposer;

use RuntimeException;
use Composer\Semver\Semver;

class LockParser
{
    protected string $composerPath;
    protected string $lockPath;

    protected array $resolved = [];
    protected array $devPackageNames = [];
    protected bool $wasResolved = false;
    protected PackagistGateway $packagist;

    public function __construct(PackagistGateway $packagist, string $composerPath = 'composer.json', string $lockPath = 'composer.lock')
    {
        $this->packagist = $packagist;
        $this->composerPath = $composerPath;
        $this->lockPath = $lockPath;
    }

    public function getPackages(): array
    {
        $composer = json_decode(file_get_contents($this->composerPath), true);

        if (file_exists($this->lockPath)) {
            $lock = json_decode(file_get_contents($this->lockPath), true);

            if (isset($lock['content-hash']) && $lock['content-hash'] !== LockFile::contentHash($composer)) {
                echo "⚠️  composer.lock is not up to date with the latest changes in composer.json. Delete it to re-resolve.\n";
            }

            $packages = $lock['packages'] ?? [];
            $packagesDev = $lock['packages-dev'] ?? [];
            return [$composer, array_merge($packages, $packagesDev)];
        }

        if (!file_exists($this->composerPath)) {
            throw new RuntimeException("composer.json not found.");
        }

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
     * Whether packages were resolved from composer.json (no composer.lock was present).
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