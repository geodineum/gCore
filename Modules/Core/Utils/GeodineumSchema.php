<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Utils;

/**
 * GeodineumSchema — Convention-over-configuration schema publisher for PHP.
 *
 * Reads config/schemas/*.yaml contract files and publishes them to ValKey
 * for runtime discovery by other components and agentic AI.
 *
 * Usage (one-liner in gCore boot):
 *   GeodineumSchema::publish($storage, $siteId, __DIR__ . '/config/schemas/contracts');
 *
 * ValKey keys:
 *   {site_id}:gnode:schema:{component}:{contract_name} — full contract JSON
 *   {site_id}:gnode:schema:_index — JSON array of all registered schema keys
 */
class GeodineumSchema
{
    /**
     * Publish all YAML contracts from a directory to ValKey.
     *
     * Resolves {site_id} and {env} placeholders. Merges into the _index
     * discovery key (idempotent — safe to call on every request/boot).
     *
     * @param \gCore\gNode\Storage\ValKeyStorage $storage ValKey storage instance
     * @param string $siteId Site identifier
     * @param string $schemasDir Path to schemas directory
     * @param string $environment Environment name (default: 'production')
     * @return array ['published' => int, 'errors' => int]
     */
    public static function publish($storage, string $siteId, string $schemasDir, string $environment = 'production'): array
    {
        $published = 0;
        $errors = 0;

        if (!is_dir($schemasDir)) {
            return ['published' => 0, 'errors' => 0, 'message' => 'Directory not found: ' . $schemasDir];
        }

        $files = glob($schemasDir . '/*.yaml') ?: [];
        $files = array_merge($files, glob($schemasDir . '/*.yml') ?: []);

        foreach ($files as $file) {
            $fname = basename($file);

            // Skip meta-files (starting with _)
            if (strpos($fname, '_') === 0) {
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                $errors++;
                continue;
            }

            // Parse YAML
            $data = yaml_parse($contents);
            if ($data === false || !isset($data['contract'])) {
                // Try without yaml extension using simple parser
                $data = self::parseSimpleYaml($contents);
                if ($data === null || !isset($data['contract'])) {
                    $errors++;
                    continue;
                }
            }

            $contract = $data['contract'];
            $name = $contract['name'] ?? null;
            $component = $contract['component'] ?? null;

            if (!$name || !$component) {
                $errors++;
                continue;
            }

            // Resolve placeholders
            $json = json_encode($contract);
            $json = str_replace('{site_id}', $siteId, $json);
            $json = str_replace('{env}', $environment, $json);

            $key = "{$siteId}:gnode:schema:{$component}:{$name}";

            try {
                $storage->set($key, $json);

                // Merge into discovery index
                $indexKey = "{$siteId}:gnode:schema:_index";
                $entry = "{$component}:{$name}";

                $existing = $storage->redis->get($indexKey);
                $allSchemas = $existing ? json_decode($existing, true) : [];
                if (!is_array($allSchemas)) {
                    $allSchemas = [];
                }

                if (!in_array($entry, $allSchemas, true)) {
                    $allSchemas[] = $entry;
                    $storage->redis->set($indexKey, json_encode($allSchemas));
                }

                $published++;
            } catch (\Exception $e) {
                $errors++;
            }
        }

        return ['published' => $published, 'errors' => $errors];
    }

    /**
     * Read a specific contract from ValKey.
     *
     * @param \gCore\gNode\Storage\ValKeyStorage $storage
     * @param string $siteId
     * @param string $component
     * @param string $contractName
     * @return array|null Decoded contract or null
     */
    public static function getContract($storage, string $siteId, string $component, string $contractName): ?array
    {
        $key = "{$siteId}:gnode:schema:{$component}:{$contractName}";
        $json = $storage->redis->get($key);
        return $json ? json_decode($json, true) : null;
    }

    /**
     * List all registered contracts from the discovery index.
     *
     * @param \gCore\gNode\Storage\ValKeyStorage $storage
     * @param string $siteId
     * @return array List of "{component}:{contract_name}" strings
     */
    public static function listContracts($storage, string $siteId): array
    {
        $indexKey = "{$siteId}:gnode:schema:_index";
        $json = $storage->redis->get($indexKey);
        return $json ? (json_decode($json, true) ?: []) : [];
    }

    /**
     * Minimal YAML parser for contract files when ext-yaml is not available.
     * Only handles the subset needed for contract YAML (flat key:value pairs).
     */
    private static function parseSimpleYaml(string $contents): ?array
    {
        // If ext-yaml is available, prefer it
        if (function_exists('yaml_parse')) {
            $result = yaml_parse($contents);
            return $result !== false ? $result : null;
        }

        // Fallback: use Symfony YAML if available via Composer
        if (class_exists('Symfony\Component\Yaml\Yaml')) {
            try {
                return \Symfony\Component\Yaml\Yaml::parse($contents);
            } catch (\Exception $e) {
                error_log("[gCore] GeodineumSchema::parseSimpleYaml (Symfony YAML) failed: " . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Validate stream entry fields against a contract.
     *
     * Checks required fields and type constraints.
     * Returns array of errors (empty = valid).
     *
     * @param array $contract Contract definition (from getContract or YAML)
     * @param array $fields Key-value pairs to validate
     * @return array List of ['field' => string, 'reason' => string]
     */
    public static function validate(array $contract, array $fields): array
    {
        $errors = [];
        $schemaFields = $contract['fields'] ?? [];

        foreach ($schemaFields as $def) {
            $name = $def['name'] ?? '';
            $required = $def['required'] ?? false;
            $type = $def['type'] ?? 'string';

            if ($required && !array_key_exists($name, $fields)) {
                $errors[] = ['field' => $name, 'reason' => 'required field missing'];
                continue;
            }

            if (!array_key_exists($name, $fields)) {
                continue;
            }

            $value = $fields[$name];

            // Type validation
            switch ($type) {
                case 'integer':
                    if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
                        $errors[] = ['field' => $name, 'reason' => "expected integer, got: $value"];
                    }
                    break;
                case 'float':
                    if (!is_numeric($value)) {
                        $errors[] = ['field' => $name, 'reason' => "expected float, got: $value"];
                    }
                    break;
                case 'boolean':
                    if (!in_array($value, ['true', 'false', '0', '1'], true)) {
                        $errors[] = ['field' => $name, 'reason' => "expected boolean, got: $value"];
                    }
                    break;
                case 'json':
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $errors[] = ['field' => $name, 'reason' => 'expected valid JSON'];
                    }
                    break;
            }

            // Enum validation (pipe-delimited)
            if (!empty($def['values'])) {
                $allowed = explode('|', $def['values']);
                if (!in_array($value, $allowed, true)) {
                    $errors[] = ['field' => $name, 'reason' => "value '$value' not in allowed set: {$def['values']}"];
                }
            }
        }

        return $errors;
    }
}
