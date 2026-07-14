<?php
declare(strict_types=1);
/**
 * SEOManager Interface
 *
 * Contract for SEO optimization including meta tags, structured data, sitemaps,
 * robots.txt, and GEO/AIO (Generative Engine Optimization).
 *
 * Extension implementations provide:
 * - Meta tag management (OpenGraph, Twitter Cards)
 * - Structured data generation (Schema.org)
 * - Sitemap and robots.txt management
 * - AI-powered content optimization (GEO/AIO)
 * - Entity extraction and Wikidata linking
 * - llms.txt generation
 *
 * Default stubs provide basic meta tag handling without AI features.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface SEOManagerInterface
 *
 * Defines the contract for SEO operations.
 * Implementations may use gNode for caching (extension) or
 * provide basic no-op stubs for graceful degradation (default).
 */
interface SEOManagerInterface extends ModuleInterface
{
    // =========================================================================
    // META TAG MANAGEMENT
    // =========================================================================

    /**
     * Set meta tags for a page
     *
     * @param string $page Page identifier
     * @param array $tags Meta tags array (title, description, image, etc.)
     * @return bool Success status
     */
    public function setMetaTags(string $page, array $tags): bool;

    /**
     * Get meta tags for a page
     *
     * @param string $page Page identifier
     * @return array|null Meta tags or null if not found
     */
    public function getMetaTags(string $page): ?array;

    /**
     * Generate OpenGraph tags from standard meta tags
     *
     * @param array $tags Standard meta tags
     * @return array OpenGraph tags
     */
    public function generateOpenGraph(array $tags): array;

    /**
     * Generate Twitter Card tags from standard meta tags
     *
     * @param array $tags Standard meta tags
     * @return array Twitter Card tags
     */
    public function generateTwitterCard(array $tags): array;

    /**
     * Cache metadata for a page
     *
     * @param string $page Page identifier
     * @param array $meta Metadata to cache
     * @return bool Success status
     */
    public function cacheMetadata(string $page, array $meta): bool;

    // =========================================================================
    // STRUCTURED DATA (Schema.org)
    // =========================================================================

    /**
     * Generate Schema.org structured data
     *
     * @param string $type Schema type (Article, Product, FAQPage, etc.)
     * @param array $data Schema data
     * @return array Structured data in JSON-LD format
     */
    public function generateSchema(string $type, array $data): array;

    /**
     * Validate schema against registered format
     *
     * @param array $schema Schema to validate
     * @return bool True if valid
     */
    public function validateSchema(array $schema): bool;

    /**
     * Cache schema for a page
     *
     * @param string $page Page identifier
     * @param array $schema Schema data
     * @return bool Success status
     */
    public function cacheSchema(string $page, array $schema): bool;

    /**
     * Get all schemas for a page
     *
     * @param string $page Page identifier
     * @return array Array of schemas
     */
    public function getAllSchemas(string $page): array;

    // =========================================================================
    // SITEMAP MANAGEMENT
    // =========================================================================

    /**
     * Generate sitemap XML from pages array
     *
     * @param array $pages Array of page data (url, lastmod, changefreq, priority)
     * @return string Sitemap XML
     */
    public function generateSitemap(array $pages): string;

    /**
     * Update sitemap with page action
     *
     * @param string $page Page URL
     * @param string $action Action (add, update, remove)
     * @return bool Success status
     */
    public function updateSitemap(string $page, string $action): bool;

    /**
     * Cache sitemap
     *
     * @param string $xml Sitemap XML
     * @return bool Success status
     */
    public function cacheSitemap(string $xml): bool;

    /**
     * Notify sitemap update via broadcast
     *
     * @return bool Success status
     */
    public function notifySitemapUpdate(): bool;

    // =========================================================================
    // ROBOTS.TXT MANAGEMENT
    // =========================================================================

    /**
     * Generate robots.txt content
     *
     * @param array $rules Array of robots.txt rules
     * @return string Robots.txt content
     */
    public function generateRobotsTxt(array $rules): string;

    /**
     * Cache robots.txt content
     *
     * @param string $content Robots.txt content
     * @return bool Success status
     */
    public function cacheRobotsTxt(string $content): bool;

    /**
     * Validate URL crawlability
     *
     * @param string $url URL to check
     * @return bool True if crawlable
     */
    public function validateCrawlability(string $url): bool;

    // =========================================================================
    // CANONICAL URL MANAGEMENT
    // =========================================================================

