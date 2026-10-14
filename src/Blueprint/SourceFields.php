<?php
namespace OSC\Blueprint;

/**
 * Where a blueprint refers to external sources, and what kind each one is: the single registry for
 * the loader (which resolves them), the self-contained check and the bundler (which fetches them),
 * so a new source field is added in one place.
 */
final class SourceFields
{
    public const CORE = 'core';
    public const MODULE = 'module';
    public const THEME = 'theme';
    public const FILE = 'file';
    public const VOCABULARY = 'vocabulary';
    public const TEMPLATE = 'template';

    /**
     * Where the Omeka S release ZIP is declared. It lives in the CLI's extension key until the
     * blueprint spec defines install.source; moving it is a change of this path only.
     */
    public const CORE_SOURCE = 'x-omeka-s-cli.install.source';

    /** List key => [item field => kind]. */
    private const LISTS = [
        'modules'           => ['source' => self::MODULE],
        'themes'            => ['source' => self::THEME],
        'files'             => ['source' => self::FILE],
        'vocabularies'      => ['source' => self::VOCABULARY],
        'resourceTemplates' => ['source' => self::TEMPLATE],
    ];

    /** Dotted object path => [field => kind]. */
    private const OBJECTS = [
        'x-omeka-s-cli.install' => ['source' => self::CORE],
    ];

    /** @return array<string,string> field => kind, for the items of the list $key */
    public static function forList(string $key): array
    {
        return self::LISTS[$key] ?? [];
    }

    /** @return array<string,array<string,string>> dotted object path => [field => kind] */
    public static function objects(): array
    {
        return self::OBJECTS;
    }

    /**
     * Whether a kind is an add-on, whose source may also be a git URL or gh:owner/repo that the
     * download commands interpret themselves.
     */
    public static function isAddon(string $kind): bool
    {
        return $kind === self::MODULE || $kind === self::THEME;
    }
}
