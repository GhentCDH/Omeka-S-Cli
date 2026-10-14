<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\Bundle\AddonPackager;
use OSC\Blueprint\Bundle\BundleOutput;
use OSC\Blueprint\Bundle\Bundler;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Downloader\AddonResolver;
use OSC\Helper\Path;
use Throwable;

class BundleCommand extends AbstractBlueprintCommand
{
    public function __construct()
    {
        parent::__construct('blueprint:bundle', 'Download everything a blueprint needs into a self-contained bundle');
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->argument('<output>', 'Directory to write the bundle to (absent or empty, unless --force)');
        $this->option('--root', 'Directory local blueprint references must stay inside (default: the blueprint\'s directory)');
        $this->option('-f --force', 'Replace an existing output directory', 'boolval', false);
        $this->option(
            '--allow-incompatible',
            'Bundle add-ons whose Omeka S version constraint excludes the bundled Omeka S version',
            'boolval',
            false
        );
        $this->usage(
            'blueprint:bundle ./site.blueprint.jsonc ./bundle<eol/>'
            . 'blueprint:bundle gh:org/infra:site.blueprint.jsonc ./bundle --force'
        );
    }

    public function execute(
        string $source,
        string $output,
        ?string $root = null,
        ?bool $force = false,
        ?bool $allowIncompatible = false,
    ): void {
        $this->note("Loading blueprint from '{$source}' ...", true);
        $loader = new BlueprintLoader(null, $root);
        $blueprint = $loader->load($source);
        foreach ($loader->takeWarnings() as $warning) {
            $this->warn("  {$warning}", true);
        }
        $errors = $this->validator(false)->validateBlueprint($blueprint->toArray());
        if ($errors) {
            $this->error('Blueprint is invalid:', true);
            foreach ($errors as $error) {
                $this->error("  - {$error}", true);
            }
            throw new Exception("Refusing to bundle an invalid blueprint. Run 'blueprint:validate' for details.");
        }

        $target = Path::toAbsolutePath($output, $this->getCwd());
        // --force must never delete the blueprint being bundled, nor the directory its references live in
        $protect = array_filter(
            [Path::toAbsolutePath($source, $this->getCwd()), $root !== null ? Path::toAbsolutePath($root, $this->getCwd()) : null],
            fn(?string $path) => $path !== null && file_exists($path)
        );
        $bundleOutput = BundleOutput::prepare($target, (bool) $force, $protect);
        try {
            $bundler = new Bundler(
                new AddonResolver($this->getModuleRepositoryManager(), 'module'),
                new AddonResolver($this->getThemeRepositoryManager(), 'theme'),
                new AddonPackager(),
                fn() => $this->webApi->getLatestOmekaVersion(),
                null,
                fn(string $message) => $this->note($message, true),
            );
            foreach ($bundler->bundle($blueprint, $bundleOutput->path(), (bool) $allowIncompatible) as $warning) {
                $this->warn($warning, true);
            }
            $this->assertSelfContained($bundleOutput->path() . '/blueprint.json');
            $bundleOutput->commit();
        } catch (Throwable $e) {
            $bundleOutput->discard();
            throw $e;
        }
        $this->ok("Bundle written to {$target}.", true);
    }

    /**
     * Check the written bundle the way `blueprint:deploy --self-contained` will: a failure here is a
     * bug in the bundler, caught before the bundle is put in place.
     */
    private function assertSelfContained(string $file): void
    {
        $loader = new BlueprintLoader(null, null, true);
        $bundled = $loader->load($file);
        $problems = array_merge(
            $loader->takeViolations(),
            SelfContainedCheck::violations($bundled, true),
            $this->validator(true)->validateBlueprint($bundled->toArray()),
        );
        if ($problems) {
            throw new Exception('The bundle is not self-contained (please report this): ' . implode('; ', $problems));
        }
    }
}
