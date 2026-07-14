# gCore Architectural Overview

## Introduction

gCore is a PHP framework built on mathematical principles rather than traditional architectural patterns. By representing services and capabilities as points in n-dimensional space, it enables dynamic discovery, composition, and orchestration of software components.

## Core Architectural Principles

### 1. Geometric Topology for Service Discovery

Traditional frameworks rely on explicit dependency injection or service locators. gCore instead models services as points in an n-dimensional capability space, where each dimension represents a specific capability (e.g., logging, caching, security). This enables:

- **O(1) Service Discovery**: Find services that satisfy capability requirements in constant time
- **Natural Service Clustering**: Related services naturally cluster in capability space
- **Capability-Based Composition**: Components connect based on capabilities, not names
- **Emergent Behavior**: System behavior emerges from service interactions in capability space

### 2. The Manager Quartet

Four core managers form the foundation of the gCore ecosystem:

1. **SecurityManager**: Authentication, authorization, and protection
2. **ErrorManager**: Error tracking, logging, and notification
3. **CacheManager**: Distributed caching and state management
4. **APIManager**: API server and client capabilities

These managers are carefully designed to operate independently without circular dependencies, while providing a foundation for applications.

### 3. Trait Capability Manifold

Instead of monolithic inheritance hierarchies, gCore implements capabilities as sections of a mathematical manifold:

- **Trait Self-Organization**: Traits organize themselves within capability space
- **Emergent Capability Regions**: Natural capability boundaries emerge from trait clusters
- **Mathematical Transformations**: Runtime capability transformations for different contexts
- **Geometric Proximity Discovery**: Traits are discovered through proximity in capability space

### 4. Zero Local State Architecture

All service state is externalized to ValKey (Redis-compatible), enabling:

- **Stateless Services**: Services maintain no local state
- **Linear Horizontal Scaling**: Add nodes without coordination overhead
- **Perfect Fault Isolation**: Failures are contained without cascading
- **Mathematical Consistency**: Atomic operations through Lua scripts
- **Natural Load Distribution**: Work distributes through geometric proximity

### 5. Multi-Tenant Isolation Through Mathematics

Multiple tenants are isolated through mathematical transforms:

- **Capability Boundary Transforms**: Tenant-specific capability boundary enforcement
- **Vector Space Projections**: Tenants operate in isolated subspaces
- **Key Space Partitioning**: Prefix-based isolation with mathematical guarantees
- **Orthogonal Permission Vectors**: Cross-tenant authorization through vector operations

## System Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                      Application Layer                       │
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│                        gCore Layer                           │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │
│  │SecurityManager│  │ErrorManager │  │CacheManager │  │APIManager   │  │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  │
│         │               │               │               │         │
│  ┌──────▼───────────────▼───────────────▼───────────────▼──────┐  │
│  │                  Trait Capability Manifold                   │  │
│  └──────────────────────────┬───────────────────────────────────┘  │
│                             │                                      │
│  ┌──────────────────────────▼───────────────────────────────────┐  │
│  │                   Geometric Topology                          │  │
│  └──────────────────────────┬───────────────────────────────────┘  │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────┐
│                      ValKey/Redis Layer                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │
│  │ Lua Scripts │  │ Connections │  │  Streams   │  │    Keys     │  │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### Flow of Operations

1. **Application Initialization**:
   - gCore loads and parses configuration
   - Geometric topology is initialized
   - Base services are registered in capability space
   - Optimal initialization sequence is determined
   - Services are initialized in mathematical dependency order

2. **Service Discovery**:
   - Capability requirements are expressed as vectors
   - Geometric intersection finds matching services
   - Optimal service is selected based on capability proximity

3. **Trait Loading**:
   - Traits register themselves in capability space
   - Trait dependencies are resolved through geometric operations
   - Traits are loaded in mathematically optimal sequence
   - Trait initialization verifies capability boundary integrity

4. **Request Processing**:
   - APIManager receives requests through HTTP or WebSocket
   - Request passes through middleware pipeline
   - Appropriate handlers are dispatched based on routing rules
   - Response is generated and passed back through middleware

5. **Storage Operations**:
   - CacheManager provides distributed storage access
   - ValKey connection pool manages connections efficiently
   - Script-based atomic operations ensure consistency
   - Circuit breaker and adaptive backoff provide resilience

## The Mathematical Foundation

gCore's approach is built on the following mathematical principles:

