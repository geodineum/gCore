# TopologyManager

**Namespace**: `gCore\Modules\Managers\Base\TopologyManager`
**Implements**: `ModuleInterface`
**Access**: `gCore::getService('TopologyManager')`

TopologyManager is the site's view of its constellation's one topology (`{<c>}:gnode:constellation`). The free-tier stub keeps the interface and degrades its behaviour; the Pro implementation (`gCore\Topology\TopologyManagerPro`) registers the site and draws the topology.

## The model

- A constellation has one topology. Every layer is a shape: node (1), component (2), site (3.1), app (3.2), daemon (3.3), placements (4.1, 4.2).
- A site declares itself by name: a shape, an environment and axis words. The daemon encodes the words into coordinates and refuses a word it does not know; the manager never sends a number for an axis.
- Reads are plain reads through gNode-Client (sites hold a read-only ACL). Writes go through daemon commands.
- Discovery is a query over a layer: facets (axis to words) and axis ranges.

## Stub behaviour (free tier)

- `discoverServices`, `findServices`, `getServiceDetails`, `getLoadSequence` return empty results.
- `smartRegister`, `forceRegister`, `deregister`, `updateCapabilities` return `false`.
- `getDimensions()` returns the site-shape axes of the one topology (read-only, for UI only).
- `getTopologyVisualization` returns empty nodes and edges.

## Public API

Lifecycle: `getInstance`, `initialize`, `isInitialized`, `getStatus`, `getConfig`, `updateConfig`.

Registration: `smartRegister`, `forceRegister`, `getRegistrationStatus`, `deregister`, `updateCapabilities`, `refreshRegistration`, `isRegisteredInTopology`, `getCapabilityVector`.

Discovery: `discoverServices(array $requirements, int $limit = 10, string $layer = '3')` where `$requirements` maps an axis to a word, a list of words or a `[lo, hi]` range and `$layer` is a shape word or layer key; `findServices`, `getServiceDetails`, `getLoadSequence`.

Axes and view: `getCapabilityDimensions`, `getDimensions`, `getDimension`, `registerDimension`, `getTopologyVisualization`.

```php
$topology = gCore::getService('TopologyManager');

$topology->smartRegister(['protocol' => 'http'], ['name' => 'Shop']);

$apps = $topology->discoverServices(['protocol' => ['resp3']], 5, 'app');
```

## Contract

See `CONTRACT.md` (TopologyManager) and the gNode `CONTRACT.md`.
