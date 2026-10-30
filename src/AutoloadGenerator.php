<?php

namespace HichemTabTech\Pomposer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class AutoloadGenerator
{
    protected string $vendorDir;
    protected string $projectRoot;
    protected string $absVendorDir;

    public function __construct(string $vendorDir = 'vendor')
    {
        $this->vendorDir = rtrim($vendorDir, '/\\');
        $this->projectRoot = $this->normalizePath(getcwd());
        $this->absVendorDir = $this->isAbsolutePath($this->vendorDir)
            ? $this->normalizePath($this->vendorDir)
            : $this->joinPaths($this->projectRoot, $this->vendorDir);
    }

    private function joinPaths(...$paths): string
    {
        return $this->normalizePath(implode('/', $paths));
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('#[/\\\\]+#', '/', $path);
        // Drop "./" segments so generated paths stay clean (e.g. "src/./Foo" -> "src/Foo")
        $path = preg_replace(['#(?<=/)\./#', '#/\.$#'], '', $path);

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    public function generate(array $packages, array $composerJson, bool $devMode = true): void
    {
        $psr4 = [];
        $classmapPathsToScan = [];
        $files = [];

        $this->extractFromComposerJson(
            $composerJson,
            $psr4,
            $classmapPathsToScan,
            $files,
            $this->projectRoot
        );

        // Like Composer, only the root package's autoload-dev is used, and only in dev mode.
        if ($devMode) {
            $this->extractFromComposerJson(
                $composerJson,
                $psr4,
                $classmapPathsToScan,
                $files,
                $this->projectRoot,
                'autoload-dev'
            );
        }

        foreach ($packages as $package) {
            $name = $package['name'];
            $version = $package['version'];

            // Resolve through the vendor/<vendor>/<package> symlink (see VendorLinker), not the store,
            // so autoload paths stay inside the project and IDEs can follow them.
            $path = $this->joinPaths($this->absVendorDir, $name);
            $composerPath = $path . '/composer.json';

            if (!file_exists($composerPath)) {
                echo "⚠️  Skipping $name ($version) - composer.json not found at $composerPath\n";
                continue;
            }

            $composerData = json_decode(file_get_contents($composerPath), true);

            $this->extractFromComposerJson(
                $composerData,
                $psr4,
                $classmapPathsToScan,
                $files,
                $path
            );
        }

        $allPathsToScanForClassmap = $classmapPathsToScan;
        foreach ($psr4 as $paths) {
            $allPathsToScanForClassmap = array_merge($allPathsToScanForClassmap, $paths);
        }
        $allPathsToScanForClassmap = array_unique($allPathsToScanForClassmap);

        $this->writeFile("$this->vendorDir/composer/autoload_classmap.php", $this->buildClassmap($allPathsToScanForClassmap));
        $this->writeFile("$this->vendorDir/composer/autoload_files.php", $this->buildFilesAutoload($files));
        $this->writeFile("$this->vendorDir/composer/autoload_psr4.php", $this->buildPsr4($psr4));
        $this->writeFile("$this->vendorDir/autoload.php", $this->buildMainAutoload());

        $this->writeFile(
            "$this->vendorDir/composer/ClassLoader.php",
            $this->getPomposerClassLoaderStub()
        );
    }

    private function extractFromComposerJson(
        array $composerData,
        array &$psr4,
        array &$classmapPaths ,
        array &$files,
        string $basePath = "",
        string $section = 'autoload'
    ): void
    {
        if (isset($composerData[$section]['psr-4'])) {
            foreach ($composerData[$section]['psr-4'] as $namespace => $relPaths) {
                foreach ((array) $relPaths as $relPath) {
                    $psr4[$namespace][] = $this->joinPaths($basePath, $relPath);
                }
            }
        }

        if (isset($composerData[$section]['classmap'])) {
            foreach ($composerData[$section]['classmap'] as $relPath) {
                $classmapPaths[] = $this->joinPaths($basePath, $relPath);
            }
        }

        if (isset($composerData[$section]['files'])) {
            foreach ($composerData[$section]['files'] as $relPath) {
                $absPath = $this->joinPaths($basePath, $relPath);
                if (file_exists($absPath)) {
                    $files[] = $absPath;
                }
            }
        }
    }

    protected function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Exports a value with absolute project/vendor paths rewritten to \$baseDir / \$vendorDir,
     * like Composer does, so the generated files stay portable and point through vendor/.
     */
    protected function exportWithPathVars(mixed $value): string
    {
        $export = var_export($value, true);

        foreach (['$vendorDir' => $this->absVendorDir, '$baseDir' => $this->projectRoot] as $var => $dir) {
            $quoted = preg_quote(substr(var_export($dir, true), 1, -1), '#');
            $export = preg_replace_callback(
                "#'$quoted((?:/[^']*)?)'#",
                fn ($m) => $m[1] === '' ? $var : $var . ' . ' . var_export($m[1], true),
                $export
            );
        }

        return $export;
    }

    protected function pathVarsHeader(): string
    {
        return <<<'PHP'
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
PHP;
    }

    protected function buildPsr4(array $map): string
    {
        $export = $this->exportWithPathVars($map);
        $header = $this->pathVarsHeader();

        return <<<PHP
<?php

$header

return $export;

PHP;
    }

    protected function buildClassmap(array $paths): string
    {
        $map = [];

        foreach ($paths as $path) {
            // Deliberately not realpath(): that would resolve the vendor symlinks back into the store.
            if (!file_exists($path)) {
                continue;
            }

            if (is_file($path)) {
                $class = $this->extractClassNameFromFile($path);
                if ($class) {
                    $map[$class] = $path;
                }
                continue;
            }

            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if ($file->getExtension() !== 'php' || $file->isDir()) {
                        continue;
                    }

                    $filePath = $this->normalizePath($file->getPathname());
                    $class = $this->extractClassNameFromFile($filePath);
                    if ($class) {
                        $map[$class] = $filePath;
                    }
                }
            }
        }

