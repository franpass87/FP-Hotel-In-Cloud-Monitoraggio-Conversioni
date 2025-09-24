<?php declare(strict_types=1);

/**
 * Simple gettext extractor for the FP HIC Monitor plugin.
 *
 * The script scans the plugin PHP files for translation functions and
 * generates a POT catalogue under the languages/ directory.
 */

$root = dirname(__DIR__);
$output = $root . '/languages/hotel-in-cloud.pot';
$domain = 'hotel-in-cloud';

$functions = [
    '__'              => 'simple',
    '_e'              => 'simple',
    'esc_html__'      => 'simple',
    'esc_html_e'      => 'simple',
    'esc_attr__'      => 'simple',
    'esc_attr_e'      => 'simple',
    '_x'              => 'context',
    '_ex'             => 'context',
    'esc_html_x'      => 'context',
    'esc_attr_x'      => 'context',
    '_n'              => 'plural',
    '_nx'             => 'plural_context',
    '_n_noop'         => 'noop',
    '_nx_noop'        => 'noop_context',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$entries = [];

/**
 * @param array<string, mixed> $entry
 */
function add_entry(array &$entries, array $entry): void
{
    $keyParts = [
        $entry['context'] ?? '',
        $entry['msgid'],
        $entry['msgid_plural'] ?? '',
    ];
    $key = md5(implode("\x04", $keyParts));

    if (!isset($entries[$key])) {
        $entries[$key] = $entry;
        $entries[$key]['references'] = [];
    }

    $entries[$key]['references'] = array_values(array_unique(array_merge(
        $entries[$key]['references'],
        $entry['references']
    )));
}

function decode_php_string(string $token): string
{
    $quote = $token[0];
    $inner = substr($token, 1, -1);

    if ($quote === '\'') {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
    }

    if ($quote === '"') {
        return stripcslashes($inner);
    }

    return $inner;
}

/**
 * @return list<array{value:string,line:int}>
 */
function get_function_strings(array $tokens, int $index): array
{
    $strings = [];
    $foundParenthesis = false;
    $level = 0;
    $total = count($tokens);

    for ($i = $index + 1; $i < $total; $i++) {
        $token = $tokens[$i];

        if (!$foundParenthesis) {
            if (is_array($token)) {
                $type = $token[0];
                if ($type === T_WHITESPACE || $type === T_COMMENT || $type === T_DOC_COMMENT) {
                    continue;
                }
            }

            if ($token === '(') {
                $foundParenthesis = true;
                $level = 1;
                continue;
            }

            break;
        }

        if (is_string($token)) {
            if ($token === '(') {
                $level++;
                continue;
            }
            if ($token === ')') {
                $level--;
                if ($level === 0) {
                    break;
                }
                continue;
            }
            continue;
        }

        if ($level === 1 && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = [
                'value' => decode_php_string($token[1]),
                'line'  => $token[2],
            ];
        }
    }

    return $strings;
}

foreach ($iterator as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    if (!$fileInfo->isFile()) {
        continue;
    }

    $path = $fileInfo->getPathname();
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $relative = str_replace('\\', '/', $relative);

    // Skip vendor, node_modules, build artefacts.
    if (preg_match('#^(vendor|node_modules|build|tests|docs)(/|$)#u', $relative)) {
        continue;
    }

    if (substr($relative, -4) !== '.php') {
        continue;
    }

    $code = file_get_contents($path);
    if ($code === false) {
        fwrite(STDERR, "Cannot read {$relative}\n");
        continue;
    }

    $tokens = token_get_all($code);

    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            continue;
        }

        if ($token[0] !== T_STRING) {
            continue;
        }

        $functionName = $token[1];
        if (!isset($functions[$functionName])) {
            continue;
        }

        $strings = get_function_strings($tokens, $i);
        if ($strings === []) {
            continue;
        }

        // Ensure the plugin text domain is present somewhere in the call.
        $hasDomain = false;
        foreach ($strings as $stringToken) {
            if ($stringToken['value'] === $domain) {
                $hasDomain = true;
                break;
            }
        }

        if (!$hasDomain) {
            continue;
        }

        $entry = [
            'msgid'        => '',
            'msgid_plural' => null,
            'context'      => null,
            'references'   => [str_replace(DIRECTORY_SEPARATOR, '/', $relative) . ':' . $token[2]],
        ];

        switch ($functions[$functionName]) {
            case 'simple':
                $entry['msgid'] = $strings[0]['value'];
                break;

            case 'context':
                if (count($strings) < 2) {
                    continue 2;
                }
                $entry['msgid'] = $strings[0]['value'];
                $entry['context'] = $strings[1]['value'];
                break;

            case 'plural':
                if (count($strings) < 2) {
                    continue 2;
                }
                $entry['msgid'] = $strings[0]['value'];
                $entry['msgid_plural'] = $strings[1]['value'];
                break;

            case 'plural_context':
                if (count($strings) < 3) {
                    continue 2;
                }
                $entry['msgid'] = $strings[0]['value'];
                $entry['msgid_plural'] = $strings[1]['value'];
                $entry['context'] = $strings[2]['value'];
                break;

            case 'noop':
                if (count($strings) < 2) {
                    continue 2;
                }
                $entry['msgid'] = $strings[0]['value'];
                $entry['msgid_plural'] = $strings[1]['value'];
                break;

            case 'noop_context':
                if (count($strings) < 3) {
                    continue 2;
                }
                $entry['msgid'] = $strings[0]['value'];
                $entry['msgid_plural'] = $strings[1]['value'];
                $entry['context'] = $strings[2]['value'];
                break;

            default:
                continue 2;
        }

        add_entry($entries, $entry);
    }
}

ksort($entries);

$potLines = [];
$potLines[] = 'msgid ""';
$potLines[] = 'msgstr ""';
$potLines[] = '"Project-Id-Version: FP HIC Monitor\\n"';
$potLines[] = '"Report-Msgid-Bugs-To: \\n"';
$potLines[] = '"POT-Creation-Date: ' . gmdate('Y-m-d H:iO') . '\\n"';
$potLines[] = '"MIME-Version: 1.0\\n"';
$potLines[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
$potLines[] = '"Content-Transfer-Encoding: 8bit\\n"';
$potLines[] = '"X-Generator: FP HIC make-pot.php\\n"';
$potLines[] = '';

foreach ($entries as $entry) {
    foreach ($entry['references'] as $reference) {
        $potLines[] = '#: ' . $reference;
    }

    if ($entry['context'] !== null) {
        $potLines[] = 'msgctxt ' . format_pot_string($entry['context']);
    }

    $potLines[] = 'msgid ' . format_pot_string($entry['msgid']);

    if ($entry['msgid_plural'] !== null) {
        $potLines[] = 'msgid_plural ' . format_pot_string($entry['msgid_plural']);
        $potLines[] = 'msgstr[0] ""';
        $potLines[] = 'msgstr[1] ""';
    } else {
        $potLines[] = 'msgstr ""';
    }

    $potLines[] = '';
}

if (!is_dir(dirname($output))) {
    mkdir(dirname($output), 0775, true);
}

file_put_contents($output, implode("\n", $potLines));

echo "Generated POT file at {$output}\n";

function format_pot_string(string $value): string
{
    $escaped = addcslashes($value, "\n\r\t\"\\");
    return '"' . $escaped . '"';
}
