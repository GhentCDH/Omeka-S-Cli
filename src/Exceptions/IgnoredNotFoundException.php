<?php

namespace OSC\Exceptions;

/**
 * Signals that a command was told to ignore a missing resource and should stop cleanly.
 *
 * A silent marker: the human-readable "… Nothing to do." note is already emitted, verbosity-aware,
 * by AbstractCommand::skipMissing() before this is thrown. Application::onError() turns it into a
 * successful (exit 0) termination without printing anything more.
 */
class IgnoredNotFoundException extends \Exception
{
}
