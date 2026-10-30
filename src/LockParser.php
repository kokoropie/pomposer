<?php

namespace HichemTabTech\Pomposer;

use RuntimeException;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

class LockParser
{
    public const WITH_NO_DEPENDENCIES = 'none';
    /** Also update dependencies of the listed packages, except root requirements (-w). */
    public const WITH_DEPENDENCIES = 'direct';
    /** Also update dependencies of the listed packages, including root requirements (-W). */
    public const WITH_ALL_DEPENDENCIES = 'all';

    protected string $composerPath;
    protected string $lockPath;

    protected array $resolved = [];
    /** @var array<string, array> Locked packages to keep during a partial update, by name. */
    protected array $locked = [];
    protected array $devPackageNames = [];
    protected bool $wasResolved = false;
    protected string $minimumStability = 'stable';
    protected string $withDependencies = self::WITH_NO_DEPENDENCIES;
    /** @var string[] Root requirement names (require + require-dev), lowercase. */
    protected array $rootRequirements = [];
    /** @var string[] Packages being updated by a partial update, lowercase. */
    protected array $updating = [];
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
            $this->devPackageNames = array_column($packagesDev, 'name');

            return [$composer, array_merge($packages, $packagesDev)];
        }

        return $this->resolveAll($composer);
    }

    /**
     * Re-resolves dependencies from composer.json, ignoring the lock.
     *
     * @param string[] $only Packages to update. Other locked packages keep their version while it still
     *                       satisfies the constraints. Empty = update everything.
     * @param string $withDependencies One of the WITH_* constants: also update the dependencies of $only.
     */
    public function update(array $only = [], string $withDependencies = self::WITH_NO_DEPENDENCIES): array
    {
        $composer = $this->readComposerJson();
        $this->withDependencies = $withDependencies;
        $this->rootRequirements = array_map('strtolower', array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? [])));

        if ($only && file_exists($this->lockPath)) {
            $lock = $this->readLock();

            if ($withDependencies !== self::WITH_NO_DEPENDENCIES) {
                $expanded = $this->expandWithDependencies($lock, $only);

                if ($extra = array_diff($expanded, $only)) {
                    echo "🔍 Also updating dependencies: " . implode(', ', $extra) . "\n";
                }

                $only = $expanded;
            }

            $this->updating = $only;

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

    /**
     * Adds the locked dependencies of $only, recursively. Root requirements are only followed with -W.
     *
     * @param string[] $only
     * @return string[]
     */
    protected function expandWithDependencies(array $lock, array $only): array
    {
        $byName = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            $byName[strtolower($package['name'])] = $package;
        }

        $result = array_fill_keys($only, true);
        $queue = $only;

        while ($queue) {
            $name = array_shift($queue);

            foreach (array_keys($byName[$name]['require'] ?? []) as $dep) {
                $dep = strtolower($dep);

                if (isset($result[$dep]) || !isset($byName[$dep]) || !$this->followsDependency($dep)) {
                    continue;
                }

                $result[$dep] = true;
                $queue[] = $dep;
            }
        }

        return array_keys($result);
    }

    protected function followsDependency(string $dep): bool
    {
        return match ($this->withDependencies) {
            self::WITH_ALL_DEPENDENCIES => true,
            self::WITH_DEPENDENCIES => !in_array($dep, $this->rootRequirements, true),
            default => false,
        };
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
        $this->minimumStability = $composer['minimum-stability'] ?? 'stable';

        $requires = $this->updatingFirst($composer['require'] ?? []);
        $requiresDev = $this->updatingFirst($composer['require-dev'] ?? []);

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
     * Resolves the packages being updated before the locked ones, so that with -w/-W their
     * dependencies are unlocked before another locked package reaches (and keeps) them.
     */
    protected function updatingFirst(array $requires): array
    {
        $first = array_filter($requires, fn ($name) => in_array(strtolower($name), $this->updating, true), ARRAY_FILTER_USE_KEY);

        return $first + $requires;
    }

    /**
     * Whether packages were resolved from composer.json (no lock was used), so composer.lock must be written.
     */
    public function wasResolved(): bool
    {
        return $this->wasResolved;
    }

    /**
     * Names of the packages only needed by require-dev (packages-dev in the lock).
     *
     * @return string[]
     */
    public function getDevPackageNames(): array
    {
        return $this->devPackageNames;
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

        if (!$bestVersion) {
            throw new RuntimeException("Could not resolve a version of $package matching $constraint");
        }

        echo "    ➡️  Best version found: {$bestVersion['version']}\n";

        // Packages that were not locked yet (e.g. just required) have no lock entry to expand from:
        // unlock their dependencies as they are discovered.
        foreach (array_keys($bestVersion['require'] ?? []) as $dep) {
            if (isset($this->locked[$dep]) && !isset($this->resolved[$dep]) && $this->followsDependency(strtolower($dep))) {
                unset($this->locked[$dep]);
            }
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
        // An explicit stability flag or dev branch in the constraint opts into unstable versions.
        $allowUnstable = str_contains($constraint, '@') || str_contains($constraint, 'dev-');
        $phpIncompatible = null;

        foreach ($versions as $v) {
            if (!$allowUnstable && !$this->isStableEnough($v['version'])) {
                continue;
            }

            $version = $v['version_normalized'] ?? $v['version'];

            if (!Semver::satisfies($version, $constraint)) {
                continue;
            }

            // Skip versions that need a newer/older PHP than the one running Pomposer.
            if (isset($v['require']['php']) && !Semver::satisfies(PHP_VERSION, $v['require']['php'])) {
                $phpIncompatible ??= $v;
                continue;
            }

            echo "    ➡️  Version {$v['version']} satisfies constraint $constraint\n";
            return $v;
        }

        if ($phpIncompatible !== null) {
            echo "    ⚠️  No version matching $constraint supports PHP " . PHP_VERSION . ", using {$phpIncompatible['version']} (requires php {$phpIncompatible['require']['php']})\n";
            return $phpIncompatible;
        }

        return null;
    }

    protected function isStableEnough(string $version): bool
    {
        $ranks = ['stable' => 0, 'RC' => 5, 'beta' => 10, 'alpha' => 15, 'dev' => 20];
        $minimum = $ranks[$this->minimumStability] ?? 0;

        return $ranks[VersionParser::parseStability($version)] <= $minimum;
    }
}
