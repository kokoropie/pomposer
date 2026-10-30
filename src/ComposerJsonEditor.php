<?php

namespace HichemTabTech\Pomposer;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use RuntimeException;
use stdClass;

/**
 * Adds requirements to composer.json, picking a default constraint the way Composer does.
 */
class ComposerJsonEditor
{
    public function __construct(
        protected PackagistGateway $packagist,
        protected string $composerPath = 'composer.json'
    ) {}

    /**
     * Parses "vendor/pkg", "vendor/pkg:^1.0", "vendor/pkg=^1.0", "vendor/pkg ^1.0" or "vendor/pkg" "^1.0".
     *
     * @param string[] $args
     * @return array<string, string|null> Constraint per package name (null = pick one).
     */
    public function parseRequirements(array $args): array
    {
        $requirements = [];
        $last = null;

        foreach ($args as $arg) {
            if (preg_match('#^([^\s:=]+)(?:[\s:=]+(.+))?$#', trim($arg), $m) !== 1) {
                throw new RuntimeException("Invalid requirement: $arg");
            }

            $name = strtolower($m[1]);
            $constraint = $m[2] ?? null;

            // A bare constraint following a package name: "vendor/pkg" "^1.0"
            if ($last !== null && $requirements[$last] === null && !$this->isPackageName($name) && !$this->isPlatformName($name)) {
                $requirements[$last] = $arg;
                $last = null;
                continue;
            }

            if (!$this->isPackageName($name) && !$this->isPlatformName($name)) {
                throw new RuntimeException("Invalid package name: $name");
            }

            $requirements[$name] = $constraint;
            $last = $name;
        }

        return $requirements;
    }

    /**
     * Fills missing constraints with "^<major>.<minor>" of the latest stable version.
     *
     * @param array<string, string|null> $requirements
     * @return array<string, string>
     */
    public function resolveConstraints(array $requirements): array
    {
        foreach ($requirements as $name => $constraint) {
            if ($constraint !== null) {
                continue;
            }

            if ($this->isPlatformName($name)) {
                $requirements[$name] = '*';
                continue;
            }

            $requirements[$name] = $this->recommendedConstraint($name);
            echo "🔍 Using version {$requirements[$name]} for $name\n";
        }

        return $requirements;
    }

    /**
     * @param array<string, string> $requirements
     */
    public function addRequirements(array $requirements, string $key): void
    {
        $otherKey = $key === 'require' ? 'require-dev' : 'require';
        $json = $this->read();

        $json->$key ??= new stdClass();

        foreach ($requirements as $name => $constraint) {
            $json->$key->$name = $constraint;

            // A package belongs to only one of require / require-dev.
            if (isset($json->$otherKey->$name)) {
                unset($json->$otherKey->$name);
                echo "ℹ️  $name moved from $otherKey to $key\n";
            }
        }

        if ($json->config->{'sort-packages'} ?? false) {
            $json->$key = $this->sortRequirements($json->$key);
        }

        $this->write($json);
    }

    public function backup(): string
    {
        return file_get_contents($this->composerPath);
    }

    public function restore(string $contents): void
    {
        file_put_contents($this->composerPath, $contents);
    }

    protected function recommendedConstraint(string $name): string
    {
        foreach ($this->packagist->getPackageMetadata($name) as $version) {
            $pretty = $version['version'];

            if (VersionParser::parseStability($pretty) !== 'stable') {
                continue;
            }

            if (isset($version['require']['php']) && !Semver::satisfies(PHP_VERSION, $version['require']['php'])) {
                continue;
            }

            $parts = explode('.', ltrim($pretty, 'vV'));

            return '^' . $parts[0] . '.' . ($parts[1] ?? '0');
        }

        throw new RuntimeException("Could not find a stable version of $name compatible with PHP " . PHP_VERSION . ". Specify a constraint, e.g. $name:dev-main");
    }

    /**
     * Platform requirements first, then packages alphabetically (Composer's sort-packages order).
     */
    protected function sortRequirements(stdClass $requires): stdClass
    {
        $array = (array) $requires;

        uksort($array, function ($a, $b) {
            $rank = fn ($name) => $name === 'php' ? 0 : ($this->isPlatformName($name) ? 1 : 2);

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return (object) $array;
    }

    protected function isPackageName(string $name): bool
    {
        return preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name) === 1;
    }

    protected function isPlatformName(string $name): bool
    {
        return preg_match('#^(php(-64bit|-ipv6|-zts|-debug)?|hhvm|composer(-plugin|-runtime)?-api|(ext|lib)-[a-z0-9._-]+)$#', $name) === 1;
    }

    protected function read(): stdClass
    {
        if (!file_exists($this->composerPath)) {
            throw new RuntimeException("composer.json not found.");
        }

        // Decode as objects so empty objects ("extra": {}) are written back as {} and not [].
        return json_decode(file_get_contents($this->composerPath), false, 512, JSON_THROW_ON_ERROR);
    }

    protected function write(stdClass $json): void
    {
        file_put_contents(
            $this->composerPath,
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }
}
