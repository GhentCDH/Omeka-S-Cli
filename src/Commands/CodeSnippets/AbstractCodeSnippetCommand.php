<?php
/**
 * Abstract CodeSnippets command.
 */

namespace OSC\Commands\CodeSnippets;

use OSC\Commands\AbstractCommand;

/**
 * Base command for CodeSnippets operations.
 */
abstract class AbstractCodeSnippetCommand extends AbstractCommand
{
    protected array $moduleDependencies = ['CodeSnippets'];

    /**
     * Get SnippetService from Omeka's ServiceManager.
     *
     * @return object
     *
     * @throws \Exception If SnippetService is not available
     */
    protected function getSnippetService(): object
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        if (!$serviceManager->has(\CodeSnippets\Service\SnippetService::class)) {
            throw new \Exception('CodeSnippets SnippetService is not available in the service manager.');
        }

        return $serviceManager->get(\CodeSnippets\Service\SnippetService::class);
    }

    /**
     * Get SnippetImportExport from Omeka's ServiceManager.
     *
     * @return object
     *
     * @throws \Exception If CodeSnippets 1.0.1 or later is not installed
     */
    protected function getSnippetImportExport(): object
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        if (!$serviceManager->has(\CodeSnippets\Service\SnippetImportExport::class)) {
            throw new \Exception('This command requires CodeSnippets 1.0.1 or later.');
        }

        return $serviceManager->get(\CodeSnippets\Service\SnippetImportExport::class);
    }

    /**
     * Find a snippet by ID, honouring --ignore-not-found.
     *
     * @param int  $id             Snippet ID
     * @param bool $ignoreNotFound Whether to ignore a missing snippet
     *
     * @return array
     *
     * @throws \Throwable If snippet not found and not ignoring
     */
    protected function requireSnippet(int $id, bool $ignoreNotFound = false): array
    {
        try {
            return $this->getSnippetService()->find($id);
        } catch (\Throwable $e) {
            $this->skipMissing($e, $ignoreNotFound);
        }
    }
}
