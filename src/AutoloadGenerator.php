<?php

namespace HichemTabTech\Pomposer;

use Composer\Semver\Constraint\Bound;
use Composer\Semver\VersionParser;
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

    /**
     * Generates a Composer-compatible autoloader: vendor/autoload.php, composer/autoload_real.php,
     * composer/autoload_static.php, the autoload_{psr4,namespaces,classmap,files}.php maps and
     * Composer's own ClassLoader. The classmap is always "optimized" (PSR-4/PSR-0 dirs are scanned).
     */
    public function generate(array $packages, array $composerJson, bool $devMode = true): void
    {
        $psr4 = [];
        $psr0 = [];
        $classmapPathsToScan = [];
        $files = [];

        // name => [install path, autoload section]
        $autoloads = [];
        foreach ($packages as $package) {
            $name = $package['name'];

            // Resolve through the vendor/<vendor>/<package> symlink (see VendorLinker), not the store,
            // so autoload paths stay inside the project and IDEs can follow them.
            $path = $this->joinPaths($this->absVendorDir, $name);
            $composerPath = $path . '/composer.json';

            if (!file_exists($composerPath)) {
                echo "⚠️  Skipping $name ({$package['version']}) - composer.json not found at $composerPath\n";
                continue;
            }

            $autoloads[$name] = [$path, json_decode(file_get_contents($composerPath), true)['autoload'] ?? []];
        }

        // Like Composer, the root's autoload-dev is merged into its autoload, in dev mode only.
        $rootName = $composerJson['name'] ?? '__root__';
        $rootAutoload = $devMode
            ? array_merge_recursive($composerJson['autoload'] ?? [], $composerJson['autoload-dev'] ?? [])
            : $composerJson['autoload'] ?? [];
        $root = [$rootName => [$this->projectRoot, $rootAutoload]];

        // Composer uses a different package order per type:
        // - PSR-0 / PSR-4: root first, then packages by name (decides the lookup order of shared namespaces);
        // - files: dependency order (PackageSorter), root last, so files load after what they depend on;
        // - classmap: the reverse of that, so the root wins when a class is declared twice.
        $byName = $autoloads;
        ksort($byName);
        $sorted = [];
        foreach ($this->sortPackages($packages) as $package) {
            if (isset($autoloads[$package['name']])) {
                $sorted[$package['name']] = $autoloads[$package['name']];
            }
        }

        foreach ($root + $byName as [$path, $autoload]) {
            $this->extractPsr($autoload, $path, $psr4, $psr0);
        }

        foreach ($sorted + $root as $name => [$path, $autoload]) {
            $this->extractFiles($autoload, $name, $path, $files);
        }

        foreach ($root + array_reverse($sorted, true) as [$path, $autoload]) {
            foreach ($autoload['classmap'] ?? [] as $relPath) {
                $classmapPathsToScan[] = $this->joinPaths($path, $relPath);
            }
        }

        krsort($psr4);
        krsort($psr0);

        $allPathsToScanForClassmap = $classmapPathsToScan;
        foreach (array_merge($psr4, $psr0) as $paths) {
            $allPathsToScanForClassmap = array_merge($allPathsToScanForClassmap, $paths);
        }
        $classMap = $this->buildClassMap(array_unique($allPathsToScanForClassmap));

        $suffix = $this->getSuffix($composerJson);
        $composerDir = "$this->vendorDir/composer";

        $this->writeFile("$composerDir/autoload_namespaces.php", $this->buildMapFile('autoload_namespaces.php', $psr0, true));
        $this->writeFile("$composerDir/autoload_psr4.php", $this->buildMapFile('autoload_psr4.php', $psr4, true));
        $this->writeFile("$composerDir/autoload_classmap.php", $this->buildMapFile('autoload_classmap.php', $classMap, false));

        if ($files) {
            $this->writeFile("$composerDir/autoload_files.php", $this->buildMapFile('autoload_files.php', $files, false));
        } elseif (file_exists("$composerDir/autoload_files.php")) {
            unlink("$composerDir/autoload_files.php");
        }

        $platformCheck = ($composerJson['config']['platform-check'] ?? 'php-only') === false
            ? null
            : $this->buildPlatformCheck($packages, $composerJson);

        if ($platformCheck !== null) {
            $this->writeFile("$composerDir/platform_check.php", $platformCheck);
        } elseif (file_exists("$composerDir/platform_check.php")) {
            unlink("$composerDir/platform_check.php");
        }

        $this->writeFile("$composerDir/autoload_static.php", $this->buildStaticFile($suffix, $psr4, $psr0, $classMap, $files));
        $this->writeFile("$composerDir/autoload_real.php", $this->buildRealFile($suffix, (bool) $files, $platformCheck !== null));
        $this->writeFile("$this->vendorDir/autoload.php", $this->buildMainAutoload($suffix));

        $this->writeFile("$composerDir/ClassLoader.php", $this->getStub('ClassLoader.php.stub'));
        $this->writeFile("$composerDir/LICENSE", $this->getStub('LICENSE-composer.stub'));
    }

    /**
     * @param array<string, string[]> $psr4
     * @param array<string, string[]> $psr0
     */
    private function extractPsr(array $autoload, string $basePath, array &$psr4, array &$psr0): void
    {
        foreach (['psr-4' => &$psr4, 'psr-0' => &$psr0] as $type => &$map) {
            foreach ($autoload[$type] ?? [] as $namespace => $relPaths) {
                foreach ((array) $relPaths as $relPath) {
                    $map[$namespace][] = $this->joinPaths($basePath, $relPath);
                }
                $map[$namespace] = array_values(array_unique($map[$namespace]));
            }
        }
        unset($map);
    }

    /**
     * @param array<string, string> $files File identifier => path
     */
    private function extractFiles(array $autoload, string $packageName, string $basePath, array &$files): void
    {
        foreach ($autoload['files'] ?? [] as $relPath) {
            $absPath = $this->joinPaths($basePath, $relPath);
            if (file_exists($absPath)) {
                // Same identifier as Composer, so a file already loaded by another Composer autoloader
                // (e.g. a global tool) is not required twice.
                $files[md5($packageName . ':' . $relPath)] = $absPath;
            }
        }
    }

    /**
     * Orders packages like Composer's PackageSorter: packages that more packages depend on come first.
     */
    protected function sortPackages(array $packages): array
    {
        $usageList = [];
        foreach ($packages as $package) {
            foreach (array_keys($package['require'] ?? []) as $target) {
                $usageList[strtolower($target)][] = strtolower($package['name']);
            }
        }

        $computing = [];
        $computed = [];
        $computeImportance = function (string $name) use (&$computeImportance, &$computing, &$computed, $usageList): int {
            if (isset($computed[$name])) {
                return $computed[$name];
            }

            // Circular dependency
            if (isset($computing[$name])) {
                return 0;
            }

            $computing[$name] = true;
            $weight = 0;

            foreach ($usageList[$name] ?? [] as $user) {
                $weight -= 1 - $computeImportance($user);
            }

            unset($computing[$name]);

            return $computed[$name] = $weight;
        };

        $weighted = [];
        foreach ($packages as $index => $package) {
            $weighted[] = ['weight' => $computeImportance(strtolower($package['name'])), 'name' => $package['name'], 'index' => $index];
        }

        usort($weighted, fn ($a, $b) => $a['weight'] <=> $b['weight'] ?: strnatcasecmp($a['name'], $b['name']));

        return array_map(fn ($entry) => $packages[$entry['index']], $weighted);
    }

    /**
     * Same as Composer: config.autoloader-suffix, else the suffix of the existing vendor/autoload.php
     * (so Composer and Pomposer can take turns), else the lock's content-hash, else random.
     */
    protected function getSuffix(array $composerJson): string
    {
        $suffix = $composerJson['config']['autoloader-suffix'] ?? '';
        if ($suffix !== '') {
            return $suffix;
        }

        $autoloadPath = "$this->vendorDir/autoload.php";
        if (is_file($autoloadPath) && preg_match('{ComposerAutoloaderInit([^:\s]+)::}', (string) file_get_contents($autoloadPath), $match)) {
            return $match[1];
        }

        if (is_file('composer.lock')) {
            $hash = json_decode((string) file_get_contents('composer.lock'), true)['content-hash'] ?? null;
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return bin2hex(random_bytes(16));
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

    /**
     * Path as PHP code relative to $vendorDir / $baseDir, like Composer's autoload_*.php files.
     */
    protected function getPathCode(string $path): string
    {
        foreach (['$vendorDir' => $this->absVendorDir, '$baseDir' => $this->projectRoot] as $var => $dir) {
            if ($path === $dir) {
                return $var;
            }
            if (str_starts_with($path, $dir . '/')) {
                return $var . ' . ' . var_export(substr($path, strlen($dir)), true);
            }
        }

        return var_export($path, true);
    }

    /**
     * Path as PHP code relative to vendor/composer (__DIR__), like Composer's autoload_static.php.
     */
    protected function getStaticPathCode(string $path): string
    {
        foreach (["__DIR__ . '/..'" => $this->absVendorDir, "__DIR__ . '/../..'" => $this->projectRoot] as $code => $dir) {
            if ($path === $dir) {
                return $code;
            }
            if (str_starts_with($path, $dir . '/')) {
                return $code . ' . ' . var_export(substr($path, strlen($dir)), true);
            }
        }

        return var_export($path, true);
    }

    /**
     * Builds autoload_namespaces.php / autoload_psr4.php (key => list of dirs) or
     * autoload_classmap.php / autoload_files.php (key => path), in Composer's format.
     */
    protected function buildMapFile(string $fileName, array $map, bool $listValues): string
    {
        $entries = '';
        foreach ($map as $key => $value) {
            $code = $listValues
                ? 'array(' . implode(', ', array_map([$this, 'getPathCode'], $value)) . ')'
                : $this->getPathCode($value);
            $entries .= '    ' . var_export((string) $key, true) . ' => ' . $code . ",\n";
        }

        $header = $this->pathVarsHeader();

        return <<<PHP
<?php

// $fileName @generated by Pomposer

$header

return array(
$entries);

PHP;
    }

    /**
     * Builds autoload_static.php: the same maps as static properties, assigned to the ClassLoader
     * in one go by getInitializer(), like Composer.
     */
    protected function buildStaticFile(string $suffix, array $psr4, array $psr0, array $classMap, array $files): string
    {
        $properties = [];

        if ($files) {
            $properties['files'] = $files;
        }

        $fallbackPsr4 = $psr4[''] ?? null;
        unset($psr4['']);
        if ($psr4) {
            $prefixLengths = [];
            foreach (array_keys($psr4) as $prefix) {
                $prefixLengths[$prefix[0]][$prefix] = strlen($prefix);
            }
            $properties['prefixLengthsPsr4'] = $prefixLengths;
            $properties['prefixDirsPsr4'] = $psr4;
        }
        if ($fallbackPsr4) {
            $properties['fallbackDirsPsr4'] = $fallbackPsr4;
        }

        $fallbackPsr0 = $psr0[''] ?? null;
        unset($psr0['']);
        if ($psr0) {
            $prefixes = [];
            foreach ($psr0 as $prefix => $paths) {
                $prefixes[$prefix[0]][$prefix] = $paths;
            }
            $properties['prefixesPsr0'] = $prefixes;
        }
        if ($fallbackPsr0) {
            $properties['fallbackDirsPsr0'] = $fallbackPsr0;
        }

        $properties['classMap'] = $classMap;

        $class = "ComposerStaticInit$suffix";
        $body = '';
        $assignments = '';

        foreach ($properties as $name => $value) {
            // Only "prefixLengthsPsr4" holds integers; every other leaf is a path.
            $body .= "    public static \$$name = " . $this->exportStatic($value, 1) . ";\n\n";

            if ($name !== 'files') {
                $assignments .= "            \$loader->$name = $class::\$$name;\n";
            }
        }

        return <<<PHP
<?php

// autoload_static.php @generated by Pomposer

namespace Composer\Autoload;

class $class
{
$body    public static function getInitializer(ClassLoader \$loader)
    {
        return \\Closure::bind(function () use (\$loader) {
$assignments
        }, null, ClassLoader::class);
    }
}

PHP;
    }

    /**
     * var_export()-like output where string leaves are written as __DIR__-relative path code.
     */
    private function exportStatic(array $value, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $code = "array (\n";

        foreach ($value as $key => $item) {
            $code .= $indent . '    ' . var_export($key, true) . ' =>';
            $code .= match (true) {
                is_array($item) => "\n" . $indent . '    ' . $this->exportStatic($item, $level + 1),
                is_string($item) => ' ' . $this->getStaticPathCode($item),
                default => ' ' . var_export($item, true),
            };
            $code .= ",\n";
        }

        return $code . $indent . ')';
    }

    protected function buildRealFile(string $suffix, bool $hasFiles, bool $hasPlatformCheck): string
    {
        $platformCheckCode = $hasPlatformCheck ? "\n        require __DIR__ . '/platform_check.php';\n" : '';

        $class = "ComposerAutoloaderInit$suffix";
        $static = "\\Composer\\Autoload\\ComposerStaticInit$suffix";

        $filesCode = !$hasFiles ? '' : <<<PHP

        \$filesToLoad = $static::\$files;
        \$requireFile = \\Closure::bind(static function (\$fileIdentifier, \$file) {
            if (empty(\$GLOBALS['__composer_autoload_files'][\$fileIdentifier])) {
                \$GLOBALS['__composer_autoload_files'][\$fileIdentifier] = true;

                require \$file;
            }
        }, null, null);
        foreach (\$filesToLoad as \$fileIdentifier => \$file) {
            \$requireFile(\$fileIdentifier, \$file);
        }

PHP;

        return <<<PHP
<?php

// autoload_real.php @generated by Pomposer

class $class
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \\Composer\\Autoload\\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }
$platformCheckCode
        spl_autoload_register(array('$class', 'loadClassLoader'), true, true);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader(\\dirname(__DIR__));
        spl_autoload_unregister(array('$class', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func($static::getInitializer(\$loader));

        \$loader->register(true);
$filesCode
        return \$loader;
    }
}

PHP;
    }

    /**
     * Composer's default "php-only" platform check: fail early, with a clear message, when the running PHP
     * is older than the highest minimum PHP version required by the root package or any installed package.
     * Returns null when nothing requires PHP.
     */
    protected function buildPlatformCheck(array $packages, array $composerJson): ?string
    {
        $parser = new VersionParser();
        $lowest = Bound::zero();

        $requires = array_map(fn ($package) => $package['require'] ?? [], $packages);
        $requires[] = $composerJson['require'] ?? [];

        foreach ($requires as $require) {
            foreach ($require as $name => $constraint) {
                if (strtolower($name) !== 'php') {
                    continue;
                }

                $bound = $parser->parseConstraints($constraint)->getLowerBound();
                if ($bound->compareTo($lowest, '>')) {
                    $lowest = $bound;
                }
            }
        }

        if ($lowest->isZero()) {
            return null;
        }

        $operator = $lowest->isInclusive() ? '>=' : '>';
        [$major, $minor, $patch] = array_map('intval', array_slice(explode('.', $lowest->getVersion()), 0, 3)) + [0, 0, 0];
        $versionId = sprintf('%d%02d%02d', $major, $minor, $patch);
        $version = "$major.$minor.$patch";

        return <<<PHP
<?php

// platform_check.php @generated by Pomposer

\$issues = array();

if (!(PHP_VERSION_ID $operator $versionId)) {
    \$issues[] = 'Your Composer dependencies require a PHP version "$operator $version". You are running ' . PHP_VERSION . '.';
}

if (\$issues) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    if (!ini_get('display_errors')) {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, 'Composer detected issues in your platform:' . PHP_EOL.PHP_EOL . implode(PHP_EOL, \$issues) . PHP_EOL.PHP_EOL);
        } elseif (!headers_sent()) {
            echo 'Composer detected issues in your platform:' . PHP_EOL.PHP_EOL . str_replace('You are running '.PHP_VERSION.'.', '', implode(PHP_EOL, \$issues)) . PHP_EOL.PHP_EOL;
        }
    }
    throw new \RuntimeException(
        'Composer detected issues in your platform: ' . implode(' ', \$issues)
    );
}

