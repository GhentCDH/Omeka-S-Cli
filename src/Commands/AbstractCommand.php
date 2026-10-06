<?php

namespace OSC\Commands;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Command;
use Exception;
use OSC\Exceptions\IgnoredNotFoundException;
use OSC\Helper\Path;
use OSC\Helper\OmekaVersion;
use OSC\Manager\Module\Manager as ModuleRepositoryManager;
use OSC\Manager\Theme\Manager as ThemeRepositoryManager;
use OSC\Omeka\OmekaDotOrgApi;
use OSC\Omeka\OmekaInstance;
use OSC\Omeka\OmekaInstanceFactory;
use Throwable;

abstract class AbstractCommand extends Command
{
    /**
     * Verbosity tiers: what each message helper needs to be shown.
     *
     * - quiet:    nothing but errors
     * - outcomes: errors, warnings, outcomes (ok) and structural notes/sections
     * - detail:   also the info detail (why/where: selected versions, download urls, ...)
     * - debug:    also debug output
     */
    public const VERBOSITY_QUIET = 0;
    public const VERBOSITY_OUTCOMES = 1;
    public const VERBOSITY_DETAIL = 2;
    public const VERBOSITY_DEBUG = 3;

    /** The verbosity a command runs at without --quiet / -v / --verbosity. */
    protected const DEFAULT_VERBOSITY = self::VERBOSITY_DETAIL;

    protected ModuleRepositoryManager $moduleRepositoryManager;
    protected ThemeRepositoryManager $themeRepositoryManager;
    protected OmekaDotOrgApi $webApi;

    protected array $moduleDependencies = [];

    public function __construct(string $_name, string $_desc = '', bool $_allowUnknown = false, ?App $_app = null)
    {
        parent::__construct($_name, $_desc, $_allowUnknown, $_app);

        $this->moduleRepositoryManager = ModuleRepositoryManager::getInstance();
        $this->themeRepositoryManager = ThemeRepositoryManager::getInstance();
        $this->webApi = OmekaDotOrgApi::getInstance();
    }

    public function init(): void {
        // check module dependencies
        if ($this->moduleDependencies) {
            $moduleApi = $this->getOmekaInstance(false)->getModuleApi();

            foreach ($this->moduleDependencies as $dependency) {
                if (!$moduleApi->isActive($dependency)) {
                    throw new \Exception("This action requires the '$dependency' module. Install/enable it before using this command.");
                }
            }
        }
    }

    public function defaults(): Command
    {
        parent::defaults();

        // verbosity: replace the library's '-v, --verbosity' flag by an incrementing '-v --verbose'
        // and an exact '--verbosity <level>' (used to pass the level on to child processes)
        $this->unset('verbosity');
        $this->option('-v --verbose', 'Increase verbosity (repeatable, e.g. -vv)')->on(
            fn () => $this->set('verbosity', ($this->values()['verbosity'] ?? static::DEFAULT_VERBOSITY) + 1) && false
        );
        // the no-op handler replaces the library's incrementing 'verbosity' handler (unset() keeps
        // events), so the given level is stored as is
        $this->option('--verbosity', 'Verbosity level (0 quiet, 1 outcomes, 2 detail, 3 debug)', 'intval')
            ->on(fn () => null);
        $this->set('verbosity', static::DEFAULT_VERBOSITY);

        // add omeka base-path option
        $this->option('-b --base-path', 'Base path to Omeka S installation', 'strval');
        $this->option('-q --quiet', 'Suppress info and warning messages', 'strval')->on([$this, 'beQuiet']);

        return $this;
    }

    public function optionEnv(): static {
        $this->option('-e --env', 'Output ENV variable', 'boolval', false)->on([$this, 'beQuiet']);
        return $this;
    }

    public function optionTable(): static {
        $this->option('-t --table', 'Output text table', 'boolval', false)->on([$this, 'beQuiet']);
        return $this;
    }

    public function optionJson(): static {
        $this->option('-j --json', 'Output json', 'boolval', false)->on([$this, 'beQuiet']);
        return $this;
    }

    public function optionCSV(): static {
        $this->option('-c --csv', 'Output csv', 'boolval', false)->on([$this, 'beQuiet']);
        return $this;
    }

    public function optionExtended(): static {
        $this->option('-x --extended', 'Extended output', 'boolval', false);
        return $this;
    }

