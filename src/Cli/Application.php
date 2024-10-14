<?php
namespace OSC\Cli;

use OSC\Commands\Controller\ModuleController;
use OSC\Commands\Controller\ThemeController;
use OSC\Omeka\Omeka;
use Throwable;

class Application extends \Ahc\Cli\Application
{
    protected Omeka $omeka;

    public function handle(array $argv): mixed
    {
        $this->onException([$this, 'onError']);

        // base path set?
        $basePath = null;
        if (isset($argv[1]) && ( $argv[1] === '-b' || $argv[1] === '--base-path' )) {
            $basePath = $argv[2] ?? null;
            unset($argv[1], $argv[2]);
            $argv = array_values($argv);
        }
        // json output?
        $jsonOutput = in_array('--json', $argv) || in_array('-j', $argv);

        if ($basePath) {
            if (!$this->isOmekaDir($basePath)) {
                $this->io()->error("The provided base path does not contain a valid Omeka S context", true);
                return false;
            }
        } else {
            $basePath = $this->searchOmekaDir();
            if (!$basePath) {
                $this->io()->error("Could not find a valid Omeka S context", true);
                return false;
            }
        }

        if(!$jsonOutput) {
            $this->io()->info("Omeka S found at {$basePath}", true);
        }

        try {
            $omeka = new Omeka($basePath);
            $omeka->init();
            $this->omeka = $omeka;
        } catch(Throwable $E) {
            $this->io()->error("Could not init Omeka S ({$E->getMessage()})", true);
            return false;
        }

        // get Omeka ServiceManager
        $serviceLocator = $omeka->application()->getServiceManager();

        // load command controllers
        $controllers = [];
        $controllers['module'] = new ModuleController($this, $serviceLocator);
        $controllers['theme'] = new ThemeController($this, $serviceLocator);


        // handle
        return parent::handle($argv);
    }

    public function omeka(): Omeka {
        return $this->omeka;
    }

    # check for
    # - /config/database.ini
    # - /bootstrap.php
    # - /application/config/application.config.php
    private function isOmekaDir($dir): bool
    {
        if (
            file_exists( join(DIRECTORY_SEPARATOR, [$dir, '/config/database.ini']))
            && file_exists( join(DIRECTORY_SEPARATOR, [$dir, '/bootstrap.php']))
            && file_exists( join(DIRECTORY_SEPARATOR, [$dir, '/application/config/application.config.php'])) )
        {
            return true;
        }
        return false;
    }

    // search for Omeka S context from current directory
    private function searchOmekaDir(): ?string
    {
        $dir = realpath('.');
        while ($dir !== false && $dir !== '/' && !$this->isOmekaDir($dir)) {
            $dir = realpath($dir . '/..');
        }

        if ($dir !== false && $dir !== '/') {
            return $dir;
        }

        return null;
    }

    protected function onError(Throwable $e, int $exitCode): void {
        $this->io()->error($e->getMessage(), true);
        exit($exitCode);
    }
}