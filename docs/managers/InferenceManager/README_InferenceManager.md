# InferenceManager

## Overview

InferenceManager provides ML inference capabilities via Ollama integration. It supports text generation, multi-turn chat, vector embeddings, and batch operations with gNode-optimized caching for 25x performance improvement.

**Key Responsibilities:**
- Text generation with multiple LLM models
- Multi-turn chat with conversation context
- Vector embeddings for semantic search
- Batch inference operations
- Model management (list, info, pull)
- Security controls (rate limiting, prompt validation, audit logging)
- Result caching with gNode integration
- Multi-tenant isolation via site_id/node_id

## Architecture

```
InferenceManager
    |
    +-- OllamaClient (HTTP wrapper)
    |       +-- /api/generate
    |       +-- /api/chat
    |       +-- /api/embeddings
    |       +-- /api/tags
    |       +-- /api/show
    |       +-- /api/pull
    |
    +-- Security Layer
    |       +-- Prompt validation
    |       +-- Rate limiting
    |       +-- Model whitelist
    |       +-- Audit logging
    |
    +-- Cache Layer
    |       +-- Inference result caching
    |       +-- Embedding caching (30 days)
    |       +-- Model list caching
    |       +-- Conversation context
    |
    +-- gNode Integration
            +-- Batch operations
            +-- Capability registration
            +-- Error broadcasting
```

## Initialization

```php
$gCore = \gCore\Modules\Core\gCore::getInstance();
$inference = $gCore->getService('InferenceManager');

// Or with custom configuration
$inference->initialize([
    'site_id' => 'my-site',
    'node_id' => 'node1',
    'use_gnode' => true,

    // Ollama configuration
    'ollama_base_url' => 'http://localhost:11434/api',
    'ollama_timeout' => 1200, // 20 minutes for CPU
    'ollama_retry_count' => 3,
    'ollama_max_concurrent' => 4,

    // Security
    'allowed_models' => ['llama3', 'mistral', 'nomic-embed-text'],
    'rate_limit_per_minute' => 60,
    'enable_audit_logging' => true,

    // Cache
    'cache_enabled' => true,
    'cache_ttl_inference' => 3600,
    'cache_ttl_embedding' => 2592000
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `site_id` | string | `default` | Multi-tenant identifier |
| `node_id` | string | `node1` | Node identifier |
| `use_gnode` | bool | `true` | Enable gNode integration |
| `ollama_base_url` | string | `http://localhost:11434/api` | Ollama API URL |
| `ollama_timeout` | int | `1200` | Request timeout (seconds) |
| `ollama_verify_ssl` | bool | `false` | Verify SSL certificates |
| `ollama_streaming` | bool | `true` | Enable streaming responses |
| `ollama_retry_count` | int | `3` | Retry count for failed requests |
| `ollama_retry_delay` | float | `1.0` | Initial retry delay (seconds) |
| `ollama_max_concurrent` | int | `4` | Max concurrent requests |
| `require_auth` | bool | `false` | Require authentication |
| `allowed_models` | array | `[]` | Model whitelist (empty = all) |
| `max_tokens` | int | `2048` | Max tokens per request |
| `rate_limit_per_minute` | int | `60` | Rate limit per user |
| `enable_monitoring` | bool | `true` | Enable metrics recording |
| `enable_audit_logging` | bool | `true` | Enable audit logging |
| `cache_enabled` | bool | `true` | Enable result caching |
| `cache_ttl_inference` | int | `3600` | Inference cache TTL (1 hour) |
| `cache_ttl_embedding` | int | `2592000` | Embedding cache TTL (30 days) |

## Public API

### Text Generation

#### generateText(string $prompt, string $model = 'llama3', array $options = [])

Generates text completion with caching.

