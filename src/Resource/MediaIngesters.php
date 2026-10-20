<?php
namespace OSC\Resource;

use Laminas\ServiceManager\ServiceManager;
use Omeka\Stdlib\ErrorStore;
use OSC\Helper\ErrorStoreFormatter;
use OSC\Helper\MediaConfig;
use OSC\Helper\ResourceFetcher;

/**
 * The media ingesters of an instance: list them, and let the CLI ingest local files.
 */
final class MediaIngesters
{
    private const MANAGER = 'Omeka\Media\Ingester\Manager';

    /**
     * Replace the `upload` ingester, for this process only, by LocalFileIngester, so `upload` media take
     * a local path or URL. The ingester manager is a plugin manager that caches what it created, so the
     * instance itself is replaced (a factory would not override an `upload` already created, e.g. by
     * registered()); it then serves both standalone media and an item's nested o:media. Idempotent.
     */
    public static function useLocalFiles(ServiceManager $services): void
    {
        $manager = $services->get(self::MANAGER);
        $manager->setAllowOverride(true);
        $manager->setService('upload', new LocalFileIngester($services->get('Omeka\File\TempFileFactory')));
    }

    /**
     * The registered ingesters, by name, with their label.
     *
     * @return array<int,array{name:string,label:string}>
     */
    public static function registered(ServiceManager $services): array
    {
        $manager = $services->get(self::MANAGER);
        $rows = [];
        foreach ($manager->getRegisteredNames() as $name) {
            $rows[] = ['name' => $name, 'label' => (string) $manager->get($name)->getLabel()];
        }
        return $rows;
    }

    /**
     * Check the local files of `upload` media the way Omeka will (exists, allowed media type and
     * extension), so a rejected file stops the import before any file is stored. URL sources are
     * checked by Omeka when they are downloaded.
     *
     * @param MediaConfig[] $media
     * @return string[] Problems, as "media[<n>]: ..." (n counts from 1)
     */
    public static function check(ServiceManager $services, array $media): array
    {
        $problems = [];
        foreach (array_values($media) as $i => $config) {
            $number = $i + 1;
            if (!$config->isFile() || ResourceFetcher::isUrl($config->source())) {
                continue;
            }
            $source = $config->source();
            if (!is_file($source) || !is_readable($source)) {
                $problems[] = "media[{$number}]: file not found: {$source}";
                continue;
            }
            $tempFile = $services->get('Omeka\File\TempFileFactory')->build();
            try {
                copy($source, $tempFile->getTempPath());
                $tempFile->setSourceName(basename($source));
                $errors = new ErrorStore();
                if (!$services->get('Omeka\File\Validator')->validate($tempFile, $errors)) {
                    foreach (explode("\n", ErrorStoreFormatter::format($errors->getErrors())) as $line) {
                        $problems[] = "media[{$number}]: " . preg_replace('/^file: /', '', $line);
                    }
                }
            } finally {
                $tempFile->delete();
            }
        }
        return $problems;
    }
}
