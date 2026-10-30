<?php

namespace HichemTabTech\Pomposer;

/**
 * Writes a Composer-compatible composer.lock for packages resolved by Pomposer.
 */
class LockFile
{
    public function __construct(
        protected string $lockPath = 'composer.lock'
    ) {}

    public function write(array $composerJson, array $packages, array $packagesDev): void
    {
        $lock = [
            '_readme' => [
                'This file locks the dependencies of your project to a known state',
                'Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                'This file is @generated automatically',
            ],
            'content-hash' => self::contentHash($composerJson),
            'packages' => $this->normalizePackages($packages),
            'packages-dev' => $this->normalizePackages($packagesDev),
            'aliases' => [],
            'minimum-stability' => $composerJson['minimum-stability'] ?? 'stable',
            'stability-flags' => (object) [],
            'prefer-stable' => $composerJson['prefer-stable'] ?? false,
            'prefer-lowest' => false,
            'platform' => (object) $this->platformRequirements($composerJson['require'] ?? []),
            'platform-dev' => (object) $this->platformRequirements($composerJson['require-dev'] ?? []),
            'plugin-api-version' => '2.6.0',
        ];

        file_put_contents(
            $this->lockPath,
            json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * Same algorithm as Composer's Locker::getContentHash(), so Composer accepts the lock as up to date.
     */
    public static function contentHash(array $composerJson): string
    {
        $relevantKeys = [
            'name',
            'version',
            'require',
            'require-dev',
            'conflict',
            'replace',
            'provide',
            'minimum-stability',
            'prefer-stable',
            'repositories',
            'extra',
        ];

        $relevantContent = [];
        foreach (array_intersect($relevantKeys, array_keys($composerJson)) as $key) {
            $relevantContent[$key] = $composerJson[$key];
        }

        if (isset($composerJson['config']['platform'])) {
            $relevantContent['config']['platform'] = $composerJson['config']['platform'];
        }

        ksort($relevantContent);

        return md5(json_encode($relevantContent));
    }

    protected function normalizePackages(array $packages): array
    {
        $packages = array_map(function (array $package) {
            // Composer's lock format does not store the normalized version.
            unset($package['version_normalized']);

            return $package + ['notification-url' => 'https://packagist.org/downloads/'];
        }, $packages);

        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * Platform requirements are the non-package entries: php, ext-*, lib-*, composer-*.
     */
    protected function platformRequirements(array $requires): array
    {
        return array_filter($requires, fn ($name) => !str_contains($name, '/'), ARRAY_FILTER_USE_KEY);
    }
}
