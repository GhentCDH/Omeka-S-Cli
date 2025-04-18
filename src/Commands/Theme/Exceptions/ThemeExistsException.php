<?php
namespace OSC\Commands\Theme\Exceptions;
class ThemeExistsException extends \Exception
{
    public function __construct(string $themeId)
    {
        parent::__construct("Theme '{$themeId}' already exists.");
    }
}