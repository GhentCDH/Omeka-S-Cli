<?php
namespace OSC\Omeka;

use CustomVocab\Stdlib\ImportExport;
use Omeka\Api\Exception\NotFoundException;
use RuntimeException;

/**
 * CustomVocab import/export with support for item set based ("Items") vocabularies.
 *
 * The module's ImportExport only handles terms and URIs. An Items vocabulary is exported
 * as a reference to its item set, identified by a property value, since item set IDs
 * differ between installations:
 *
 * ```json
 * "o:item_set": {
 *     "property": "dcterms:identifier",
 *     "value": "urn:middle-earth:item-set:palantiri",
 *     "o:title": "The Seeing-stones of Arnor and Gondor"
 * }
 * ```
 *
 * `o:title` is informational only; import resolves the item set by property and value.
 *
 * The parent class only exists once Omeka has loaded the CustomVocab module, so do not
 * reference this class (constants included) before the Omeka instance is bootstrapped.
 */
class CustomVocabImportExport extends ImportExport
{
    public const DEFAULT_ITEM_SET_PROPERTY = 'dcterms:identifier';

    /**
     * Get the export array for a custom vocab.
     *
     * @param int    $customVocabId   The custom vocab ID
     * @param string|null $itemSetProperty Property term identifying the item set of an Items vocab
     *                                     (default: DEFAULT_ITEM_SET_PROPERTY)
     * @return array|false Returns false if cannot export
     * @throws RuntimeException If the item set has no value for the identifying property
     */
    public function getExport(int $customVocabId, ?string $itemSetProperty = null)
    {
        $itemSetProperty ??= self::DEFAULT_ITEM_SET_PROPERTY;

        $export = parent::getExport($customVocabId);
        if ($export !== false) {
            return $export;
        }

        try {
            $vocab = $this->api->read('custom_vocabs', $customVocabId)->getContent();
        } catch (NotFoundException $e) {
            return false;
        }
        if ($vocab->type() !== 'resource') {
            return false;
        }

        $itemSet = $vocab->itemSet();
        $value = $itemSet->value($itemSetProperty);
        $identifier = $value ? ($value->uri() ?? $value->value()) : null;
        if ($identifier === null || $identifier === '') {
            throw new RuntimeException(sprintf(
                "Item set '%s' has no %s value to identify it by.",
                $itemSet->displayTitle(),
                $itemSetProperty
            ));
        }

        return [
            'o:label' => $vocab->label(),
            'o:lang' => $vocab->lang(),
            'o:item_set' => [
                'property' => $itemSetProperty,
                'value' => $identifier,
                'o:title' => $itemSet->displayTitle(),
            ],
        ];
    }

    /**
     * Is the import valid?
     *
     * @param array $import
     * @return bool Returns true if valid, false if invalid
     */
    public function isValidImport($import)
    {
        if (!is_array($import) || !array_key_exists('o:item_set', $import)) {
            return parent::isValidImport($import);
        }

        $reference = $import['o:item_set'];
        return array_key_exists('o:label', $import)
            && array_key_exists('o:lang', $import)
            && is_array($reference)
            && is_string($reference['property'] ?? null) && $reference['property'] !== ''
            && is_string($reference['value'] ?? null) && $reference['value'] !== '';
    }

    /**
     * Replace the item set reference of an import with the API shape (['o:id' => N]).
     *
     * Imports without an item set are returned unchanged.
     *
     * @param array $import A valid import (see isValidImport())
     * @return array The import, ready for the custom_vocabs API
     * @throws RuntimeException If no item set, or more than one, matches the reference
     */
    public function resolveItemSet(array $import): array
    {
        if (!isset($import['o:item_set'])) {
            return $import;
        }

        $property = $import['o:item_set']['property'];
        $value = $import['o:item_set']['value'];
        $ids = $this->api->search('item_sets', [
            'property' => [[
                'property' => $property,
                'type' => 'eq',
                'text' => $value,
            ]],
            'limit' => 2,
        ], ['returnScalar' => 'id'])->getContent();

        if (count($ids) === 0) {
            throw new RuntimeException(
                "No item set found with {$property} = '{$value}'. Create it before importing the custom vocabulary."
            );
        }
        if (count($ids) > 1) {
            throw new RuntimeException(
                "Several item sets have {$property} = '{$value}'. Use a property that identifies a single item set."
            );
        }

        $import['o:item_set'] = ['o:id' => (int) reset($ids)];
        return $import;
    }
}
