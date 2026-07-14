# FormatManager Documentation

## Overview

FormatManager manages message format definitions, detection, and conversion. It provides a format registry with JSONSchema validation, pattern-based auto-detection, and bidirectional format transformation. All operations require gNode integration - there is no local fallback mode.

**Namespace**: `gCore\Modules\Managers\Base\FormatManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)
**Requires**: gNode integration (no local mode)

## Architecture

FormatManager operates as a **gNode-only format gateway**:

1. **Format Registration**: Store formats with JSONSchema and detection patterns
2. **Format Detection**: Auto-detect format from message content using regex patterns
3. **Format Conversion**: Bidirectional transformation between formats
4. **Format Validation**: Validate messages against format schemas

### Key Features

- JSONSchema Draft 7 format definitions
- Regex pattern-based format detection
- Bidirectional format conversion with validation
- Batch operations for bulk processing
- Detection confidence scoring
- Multi-tenant isolation (site_id/node_id)

## Initialization

```php
// Get FormatManager instance via gCore
$format = gCore::getService('FormatManager');

// Configuration (passed during gCore initialization)
$config = [
    'use_gnode' => true,
    'auto_detect' => true,
    'validation_mode' => 'strict',
    'cache_formats' => true,
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'gnode_client' => $gNodeClient
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of FormatManager.

#### `initialize(array $config = [], array $nodeMetadata = [], $gNodeClient = null): void`
Initializes the format system with configuration.
- **Throws**: `InitializationException` if initialization fails

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including gNode connection and metrics.

```php
[
    'initialized' => true,
    'use_gnode' => true,
    'gnode_connected' => true,
    'registered_formats' => 5,
    'metrics' => [...],
    'node_metadata' => [...],
    'capability_vector' => [...]
]
```

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

#### `getCapabilityVector(): array`
Get capability vector for gNode registration.

#### `getMetrics(): array`
Get format operation metrics.

```php
[
    'registrations' => 5,
    'detections' => 100,
    'conversions' => 50,
    'detection_hits' => 95,
    'detection_misses' => 5,
    'detection_rate' => 95.0,
    'conversion_failures' => 2,
    'conversion_success_rate' => 96.0,
    'mode' => 'gnode'
]
```

### Format Registration

#### `registerFormat(string $name, array $schema, array $patterns = [], array $metadata = []): array`
Register a custom message format with JSONSchema and detection patterns.
- **Parameters**:
  - `$name`: Format name (unique identifier)
  - `$schema`: JSONSchema Draft 7 specification
  - `$patterns`: Regex patterns for format detection
  - `$metadata`: Optional metadata (version, description)
- **Returns**: Result with registration status
- **Throws**: `StorageException`, `ValidationException`

```php
$result = $format->registerFormat('order_v1', [
    'type' => 'object',
    'properties' => [
        'order_id' => ['type' => 'string'],
        'items' => ['type' => 'array'],
        'total' => ['type' => 'number']
    ],
    'required' => ['order_id', 'items', 'total']
], [
    '/^ORDER-\d{8}-\d{4}/',          // Order ID pattern
    '/"order_id":\s*"ORDER-/'         // JSON structure pattern
], [
    'version' => '1.0.0',
    'description' => 'Order message format v1'
]);
```

#### `listFormats(): array`
List all registered formats.
- **Returns**: Array of format definitions
- **Throws**: `StorageException`

#### `getFormat(string $name): ?array`
Get a specific format definition by name.
- **Returns**: Format definition or null

#### `deleteFormat(string $name): bool`
Delete a registered format.
- **Returns**: Success status

### Format Detection

#### `detectFormat(string $message): array`
Detect message format using registered regex patterns.
- **Parameters**:
  - `$message`: Message content to analyze
- **Returns**: Detection result with confidence

```php
$detection = $format->detectFormat($jsonMessage);
// [
//     'format' => 'order_v1',
//     'confidence' => 0.95,
//     'pattern_matched' => '/^ORDER-\d{8}-\d{4}/'
// ]
```

#### `detectAndValidate(string $message): array`
Detect format and validate message against detected schema.
- **Returns**: Detection result with validation status

```php
$result = $format->detectAndValidate($jsonMessage);
// [
//     'format' => 'order_v1',
//     'confidence' => 0.95,
//     'pattern_matched' => '...',
//     'validation' => [
//         'valid' => true,
//         'errors' => []
//     ]
// ]
```

### Format Conversion

#### `convertFormat(string $message, string $sourceFormat, string $targetFormat, array $options = []): array`
Convert message from one format to another.
- **Parameters**:
  - `$message`: Message content to convert
  - `$sourceFormat`: Source format name
  - `$targetFormat`: Target format name
  - `$options`: Conversion options
    - `source_version`: Source format version
    - `target_version`: Target format version
    - `validate`: Validate before/after (default: true)
- **Returns**: Conversion result
- **Throws**: `StorageException`

```php
$result = $format->convertFormat(
    $jsonMessage,
    'order_v1',
    'order_v2',
    ['validate' => true]
);
// [
//     'converted_message' => '...',
//     'validation' => 'passed',
//     'source_format' => 'order_v1',
//     'target_format' => 'order_v2'
// ]
```

#### `autoConvertFormat(string $message, string $targetFormat, array $options = []): array`
Convert message with auto-detection of source format.
- **Returns**: Conversion result including detected source
- **Throws**: `StorageException`

```php
$result = $format->autoConvertFormat($message, 'order_v2');
// Automatically detects source format before conversion
```

### Format Validation

#### `validateMessage(string $message, string $formatName): array`
Validate message against a specific format's JSONSchema.
- **Returns**: Validation result

```php
$validation = $format->validateMessage($jsonMessage, 'order_v1');
// [
//     'valid' => true,
//     'errors' => []
// ]
```

### Bulk Operations

#### `registerFormats(array $formats): array`
Register multiple formats in a single batch operation.
- **Parameters**:
  - `$formats`: Array of format definitions
- **Returns**: Results with success/failure for each

```php
$results = $format->registerFormats([
    [
        'name' => 'order_v1',
        'schema' => [...],
        'patterns' => [...],
        'metadata' => [...]
    ],
    [
        'name' => 'payment_v1',
        'schema' => [...],
        'patterns' => [...]
    ]
]);
```

#### `detectFormats(array $messages): array`
Detect format for multiple messages in batch.
- **Returns**: Array of detection results

```php
$results = $format->detectFormats([
    $message1,
    $message2,
    $message3
]);
// [
//     0 => ['format' => 'order_v1', 'confidence' => 0.95],
//     1 => ['format' => 'payment_v1', 'confidence' => 0.88],
//     2 => ['format' => 'unknown', 'confidence' => 0.0]
// ]
```

## Usage Examples

### Registering a Format

```php
$format = gCore::getService('FormatManager');

// Register an order format with schema and detection patterns
$format->registerFormat('ecommerce_order', [
    'type' => 'object',
    'properties' => [
        'order_id' => [
            'type' => 'string',
            'pattern' => '^ORD-[0-9]{10}$'
        ],
        'customer' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email']
            ],
            'required' => ['id', 'email']
        ],
        'items' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1],
                    'price' => ['type' => 'number', 'minimum' => 0]
                ]
            ]
        ],
        'total' => ['type' => 'number', 'minimum' => 0]
    ],
    'required' => ['order_id', 'customer', 'items', 'total']
], [
    '/"order_id":\s*"ORD-\d{10}"/',
    '/^{"order_id":"ORD-/'
], [
    'version' => '1.0.0',
    'description' => 'E-commerce order message format'
]);
```

### Auto-Detection and Conversion

```php
// Incoming message with unknown format
$incomingMessage = '{"order_id":"ORD-0123456789","customer":{"id":"C123"},...}';

// Detect and convert to internal format
$result = $format->autoConvertFormat($incomingMessage, 'internal_order');

if ($result['validation'] === 'passed') {
    $converted = $result['converted_message'];
    processOrder($converted);
} else {
    logError('Conversion failed', $result);
}
```

### Format Pipeline

```php
// Process messages through format detection
function processMessage($message) {
    $format = gCore::getService('FormatManager');

    // Step 1: Detect format
    $detection = $format->detectFormat($message);

    if ($detection['format'] === 'unknown') {
        return ['error' => 'Unknown format'];
    }

    // Step 2: Validate against detected schema
    $validation = $format->validateMessage($message, $detection['format']);

    if (!$validation['valid']) {
        return ['error' => 'Validation failed', 'details' => $validation['errors']];
    }

    // Step 3: Convert to standard internal format
    $converted = $format->convertFormat(
        $message,
        $detection['format'],
        'internal_v1'
    );

    return ['success' => true, 'data' => $converted['converted_message']];
}
```

## Capability Vector

```php
[
    'format' => 1.0,
    'detection' => 0.9,
    'conversion' => 0.9,
    'validation' => 0.8
]
```

## Format Name Validation

- Allowed characters: `[a-zA-Z0-9_-]`
- Maximum length: 64 characters
- Cannot be empty

## Error Handling

All format operations throw `StorageException` when gNode is unavailable:

```php
try {
    $result = $format->detectFormat($message);
} catch (StorageException $e) {
    // gNode not available
    logError('Format operations require gNode: ' . $e->getMessage());
}
```

---

*Last Updated: January 2026*