### 1. Vector Spaces and Metrics

Services and traits are represented as vectors in a capability space. The distance between two services A and B is calculated through a weighted Euclidean metric:

```
distance(A, B) = sqrt(Σ(w_i * (A_i - B_i)²))
```

Where:
- A_i and B_i are capability values in dimension i
- w_i is the weight of dimension i

### 2. Hyperplane Partitioning

Capability requirements form hyperplanes that partition the space:

```
{ p ∈ R^n | p_i ≥ r_i for all i in required capabilities }
```

Where:
- p is a point in n-dimensional space
- r_i is the required value for capability i

### 3. Optimal Path Finding

Load sequences are determined through directed acyclic graph traversal with topological sorting, ensuring services are loaded in dependency order with O(V+E) complexity.

### 4. Boundary Transforms for Multi-Tenancy

Tenant isolation is achieved through boundary transforms:

```
B_tenant(p) = T_tenant * p
```

Where:
- B_tenant is the boundary function for a tenant
- T_tenant is a transform matrix specific to the tenant
- p is a point in capability space

## Real-World Implementation

The mathematical elegance translates to practical implementation:

### Rust-Powered Geometric Core

The geometric capabilities are implemented in Rust inside the gNode daemon and
reached over ValKey streams through the optional `gcore/gnode-client` package:

```php
// PHP interface to the gNode daemon
$topology = $adapter->findServices([
    'logging' => 0.8,
    'security' => 0.7,
    'caching' => 0.5
]);
```

### Script-Based Distributed Operations

Complex distributed operations are implemented as ValKey/Redis Lua scripts:

```lua
-- Atomic batch operation in Lua
local function batch_operations(KEYS, ARGV)
    local ops = cjson.decode(ARGV[1])
    local results = {}
    
    for i, op in ipairs(ops) do
        -- Execute operation atomically
        results[i] = execute_operation(op.type, op.keys, op.args)
    end
    
    return cjson.encode(results)
end
```

### Trait Loading System

The trait loading system automatically discovers and loads traits based on capabilities:

```php
// Find traits with specific capabilities
$traits = $traitLoader->findTraitsWithCapabilities([
    'logging' => 0.7,
    'error_handling' => 0.5
]);

// Load traits in optimal order
foreach ($traitLoader->getOptimalLoadSequence() as $trait) {
    $manager->loadTrait($trait);
}
```

## Advanced Architectural Features

### 1. Self-Healing Architecture

The system can automatically recover from failures:

- **Circuit Breakers**: Automatic service isolation during failures
- **Adaptive Backoff**: Intelligent retry mechanisms with exponential backoff
- **Service Health Monitoring**: Continuous health assessment in capability space
- **Automatic Failover**: Services naturally shift to backup providers through capability matching

### 2. Capability-Based Security

Security is implemented through capability verification:

```php
// Check if system has required capability level
if ($securityManager->checkCapability('encryption', 0.9)) {
    // System meets strong encryption requirements
}
```

### 3. Mathematical Rate Limiting

Rate limiting is implemented through leaky bucket algorithms with mathematical guarantees:

```php
// Check rate limit with mathematical bounds
if ($apiManager->isRateLimited($clientId, $route)) {
    // Rate exceeded with mathematical certainty
}
```

### 4. Adaptive Connection Management

Connection pools automatically adapt to workloads:

```php
// Get optimal connection from pool
$pool->executeWithConnection(function($conn) {
    // Operation using optimally selected connection
});
```

## Performance Characteristics

The mathematical approach delivers exceptional performance:

1. **O(1) Service Discovery**: Find services in constant time regardless of system size
2. **O(log n) Key Operations**: ValKey provides logarithmic complexity for most operations
3. **O(1) Capability Checks**: Verify capabilities with constant-time complexity
4. **Linear Horizontal Scaling**: Performance scales linearly with added nodes
5. **Zero-Coordination Clustering**: No coordination overhead for distributed operations

## Conclusion

gCore applies mathematical principles to solve software engineering challenges. By modeling software components as points in capability space, it achieves flexible service discovery, performance, and resilience while maintaining architectural simplicity.

The system's mathematical foundation provides not just theoretical elegance but practical benefits: more efficient service discovery, natural fault isolation, transparent scaling, and emergent behaviors that would be difficult or impossible to achieve with traditional architectural approaches.

---

*Created: March 2025*