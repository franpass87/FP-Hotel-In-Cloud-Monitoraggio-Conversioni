#!/usr/bin/env php
<?php declare(strict_types=1);

const PLUGIN_MAIN_FILE = __DIR__ . '/../FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php';

function error(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$options = getopt('', ['major', 'minor', 'patch', 'set:'], $optind);

$set = $options['set'] ?? null;

$bumpFlags = array_filter([
    'major' => array_key_exists('major', $options),
    'minor' => array_key_exists('minor', $options),
    'patch' => array_key_exists('patch', $options),
]);

if ($set !== null && count(array_filter($bumpFlags)) > 0) {
    error('The --set option cannot be combined with --major/--minor/--patch.');
}

if ($set === null) {
    $selected = array_keys(array_filter($bumpFlags));
    if (count($selected) > 1) {
        error('Only one of --major, --minor, or --patch can be specified.');
    }
    $bump = $selected[0] ?? 'patch';
} else {
    $bump = null;
}

$mainFile = realpath(PLUGIN_MAIN_FILE);
if ($mainFile === false || !is_readable($mainFile) || !is_writable($mainFile)) {
    error('Unable to access plugin main file: ' . PLUGIN_MAIN_FILE);
}

$content = file_get_contents($mainFile);
if ($content === false) {
    error('Unable to read plugin main file.');
}

$pattern = '/^(\s*\*\s*Version:\s*)([^\r\n]+)(\r?\n)/mi';
if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
    error('Unable to locate plugin version header.');
}

$currentVersion = trim($matches[2][0]);

if ($set !== null) {
    if (!preg_match('/^\d+\.\d+\.\d+$/', $set)) {
        error('The --set option requires a semantic version number (e.g. 1.2.3).');
    }
    $newVersion = $set;
} else {
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $currentVersion, $versionParts)) {
        error('Current version is not in a semantic version format.');
    }

    $major = (int) $versionParts[1];
    $minor = (int) $versionParts[2];
    $patch = (int) $versionParts[3];

    switch ($bump) {
        case 'major':
            $major++;
            $minor = 0;
            $patch = 0;
            break;
        case 'minor':
            $minor++;
            $patch = 0;
            break;
        case 'patch':
        default:
            $patch++;
            break;
    }

    $newVersion = sprintf('%d.%d.%d', $major, $minor, $patch);
}

$replacement = $matches[1][0] . $newVersion . $matches[3][0];
$content = substr_replace($content, $replacement, $matches[0][1], strlen($matches[0][0]));

if (file_put_contents($mainFile, $content) === false) {
    error('Unable to write updated plugin file.');
}

echo $newVersion . PHP_EOL;