        $map['Composer\\Autoload\\ClassLoader'] = $this->joinPaths($this->absVendorDir, 'composer', 'ClassLoader.php');
        $map['Composer\\InstalledVersions'] = $this->joinPaths($this->absVendorDir, 'composer', 'InstalledVersions.php');

        ksort($map);
        $export = $this->exportWithPathVars($map);
        $header = $this->pathVarsHeader();

        return <<<PHP
<?php

$header

return $export;

PHP;
    }

    protected function buildFilesAutoload(array $files): string
    {
        $map = [];
        foreach ($files as $file) {
            // Hash the vendor-relative path so the id is stable across machines/stores.
            $map[md5(str_replace($this->projectRoot, '', $file))] = $file;
        }
        $export = $this->exportWithPathVars($map);
        $header = $this->pathVarsHeader();

        return <<<PHP
<?php

$header

\$files = $export;

\$GLOBALS['__composer_autoload_files'] = \$GLOBALS['__composer_autoload_files'] ?? [];

\$require = \\Closure::bind(static function (\$fileIdentifier, \$file) {
    if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
        \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;
        require \$file;
    }
}, null, null);

foreach (\$files as \$fileIdentifier => \$file) {
    \$require(\$fileIdentifier, \$file);
}

PHP;
    }

    protected function buildMainAutoload(): string
    {
        return <<<PHP
<?php

\$psr4 = require __DIR__ . '/composer/autoload_psr4.php';
foreach (\$psr4 as \$namespace => \$dirs) {
    foreach ((array) \$dirs as \$dir) {
        spl_autoload_register(function (\$class) use (\$namespace, \$dir) {
            if (str_starts_with(\$class, \$namespace)) {
                \$path = \$dir . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$namespace))) . '.php';
                if (file_exists(\$path)) {
                    require \$path;
                }
            }
        });
    }
}

