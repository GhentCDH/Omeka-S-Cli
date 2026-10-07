<?php
namespace OSC\Commands\Site;

use InvalidArgumentException;
use OSC\Helper\SiteConfig;

class AddCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:add', 'Add a new site');
        $this->argument('<title>', 'Title of the site');
        $this->option('--slug', 'URL slug: letters, digits, _ and - (default: derived from the title)');
        $this->option('--summary', 'Summary of the site');
        $this->option('--theme', 'Theme of the site (default: default)');
        $this->option('--private', 'Make the site private (default: public)', 'boolval', false);
        $this->option('--default', 'Make it the default site', 'boolval', false);
        $this->option('--no-assign-new-items', 'Do not add newly created items to the site automatically');
        $this->option('--owner', 'Owner of the site, by user ID or email address (default: the first global administrator)');
        $this->option('-e --ignore-existing', 'Ignore if the site already exists (default: throw an error)', 'boolval', false);
        $this->optionJson();
    }

    public function execute(
        string $title,
        ?string $slug = null,
        ?string $summary = null,
        ?string $theme = null,
        ?bool $private = false,
        ?bool $default = false,
        ?bool $assignNewItems = true,
        ?string $owner = null,
        ?bool $ignoreExisting = false,
        ?bool $json = false
    ): void {
        $config = SiteConfig::fromArray([
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'theme' => $this->resolveTheme($theme ?? SiteConfig::DEFAULT_THEME),
            'isPublic' => !$private,
            'assignNewItems' => $assignNewItems ?? true,
        ]);

        $ownerId = $owner !== null ? $this->requireUser($owner, $this->getOmekaInstance()->getApi())->id() : null;

        $siteApi = $this->siteApi();

        // without a slug, Omeka derives one from the title; the title then identifies the site
        $existing = $config->slug() !== null
            ? $siteApi->findSiteBySlug($config->slug())
            : $siteApi->findSiteByTitle($config->title());
        if ($existing) {
            $what = $config->slug() !== null ? "with slug '{$config->slug()}'" : "titled '{$config->title()}'";
            if ($ignoreExisting) {
                $this->warn("A site {$what} already exists.", true);
                return;
            }
            $hint = $config->slug() === null ? ' Pass --slug to create another one.' : '';
            throw new InvalidArgumentException("A site {$what} already exists.{$hint}");
        }

        $site = $siteApi->create($config, $ownerId);

        if ($default) {
            $siteApi->setDefaultSite($site->id());
        }

        if ($json) {
            $this->outputFormatted($this->siteRow($site, $siteApi->getDefaultSiteId()), 'json');
        }

        $this->ok("Site '{$site->slug()}' created.", true);
    }
}
