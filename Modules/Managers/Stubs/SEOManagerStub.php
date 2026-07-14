<?php
declare(strict_types=1);
/**
 * SEOManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides basic meta tag and schema handling without caching or AI features.
 * No GEO/AIO capabilities without extension InferenceManager.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\SEOManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class SEOManagerStub
 *
 * Free-tier stub implementation of SEOManagerInterface.
 * Provides basic SEO features without caching or AI optimization.
 */
class SEOManagerStub implements SEOManagerInterface
{
    /** @var SEOManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var array In-memory meta tag storage */
    private $metaStorage = [];

    /** @var array In-memory schema storage */
    private $schemaStorage = [];

    /** @var array In-memory canonical storage */
    private $canonicalStorage = [];

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => false,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
        'sitemap_url' => '/sitemap.xml',
    ];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'seo_optimization' => 0.3,
        'structured_data' => 0.3,
        'meta_management' => 0.4,
        'sitemap_generation' => 0.3,
        'schema_markup' => 0.3,
        'geo_optimization' => 0.0,
        'ai_meta_generation' => 0.0,
        'entity_extraction' => 0.0,
        'llms_txt_generation' => 0.1
    ];

    /** @var array Statistics */
    private $stats = [
        'meta_tags_set' => 0,
        'schemas_generated' => 0,
        'sitemaps_generated' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'ai_meta_generated' => 0,
        'faq_pairs_generated' => 0,
        'entities_extracted' => 0,
        'spr_compressions' => 0,
        'llms_txt_generated' => 0,
        'wikidata_lookups' => 0
    ];

    /** @var array Supported schema types */
    private const SCHEMA_TYPES = [
        'Organization', 'WebSite', 'WebPage', 'Article', 'BlogPosting',
        'Product', 'Person', 'Event', 'Review', 'BreadcrumbList',
        'FAQPage', 'HowTo', 'Recipe'
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): ModuleInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize stub
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = array_merge($this->defaultConfig, $config);
        $this->initialized = true;

        $this->logUpgradeNotice();
    }

    /**
     * Log upgrade notice (once per request)
     */
    private function logUpgradeNotice(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }

        self::$upgradeNoticeLogged = true;

        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] SEOManager stub active - the gcore-seo extension provides AI-powered SEO optimization'); }
        }
    }

    // =========================================================================
    // META TAG MANAGEMENT (basic in-memory)
    // =========================================================================

    /**
     * Set meta tags (in-memory only)
     */
    public function setMetaTags(string $page, array $tags): bool
    {
        $this->metaStorage[$page] = [
            'page' => $page,
            'tags' => $tags,
            'og' => $this->generateOpenGraph($tags),
            'twitter' => $this->generateTwitterCard($tags),
            'updated_at' => time()
        ];
        $this->stats['meta_tags_set']++;
        return true;
    }

    /**
     * Get meta tags
     */
    public function getMetaTags(string $page): ?array
    {
        return $this->metaStorage[$page] ?? null;
    }

    /**
     * Generate OpenGraph tags
     */
    public function generateOpenGraph(array $tags): array
    {
        $og = [];
        $mapping = [
            'title' => 'og:title',
            'description' => 'og:description',
            'image' => 'og:image',
            'url' => 'og:url',
            'type' => 'og:type'
        ];

        foreach ($mapping as $standard => $ogTag) {
            if (isset($tags[$standard])) {
                $og[$ogTag] = $tags[$standard];
            }
        }

        if (!isset($og['og:type'])) {
            $og['og:type'] = 'website';
        }

        return $og;
    }

    /**
     * Generate Twitter Card tags
     */
    public function generateTwitterCard(array $tags): array
    {
        $twitter = [];
        $mapping = [
            'title' => 'twitter:title',
            'description' => 'twitter:description',
            'image' => 'twitter:image'
        ];

        foreach ($mapping as $standard => $twitterTag) {
            if (isset($tags[$standard])) {
                $twitter[$twitterTag] = $tags[$standard];
            }
        }

        if (!isset($twitter['twitter:card'])) {
            $twitter['twitter:card'] = 'summary_large_image';
        }

        return $twitter;
    }

    /**
     * Cache metadata (stub: uses in-memory)
     */
    public function cacheMetadata(string $page, array $meta): bool
    {
        $this->metaStorage[$page] = $meta;
        return true;
    }

    // =========================================================================
    // STRUCTURED DATA (basic generation)
    // =========================================================================

    /**
     * Generate Schema.org structured data
     */
    public function generateSchema(string $type, array $data): array
    {
        if (!in_array($type, self::SCHEMA_TYPES)) {
            return ['error' => "Unsupported schema type: {$type}", 'stub_mode' => true];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $type
        ];

        $schema = array_merge($schema, $data);
        $this->stats['schemas_generated']++;

        return $schema;
    }

    /**
     * Validate schema (stub: always valid)
     */
    public function validateSchema(array $schema): bool
    {
        return true;
    }

    /**
     * Cache schema (in-memory)
     */
    public function cacheSchema(string $page, array $schema): bool
    {
        $this->schemaStorage[$page] = $schema;
        return true;
    }

    /**
     * Get all schemas for a page
     */
    public function getAllSchemas(string $page): array
    {
        $cached = $this->schemaStorage[$page] ?? null;
        if ($cached === null) {
            return [];
        }
        return is_array($cached) ? (isset($cached['@type']) ? [$cached] : $cached) : [];
    }

    // =========================================================================
    // SITEMAP MANAGEMENT (basic generation)
    // =========================================================================

    /**
     * Generate sitemap XML
     */
    public function generateSitemap(array $pages): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($page['url'] ?? '') . '</loc>' . "\n";

            if (isset($page['lastmod'])) {
                $xml .= '    <lastmod>' . date('c', $page['lastmod']) . '</lastmod>' . "\n";
            }

            if (isset($page['changefreq'])) {
                $xml .= '    <changefreq>' . htmlspecialchars($page['changefreq']) . '</changefreq>' . "\n";
            }

            if (isset($page['priority'])) {
                $xml .= '    <priority>' . number_format($page['priority'], 1) . '</priority>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';
        $this->stats['sitemaps_generated']++;

        return $xml;
    }

    /**
     * Update sitemap (stub: returns true but no persistence)
     */
    public function updateSitemap(string $page, string $action): bool
    {
        return true;
    }

    /**
     * Cache sitemap (stub: no persistence)
     */
    public function cacheSitemap(string $xml): bool
    {
        return true;
    }

    /**
     * Notify sitemap update (stub: no-op)
     */
    public function notifySitemapUpdate(): bool
    {
        return false;
    }

    // =========================================================================
    // ROBOTS.TXT MANAGEMENT (basic generation)
    // =========================================================================

    /**
     * Generate robots.txt content
     */
    public function generateRobotsTxt(array $rules): string
    {
        $content = '';

        foreach ($rules as $userAgent => $directives) {
            $content .= "User-agent: {$userAgent}\n";

            if (isset($directives['allow'])) {
                foreach ((array)$directives['allow'] as $path) {
                    $content .= "Allow: {$path}\n";
                }
            }

            if (isset($directives['disallow'])) {
                foreach ((array)$directives['disallow'] as $path) {
                    $content .= "Disallow: {$path}\n";
                }
            }

            $content .= "\n";
        }

        if ($this->config['sitemap_url']) {
            $content .= "Sitemap: " . $this->config['sitemap_url'] . "\n";
        }

        return $content;
    }

    /**
     * Cache robots.txt (stub: no persistence)
     */
    public function cacheRobotsTxt(string $content): bool
    {
        return true;
    }

    /**
     * Validate URL crawlability (basic check)
     */
    public function validateCrawlability(string $url): bool
    {
        return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    // =========================================================================
    // CANONICAL URL MANAGEMENT (in-memory)
    // =========================================================================

    /**
     * Set canonical URL (in-memory)
     */
    public function setCanonical(string $page, string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $this->canonicalStorage[$page] = $url;
        return true;
    }

    /**
     * Get canonical URL
     */
    public function getCanonical(string $page): ?string
    {
        return $this->canonicalStorage[$page] ?? null;
    }

    /**
     * Detect duplicates (stub: returns empty)
     */
    public function detectDuplicates(): array
    {
        return [];
    }

    // =========================================================================
    // GEO/AIO (all return empty/disabled responses)
    // =========================================================================

    /**
     * GEO is not enabled in stub mode
     */
    public function isGeoEnabled(): bool
    {
        return false;
    }

    /**
     * Generate AI meta (stub: not available)
     */
    public function generateAIMeta(string $contentId, string $content, array $options = []): array
    {
        return [
            'success' => false,
            'error' => 'GEO/AIO requires gcore-seo full with InferenceManager',
            'content_id' => $contentId,
            'stub_mode' => true
        ];
    }

    /**
     * Generate TL;DR (stub: returns empty)
     */
    public function generateTLDR(string $content, int $maxWords = 60): string
    {
        return '';
    }

    /**
     * Generate FAQ pairs (stub: returns empty)
     */
    public function generateFAQPairs(string $content, int $count = 5): array
    {
        return [];
    }

    /**
     * Generate AI description (stub: returns empty)
     */
    public function generateAIDescription(string $content, string $model = null): string
    {
        return '';
    }

    /**
     * Generate SPR (stub: returns empty)
     */
    public function generateSPR(string $content): string
    {
        return '';
    }

    /**
     * Extract entities (stub: returns empty)
     */
    public function extractEntities(string $content): array
    {
        return [];
    }

    /**
     * Link entities to Wikidata (stub: returns unchanged)
     */
    public function linkEntitiesToWikidata(array $entities): array
    {
        return $entities;
    }

    /**
     * Generate llms.txt (basic version without AI)
     */
    public function generateLLMsTxt(array $siteConfig, array $pages = []): string
    {
        $siteName = $siteConfig['name'] ?? 'Website';
        $siteDescription = $siteConfig['description'] ?? '';

        $content = "# {$siteName}\n\n";

        if ($siteDescription) {
            $content .= "> {$siteDescription}\n\n";
        }

        if (!empty($pages)) {
            $content .= "## Pages\n\n";
            foreach ($pages as $page) {
                $title = $page['title'] ?? 'Untitled';
                $url = $page['url'] ?? '#';
                $content .= "- [{$title}]({$url})\n";
            }
        }

        $this->stats['llms_txt_generated']++;
        return $content;
    }

    /**
     * Generate llms-full.txt (basic version)
     */
    public function generateLLMsFullTxt(array $siteConfig, array $pages = []): string
    {
        return $this->generateLLMsTxt($siteConfig, $pages);
    }

    /**
     * Generate LLM context (stub: not available)
     */
    public function generateLLMContext(string $contentId, string $content, array $options = []): array
    {
        return [
            'success' => false,
            'error' => 'LLM context generation requires the matching extension',
            'content_id' => $contentId,
            'stub_mode' => true
        ];
    }

    /**
     * Generate FAQPage schema (basic, no AI generation)
     */
    public function generateFAQPageSchema(array $faqPairs): array
    {
        if (empty($faqPairs)) {
            return [];
        }

        $mainEntity = [];

        foreach ($faqPairs as $faq) {
            if (empty($faq['q']) || empty($faq['a'])) {
                continue;
            }

            $mainEntity[] = [
                '@type' => 'Question',
                'name' => $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['a']
                ]
            ];
        }

        return $this->generateSchema('FAQPage', ['mainEntity' => $mainEntity]);
    }

    /**
     * Generate Article schema with entities (basic, no entity linking)
     */
    public function generateArticleSchemaWithEntities(array $article, array $entities = []): array
    {
        $schemaData = [
            'headline' => $article['title'] ?? '',
            'description' => $article['description'] ?? '',
            'datePublished' => $article['date_published'] ?? date('c'),
            'dateModified' => $article['date_modified'] ?? date('c'),
        ];

        if (!empty($article['author'])) {
            $schemaData['author'] = [
                '@type' => 'Person',
                'name' => $article['author']
            ];
        }

        if (!empty($article['image'])) {
            $schemaData['image'] = $article['image'];
        }

        if (!empty($article['url'])) {
            $schemaData['url'] = $article['url'];
        }

        return $this->generateSchema('Article', $schemaData);
    }

    /**
     * Invalidate GEO cache (stub: no-op)
     */
    public function invalidateGeoCache(string $contentId): bool
    {
        return true;
    }

    /**
     * Batch generate AI meta (stub: returns errors for all)
     */
    public function batchGenerateAIMeta(array $contents): array
    {
        $results = [];
        foreach ($contents as $item) {
            $id = $item['id'] ?? '';
            if ($id) {
                $results[$id] = [
                    'success' => false,
                    'error' => 'GEO/AIO requires the matching extension',
                    'stub_mode' => true
                ];
            }
        }
        return $results;
    }

    // =========================================================================
    // STATISTICS AND CAPABILITY
    // =========================================================================

    /**
     * Get SEO statistics
     */
    public function getStatistics(): array
    {
        return $this->stats;
    }

    /**
     * Get capability vector (minimal for stub)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // =========================================================================
    // MODULE INTERFACE
    // =========================================================================

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'stub_mode' => true,
            'mode' => 'stub',
            'gnode_enabled' => false,
            'geo_enabled' => false,
            'cache_enabled' => false,
            'statistics' => $this->stats,
            'capabilities' => $this->capabilityVector,
            'schema_types_supported' => count(self::SCHEMA_TYPES),
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-seo extension provides AI-powered SEO optimization with GEO/AIO',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
