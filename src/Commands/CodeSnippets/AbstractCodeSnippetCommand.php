<?php
/**
 * Abstract CodeSnippets command.
 */

namespace OSC\Commands\CodeSnippets;

use Ahc\Cli\Exception\InvalidArgumentException;
use CodeSnippets\Exception\SnippetNotFoundException;
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
     * Parse and validate a numeric snippet ID.
     *
     * @param string $id Snippet ID string from input
     *
     * @return int Valid positive integer ID
     *
     * @throws InvalidArgumentException If the ID is not a valid positive integer
     */
    protected function parseSnippetId(string $id): int
    {
        if (!preg_match('/^[1-9]\d*$/', $id)) {
            throw new InvalidArgumentException(
                sprintf("Snippet ID must be a positive integer, got: '%s'.", $id)
            );
        }

        $intId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($intId === false) {
            throw new InvalidArgumentException(
                sprintf("Snippet ID is out of range, got: '%s'.", $id)
            );
        }

        return $intId;
    }

    /**
     * Find a snippet by ID, honouring --ignore-not-found.
     *
     * @param int  $id             Snippet ID
     * @param bool $ignoreNotFound Whether to ignore a missing snippet
     *
     * @return array
     *
     * @throws SnippetNotFoundException If snippet not found and not ignoring
     */
    protected function requireSnippet(int $id, bool $ignoreNotFound = false): array
    {
        try {
            return $this->getSnippetService()->find($id);
        } catch (SnippetNotFoundException $e) {
            $this->skipMissing($e, $ignoreNotFound);
        }
    }
}
