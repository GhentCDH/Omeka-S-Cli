<?php
namespace OSC\Cli;

use Ahc\Cli\Input\Command;
use OSC\Commands\AbstractCommand;
use OSC\Manager\AbstractManager;
use Throwable;

class Application extends \Ahc\Cli\Application
{
    private bool $debug = false;

    public function __construct(protected string $name, protected string $version = '0.0.1', ?callable $onExit = null)    {
        parent::__construct($name, $version);

        // set error handler
        $this->onException([$this, 'onError']);

        // update color schema
        \Ahc\Cli\Output\Color::style('info', [
            'fg' => \Ahc\Cli\Output\Color::WHITE,
            'options' => ['bold']
        ]);
    }

    public function handle(array $argv): mixed
    {
        // parse arguments
        $this->debug = in_array('--debug', $argv, true);
        $argv = array_filter($argv, fn($arg) => $arg !== '--debug');

        // handle
        return parent::handle($argv);
    }

    public function doAction(Command $command): mixed
    {
        if ($command->name() === '__default__') {
            return $this->notFound();
        }

        $command->init();

        try {
            return parent::doAction($command);
        } finally {
            $this->reportRepositoryWarnings($command);
        }
    }

    /**
     * Report repositories that could not be reached while running the command.
     */
    protected function reportRepositoryWarnings(Command $command): void
    {
        $warnings = AbstractManager::takeWarnings();
        if (!$warnings || !$command instanceof AbstractCommand) {
            return;
        }

        foreach ($warnings as $warning) {
            $command->warn($warning, true);
        }
    }

    protected function onError(Throwable $e, int $exitCode): void {
        if ( $e instanceof \OSC\Exceptions\WarningException) {
            $this->io()->warn($e->getMessage(), true);
            $exitCode = 0;
        } else {
            $this->io()->error($e->getMessage(), true);
        }
        // output debug trace?
        if ($this->debug) {
            $this->io()->error($e->getTraceAsString(), true);
        }
        exit($exitCode);
    }
}