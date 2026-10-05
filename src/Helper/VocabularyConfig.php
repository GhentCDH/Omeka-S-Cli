<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * A vocabulary import configuration: the RDF source plus the identifying/formatting fields.
 *
 * One source of truth for the vocabulary-config field set, its validation, and the transform into the
 * options the Omeka RDF importer consumes. The RDF is identified by a single `source` (a path or a
 * URL); the legacy `file`/`url` keys are still accepted but deprecated.
 *
 * This object does no IO: a relative `source` must already be resolved against its base (see
 * {@see \OSC\Commands\Vocabulary\ImportCommand} / {@see \OSC\Blueprint\BlueprintApplier}) before
 * construction, and filesystem/URL existence is checked later by the importer step.
 */
class VocabularyConfig
{
    public const VALID_FORMATS = ['auto', 'jsonld', 'rdfxml', 'turtle', 'ntriples'];

    /**
     * @param 'file'|'url'  $strategy           How the importer reads the source
     * @param string        $source             The resolved path or URL
     * @param string|null   $deprecatedSourceKey The legacy key used ('file'|'url'), or null for 'source'
     */
    private function __construct(
        private string $strategy,
        private string $source,
        private string $namespaceUri,
        private string $prefix,
        private string $label,
        private ?string $comment,
        private string $format,
        private ?string $lang,
        private ?string $labelProperty,
        private ?string $commentProperty,
        private ?string $deprecatedSourceKey,
    ) {
    }

    /**
     * Build from a loose config array (CLI values or a parsed JSON config). Unknown keys are ignored.
     *
     * @throws InvalidArgumentException When the source or an identifying field is missing/invalid
     */
    public static function fromArray(array $data): self
    {
        $clean = static function (mixed $value): ?string {
            if ($value === null) {
                return null;
            }
            $value = is_string($value) ? trim($value) : $value;
            return $value === '' ? null : (string) $value;
        };

        $source = $clean($data['source'] ?? null);
        $url = $clean($data['url'] ?? null);
        $file = $clean($data['file'] ?? null);

        // exactly one RDF source; `source` is canonical, `file`/`url` are deprecated aliases
        if ($source !== null) {
            if ($url !== null || $file !== null) {
                throw new InvalidArgumentException(
                    "Use 'source' on its own; the deprecated 'file'/'url' keys cannot be combined with it."
                );
            }
            $strategy = ResourceFetcher::isUrl($source) ? 'url' : 'file';
            $value = $source;
            $deprecatedKey = null;
        } elseif ($url !== null && $file !== null) {
            throw new InvalidArgumentException(
                "Specify a single 'source' for the vocabulary, not both 'file' and 'url'."
            );
        } elseif ($url !== null) {
            $strategy = 'url';
            $value = $url;
            $deprecatedKey = 'url';
        } elseif ($file !== null) {
            $strategy = 'file';
            $value = $file;
            $deprecatedKey = 'file';
        } else {
            throw new InvalidArgumentException(
                "You must specify a 'source' (a path or URL) for the vocabulary."
            );
        }

        $label = $clean($data['label'] ?? null);
        if ($label === null) {
            throw new InvalidArgumentException('A vocabulary must have a label.');
        }

        $namespaceUri = $clean($data['namespaceUri'] ?? null);
        $prefix = $clean($data['prefix'] ?? null);
        if ($namespaceUri === null || $prefix === null) {
            throw new InvalidArgumentException('A vocabulary must have a namespace uri and a prefix.');
        }

        $format = strtolower($clean($data['format'] ?? null) ?? 'auto');
        if (!in_array($format, self::VALID_FORMATS, true)) {
            throw new InvalidArgumentException(
                "Invalid format '{$format}'. Valid formats are: " . implode(', ', self::VALID_FORMATS) . '.'
            );
        }

        return new self(
            $strategy,
            $value,
            $namespaceUri,
            $prefix,
            $label,
            $clean($data['comment'] ?? null),
            $format,
            $clean($data['lang'] ?? null),
            $clean($data['labelProperty'] ?? null),
            $clean($data['commentProperty'] ?? null),
            $deprecatedKey,
        );
    }

    /** The deprecated key a caller supplied the source under ('file'|'url'), or null when 'source' was used. */
    public function deprecatedSourceKey(): ?string
    {
        return $this->deprecatedSourceKey;
    }

    public static function isValidFormat(?string $format): bool
    {
        return $format === null || in_array(strtolower($format), self::VALID_FORMATS, true);
    }

    /**
     * The exact structure the Omeka RDF importer consumes (strategy + vocabulary + options), null-filtered.
     *
     * @return array{strategy:string, vocabulary:array<string,string>, options:array<string,string>}
     */
    public function toImporterOptions(): array
    {
        $vocabulary = [
            'o:namespace_uri' => $this->namespaceUri,
            'o:prefix' => $this->prefix,
            'o:label' => $this->label,
            'o:comment' => $this->comment,
        ];

        $options = [
            $this->strategy => $this->source,
            'format' => $this->format === 'auto' ? 'guess' : $this->format,
            'lang' => $this->lang,
            'label_property' => $this->labelProperty,
            'comment_property' => $this->commentProperty,
        ];

        return [
            'strategy' => $this->strategy,
            'vocabulary' => array_filter($vocabulary, fn($v) => $v !== null),
            'options' => array_filter($options, fn($v) => $v !== null),
        ];
    }

    /**
     * The canonical config shape: emits `source` (never the deprecated file/url), omits null fields and
     * a default `format` of 'auto'.
     *
     * @return array<string,string>
     */
    public function toArray(): array
    {
        $config = [
            'source' => $this->source,
            'label' => $this->label,
            'namespaceUri' => $this->namespaceUri,
            'prefix' => $this->prefix,
            'comment' => $this->comment,
            'format' => $this->format === 'auto' ? null : $this->format,
            'lang' => $this->lang,
            'labelProperty' => $this->labelProperty,
            'commentProperty' => $this->commentProperty,
        ];

        return array_filter($config, fn($v) => $v !== null);
    }
}