    /**
     * Report a resource the command could not find, when it was told to tolerate that.
     *
     * Rethrows the original error unless --ignore-not-found was given, so the caller only has to
     * decide what to do next, not how to word it.
     *
     * @param Throwable $notFound       The error the lookup produced
     * @param bool      $ignoreNotFound Whether the absence may be ignored
     *
     * @return void
     *
     * @throws Throwable The original error, when the absence must not be ignored
     */
    protected function skipMissing(Throwable $notFound, bool $ignoreNotFound): never
    {
        if (!$ignoreNotFound) {
            throw $notFound;
        }

        // verbosity-aware, so --quiet/--json silence the note
        $this->warn(rtrim($notFound->getMessage(), '.') . '. Nothing to do.', true);

        // stop the command cleanly; Application::onError() maps this to exit 0 without output
        throw new IgnoredNotFoundException();
    }

    public function optionIgnoreNotFound(string $resource = 'resource'): static {
        $this->option(
            '--ignore-not-found',
            "Do nothing if the {$resource} does not exist (default: throw an error)",
            'boolval',
            false
        );
        return $this;
    }

    public function optionDryRun(): static {
        $this->option('--dry-run', 'Report what would be done, without doing it', 'boolval', false);
        return $this;
    }

    /**
     * Whether the command was asked to report its work instead of carrying it out.
     *
     * @return bool
     */
    public function isDryRun(): bool
    {
        return (bool) ($this->values()['dryRun'] ?? false);
    }

    /**
     * Report the work a dry run would have carried out.
     *
     * @param string $message What the command would have done
     *
     * @return void
     */
    public function reportDryRun(string $message): void
    {
        $this->warn('Dry run, no changes are made.', true);
        $this->note($message, true);
    }

    public function getOutputFormat($defaultFormat = null) {
        $values = $this->values();
        $supportedFormats = ['json', 'table', 'env', 'csv'];

        $format = $defaultFormat;
        foreach($supportedFormats as $supportedFormat) {
            if ($values[$supportedFormat] ?? null) {
                $format = $supportedFormat;
                break;
            }
        }

        return $format;
    }

    /**
     * Whether the command runs at (at least) the given verbosity tier.
     *
     * @param int $level One of the VERBOSITY_* tiers
     *
     * @return bool
     */
    public function isVerbose(int $level): bool
    {
        return (int) ($this->values()['verbosity'] ?? static::DEFAULT_VERBOSITY) >= $level;
    }

