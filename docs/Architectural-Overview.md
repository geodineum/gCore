# gCore Architectural Overview

## Introduction

gCore is a PHP framework of managers behind one container. It holds no local state: everything lives in ValKey, and a site finds other services through the constellation's one topology, which the gNode daemon maintains.

## Principles

### 1. One topology per constellation

A constellation has one topology, `{<c>}:gnode:constellation`. Nodes, components, sites, apps, daemons and their placements are records of it. A site declares itself by name (shape, environment, axis words); the daemon encodes the declaration and refuses a word it does not know. Cross-service lookup is a query over a layer by facet and axis range. Sites read the topology with plain reads (read-only ACL); every write is a daemon command.

Resolving a manager inside the process (`getService`) is an ordinary local lookup and does not use the topology.

### 2. The Manager Quartet

1. **SecurityManager**: authentication, authorization, protection
2. **ErrorManager**: error tracking, logging, notification
3. **CacheManager**: distributed caching and state
4. **APIManager**: API server and client

They operate without circular dependencies and are the foundation the other managers build on.

### 3. Trait composition

Manager functionality is composed from traits loaded from configuration rather than inherited through deep hierarchies.

### 4. Zero local state

All state is externalised to ValKey: services are stateless, add nodes without coordination, and use Lua scripts for atomic multi-key operations.

### 5. Per-site isolation

Every key is namespaced by site; a site's ACL user reaches its own keyspace and reads the constellation topology.

## System layers

```
Application layer
  gCore layer: SecurityManager | ErrorManager | CacheManager | APIManager (+ other managers)
    gNode-Client (optional package)
      gNode daemon  <->  ValKey (Lua scripts, connections, streams, keys)
```

## Flow of operations

1. **Initialization**: gCore loads configuration, injects gNode-Client early, and initializes services in `priority` order.
2. **Registration**: with the Pro TopologyManager, the site registers itself by name on the one topology.
3. **Discovery**: a lookup names a layer, facets and axis ranges; the daemon answers from its indexes.
4. **Request processing**: APIManager receives the request, passes it through the middleware pipeline, dispatches to a handler and returns the response through the middleware.
5. **Storage**: CacheManager provides distributed storage through the ValKey connection pool, with circuit breaker and adaptive backoff.

## Resilience

- **Circuit breakers** isolate a failing dependency.
- **Adaptive backoff** spaces retries.
- **Connection pooling** adapts to the workload.
- **Rate limiting** is applied per client and route by APIManager.

## Daemon-backed operations

Operations that need the daemon (registration, discovery, templates, relay) go through the optional `gcore/gnode-client` package over ValKey streams:

```php
$apps = $gNode->query([
    'layer' => 'app',
    'facets' => ['protocol' => ['resp3']],
]);
```