```php
$result = $inference->generateText('Explain quantum computing', 'llama3', [
    'temperature' => 0.7,
    'max_tokens' => 500
]);

// Success:
// [
//     'success' => true,
//     'result' => 'Quantum computing is...',
//     'metrics' => [
//         'total_duration_sec' => 2.5,
//         'prompt_tokens' => 10,
//         'completion_tokens' => 150,
//         'tokens_per_second' => 60.0
//     ],
//     'cached' => false,
//     'model' => 'llama3'
// ]

// Failure:
// [
//     'success' => false,
//     'error' => 'Rate limit exceeded',
//     'cached' => false
// ]
```

**Options:**
- `temperature`: Creativity (0.0-1.0)
- `max_tokens`: Maximum tokens to generate
- `top_p`: Nucleus sampling
- `top_k`: Top-k sampling
- `seed`: Random seed for reproducibility
- `stop`: Stop sequences

### Chat

#### chat(array $messages, string $model = 'llama3', array $options = [])

Multi-turn chat with conversation context.

```php
$result = $inference->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'What is PHP?'],
    ['role' => 'assistant', 'content' => 'PHP is a programming language...'],
    ['role' => 'user', 'content' => 'What frameworks does it have?']
], 'llama3', [
    'conversation_id' => 'conv_abc123' // Optional: continue conversation
]);

// [
//     'success' => true,
//     'result' => 'PHP has several frameworks...',
//     'conversation_id' => 'conv_abc123',
//     'metrics' => [...],
//     'model' => 'llama3',
//     'message_count' => 5
// ]
```

**Message Roles:**
- `system`: System prompt (first message)
- `user`: User input
- `assistant`: Assistant response

### Embeddings

#### generateEmbeddings(string $text, string $model = 'nomic-embed-text')

Generates vector embeddings for semantic search.

```php
$result = $inference->generateEmbeddings('The quick brown fox', 'nomic-embed-text');

// [
//     'success' => true,
//     'embedding' => [0.123, -0.456, 0.789, ...],
//     'dimensions' => 768,
//     'cached' => false,
//     'model' => 'nomic-embed-text'
// ]
```

**Note:** Embeddings are cached for 30 days (deterministic, immutable).

### Batch Operations

#### batchInference(array $prompts, string $model = 'llama3', array $options = [])

Batch inference using gNode for optimized performance.

```php
$prompts = [
    'Explain photosynthesis',
    'What is machine learning?',
    'Describe the water cycle'
];

$results = $inference->batchInference($prompts, 'llama3');

// [
//     'md5hash1' => ['success' => true, 'result' => '...', 'cached' => true],
//     'md5hash2' => ['success' => true, 'result' => '...', 'cached' => false],
//     'md5hash3' => ['success' => true, 'result' => '...', 'cached' => false]
// ]
```

**Requirements:** gNode integration must be enabled (`use_gnode = true`).

### Model Management

#### listAvailableModels(bool $useCache = true)

Lists available Ollama models.

```php
$models = $inference->listAvailableModels();
// [
//     ['name' => 'llama3:latest', 'size' => 4661234567, ...],
//     ['name' => 'mistral:latest', 'size' => 3825205248, ...],
//     ...
// ]
```

#### getModelInfo(string $model)

Gets detailed model information.

```php
$info = $inference->getModelInfo('llama3');
// [
//     'modelfile' => '...',
//     'parameters' => '...',
//     'template' => '...',
//     ...
// ]
```

#### pullModel(string $model, callable $progressCallback = null)

Downloads a model from Ollama registry.

```php
// Without progress
$success = $inference->pullModel('llama3');

// With progress callback
$success = $inference->pullModel('llama3', function($progress) {
    echo "Downloaded: " . $progress['completed'] . "/" . $progress['total'] . "\n";
});
```

### Status

#### getStatus()

Returns manager status.