\$classmap = require __DIR__ . '/composer/autoload_classmap.php';
spl_autoload_register(function (\$class) use (\$classmap) {
    if (isset(\$classmap[\$class])) {
        require \$classmap[\$class];
    }
});

require __DIR__ . '/composer/autoload_files.php';

PHP;
    }

    protected function extractClassNameFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);

        // Match namespace
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)) {
            $namespace = trim($nsMatch[1]) . '\\';
        }

        // Match class/interface/trait/enum
        if (preg_match('/^\s*(?:\b(?:final|abstract|readonly)\b\s+)*\b(class|interface|trait|enum)\b\s+([a-zA-Z0-9_]+)/mi', $contents, $classMatch)) {
            return $namespace . $classMatch[2];
        }

        return null;
    }

    protected function getPomposerClassLoaderStub(): string
    {
        $stubPath = __DIR__ . '/stubs/ClassLoader.php.stub';

        if (!file_exists($stubPath)) {
            throw new RuntimeException("ClassLoader stub file not found at $stubPath");
        }

        return file_get_contents($stubPath);
    }

    /**
     * Generates all necessary package manifest files for full compatibility.
     * This includes installed.json, installed.php, and InstalledVersions.php.
     *
     * @param array $packages The list of all installed packages.
     * @param array $rootComposerJson The content of the root composer.json.
     * @param bool $devMode Whether dev requirements are installed.
     * @param string[] $devPackageNames Installed packages only needed by require-dev.
     */
    public function generatePackageManifests(array $packages, array $rootComposerJson, bool $devMode = true, array $devPackageNames = []): void
    {
        $manifestJson = [
            'packages' => array_map(fn ($package) => $package + ['install-path' => '../' . $package['name']], $packages),
            'dev' => $devMode,
            'dev-package-names' => array_values($devPackageNames),
        ];
        $jsonContent = json_encode($manifestJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->writeFile($this->joinPaths($this->vendorDir, 'composer', 'installed.json'), $jsonContent);

        $rootPackageName = $rootComposerJson['name'] ?? '__root__';
        $installedPhp = [
            'root' => [
                'name' => $rootPackageName,
                'version' => $rootComposerJson['version'] ?? 'dev-main',
                'pretty_version' => $rootComposerJson['version'] ?? 'dev-main',
                'type' => 'project',
                'install_path' => $this->projectRoot . '/',
                'aliases' => [],
                'reference' => null,
                'dev' => $devMode,
            ],
            'versions' => [],
        ];

        foreach ($packages as $package) {
            $packageName = $package['name'];

            $installPath = $this->joinPaths($this->absVendorDir, $packageName);

            $installedPhp['versions'][$packageName] = [
                'pretty_version' => $package['version'],
                'version' => $package['version_normalized'] ?? $package['version'],
                'type' => $package['type'] ?? 'library',
                'install_path' => $installPath,
                'aliases' => $package['aliases'] ?? [],
                'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null,
                'dev_requirement' => in_array($packageName, $devPackageNames, true),
            ];
        }

        $installedPhp['versions'][$rootPackageName] = $installedPhp['root'];

        $phpContent = "<?php\n\n" . $this->pathVarsHeader() . "\n\nreturn " . $this->exportWithPathVars($installedPhp) . ";\n";
        $this->writeFile($this->joinPaths($this->vendorDir, 'composer', 'installed.php'), $phpContent);

        $this->writeFile(
            $this->joinPaths($this->vendorDir, 'composer', 'InstalledVersions.php'),
            $this->getInstalledVersionsStub()
        );
    }

    /**
     * Helper to load the InstalledVersions stub file.
     */
    protected function getInstalledVersionsStub(): string
    {
        $stubPath = __DIR__ . '/stubs/InstalledVersions.php.stub';
        if (!file_exists($stubPath)) {
            throw new RuntimeException("InstalledVersions stub not found at $stubPath");
        }
        return file_get_contents($stubPath);
    }
}