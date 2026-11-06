<?php
namespace OSC\Addon;

/** The two kinds of Omeka S add-on. The value is the singular name, as used in messages. */
enum AddonType: string
{
    case Module = 'module';
    case Theme = 'theme';

    /** The folder of the Omeka S installation that holds this kind of add-on. */
    public function dir(): string
    {
        return $this->value . 's';
    }

    /** The ini file in an add-on's config/ folder. */
    public function iniName(): string
    {
        return $this->value . '.ini';
    }
}
