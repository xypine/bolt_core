<?php

declare(strict_types=1);

namespace Bolt\Configuration;

use Bolt\Common\Arr;
use Bolt\Configuration\Parser\BaseParser;
use Bolt\Configuration\Parser\ContentTypesParser;
use Bolt\Configuration\Parser\GeneralParser;
use Bolt\Configuration\Parser\TaxonomyParser;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\Stopwatch\Stopwatch;
use Tightenco\Collect\Support\Collection;

class Config
{
    /** @var Collection */
    protected $data;

    /** @var PathResolver */
    private $pathResolver;

    /** @var Stopwatch */
    private $stopwatch;

    /** @var FilesystemCache */
    private $cache;

    /** @var string */
    private $projectDir;

    public function __construct(Stopwatch $stopwatch, $projectDir)
    {
        $this->stopwatch = $stopwatch;
        $this->cache = new FilesystemCache();
        $this->pathResolver = new PathResolver($projectDir, []);
        $this->projectDir = $projectDir;
        $this->data = $this->getConfig();
    }

    private function getConfig(): Collection
    {
        $this->stopwatch->start('bolt.parseconfig');

        if ($this->validCache()) {
            $data = $this->getCache();
        } else {
            $data = $this->parseConfig();
            $this->setCache($data);
        }

        $this->stopwatch->stop('bolt.parseconfig');

        return $data;
    }

    private function validCache(): bool
    {
        if (! $this->cache->has('config_cache') || ! $this->cache->has('config_timestamps')) {
            return false;
        }

        $timestamps = $this->cache->get('config_timestamps');

        foreach ($timestamps as $filename => $timestamp) {
            if (filemtime($filename) > $timestamp) {
                return false;
            }
        }

        return true;
    }

    private function getCache(): Collection
    {
        return $this->cache->get('config_cache');
    }

    private function setCache($data): void
    {
        $this->cache->set('config_cache', $data);
    }

    /**
     * Load the configuration from the various YML files.
     */
    private function parseConfig(): Collection
    {
        $general = new GeneralParser();

        $config = collect([
            'general' => $general->parse(),
        ]);

        $taxonomy = new TaxonomyParser();
        $config['taxonomies'] = $taxonomy->parse();

        $contentTypes = new ContentTypesParser($config->get('general')['accept_file_types']);
        $config['contenttypes'] = $contentTypes->parse();

        // @todo Add these config files if needed, or refactor them out otherwise
        //'menu' => $this->parseConfigYaml('menu.yml'),
        //'routing' => $this->parseConfigYaml('routing.yml'),
        //'permissions' => $this->parseConfigYaml('permissions.yml'),
        //'extensions' => $this->parseConfigYaml('extensions.yml'),

        $this->getConfigFilesTimestamps($general, $taxonomy, $contentTypes);

        return $config;
    }

    private function getConfigFilesTimestamps(BaseParser ...$configs): void
    {
        $timestamps = [];

        foreach ($configs as $config) {
            foreach ($config->getFilenames() as $file) {
                $timestamps[$file] = filemtime($file);
            }
        }

        $envFilename = $this->projectDir . '/.env';
        if (file_exists($envFilename)) {
            $timestamps[$envFilename] = filemtime($envFilename);
        }

        $this->cache->set('config_timestamps', $timestamps);
    }

    /**
     * Get a config value, using a path.
     *
     * For example:
     * $var = $config->get('general/wysiwyg/ck/contentsCss');
     *
     * @param string|array|bool $default
     */
    public function get(string $path, $default = null)
    {
        return Arr::get($this->data, $path, $default);
    }

    public function getPath(string $path, bool $absolute = true, $additional = null): string
    {
        return $this->pathResolver->resolve($path, $absolute, $additional);
    }

    public function getPaths(): Collection
    {
        return $this->pathResolver->resolveAll();
    }

    public function getMediaTypes(): Collection
    {
        return collect(['png', 'jpg', 'jpeg', 'gif', 'svg', 'pdf', 'mp3', 'tiff']);
    }
}
