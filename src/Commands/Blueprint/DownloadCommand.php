<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\BlueprintApplier;
use OSC\Blueprint\CoreInstaller;
use OSC\Helper\Path;

/**
 * Put a blueprint's core, modules, themes and files on disk without installing anything: no database,
 * no Omeka bootstrap. Builds e.g. a Docker image that ships the code, installed when a container starts.
 */
class DownloadCommand extends AbstractBlueprintCommand
{
    /** All phases, in download order. */
    private const PHASES = ['core', 'modules', 'themes', 'files'];

    /** A download shows outcomes by default; -v adds the sub-commands' detail, -vv debug output. */
    protected const DEFAULT_VERBOSITY = self::VERBOSITY_OUTCOMES;

    public function __construct()
    {
        parent::__construct('blueprint:download', "Download a blueprint's core, modules, themes and files without installing them");
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->option('-u --update', 'Re-download modules and themes that are already present', 'boolval', false);
        $this->option('--skip', 'Comma-separated phases to skip (core, modules, themes, files)');
        $this->option('--root', 'Directory local blueprint references must stay inside (default: the blueprint\'s directory)');
        $this->option(
            '--self-contained',
            'Refuse a blueprint that has a source outside it (a catalog, git or remote reference): download a bundle offline',
            'boolval',
            false
        );
        $this->optionDryRun();
        $this->usage(
            'blueprint:download ./site.blueprint.jsonc --base-path /var/www/omeka-s<eol/>'
            . 'blueprint:download ./site.blueprint.jsonc --base-path /var/www/omeka-s --skip core   (add-ons into an existing core)<eol/>'
            . 'blueprint:download ./bundle/blueprint.json --self-contained --base-path /var/www/omeka-s'
        );
    }

    public function execute(
        string $source,
        ?bool $update = false,
        ?string $skip = null,
        ?string $root = null,
        ?bool $selfContained = false,
    ): void {
        $skipPhases = $this->parsePhases($skip);
        $unknown = array_diff($skipPhases, self::PHASES);
        if ($unknown) {
            throw new Exception(
                "Unknown phase(s) to skip: '" . implode("', '", $unknown) . "'. Use: " . implode(', ', self::PHASES) . '.'
            );
        }

        // the core phase downloads into --base-path, which need not exist yet
        $targetPath = null;
        if (!in_array('core', $skipPhases, true)) {
            $basePath = $this->values()['basePath'] ?? null;
            if (!$basePath) {
                throw new Exception(
                    'The core phase needs --base-path (where Omeka S is or will be downloaded). '
                    . "Use '--skip core' to download into the current instance."
                );
            }
            $targetPath = rtrim(Path::toAbsolutePath($basePath, $this->getCwd()), DIRECTORY_SEPARATOR);
        }
        $coreWillDownload = $targetPath !== null && !$this->isOmekaDir($targetPath);

        $blueprint = $this->loadValidBlueprint('download', $source, $root, (bool) $selfContained, $coreWillDownload);

        $dryRun = $this->isDryRun();
        if ($dryRun) {
            $this->warn('Dry run: no changes will be made.', true);
        }

        // ── core phase ──────────────────────────────────────────────────────────────────────
        if ($targetPath === null) {
            $this->section('Core', null, 'skipped (--skip)');
        } else {
            $this->section('Core');
            if ($dryRun && $coreWillDownload) {
                $version = $blueprint->preferredOmekaVersion() ?? 'latest';
                $from = $blueprint->installSource() !== null ? ' from ' . basename($blueprint->installSource()) : '';
                $this->note("would download the Omeka S core ({$version}){$from} to {$targetPath}", true);
            } else {
                (new CoreInstaller($this))->download($blueprint, $targetPath);
            }
        }

        // ── modules, themes and files ───────────────────────────────────────────────────────
        (new BlueprintApplier($this, $dryRun, (bool) $update, $skipPhases))->applyDownloads($blueprint);

        $this->ok($dryRun ? 'Dry run complete.' : 'Blueprint downloaded.', true);
    }
}
