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
    public const ITEM = 'item';
    public const ITEM_SET = 'itemSet';
    public const MEDIA = 'media';

    /** The CLI's own item and item-set entries (one JSON-LD source each), until the shared format has them. */
    public const EXTENSION_ITEMS = 'x-omeka-s-cli.items';
    public const EXTENSION_ITEM_SETS = 'x-omeka-s-cli.itemSets';

    /**
     * Where the Omeka S release ZIP is declared. It lives in the CLI's extension key until the
     * blueprint spec defines install.source; moving it is a change of this path only.
     */
    public const CORE_SOURCE = 'x-omeka-s-cli.install.source';

    /**
     * List key => [item field => kind]. A dotted key is a list inside an object; a field `a[].b` is
     * the field `b` of every element of the list `a`.
     */
    private const LISTS = [
        'modules'                 => ['source' => self::MODULE],
        'themes'                  => ['source' => self::THEME],
        'files'                   => ['source' => self::FILE],
        'vocabularies'            => ['source' => self::VOCABULARY],
        'resourceTemplates'       => ['source' => self::TEMPLATE],
        'items'                   => ['media[].url' => self::MEDIA],
        self::EXTENSION_ITEM_SETS => ['source' => self::ITEM_SET],
        self::EXTENSION_ITEMS     => ['source' => self::ITEM, 'media[].source' => self::MEDIA],
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

    /** @return string[] The lists that sit inside an object (dotted paths) */
    public static function dottedLists(): array
    {
        return [self::EXTENSION_ITEM_SETS, self::EXTENSION_ITEMS];
    }

    /**
     * Whether a media's $field is a file to fetch (an upload), rather than a link the ingester keeps
     * (url, iiif, youtube, html, ...). Without an ingester (the shared format's simple media, field
     * `url`) a value that is not an http(s) URL is a file.
     */
    public static function isMediaFile(array $media, string $field): bool
    {
        if (isset($media['ingester'])) {
            return $media['ingester'] === 'upload';
        }
        $value = $media[$field] ?? null;
        return is_string($value) && preg_match('#^https?://#i', trim($value)) !== 1;
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
