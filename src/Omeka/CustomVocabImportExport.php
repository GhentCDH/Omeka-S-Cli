<?php
namespace OSC\Omeka;

use CustomVocab\Stdlib\ImportExport;
use Omeka\Api\Exception\NotFoundException;
use OSC\Resource\Catalog;
use OSC\Resource\RefResolution;
use OSC\Resource\RefResolver;
use OSC\Resource\RefWriter;
use RuntimeException;

/**
 * CustomVocab import/export with support for item set based ("Items") vocabularies.
 *
 * The module's ImportExport only handles terms and URIs. An Items vocabulary names its item set with
 * an o-ref:ref (https://omeka-s-contrib.github.io/omeka-s-ref/ns), a property value instead of the
 * item set's id, since ids differ between installations (omeka-s-modules/CustomVocab#46):
 *
 * ```json
 * {
 *     "@context": {"dcterms": "http://purl.org/dc/terms/", "o": "http://omeka.org/s/vocabs/o#", "o-ref": "https://omeka-s-contrib.github.io/omeka-s-ref/ns#"},
 *     "o:label": "Palantíri",
 *     "o:lang": "en",
 *     "o:item_set": {
 *         "o-ref:ref": {"dcterms:identifier": "urn:middle-earth:item-set:palantiri"},
 *         "o:title": "The Seeing-stones of Arnor and Gondor"
 *     }
 * }
 * ```
 *
 * `o:title` is informational only. An item set reference that matches no item set, or several, fails
 * the import: an Items vocabulary cannot be created without its item set.
 *
 * The parent class only exists once Omeka has loaded the CustomVocab module, so do not
 * reference this class (constants included) before the Omeka instance is bootstrapped.
 */
class CustomVocabImportExport extends ImportExport
{
    public const DEFAULT_ITEM_SET_PROPERTY = 'dcterms:identifier';

    public function __construct($api, private Catalog $catalog)
    {
        parent::__construct($api);
    }

    /**
     * Get the export array for a custom vocab.
     *
     * @param int         $customVocabId   The custom vocab ID
     * @param string|null $itemSetProperty Property term identifying the item set of an Items vocab
     *                                     (default: DEFAULT_ITEM_SET_PROPERTY)
     * @return array|false Returns false if cannot export
     * @throws RuntimeException If the property is unknown or the item set has no value for it
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

        if (!$this->catalog->hasProperty($itemSetProperty)) {
            throw new RuntimeException("Unknown property '{$itemSetProperty}'.");
        }
        $itemSet = $vocab->itemSet();
        $writer = new RefWriter($this->catalog, $itemSetProperty);
        $ref = $writer->write('item_sets', $itemSet->id());
        if ($ref === null) {
            throw new RuntimeException(sprintf(
                "Item set '%s' has no %s value to identify it by.",
                $itemSet->displayTitle(),
                $itemSetProperty
            ));
        }

        return [
            '@context' => $writer->context(),
            'o:label' => $vocab->label(),
            'o:lang' => $vocab->lang(),
            'o:item_set' => $ref + ['o:title' => $itemSet->displayTitle()],
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

        $resolver = RefResolver::forDocument($this->catalog, $import);
        return array_key_exists('o:label', $import)
            && array_key_exists('o:lang', $import)
            && $resolver->isRef($import['o:item_set']);
    }

    /**
     * Ready an import for the custom_vocabs API: the item set reference becomes ['o:id' => N] and
     * the @context is removed. Imports without an item set only lose their @context.
     *
     * @param array $import A valid import (see isValidImport())
     * @return array The import, ready for the custom_vocabs API
     * @throws RuntimeException If the reference is invalid, or no item set or more than one matches it
     */
    public function resolveItemSet(array $import): array
    {
        $resolver = RefResolver::forDocument($this->catalog, $import);
        unset($import['@context']);
        if (!isset($import['o:item_set'])) {
            return $import;
        }

        $resolution = $resolver->resolve($import['o:item_set'], 'item_sets');
        $term = $resolution->term;
        $value = $resolution->value;
        switch ($resolution->status) {
            case RefResolution::OK:
                $import['o:item_set'] = ['o:id' => $resolution->id];
                return $import;
            case RefResolution::NOT_FOUND:
                throw new RuntimeException(
                    "No item set found with {$term} = '{$value}'. Create it before importing the custom vocabulary."
                );
            case RefResolution::AMBIGUOUS:
                throw new RuntimeException(
                    "Several item sets have {$term} = '{$value}'. Use a property that identifies a single item set."
                );
            default:
                throw new RuntimeException(
                    'Invalid item set reference: o-ref:ref must name exactly one property and a non-empty value.'
                );
        }
    }
}
