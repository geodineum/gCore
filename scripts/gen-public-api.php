<?php
declare(strict_types=1);
/**
 * gen-public-api.php — regenerate PUBLIC_API.md from the public build surface.
 *
 * Extracts public method signatures + their docblock summary line straight from
 * source (token_get_all, no autoload/bootstrap — so it never depends on ValKey
 * or gNode being reachable). The README links here; CONTRACT.md remains the
 * authoritative prose. Run:  php scripts/gen-public-api.php
 */

$ROOT = dirname(__DIR__);

// The public build-with surface (Chapter 1). A method is included only if it is
// declared in an interface (interfaces ARE the contract) or its docblock carries
// an `@api` tag on a class/trait. Plain `public` visibility is NOT the boundary —
// WordPress hook callbacks, inter-manager plumbing, and the repeated lifecycle are
// public without being build-with API. The Chapter-2 extension/Pro interfaces are
// out of scope here (the README roster routes those to geodineum.com).
$SECTIONS = [
    'Container' => ['Modules/Core/gCore.php'],
    'Universal lifecycle (every manager implements this)' => ['Modules/Core/Interfaces/ModuleInterface.php'],
    'First-party (Base) managers' => ['Modules/Managers/Base/*/*.php', 'Modules/Managers/Base/*/Traits/*.php'],
];

/** Extract [class => [ [sig, summary], ... ]] from one PHP file. */
function extract_public(string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) return [];
    $tokens = token_get_all($src);
    $out = [];
    $class = null;
    $isInterface = false;
    $lastDoc = null;
    $pendingPublic = false;   // saw an explicit `public` since the last function/`;`/`{`
    $sawVisibility = false;   // saw any visibility modifier for the current member

    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            [$id, $text] = $t;
            switch ($id) {
                case T_DOC_COMMENT: $lastDoc = $text; break;
                case T_WHITESPACE:  break;                       // keep $lastDoc across whitespace
                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                    // Real declaration only if the IMMEDIATELY following significant
                    // token is the type name. Skips `Foo::class` and `new class {`.
                    $j = $i + 1;
                    while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
                    if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        $isInterface = ($id === T_INTERFACE);
                    }
                    break;
                case T_PUBLIC:    $pendingPublic = true; $sawVisibility = true; break;
                case T_PRIVATE:
                case T_PROTECTED: $sawVisibility = true; break;
                case T_ABSTRACT:
                case T_FINAL:
                case T_STATIC:    break;                         // modifiers don't reset visibility
                case T_FUNCTION:
                    // public if explicitly public, or (interface) implicit public,
                    // or (class) no visibility modifier at all before `function`.
                    $public = $pendingPublic || $isInterface || !$sawVisibility;
                    $sig = read_signature($tokens, $i, $n);      // advances past the signature
                    // Interface methods are contract by definition; class/trait
                    // methods must be explicitly marked @api.
                    $hasApi = $lastDoc !== null && strpos($lastDoc, '@api') !== false;
                    $onSurface = $isInterface || $hasApi;
                    if ($public && $onSurface && $sig !== null && $class !== null && $sig['name'][0] !== '_') {
                        $out[$class][] = [$sig['text'], summary_of($lastDoc)];
                    }
                    $lastDoc = null; $pendingPublic = false; $sawVisibility = false;
                    break;
                default:
                    // any other significant token ends a pending docblock's adjacency only
                    // when it's not part of a member header; keep it simple: reset flags on ';'
                    break;
            }
        } else {
            // single-char token
            if ($t === ';' || $t === '{' || $t === '}') { $pendingPublic = false; $sawVisibility = false; $lastDoc = ($t === '}') ? null : $lastDoc; }
        }
    }
    return $out;
}