```php
$status = $inference->getStatus();
// [
//     'initialized' => true,
//     'gnode_enabled' => true,
//     'ollama_url' => 'http://localhost:11434/api',
//     'ollama_connected' => true,
//     'statistics' => [
//         'inferences_total' => 150,
//         'inferences_cached' => 50,
//         'inferences_generated' => 100,
//         'embeddings_generated' => 30,
//         'cache_hits' => 80,
//         'cache_misses' => 100,
//         'errors' => 2,
//         'rate_limit_hits' => 5
//     ],
//     'site_id' => 'my-site',
//     'node_id' => 'node1'
// ]
```

## Security

### Prompt Validation

All prompts are validated for:
- Maximum length (32,000 characters)
- Suspicious patterns (shell commands, code injection, SQL)

```php
// These will throw ValidationException:
$inference->generateText(str_repeat('x', 50000)); // Too long
$inference->generateText('exec("rm -rf /")'); // Suspicious pattern
```

### Rate Limiting

Per-user rate limiting (sliding window):

```php
// After 60 requests in 1 minute:
// ValidationException: Rate limit exceeded: 60 requests per minute
```

### Model Whitelist

Restrict to specific models:

```php
$inference->initialize([
    'allowed_models' => ['llama3', 'mistral']
]);

// This will throw ValidationException:
$inference->generateText('Hello', 'codellama');
// "Model not in allowed list: codellama"
```

### Audit Logging

All requests logged with:
- Model used
- Prompt hash (not content)
- Prompt length
- Success/failure
- Cached status
- Timestamp

## Cache Strategy

| Content Type | TTL | Rationale |
|--------------|-----|-----------|
| Inference results | 1 hour | Balance freshness and performance |
| Embeddings | 30 days | Deterministic, immutable |
| Model list | 5 minutes | Rarely changes |
| Conversations | 24 hours | Session-based context |

Cache keys include:
- Site ID
- Content type
- Model
- Content hash
- Options hash

## Metrics

When MetricsManager is available:
- Inference duration
- Token counts
- Cache hit/miss rates
- Error rates
- Rate limit violations

## Error Handling

All errors are:
1. Logged via SelfContainedErrorHandler
2. Broadcast via ErrorManager (if gNode enabled)
3. Returned in result array with `success: false`

```php
$result = $inference->generateText('Hello');
if (!$result['success']) {
    // Handle error
    error_log('Inference failed: ' . $result['error']);
}
```

## Troubleshooting

### Ollama connection fails

1. Verify Ollama is running: `curl http://localhost:11434/api/version`
2. Check firewall allows port 11434
3. Verify `ollama_base_url` configuration

### Slow inference

1. CPU inference is slow (10-60 tokens/sec)
2. GPU dramatically improves speed
3. Use smaller models for faster response
4. Increase `ollama_timeout` for large prompts

### Rate limit errors

1. Increase `rate_limit_per_minute`
2. Implement request queuing
3. Use batch operations for multiple prompts

### Cache misses

1. Verify `cache_enabled` is true
2. Check CacheManager is initialized
3. Different options create different cache keys

### Model not found

1. Pull the model: `ollama pull llama3`
2. Or use `$inference->pullModel('llama3')`
3. Check `allowed_models` whitelist

## OllamaClient

Internal HTTP client for Ollama API:

```php
// Direct usage (not recommended - use InferenceManager)
$client = new OllamaClient([
    'base_url' => 'http://localhost:11434/api',
    'timeout' => 1200,
    'retry_count' => 3
]);

$response = $client->generate('llama3', 'Hello world');
$models = $client->listModels();
$version = $client->getVersion();
```

**Features:**
- Automatic retry with exponential backoff
- Streaming response handling
- Error handling with retries

## Dependencies

- **CacheManager**: Required for result caching
- **MetricsManager**: Optional for metrics recording
- **ErrorManager**: Optional for error broadcasting
- **FormatManager**: Optional for structured output validation
- **gNode-Client**: Optional for batch operations and capability registration
- **curl**: Required for HTTP communication

## Related Managers

- **SEOManager**: Uses InferenceManager for GEO/AIO features
- **CacheManager**: Result caching
- **MetricsManager**: Performance tracking
- **ErrorManager**: Error broadcasting
