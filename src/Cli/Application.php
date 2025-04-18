<?php
namespace OSC\Cli;

use OSC\Commands\Module\AvailableCommand;
use Throwable;

class Application extends \Ahc\Cli\Application
{
    public function handle(array $argv): mixed
    {
        $this->onException([$this, 'onError']);

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
        $this->io()->error($e->getMessage(), true);
        $this->io()->info($e->getTraceAsString(), true);
        exit($exitCode);
    }
}