/** Read `name(params...) : ret` starting at the T_FUNCTION token index. */
function read_signature(array $tokens, int $fi, int $n): ?array {
    // find the method name
    $name = null; $k = $fi + 1;
    for (; $k < $n; $k++) {
        $tk = $tokens[$k];
        if (is_array($tk) && $tk[0] === T_STRING) { $name = $tk[1]; break; }
        if (!is_array($tk) && $tk === '(') break;  // anonymous — skip
    }
    if ($name === null) return null;
    // accumulate raw text from name up to the end of the param list (balanced parens)
    // then any `: returntype` up to `{` or `;`.
    $buf = ''; $depth = 0; $started = false;
    for ($k = $fi + 1; $k < $n; $k++) {
        $tk = $tokens[$k];
        $txt = is_array($tk) ? $tk[1] : $tk;
        if (!$started) {
            if ($txt === '(') { $started = true; $depth = 1; $buf = $name . '('; }
            continue;
        }
        if (is_array($tk) && $tk[0] === T_WHITESPACE) $txt = ' ';
        if ($txt === '(') $depth++;
        if ($txt === ')') { $depth--; if ($depth === 0) { $buf .= ')'; // read optional return type
                for ($m = $k + 1; $m < $n; $m++) {
                    $mt = $tokens[$m]; $mx = is_array($mt) ? $mt[1] : $mt;
                    if ($mx === '{' || $mx === ';') break;
                    if (is_array($mt) && $mt[0] === T_WHITESPACE) $mx = ' ';
                    $buf .= $mx;
                }
                break; } }
        $buf .= $txt;
    }
    // collapse runs of whitespace
    $buf = preg_replace('/\s+/', ' ', trim($buf));
    return ['name' => $name, 'text' => $buf];
}

/** First meaningful sentence of a docblock. */
function summary_of(?string $doc): string {
    if ($doc === null) return '';
    $lines = preg_split('/\r?\n/', $doc);
    foreach ($lines as $ln) {
        $ln = trim($ln);
        $ln = preg_replace('#^/\*+#', '', $ln);
        $ln = preg_replace('#\*+/$#', '', $ln);
        $ln = ltrim($ln, "* \t");
        if ($ln === '' || $ln[0] === '@') continue;
        return rtrim($ln, " .");
    }
    return '';
}

// ---- build the document ----------------------------------------------------
$md = [];
$md[] = "# gCore — Public API";
$md[] = "";
$md[] = "> **Generated — do not edit by hand.** Regenerate with `php scripts/gen-public-api.php`.";
$md[] = "> Extracted from public method signatures + docblock summaries on the build-with";
$md[] = "> surface. [`CONTRACT.md`](CONTRACT.md) is the authoritative prose contract; where the";
$md[] = "> two differ, the code (and this generated index) win.";
$md[] = "";

foreach ($SECTIONS as $heading => $globs) {
    $files = [];
    foreach ($globs as $g) {
        foreach (glob("$ROOT/$g") ?: [] as $f) {
            // Base managers: main class file per dir (Base/<N>/<N>.php) + Traits/.
            if (strpos($f, '/Managers/Base/') !== false && strpos($f, '/Traits/') === false) {
                if (basename($f, '.php') !== basename(dirname($f))) continue;
            }
            $files[$f] = true;
        }
    }
    $files = array_keys($files);
    sort($files);

    // Accumulate by display-name so a manager's Traits/ methods fold into the
    // manager's own section (one heading, one anchor per manager).
    $acc = [];
    foreach ($files as $f) {
        $rel = ltrim(str_replace($ROOT, '', $f), '/');
        $ownedByManager = preg_match('#/Managers/Base/([^/]+)/Traits/#', $f, $mm) ? $mm[1] : null;
        foreach (extract_public($f) as $class => $methods) {
            if (!$methods) continue;
            $disp = $ownedByManager ?? $class;
            if (!isset($acc[$disp])) $acc[$disp] = ['rel' => $rel, 'methods' => []];
            foreach ($methods as $x) $acc[$disp]['methods'][] = $x;
        }
    }
    $section = [];
    foreach ($acc as $disp => $info) {
        $section[] = "";
        $section[] = "### `$disp`";
        $section[] = "<sub>`{$info['rel']}`</sub>";
        $section[] = "";
        foreach ($info['methods'] as [$sig, $sum]) {
            $section[] = $sum !== '' ? "- `$sig` — $sum" : "- `$sig`";
        }
    }
    if ($section) {
        $md[] = "## $heading";
        $md = array_merge($md, $section);
        $md[] = "";
    }
}

$doc = implode("\n", $md) . "\n";
file_put_contents("$ROOT/PUBLIC_API.md", $doc);
$count = substr_count($doc, "\n- `");
fwrite(STDERR, "wrote PUBLIC_API.md ($count public members across the build-with surface)\n");
