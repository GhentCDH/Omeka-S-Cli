<?php
namespace OSC\Omeka;

use Exception;
use Laminas\Mvc\Application;

class Omeka
{
    protected Application $application;

    public function __construct(private string $path)
    {
    }

    public function init(): ?Application
    {
        try {
            ob_start();
            require $this->path . "/bootstrap.php";

            $application = Application::init(require $this->path . '/application/config/application.config.php');
            $application->run();
            ob_end_clean();

            $this->application = $application;
            return $application;
        } catch (Exception $e) {
            return null;
        }
    }

    public function application(): ?Application
    {
        return $this->application;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function elevatePrivileges(): void {
        $serviceLocator = $this->application()->getServiceManager();
        $auth = $serviceLocator->get('Omeka\AuthenticationService');

        $entityManager = $serviceLocator->get('Omeka\EntityManager');
        $userRepository = $entityManager->getRepository('Omeka\Entity\User');
        $identity = $userRepository->findOneBy(['id' => 1, 'isActive' => true]);
        $auth->getStorage()->write($identity);
    }
}