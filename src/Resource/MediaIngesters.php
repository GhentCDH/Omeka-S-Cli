<?php
namespace OSC\Resource;

use Laminas\ServiceManager\ServiceManager;

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
}