    /**
     * Set canonical URL for a page
     *
     * @param string $page Page identifier
     * @param string $url Canonical URL
     * @return bool Success status
     */
    public function setCanonical(string $page, string $url): bool;

    /**
     * Get canonical URL for a page
     *
     * @param string $page Page identifier
     * @return string|null Canonical URL or null if not set
     */
    public function getCanonical(string $page): ?string;

    /**
     * Detect duplicate pages
     *
     * @return array Array of potential duplicates
     */
    public function detectDuplicates(): array;

    // =========================================================================
    // GEO/AIO (GENERATIVE ENGINE OPTIMIZATION)
    // =========================================================================

    /**
     * Check if GEO/AIO features are available
     *
     * @return bool True if InferenceManager is available and GEO is enabled
     */
    public function isGeoEnabled(): bool;

    /**
     * Generate AI metadata for content
     *
     * @param string $contentId Unique content identifier
     * @param string $content The content to analyze
     * @param array $options Optional settings (title, url, author, model, etc.)
     * @return array Complete AI metadata package (tldr, faq, entities, spr, description)
     */
    public function generateAIMeta(string $contentId, string $content, array $options = []): array;

    /**
     * Generate TL;DR summary for content
     *
     * @param string $content Content to summarize
     * @param int $maxWords Maximum words (default: 60)
     * @return string TL;DR summary
     */
    public function generateTLDR(string $content, int $maxWords = 60): string;

    /**
     * Generate FAQ pairs from content
     *
     * @param string $content Content to analyze
     * @param int $count Number of FAQ pairs to generate
     * @return array Array of ['q' => question, 'a' => answer]
     */
    public function generateFAQPairs(string $content, int $count = 5): array;

    /**
     * Generate AI-optimized description
     *
     * @param string $content Content to describe
     * @param string|null $model Model to use
     * @return string AI-optimized description
     */
    public function generateAIDescription(string $content, string $model = null): string;

    /**
     * Generate Sparse Priming Representation (SPR)
     *
     * @param string $content Content to compress
     * @return string SPR-formatted content
     */
    public function generateSPR(string $content): string;

    /**
     * Extract entities from content
     *
     * @param string $content Content to analyze
     * @return array Array of entities with type and name
     */
    public function extractEntities(string $content): array;

    /**
     * Link entities to Wikidata Q-IDs
     *
     * @param array $entities Entities to link
     * @return array Entities with wikidata_id added
     */
    public function linkEntitiesToWikidata(array $entities): array;

    /**
     * Generate llms.txt content for site
     *
     * @param array $siteConfig Site configuration (name, description, url)
     * @param array $pages Array of page data for linking
     * @return string llms.txt content in Markdown
     */
    public function generateLLMsTxt(array $siteConfig, array $pages = []): string;

    /**
     * Generate llms-full.txt content
     *
     * @param array $siteConfig Site configuration
     * @param array $pages Array of pages with full content
     * @return string llms-full.txt content
     */
    public function generateLLMsFullTxt(array $siteConfig, array $pages = []): string;

    /**
     * Generate complete LLM context for content
     *
     * @param string $contentId Content identifier
     * @param string $content Full content
     * @param array $options Additional options
     * @return array LLM context data
     */
    public function generateLLMContext(string $contentId, string $content, array $options = []): array;

    /**
     * Generate FAQPage schema from FAQ pairs
     *
     * @param array $faqPairs Array of ['q' => question, 'a' => answer]
     * @return array FAQPage schema
     */
    public function generateFAQPageSchema(array $faqPairs): array;

    /**
     * Generate Article schema with entity mentions
     *
     * @param array $article Article data
     * @param array $entities Extracted entities
     * @return array Article schema with entity links
     */
    public function generateArticleSchemaWithEntities(array $article, array $entities = []): array;

    /**
     * Invalidate GEO cache for content
     *
     * @param string $contentId Content identifier
     * @return bool Success status
     */
    public function invalidateGeoCache(string $contentId): bool;

    /**
     * Batch generate AI metadata for multiple contents
     *
     * @param array $contents Array of ['id' => string, 'content' => string, 'options' => array]
     * @return array Results keyed by content ID
     */
    public function batchGenerateAIMeta(array $contents): array;

    // =========================================================================
    // STATISTICS AND CAPABILITY
    // =========================================================================

    /**
     * Get SEO statistics
     *
     * @return array SEO statistics
     */
    public function getStatistics(): array;

    /**
     * Get capability vector for GeometricTopology service discovery
     *
     * @return array Capability vector with dimension scores (0.0-1.0)
     */
    public function getCapabilityVector(): array;
}