    // messages
    public function debug(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_DEBUG)) {
            $this->io()->info($message, $eol);
        }
    }

    public function warn(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            $this->io()->warn($message, $eol);
        }
    }

    /**
     * Detail: why/where something happens (selected versions, download urls, ...).
     *
     * @param string $message The message
     * @param bool   $eol     Whether to end the line
     *
     * @return void
     */
    public function info(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_DETAIL)) {
            $this->io()->info($message, $eol);
        }
    }

    /**
     * A neutral, structural narrative line (e.g. a deploy step), shown at the outcome tier.
     *
     * @param string $message The message
     * @param bool   $eol     Whether to end the line
     *
     * @return void
     */
    public function note(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            $this->io()->write($message, $eol);
        }
    }

    /**
     * A section header, shown at the outcome tier: a blank line, the title in bold, and an optional
     * status (on the same line) and detail (on the next line), both dimmed.
     *
     * @param string      $title  The section title, e.g. 'Modules'
     * @param string|null $detail A subtitle line, e.g. what the section is about to do
     * @param string|null $status A short status after the title, e.g. 'nothing to do'
     *
     * @return void
     */
    public function section(string $title, ?string $detail = null, ?string $status = null): void
    {
        if (!$this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            return;
        }
        $writer = $this->io()->writer();
        $writer->eol();
        $writer->boldCyan($title);
        if ($status !== null && $status !== '') {
            $writer->comment(" — {$status}");
        }
        $writer->eol();
        if ($detail !== null && $detail !== '') {
            $writer->comment("  {$detail}", true);
        }
    }

    /**
     * A top-level heading above a group of sections, shown at the outcome tier: a blank line, the
     * title in bold and a dimmed rule underneath.
     *
     * @param string $title The heading, e.g. 'Phase 1/2: Install core, modules and themes'
     *
     * @return void
     */
    public function heading(string $title): void
    {
        if (!$this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            return;
        }
        $writer = $this->io()->writer();
        $writer->eol();
        $writer->bold($title, true);
        $writer->comment(str_repeat('─', mb_strlen($title)), true);
    }

    public function ok(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            $this->io()->ok($message, $eol);
        }
    }

    public function error(string $message, bool $eol = false): void
    {
        if ($this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            $this->io()->error($message, $eol);
        }
    }

    public function echo(string $message, bool $eol = false): void
    {
        $this->io()->writer()->raw($message);
        if ($eol) {
            $this->io()->eol();
        }
    }

    public function beQuiet(): void {
        $this->set('verbosity', self::VERBOSITY_QUIET);
    }

    /**
     * Set a parsed option/argument value from outside the command.
     *
     * The underlying set() is protected; this exposes it so an orchestrator (e.g. the blueprint
     * applier) can prime another command's inputs before invoking its execute() directly.
     *
     * @param string $name  The value key (camel-cased long option/argument name)
     * @param mixed  $value The value to set
     * @return static
     */
    public function primeValue(string $name, mixed $value): static {
        $this->set($name, $value);
        return $this;
    }

    /**
     * Resolve the Omeka S installation path (public wrapper around the protected resolver), so an
     * orchestrator such as the blueprint applier can read on-disk files (e.g. a module's version).
     *
     * @return string
     */
    public function resolveOmekaPath(): string {
        return $this->getOmekaPath();
    }

    public function outputFormatted($object, $format='json', $return_value = false): ?string
    {
        if($return_value)
            ob_start();
        switch($format){
            case 'raw':
                $this->io()->writer()->raw($object);
                break;
            case 'table':
                if(is_array($object)) {
                    $data = $this->prepareOutputData($object);
                    $this->io()->table($data); break;
                }
            case 'print_r': $this->io()->writer()->raw(print_r($object, true)); break;
            case 'var_export': $this->io()->writer()->raw(var_export($object, true)); break;
            case 'csv':
                if(is_array($object) && count($object) > 0 && is_array($object[0])) {
                    $data = $this->prepareOutputData($object);
                    $fp = fopen('php://output', 'w');
                    fputcsv($fp, array_keys($data[0]));
                    foreach ($data as $line) {
                        fputcsv($fp, $line);
                    }
                    fclose($fp);
                }
                break;
            case 'json':
            default:
                if(is_object($object))
                    $object = (array)$object;
                $this->io()->writer()->raw(json_encode($object, JSON_PRETTY_PRINT));
                $this->io()->eol();
                break;
        }
        if($return_value)
            return ob_get_clean();

        return null;
    }

    // prepare output data
    // - convert all booleans to 'yes' or 'no'
    // - convert all null values to 'unknown
    private function prepareOutputData($data) {
        if (is_array($data)) {
            return array_map([$this, 'prepareOutputData'], $data);
        }
        if (is_bool($data)) {
            return $data ? 'yes' : 'no';
        }
        if (is_null($data)) {
            return 'unknown';
        }
        return $data;
    }

    /**
     * Resolve the Omeka S installation path.
     *
     * An explicit --base-path is strict: if it is given but does not exist or is not an Omeka
     * installation, this throws (a deliberate user error). When no --base-path is given it searches
     * upward from the current directory and returns null when nothing is found — so callers can decide
     * whether loading the instance is worthwhile. Only inspects the directory layout (via isOmekaDir);
     * it does not bootstrap Omeka.
     *
     * @return string|null The resolved Omeka S base path, or null when no --base-path was given and
     *                     none was found by searching from the current directory
     * @throws Exception When an explicit --base-path is given but does not exist / is not an Omeka dir
     */
    protected function findOmekaPath(): ?string {
        $basePath = $this->values()['basePath'] ?? null;
        if ($basePath) {
            $resolved = realpath(Path::toAbsolutePath(rtrim($basePath, DIRECTORY_SEPARATOR), $this->getCwd()));
            if ($resolved === false) {
                throw new Exception("The provided base path does not exist.");
            }
            if (!$this->isOmekaDir($resolved)) {
                throw new Exception("The provided base path {$resolved} does not contain a valid Omeka S context.");
            }
            return $resolved;
        }

        return $this->searchOmekaDir();
    }

    /**
     * Whether an Omeka S instance is available, so a command can decide if loading the instance /
     * reading its version is worthwhile. Does not bootstrap Omeka.
     *
     * Soft only for auto-detection: when no --base-path is given it returns false if the current
     * directory is not inside an instance. An explicit but invalid --base-path still throws (that is a
     * deliberate user error, per findOmekaPath()).
     *
     * @return bool
     * @throws Exception When an explicit --base-path is given but is invalid
     */
    public function hasOmekaInstance(): bool {
        return $this->findOmekaPath() !== null;
    }

    protected function getOmekaPath(): string {
        static $basePath = null;
        if ($basePath) {
            return $basePath;
        }

        $basePath = $this->findOmekaPath();
        if (!$basePath) {
            throw new Exception("Could not find a valid Omeka S context.");
        }

        $this->debug("Omeka S found at {$basePath}", true);

        return $basePath;
    }

    public function getOmekaInstance(bool $elevated = true): OmekaInstance {
        $instance = OmekaInstanceFactory::createInstance($this->getOmekaPath());
        if ($elevated) {
            // check if omeka is installed
            if (!$instance->getStatus()->isInstalled()) {
                throw new Exception("Omeka S is not installed.");
            }
            $instance->elevatePrivileges();
        }
        return $instance;
    }

    protected function ensureOmekaInstance(): void {
        $this->getOmekaInstance();
    }

    /**
     * Run another omeka-s-cli command in a new process.
     *
     * PHP loads a class only once per process, so the Module class of every active module stays
     * in memory exactly as it was when Omeka S was bootstrapped. Once module files have been
     * replaced on disc, that stale class can no longer be refreshed: re-bootstrapping does not
     * help, because 'require_once' on an already included path is a no-op and redeclaring the
     * class would be a fatal error. Work that has to see the new files must therefore run in a
     * new process.
     *
     * The current base path and exact verbosity level are passed on, so the child works on the
     * same Omeka S instance and produces output at the same tier.
     *
     * @param string[] $arguments Command name and its arguments, e.g. ['module:upgrade', 'Common']
     * @return int The exit code of the child process
     * @throws Exception If the omeka-s-cli entry point can not be determined
     */
    public function runInNewProcess(array $arguments): int
    {
        // running from a phar: Phar::running() is the only reliable path to the entry point
        $entryPoint = \Phar::running(false);
        if ($entryPoint === '') {
            $entryPoint = realpath($_SERVER['argv'][0] ?? '') ?: '';
        }
        if ($entryPoint === '') {
            throw new Exception("Could not determine the omeka-s-cli entry point to run '{$arguments[0]}'.");
        }

        $verbosity = (int) ($this->values()['verbosity'] ?? static::DEFAULT_VERBOSITY);
        $arguments = [...$arguments, '--base-path', $this->getOmekaPath(), '--verbosity', (string) $verbosity];
        if (in_array('--debug', $_SERVER['argv'] ?? [], true)) {
            $arguments[] = '--debug';
        }

        $command = implode(' ', array_map(
            'escapeshellarg',
            [PHP_BINARY, $entryPoint, ...$arguments]
        ));

        $this->debug("Running in a new process: {$command}", true);

        $exitCode = 0;
        passthru($command, $exitCode);

        return $exitCode;
    }

    protected function getOmekaVersion(): string {
        static $version = null;

        if ($version) {
            return $version;
        }

        $version = OmekaVersion::getVersion($this->getOmekaPath());
        return $version;
    }

    protected function getModuleRepositoryManager(): ModuleRepositoryManager {
        return $this->moduleRepositoryManager;
    }

    protected function getThemeRepositoryManager(): ThemeRepositoryManager {
        return $this->themeRepositoryManager;
    }

    # check for
    # - /config/database.ini
    # - /bootstrap.php
    # - /application/config/application.config.php
    public function isOmekaDir($dir): bool
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (
            file_exists( join(DIRECTORY_SEPARATOR, [$dir, '/bootstrap.php']))
            && file_exists( join(DIRECTORY_SEPARATOR, [$dir, '/application/config/application.config.php']))
            && file_exists(join(DIRECTORY_SEPARATOR, [$dir, '/modules']))
            && file_exists(join(DIRECTORY_SEPARATOR, [$dir, '/themes']))
            && file_exists(join(DIRECTORY_SEPARATOR, [$dir, '/files']))
        )
        {
            return true;
        }
        return false;
    }

    protected function getCwd(): string
    {
        return defined('OMEKA_S_CLI_CWD') ? OMEKA_S_CLI_CWD : getcwd();
    }

    // search for Omeka S context from current directory
    private function searchOmekaDir(): ?string
    {
        $cwd = $this->getCwd();
        $dir = realpath($cwd);
        while ($dir !== false && $dir !== '/' && !$this->isOmekaDir($dir)) {
            $dir = realpath($dir . '/..');
        }

        if ($dir !== false && $dir !== '/') {
            return $dir;
        }

        return null;
    }
}