PHP;
    }

    protected function buildMainAutoload(string $suffix): string
    {
        return <<<PHP
<?php

// autoload.php @generated by Pomposer

if (PHP_VERSION_ID < 50600) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    \$err = 'Composer 2.3.0 dropped support for autoloading on PHP <5.6 and you are running '.PHP_VERSION.', please upgrade PHP or use Composer 2.2 LTS via "composer self-update --2.2". Aborting.'.PHP_EOL;
    if (!ini_get('display_errors')) {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, \$err);
        } elseif (!headers_sent()) {
            echo \$err;
        }
    }
    throw new RuntimeException(\$err);
}

require_once __DIR__ . '/composer/autoload_real.php';

return ComposerAutoloaderInit$suffix::getLoader();

PHP;
    }

    /**
     * @param string[] $paths Files or directories to scan
     * @return array<string, string> Class => path
     */
    protected function buildClassMap(array $paths): array
    {
        $map = [];

        foreach ($paths as $path) {
            // Deliberately not realpath(): that would resolve the vendor symlinks back into the store.
            if (!file_exists($path)) {
                continue;
            }

            if (is_file($path)) {
                foreach ($this->extractClassNamesFromFile($path) as $class) {
                    $map[$class] ??= $path;
                }
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || $file->isDir()) {
                    continue;
                }

                $filePath = $this->normalizePath($file->getPathname());
                foreach ($this->extractClassNamesFromFile($filePath) as $class) {
                    $map[$class] ??= $filePath;
                }
            }
        }

        $map['Composer\\InstalledVersions'] = $this->joinPaths($this->absVendorDir, 'composer', 'InstalledVersions.php');

        ksort($map);

        return $map;
    }

    /**
     * Returns every class, interface, trait and enum declared in a file, using PHP's tokenizer so that
     * one-line files, several namespaces/classes per file, comments, strings, Foo::class and
     * anonymous classes are handled correctly.
     *
     * @return string[]
     */
    protected function extractClassNamesFromFile(string $file): array
    {
        $contents = @file_get_contents($file);

        // Cheap pre-check so files without any declaration are not tokenized.
        if ($contents === false || !preg_match('{\b(?:class|interface|trait|enum)\s}i', $contents)) {
            return [];
        }

        $tokens = token_get_all($contents);
        $count = count($tokens);
        $classes = [];
        $namespace = '';
        $previous = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $previous = $token;
                continue;
            }

            $id = $token[0];

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if ($id === T_HALT_COMPILER) {
                break;
            }

            if ($id === T_NAMESPACE) {
                // "namespace Foo\Bar;" or "namespace Foo\Bar {"; "namespace {" is the global namespace.
                $next = $this->nextSignificantToken($tokens, $i);
                $namespace = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)
                    ? $next[1] . '\\'
                    : '';
            } elseif (
                in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && $previous !== T_DOUBLE_COLON // Foo::class
                && $previous !== T_NEW          // new class { ... }
            ) {
                $next = $this->nextSignificantToken($tokens, $i);

                if (is_array($next) && $next[0] === T_STRING) {
                    $classes[] = $namespace . $next[1];
                }
            }

            $previous = $id;
        }

        return $classes;
    }

    /**
     * @return array|string|null The next token that is not whitespace or a comment.
     */
    private function nextSignificantToken(array $tokens, int $index): array|string|null
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token;
            }
        }

        return null;
    }

    protected function getStub(string $name): string
    {
        $stubPath = __DIR__ . '/stubs/' . $name;

        if (!file_exists($stubPath)) {
            throw new RuntimeException("Stub file not found at $stubPath");
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