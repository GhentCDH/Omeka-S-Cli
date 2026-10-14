<?php
namespace OSC\Commands\Blueprint;

use OSC\Blueprint\BlueprintValidator;
use OSC\Commands\AbstractCommand;

abstract class AbstractBlueprintCommand extends AbstractCommand
{
    /**
     * The schema validator. A self-contained check uses the copy shipped with the CLI, so it never
     * needs the network; otherwise the shared schema is fetched (with the bundled copy as fallback).
     */
    protected function validator(bool $selfContained): BlueprintValidator
    {
        return $selfContained ? new BlueprintValidator(BlueprintValidator::bundledSchemaPath()) : new BlueprintValidator();
    }
}
