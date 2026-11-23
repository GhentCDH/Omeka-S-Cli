<?php
namespace OSC\Blueprint;

/**
 * Validates one document of a blueprint as written, before the loader resolves it: the blueprint
 * itself, or a file it imports (a list, or the settings).
 */
interface DocumentValidator
{
    /**
     * @param mixed       $data The decoded document; an imported list is always a list
     * @param string|null $type Null for the blueprint, else the key it is imported under (a list key or
     *                          'settings')
     * @return string[] Error messages ("<json pointer>: <message>"); empty when valid
     */
    public function validateDocument(mixed $data, ?string $type): array;
}
