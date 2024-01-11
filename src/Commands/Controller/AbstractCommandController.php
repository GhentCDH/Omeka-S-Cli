<?php
namespace OSC\Commands\Controller;
use OSC\Cli\Application;
use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Laminas\ServiceManager\ServiceManager;

abstract class AbstractCommandController
{

    protected array $commands = [];

    public function __construct(protected Application $app, protected ServiceManager $serviceLocator)
    {
        foreach( $this->getCommands() as $command ) {
            $this->app->add($command);
        }
    }

    protected function error($message): void
    {
        fwrite(STDERR, $message."\n");
        exit(1);
    }

    protected function io(): Interactor
    {
        return $this->app->io();
    }

    protected function app(): Application
    {
        return $this->app;
    }

    public function outputFormatted($object, $format='json', $return_value = false): ?string
    {
        if(!$object)
            return null;
        if($return_value)
            ob_start();
        switch($format){
            case 'table': $this->io()->table($object); break;
            case 'print_r': $this->io()->writer()->raw(print_r($object, true)); break;
            case 'var_export': $this->io()->writer()->raw(var_export($object, true)); break;
            case 'json':
            default:
                if(is_object($object))
                    $object = (array)$object;
                $this->io()->writer()->raw(json_encode($object, true));
                break;
        }
        if($return_value)
            return ob_get_clean();

        return null;
    }

    /**
     * @return Command[]
     */
    abstract protected function getCommands(): array;

}