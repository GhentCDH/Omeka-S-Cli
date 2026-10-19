<?php
namespace OSC\Resource;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use OSC\Helper\ResourceFetcher;
use Throwable;

/**
 * The `upload` ingester as the CLI sees it: Omeka's own one only accepts files posted over HTTP, so in
 * the CLI process it is replaced by this one (see MediaIngesters::useLocalFiles()), which ingests a local
 * path or downloads a URL given as `ingest_source`. The media is stored with ingester `upload` and
 * renderer `file`, exactly like a file uploaded in the admin interface. Omeka's file validation
 * (allowed media types and extensions) still applies.
 */
class LocalFileIngester implements IngesterInterface
{
    public function __construct(private TempFileFactory $tempFileFactory)
    {
    }

    public function getLabel()
    {
        return 'Upload'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        $source = $data['ingest_source'] ?? null;
        if (!is_string($source) || $source === '') {
            $errorStore->addError('ingest_source', 'No file to ingest: set ingest_source to a local path or URL.');
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        if (ResourceFetcher::isUrl($source)) {
            try {
                ResourceFetcher::download($source, $tempFile->getTempPath());
            } catch (Throwable $e) {
                $tempFile->delete();
                $errorStore->addError('ingest_source', "Could not download {$source}: {$e->getMessage()}");
                return;
            }
            $name = basename((string) parse_url($source, PHP_URL_PATH));
        } else {
            if (!is_file($source) || !is_readable($source)) {
                $tempFile->delete();
                $errorStore->addError('ingest_source', "File not found: {$source}");
                return;
            }
            copy($source, $tempFile->getTempPath());
            $name = basename($source);
        }

        $tempFile->setSourceName($name);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($name);
        }
        $tempFile->mediaIngestFile($media, $request, $errorStore, true, true, true, true);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return '';
    }
}
