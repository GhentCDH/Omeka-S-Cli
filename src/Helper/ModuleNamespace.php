<?php
namespace OSC\Helper;

/** The namespace of an Omeka S module, read from its Module.php (an unpacked release or a git checkout). */
final class ModuleNamespace
{
    /** The namespace a module's Module.php declares (Omeka requires it to be the module's folder), or null. */
    public static function fromModulePhp(string $src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }
}
