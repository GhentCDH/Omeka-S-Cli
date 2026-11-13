<?php
namespace OSC\Exceptions;

/** A network lookup the CLI makes on its own was refused because offline mode is on. */
class OfflineException extends \RuntimeException {}
