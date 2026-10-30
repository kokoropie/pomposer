# Pomposer - Shared Package Manager for PHP (pnpm-style for PHP)

A proof-of-concept package manager for PHP that installs dependencies once and shares them globally across projects to save space and boost speed. Inspired by pnpm, Pomposer avoids duplication by linking packages instead of reinstalling them for every project.
![Pomposer logo](meta/pomposer.png "Pomposer logo")

> [!IMPORTANT]
> **This is a fork** of [HichemTab-tech/pomposer](https://github.com/HichemTab-tech/pomposer), maintained at [kokoropie/pomposer](https://github.com/kokoropie/pomposer).
> It adds a symlinked, IDE-friendly `vendor/` (pnpm-style), `composer.lock` generation, the `update` / `require` / `require-dev` / `remove` / `dump-autoload` commands, `--no-dev`, `-w` / `-W`, `vendor/bin`, and several resolver/autoloader fixes.
> It is not published on Packagist under its own name — install it from a local clone as shown below.

### Install this fork globally from a local clone (symlinked)

Composer's [`path` repository](https://getcomposer.org/doc/05-repositories.md#path) with `"symlink": true` links the global install to your clone, so every change in the clone (edits, `git pull`, switching branches) takes effect immediately without reinstalling.

```bash
# 1. Clone the fork and install its own dependencies (bin/pomposer loads the clone's vendor/autoload.php)
git clone https://github.com/kokoropie/pomposer.git ~/src/pomposer
cd ~/src/pomposer && composer install

# 2. If the upstream package is already installed globally, remove it first
composer global remove hichemtab-tech/pomposer

# 3. Point Composer's global project at the clone (use the absolute path of your clone)
composer global config repositories.pomposer '{"type": "path", "url": "'"$HOME"'/src/pomposer", "options": {"symlink": true}}'

# 4. Require it from that path; @dev accepts the clone's current branch (e.g. dev-master)
composer global require "hichemtab-tech/pomposer:@dev"
```

Make sure Composer's global bin directory is on your `PATH` (`composer global config bin-dir --absolute` prints it, usually `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`), then check:

```bash
pomposer --version
ls -l "$(composer global config home)/vendor/hichemtab-tech"   # pomposer -> ~/src/pomposer
```

To go back to the Packagist release:

```bash
composer global remove hichemtab-tech/pomposer
composer global config --unset repositories.pomposer
composer global require hichemtab-tech/pomposer
```

> [!TIP]
> Without a global install you can also run the clone directly: `php ~/src/pomposer/bin/pomposer install`, or add `alias pomposer="php ~/src/pomposer/bin/pomposer"` to your shell profile.

## Why Pomposer?

Composer is the backbone of modern PHP development, but it's not optimized for shared storage.
Every `composer install` duplicates packages per project, eating up disk space and time.

**Pomposer** brings the best of `pnpm` to PHP:

- 📦 **Global Package Store**: Each package version is downloaded and stored *only once*.
- ⚡ **Faster Installs**: Once a package is in the global store, installs are nearly instant.
- 🔗 **Symlinked `vendor/`**: Packages are symlinked into `vendor/`, pnpm-style, so IDEs and tools see a normal vendor directory.
- ⚙️ **Advanced Autoloader**: Generates a complete, optimized autoloader (PSR-4, classmap, files).
- 🤝 **Framework Compatibility**: Creates the necessary manifests (`installed.json`, etc.) for features like Laravel's package auto-discovery.
- 📜 **Script Execution**: Correctly runs `post-install-cmd` and `post-autoload-dump` scripts.

---

## How It Works

1.  **Read `composer.lock`** (or falls back to `composer.json` with its own resolver, then **writes a Composer-compatible `composer.lock`** so later installs are reproducible).
2.  **Download and cache packages** to the global store at `~/.pomposer-store`.
3.  **Read the full `composer.json` from within each package** to gather all metadata (autoloading rules, scripts, and framework providers).
4.  **Symlink each package into `vendor/`** (`vendor/<vendor>/<package>` → `~/.pomposer-store/<vendor>/<package>/<version>`), pnpm-style, so IDEs (PhpStorm, Intelephense, …) index `vendor/` as usual.
5.  **Generate a complete vendor directory**, including:
    - An optimized classmap and PSR-4 autoloader resolved through the `vendor/` links (portable `$vendorDir`/`$baseDir` paths, like Composer).
    - The full package manifest (`installed.json`, `installed.php`, etc.) for framework compatibility.
    - Compatibility stubs for Composer's internal classes.
    - A stub `Composer\Script\Event` passed to static script handlers (e.g. `Illuminate\Foundation\ComposerScripts::postAutoloadDump`).
6.  **Execute post-install scripts** to finalize the installation (e.g., `php artisan package:discover`).

---

## Installation

You can install Pomposer globally or locally via Composer:

```bash
composer global require hichemtab-tech/pomposer
```

Make sure Composer global bin is in your `$PATH`. Then run:

```bash
pomposer install
```

---

## Commands

| Command | Description |
|---|---|
| `pomposer install [--no-dev]` | Install from `composer.lock`, or resolve `composer.json` and write the lock if there is none. |
| `pomposer update [packages...] [--no-dev]` | Re-resolve from `composer.json` and rewrite `composer.lock`. With package names, only those are updated; other locked packages keep their version while it still satisfies the constraints. Aliases: `u`, `upgrade`. |
| `pomposer require <packages...>` | Add packages to `composer.json` and install them. Accepts `vendor/pkg`, `vendor/pkg:^1.0`, `vendor/pkg=^1.0` or `vendor/pkg ^1.0`; without a constraint, `^<major>.<minor>` of the latest stable version is used. `--no-update` only edits `composer.json`. On failure, `composer.json` and `composer.lock` are restored. Alias: `r`. |
| `-w`, `--with-dependencies` (update / require / remove) | Also update the dependencies of the listed packages, recursively, except those that are root requirements. `require` / `remove` also accept `--update-with-dependencies`. |
| `-W`, `--with-all-dependencies` (update / require / remove) | Same as `-w`, but root requirements are updated too. `require` / `remove` also accept `--update-with-all-dependencies`. |
| `--no-dev` (install / update) | Skip `packages-dev` and the root `autoload-dev`; dev links are removed from `vendor/`, `installed.json` gets `"dev": false` and script handlers see `$event->isDevMode() === false`. `composer.lock` still lists the dev packages. |
| `pomposer require --dev <packages...>` / `pomposer require-dev <packages...>` | Same as `require`, but adds the packages to `require-dev` (they end up in `packages-dev` in the lock). Alias: `rd`. |
| `pomposer remove <packages...>` | Remove packages from `require` / `require-dev` and uninstall them, along with dependencies nothing else needs; everything else stays locked. Supports `--no-update` and `--no-dev`. On failure, `composer.json` and `composer.lock` are restored. Aliases: `rm`, `uninstall`. |
| `pomposer dump-autoload [--no-dev]` | Regenerate the autoloader and package manifests for the packages linked in `vendor/` (from `vendor/composer/installed.json`) and run `post-autoload-dump`. `-o` is accepted for Composer compatibility (the classmap is always generated). Alias: `dumpautoload`. |

---

## Example 1: Build a small Project with Pomposer

Let’s test Pomposer using a simple PHP app that:

* Uses `monolog/monolog` for logging
* Uses your custom package `hichemtab-tech/namecrement`
* Has its own PSR-4 autoloading

---

### 1. Create your test project

```bash
mkdir test-pomposer && cd test-pomposer
```

### 2. Create a `composer.json`:

```json
{
  "name": "hichemtab-tech/test-pomposer",
  "autoload": {
    "psr-4": {
      "HichemTabTech\\TestPomposer\\": "src/"
    }
  },
  "authors": [
    {
      "name": "HichemTab-tech",
      "email": "konanhichemsinshi@gmail.com"
    }
  ],
  "require": {
    "hichemtab-tech/namecrement": "^1.1",
    "monolog/monolog": "^3.9"
  }
}
```

### 3. Create your source and test files

```bash
mkdir src
touch src/index.php
```

Then edit `src/index.php`:

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use HichemTabTech\Namecrement\Namecrement;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$existing = ['file', 'file (1)', 'file (2)'];
$newName = Namecrement::namecrement('file', $existing);

echo "Next unique file name after the list of existing files:\n";
echo "Existing files: " . implode(', ', $existing) . "\n";
echo "New file name: ";
echo $newName . "\n\n";

$log = new Logger('test');
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
$log->info('Pomposer is alive!');
```

### 4. Run Pomposer

```bash
pomposer install
```

It will:

* Resolve dependencies (even if no `composer.lock`) and write a `composer.lock`
* Download and cache each package version in `~/.pomposer-store`
* Symlink each package into your `vendor/` folder (e.g. `vendor/monolog/monolog` → `~/.pomposer-store/monolog/monolog/3.9.0`)
* Generate a working autoloader

### 5. Run the test script

```bash
php src/index.php
```

✅ You should see output like:

```
Next unique file name after the list of existing files:
Existing files: file, file (1), file (2)
New file name: file (3)

[2025-07-06 20:31:22] test.INFO: Pomposer is alive! []
```

✅ You just installed `monolog/monolog` without Composer touching your `vendor/` at all.

## Vendor Layout

Instead of copying files, Pomposer fills `vendor/` with symlinks into the global store, the same way pnpm links `node_modules`:

```
vendor/
├── autoload.php
├── bin/                      # package binaries
│   ├── phpunit               # PHP proxy (sets $_composer_autoload_path, includes the real bin)
│   └── sail -> ../laravel/sail/bin/sail
├── composer/                 # generated autoloader + manifests (real files)
│   ├── autoload_classmap.php
│   ├── autoload_files.php
│   ├── autoload_psr4.php
│   ├── ClassLoader.php
│   ├── InstalledVersions.php
│   ├── installed.json
│   └── installed.php
├── hichemtab-tech/
│   └── namecrement -> ~/.pomposer-store/hichemtab-tech/namecrement/1.1.0
├── monolog/
│   └── monolog -> ~/.pomposer-store/monolog/monolog/3.9.0
├── nikic/
│   └── php-parser/           # package with a bin: real directory
│       ├── .pomposer-link    # records the store path it mirrors
│       ├── bin/
│       │   └── php-parse     # hard link to the store file
│       ├── composer.json -> ~/.pomposer-store/nikic/php-parser/v5.9.0/composer.json
│       └── lib -> ~/.pomposer-store/nikic/php-parser/v5.9.0/lib
└── psr/
    └── log -> ~/.pomposer-store/psr/log/3.0.2
```

- **IDE support**: PhpStorm, Intelephense and other tools index `vendor/` as usual, so autocompletion and go-to-definition work.
- **Portable autoloader**: the generated autoload files use `$vendorDir` / `$baseDir` paths (like Composer) and resolve classes through the `vendor/` links, not hard-coded store paths.
- **Kept in sync**: re-running `pomposer install` repoints links when a version changes, replaces real directories left behind by Composer, and removes links for packages that are no longer required.
- **Binaries (`vendor/bin`)**: like Composer, PHP binaries get a proxy that sets `$GLOBALS['_composer_autoload_path']` and includes the real file, other binaries are symlinked (plus `.bat` proxies on Windows). Since PHP resolves symlinks, a package that ships binaries is *materialized*: its directory and the path to each bin are real, the bin files are hard links into the store (copies across filesystems) and everything else stays symlinked — so bins that look for `__DIR__ . '/../../../autoload.php'` find `vendor/autoload.php`. `vendor/bin` is also on the `PATH` while scripts run. Files in `vendor/bin` not created by Pomposer are left alone.
- **Windows**: if creating a symlink is not permitted, Pomposer falls back to a directory junction (`mklink /J`).

> [!NOTE]
> At runtime PHP resolves symlinks, so `__FILE__` / `__DIR__` in a package's *library* code point to the store path. Library code that locates `vendor/autoload.php` by walking up from its own directory won't find it (binaries are not affected, see above).

> [!TIP]
> Add `vendor/` to `.gitignore` as usual — the links point to your local store and should not be committed.

## Example: Building a Real Laravel App with Pomposer

We have adapted Pomposer to install and run a modern Laravel application.
This process served as a practical test of its ability to handle complex dependencies, package auto-discovery, and post-install scripting.

### 1. Create a Laravel Project

First, create a standard Laravel project with a starter kit like Breeze.

#### using Laravel Installer

```bash
# Example using Laravel Breeze with React & SSR
laravel new pomposer-test-app --breeze --stack react --ssr
cd pomposer-test-app
```
#### or using [LaravelFS Installer](https://github.com/HichemTab-tech/LaravelFS)

```bash
laravelfs new pomposer-test-app --breeze --stack react --ssr
cd pomposer-test-app
```


### 2. Remove Existing Vendor Directory

We want to install from scratch using only Pomposer.

```bash
rm -rf vendor composer.lock
```

### 3. Run Pomposer

Now, tell Pomposer to handle the installation.

```bash
pomposer install
```

Pomposer will resolve dependencies, use its global cache, generate the autoloader and package manifests, and correctly run php artisan package:discover as part of its script execution.

### 4. Verify It Works

Once the installation is complete, you can run standard Artisan commands. The application is ready to go.

```bash
php artisan about
```

You will see a complete list of environment details and discovered packages (like Inertia), proving that Laravel has booted successfully using the Pomposer-generated vendor directory.

## Global Store Layout

Packages are stored by name + version:

```
└── ~/.pomposer-store/
    ├── hichemtab-tech
    │   └── namecrement
    │       └── 1.1.0
    │           ├── composer.json
    │           └── src
    ├── monolog
    │   └── monolog
    │       └── 3.9.0
    │           ├── composer.json
    │           └── src
    └── psr
        └── log
            └── 3.0.2
                ├── composer.json
                └── src
```

---

## ⚠️ Limitations (Beta Notice)

> [!WARNING]  
> Pomposer is still in **beta** — built as a proof of concept.

Current limitations:

**Commands & workflow**

* ⚠️ **`composer.json` is re-encoded:** `require` / `remove` rewrite `composer.json` with 4-space indentation, so custom formatting is not preserved.
* ⚠️ **Lenient partial updates:** Without `-w`/`-W`, a locked dependency whose version no longer satisfies the new constraints is re-resolved anyway instead of failing like Composer.

**Dependency resolution**

* ⚠️ **Basic Dependency Resolver:** The resolver is simple and may fail on complex version constraints. It works best when a `composer.lock` file is present.
* ❌ **No Support for `provide`/`replace`/`conflict`:** These advanced dependency management rules are not implemented.
* ❌ **Packagist dist archives only:** Custom `repositories` (VCS, path, private Packagist/Satis), source installs (`--prefer-source`) and authentication (`auth.json`) are not supported.

**Vendor & autoloading**

* ⚠️ **Symlinks resolve to the store at runtime:** `__FILE__` / `__DIR__` in a package's library code point to `~/.pomposer-store/...`, not `vendor/`. Library code that finds `vendor/autoload.php` by walking up from its own directory will fail (binaries are handled).
* ⚠️ **Shared store:** All projects share the same package files — patching a file in `vendor/` changes it for every project using that version.
* ⚠️ **Symlinks required:** On Windows, symlinks need Developer Mode or admin rights; otherwise Pomposer falls back to directory junctions.
* ⚠️ **Limited Autoloading:** No support for the deprecated `psr-0` standard or `exclude-from-classmap`.

**Ecosystem**

* ❌ **No Composer Plugin System:** Cannot run Composer plugins.
* ⚠️ **Partial script support:** Only `post-autoload-dump` and `post-install-cmd` run; `@composer` and other `@alias` scripts are skipped.

---

## 💡 Want to Contribute?

Got ideas or experience with Composer internals? Want to help evolve Pomposer into something production-ready?

👉 **Join the discussion and contribute on GitHub**:
[https://github.com/HichemTab-tech/pomposer/discussions/4](https://github.com/HichemTab-tech/pomposer/discussions/4)

---

## License

MIT © @HichemTab-tech
