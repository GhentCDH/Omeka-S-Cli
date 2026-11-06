<?php
namespace OSC\Resource;

/**
 * The resource kinds the item commands handle: the same command classes are instantiated once per
 * type, and the import/export core uses the type for API names, JSON-LD types and messages.
 */
enum ResourceType: string
{
    case Item = 'items';
    case ItemSet = 'item_sets';

    /** The JSON-LD @type Omeka gives the resource. */
    public function jsonLdType(): string
    {
        return match ($this) {
            self::Item => 'o:Item',
            self::ItemSet => 'o:ItemSet',
        };
    }

    /** For messages: "item", "item set". */
    public function label(): string
    {
        return match ($this) {
            self::Item => 'item',
            self::ItemSet => 'item set',
        };
    }

    /** The command namespace: "item", "item-set". */
    public function commandPrefix(): string
    {
        return match ($this) {
            self::Item => 'item',
            self::ItemSet => 'item-set',
        };
    }

    /** The type for an API resource name, or null for one these commands do not handle (e.g. media). */
    public static function fromApiName(string $name): ?ResourceType
    {
        return self::tryFrom($name);
    }
}
