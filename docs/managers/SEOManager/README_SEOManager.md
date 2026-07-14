# SEOManager

## Overview

SEOManager provides search engine optimization including meta tag management, structured data (Schema.org), sitemaps, robots.txt, and advanced GEO/AIO (Generative Engine Optimization / AI Optimization) features for AI discovery.

**Key Responsibilities:**
- Meta tag management (OpenGraph, Twitter Cards, standard meta)
- Schema.org structured data generation
- Sitemap and robots.txt generation
- Canonical URL management
- GEO/AIO features for AI discovery
  - TL;DR summary generation
  - FAQ pair extraction
  - Entity extraction with Wikidata linking
  - Compressed content representations
  - llms.txt generation
- Multi-tenant isolation via site_id/node_id
- gNode integration for caching and broadcast

## Architecture

```
SEOManager
    |
    +-- Traditional SEO
    |       +-- Meta Tags (OG, Twitter, standard)
    |       +-- Schema.org Structured Data
    |       +-- Sitemaps
    |       +-- Robots.txt
    |       +-- Canonical URLs
    |
    +-- GEO/AIO (AI Optimization)
    |       +-- TL;DR Summaries
    |       +-- FAQ Generation
    |       +-- Entity Extraction
    |       +-- Wikidata Linking
    |       +-- Content Compression
    |       +-- llms.txt Generation
    |
    +-- gNode Integration
            +-- Result Caching
            +-- Broadcast Notifications
```

## Initialization

```php
$gCore = \gCore\Modules\Core\gCore::getInstance();
$seo = $gCore->getService('SEOManager');

// Or with custom configuration
$seo->initialize([
    'site_id' => 'my-site',
    'node_id' => 'node1',
    'use_gnode' => true,
    'cache_enabled' => true,
    'default_ttl' => 3600,
    'enable_og' => true,
    'enable_twitter' => true,
    'enable_schema' => true,

    // GEO/AIO options
    'enable_geo' => true,
    'geo_model' => 'llama3',
    'geo_faq_count' => 5,
    'geo_tldr_max_words' => 60,
    'geo_cache_ttl' => 86400,
    'geo_wikidata_enabled' => true,
    'geo_spr_enabled' => true
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `site_id` | string | `default` | Multi-tenant identifier |
| `node_id` | string | `node1` | Node identifier |
| `use_gnode` | bool | `true` | Enable gNode integration |
| `cache_enabled` | bool | `true` | Enable caching |
| `default_ttl` | int | `3600` | Cache TTL (seconds) |
| `sitemap_url` | string | `/sitemap.xml` | Sitemap URL |
| `robots_url` | string | `/robots.txt` | Robots.txt URL |
| `enable_og` | bool | `true` | Generate OpenGraph tags |
| `enable_twitter` | bool | `true` | Generate Twitter Cards |
| `enable_schema` | bool | `true` | Generate Schema.org |
| `enable_geo` | bool | `true` | Enable GEO/AIO features |
| `geo_model` | string | `llama3` | LLM for GEO features |
| `geo_faq_count` | int | `5` | Default FAQ pairs |
| `geo_tldr_max_words` | int | `60` | TL;DR max words |
| `geo_cache_ttl` | int | `86400` | GEO content cache TTL |
| `geo_wikidata_enabled` | bool | `true` | Enable Wikidata linking |
| `geo_spr_enabled` | bool | `true` | Enable compressed representation generation |

## Public API

### Meta Tag Management

#### setMetaTags(string $page, array $tags)

Sets meta tags for a page (auto-generates OG and Twitter tags).

```php
$seo->setMetaTags('home', [
    'title' => 'Welcome to My Site',
    'description' => 'A great site for great things',
    'image' => 'https://example.com/og-image.jpg',
    'url' => 'https://example.com/'
]);
```

#### getMetaTags(string $page)

Retrieves cached meta tags for a page.

```php
$meta = $seo->getMetaTags('home');
// [
//     'page' => 'home',
//     'tags' => [...],
//     'og' => [...],
//     'twitter' => [...],
//     'updated_at' => 1704067200
// ]
```

#### generateOpenGraph(array $tags)

Generates OpenGraph tags from standard meta tags.

```php
$og = $seo->generateOpenGraph([
    'title' => 'Page Title',
    'description' => 'Page description',
    'image' => 'https://example.com/image.jpg'
]);
// ['og:title' => 'Page Title', 'og:description' => '...', ...]
```

#### generateTwitterCard(array $tags)

Generates Twitter Card tags.

```php
$twitter = $seo->generateTwitterCard([
    'title' => 'Page Title',
    'description' => 'Page description',
    'image' => 'https://example.com/image.jpg'
]);
// ['twitter:card' => 'summary_large_image', 'twitter:title' => '...', ...]
```

### Schema.org Structured Data

#### generateSchema(string $type, array $data)

Generates JSON-LD structured data.

```php
$schema = $seo->generateSchema('Article', [
    'headline' => 'My Article Title',
    'author' => ['@type' => 'Person', 'name' => 'John Doe'],
    'datePublished' => '2024-01-01',
    'image' => 'https://example.com/article-image.jpg'
]);
// [
//     '@context' => 'https://schema.org',
//     '@type' => 'Article',
//     'headline' => 'My Article Title',
//     ...
// ]
```

**Supported Schema Types:**
- Organization, WebSite, WebPage
- Article, BlogPosting
- Product, Review
- Person, Event
- BreadcrumbList, FAQPage
- HowTo, Recipe

#### cacheSchema(string $page, array $schema)

Caches schema for a page.

```php
$seo->cacheSchema('article-123', $schema);
```

#### getAllSchemas(string $page)

Gets all schemas for a page.

```php
$schemas = $seo->getAllSchemas('article-123');
```

### Sitemap Management

#### generateSitemap(array $pages)

Generates sitemap XML.

```php
$xml = $seo->generateSitemap([
    [
        'url' => 'https://example.com/',
        'lastmod' => time(),
        'changefreq' => 'daily',
        'priority' => 1.0
    ],
    [
        'url' => 'https://example.com/about',
        'lastmod' => time(),
        'changefreq' => 'monthly',
        'priority' => 0.8
    ]
]);
```

#### updateSitemap(string $page, string $action)

Updates sitemap with page action.

```php
$seo->updateSitemap('https://example.com/new-page', 'add');
$seo->updateSitemap('https://example.com/updated-page', 'update');
$seo->updateSitemap('https://example.com/deleted-page', 'remove');
```

#### cacheSitemap(string $xml)

Caches generated sitemap.

```php
$seo->cacheSitemap($xml);
```

### Robots.txt Management

#### generateRobotsTxt(array $rules)

Generates robots.txt content.

```php
$robots = $seo->generateRobotsTxt([
    '*' => [
        'allow' => ['/'],
        'disallow' => ['/admin', '/private']
    ],
    'Googlebot' => [
        'allow' => ['/'],
        'disallow' => ['/no-index']
    ]
]);
```

#### cacheRobotsTxt(string $content)

Caches robots.txt content.

```php
$seo->cacheRobotsTxt($robots);
```

### Canonical URL Management

#### setCanonical(string $page, string $url)

Sets canonical URL for a page.

```php
$seo->setCanonical('article-123', 'https://example.com/articles/my-article');
```

#### getCanonical(string $page)

Gets canonical URL.

```php
$canonical = $seo->getCanonical('article-123');
```

## GEO/AIO Features (AI Optimization)

### isGeoEnabled()

Checks if GEO features are available.

```php
if ($seo->isGeoEnabled()) {
    // InferenceManager available, GEO enabled
}
```

### generateAIMeta(string $contentId, string $content, array $options = [])

**Main entry point for GEO/AIO.** Generates AI metadata.

```php
$aiMeta = $seo->generateAIMeta('article-123', $articleContent, [
    'title' => 'Article Title',
    'url' => 'https://example.com/articles/my-article',
    'author' => 'John Doe',
    'model' => 'llama3',
    'faq_count' => 5
]);

