<?php
declare(strict_types=1);
/**
 * Canonical DTAP domain-prefix rules — the ONE PHP mirror of
 * gNode/daemon/config/dtap_schema.yaml (prefix_rules). That schema is the
 * source of truth (it additionally carries i18n aliases and is applied at
 * registration); this file is the runtime fallback for a request that has no
 * environment stored in config. Keep the tokens in lockstep with the schema.
 *
 * Pure PHP, no WordPress and no framework dependencies, so both the
 * early page cache (pre-bootstrap) and gCore proper consume the same
 * table and the same algorithm. Do NOT add per-consumer copies.
 *
 * @package gCore
 */

if (!function_exists('gcore_dtap_prefix_rules')) {
    /**
     * @return array<string, list<string>> environment => whole-token prefixes
     */
    function gcore_dtap_prefix_rules(): array {
        return [
            'development' => ['dev', 'develop', 'local', 'localhost'],
            'testing'     => ['test', 'testing', 'ci', 'qa'],
            'staging'     => ['staging', 'stage', 'preprod', 'preview'],
            'acceptance'  => ['accept', 'acceptance', 'uat', 'review'],
        ];
    }
}

if (!function_exists('gcore_dtap_environment_from_host')) {
    /**
     * Resolve a DTAP environment from a hostname using the canonical schema
     * algorithm: take the subdomain prefix before the first dot and match it
     * (case-insensitive, whole-token) against the prefix rules; no match →
     * production. Whole-token matching is deliberate — substring matching
     * classified any bare domain that merely CONTAINED a token as
     * non-production ("protest.org" → testing on a live site).
     *
     * Loopback hosts resolve to development: a request literally served from
     * 127.0.0.1/::1 can never be the production site.
     *
     * @param string $host raw host (may carry port / www. / mixed case)
     * @return string development|testing|staging|acceptance|production
     */
    function gcore_dtap_environment_from_host(string $host): string {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/:[0-9]+$/', '', $host);
        $host = (string) preg_replace('/^www\./', '', $host);
        if ($host === '') {
            return 'production';
        }
        if ($host === '127.0.0.1' || $host === '::1' || $host === '[::1]') {
            return 'development';
        }
        $prefix = strstr($host, '.', true);
        if ($prefix === false) {
            $prefix = $host; // no dot, e.g. "localhost"
        }
        foreach (gcore_dtap_prefix_rules() as $environment => $tokens) {
            if (in_array($prefix, $tokens, true)) {
                return $environment;
            }
        }
        return 'production';
    }
}
