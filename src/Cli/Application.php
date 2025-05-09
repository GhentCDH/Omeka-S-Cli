<?php
namespace OSC\Cli;

use Ahc\Cli\Input\Command;
use Throwable;

class Application extends \Ahc\Cli\Application
{
    private bool $debug = false;

    public function handle(array $argv): mixed
    {
        // set error handler
        $this->onException([$this, 'onError']);

        // parse arguments
        $this->debug = in_array('--debug', $argv, true);
        $argv = array_filter($argv, fn($arg) => $arg !== '--debug');

        // register commands
        /** @var Command[] $commands */
        $commands = [];
        $commands = [...$commands, ...require(__DIR__ . '/../Commands/Module/Index.php')];
        $commands = [...$commands, ...require(__DIR__ . '/../Commands/Theme/Index.php')];
        $commands = [...$commands, ...require(__DIR__ . '/../Commands/Config/Index.php')];

        foreach ($commands as $command) {
            $this->add($command);
        }

        // handle
        return parent::handle($argv);
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
            $this->io()->info($e->getTraceAsString(), true);
        }
        exit($exitCode);
    }
}