// Returns:
// [
//     'success' => true,
//     'content_id' => 'article-123',
//     'generated_at' => 1704067200,
//     'model' => 'llama3',
//     'cached' => false,
//     'description' => 'AI-optimized description...',
//     'tldr' => 'Brief summary...',
//     'faq' => [
//         ['q' => 'What is...?', 'a' => 'It is...'],
//         ...
//     ],
//     'entities' => [
//         ['name' => 'Entity', 'type' => 'Technology', 'wikidata_id' => 'Q123'],
//         ...
//     ],
//     'spr' => 'Compressed content representation string...',
//     'metrics' => [
//         'generation_time' => 2.5,
//         'content_length' => 5000,
//         'faq_count' => 5,
//         'entity_count' => 8
//     ]
// ]
```

### generateTLDR(string $content, int $maxWords = 60)

Generates TL;DR summary.

```php
$tldr = $seo->generateTLDR($content, 60);
// "A concise, conversational summary of the content..."
```

### generateFAQPairs(string $content, int $count = 5)

Generates FAQ question-answer pairs.

```php
$faqs = $seo->generateFAQPairs($content, 5);
// [
//     ['q' => 'What is...?', 'a' => 'It is...'],
//     ['q' => 'How do you...?', 'a' => 'You can...'],
//     ...
// ]
```

### generateAIDescription(string $content, string $model = null)

Generates AI-optimized description.

```php
$desc = $seo->generateAIDescription($content);
// "A conversational, helpful description optimized for AI citation..."
```

### generateSPR(string $content)

Generates a compressed content representation for efficient AI consumption.

```php
$spr = $seo->generateSPR($content);
// "Compressed content representation string..."
```

### extractEntities(string $content)

Extracts named entities.

```php
$entities = $seo->extractEntities($content);
// [
//     ['name' => 'PHP', 'type' => 'Technology'],
//     ['name' => 'John Doe', 'type' => 'Person'],
//     ['name' => 'Anthropic', 'type' => 'Organization'],
//     ...
// ]
```

**Entity Types:** Person, Organization, Technology, Concept, Product, Place

### linkEntitiesToWikidata(array $entities)

Links entities to Wikidata Q-IDs.

```php
$linked = $seo->linkEntitiesToWikidata($entities);
// [
//     [
//         'name' => 'PHP',
//         'type' => 'Technology',
//         'wikidata_id' => 'Q59',
//         'wikidata_url' => 'https://www.wikidata.org/wiki/Q59'
//     ],
//     ...
// ]
```

### generateLLMsTxt(array $siteConfig, array $pages = [])

Generates llms.txt (llmstxt.org specification).

```php
$llmsTxt = $seo->generateLLMsTxt([
    'name' => 'My Website',
    'description' => 'A site about technology',
    'url' => 'https://example.com',
    'details' => 'Additional site information...'
], [
    ['title' => 'Home', 'url' => '/', 'type' => 'Pages', 'description' => 'Main page'],
    ['title' => 'About', 'url' => '/about', 'type' => 'Pages', 'description' => 'About us'],
    ['title' => 'API Docs', 'url' => '/docs', 'type' => 'Documentation', 'description' => 'API reference']
]);
```

### generateLLMsFullTxt(array $siteConfig, array $pages = [])

Generates extended llms-full.txt with content.

```php
$fullTxt = $seo->generateLLMsFullTxt($siteConfig, [
    [
        'title' => 'Article',
        'url' => '/article',
        'content' => 'Full article content...',
        'tldr' => 'Brief summary...',
        'spr' => 'Compressed representation...'
    ]
]);
```

### generateFAQPageSchema(array $faqPairs)

Generates FAQPage schema from FAQ pairs.

```php
$schema = $seo->generateFAQPageSchema($faqs);
// JSON-LD FAQPage structured data
```

### generateArticleSchemaWithEntities(array $article, array $entities = [])

Generates Article schema with entity mentions.

```php
$schema = $seo->generateArticleSchemaWithEntities([
    'title' => 'Article Title',
    'description' => 'Article description',
    'author' => 'John Doe',
    'date_published' => '2024-01-01',
    'image' => 'https://example.com/image.jpg',
    'url' => 'https://example.com/article'
], $entities);
```

### generateLLMContext(string $contentId, string $content, array $options = [])

Generates LLM context blob (for API).

```php
$context = $seo->generateLLMContext('article-123', $content, [
    'title' => 'Article Title',
    'url' => 'https://example.com/article',
    'include_content' => true
]);
```

### batchGenerateAIMeta(array $contents)

Batch AI metadata generation.

```php
$results = $seo->batchGenerateAIMeta([
    ['id' => 'article-1', 'content' => '...', 'options' => []],
    ['id' => 'article-2', 'content' => '...', 'options' => []]
]);
```

### invalidateGeoCache(string $contentId)

Invalidates GEO cache for content.

```php
$seo->invalidateGeoCache('article-123');
```

## Statistics

```php
$stats = $seo->getStatistics();
// [
//     'meta_tags_set' => 150,
//     'schemas_generated' => 100,
//     'sitemaps_generated' => 5,
//     'cache_hits' => 500,
//     'cache_misses' => 50,
//     'ai_meta_generated' => 80,
//     'faq_pairs_generated' => 400,
//     'entities_extracted' => 600,
//     'content_compressions' => 80,
//     'llms_txt_generated' => 3,
//     'wikidata_lookups' => 200
// ]
```

## Troubleshooting

### GEO features not working

1. Check `enable_geo` is `true`
2. Verify InferenceManager is initialized
3. Check Ollama is running and accessible
4. Verify `geo_model` is available

### Schema validation errors

1. Verify schema type is supported
2. Check required fields for schema type
3. Ensure valid URLs and dates

### Wikidata lookups slow

1. Cached for 7 days by default
2. Increase `geo_wikidata_cache_ttl`
3. Limit entity count in extraction

### High inference latency

1. Use cached results when possible
2. Reduce `geo_faq_count`
3. Use faster model
4. Enable gNode caching

## Dependencies

- **CacheManager**: Required for caching
- **InferenceManager**: Required for GEO/AIO features
- **ResourceManager**: Optional for templates
- **FormatManager**: Optional for schema validation
- **gNode-Client**: Optional for broadcast/caching

## Related Managers

- **InferenceManager**: LLM inference for GEO features
- **CacheManager**: Result caching
- **ResourceManager**: Template rendering
- **FormatManager**: Schema validation
