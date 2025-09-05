<?php
namespace OSC\Omeka;

use Exception;
use Laminas\Mvc\Application;
use Omeka\Mvc\Status;
use Omeka\Api\Manager as ApiManager;

class OmekaInstance
{
    protected ?Application $application;
    protected ?ModuleApi $moduleApi;
    protected ?ThemeApi $themeApi;

    private string $path;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function init(): ?Application
    {
        try {
            ob_start();
            // bootstrap Omeka S
            require $this->path . "/bootstrap.php";

            // initialize the application
            $this->application = \Omeka\Mvc\Application::init(require $this->path . '/application/config/application.config.php');
            $this->application->run();

            ob_end_clean();

            // init apis
            $this->themeApi = new ThemeApi($this->getServiceManager());
            $this->moduleApi = new ModuleApi($this->getServiceManager());

            // todo: dirty! should fix module init on startup
            if (file_exists($this->path . '/modules/Common/vendor/autoload.php')) {
                require_once($this->path . '/modules/Common/vendor/autoload.php');
            }

            return $this->application;
        } catch (Exception $e) {
            throw new Exception("Failed to initialize Omeka S instance: " . $e->getMessage(), 0, $e);
        }
    }

    public function getApplication(): ?Application
    {
        if (!$this->application) {
            $this->init();
        }
        return $this->application;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getApi(): ApiManager
    {
        return $this->getServiceManager()->get('Omeka\ApiManager');
    }

    public function getStatus(): Status
    {
        return $this->getServiceManager()->get('Omeka\Status');
    }

    public function getServiceManager(): ?\Laminas\ServiceManager\ServiceManager
    {
        return $this->getApplication()->getServiceManager();
    }

    public function getThemeApi(): ThemeApi
    {
        return $this->themeApi;
    }

    public function getModuleApi(): ModuleApi
    {
        return $this->moduleApi;
    }

    public function elevatePrivileges(): void
    {
        try {
            $serviceLocator = $this->getServiceManager();
            $auth = $serviceLocator->get('Omeka\AuthenticationService');

            $entityManager = $serviceLocator->get('Omeka\EntityManager');
            $userRepository = $entityManager->getRepository('Omeka\Entity\User');
            $identity = $userRepository->findOneBy(['role' => 'global_admin', 'isActive' => true]);
            $auth->getStorage()->write($identity);
        } catch (\Throwable $e) {
            throw new Exception("Failed to elevate privileges.");
        }
    }
}
