<?php
declare(strict_types=1);

// Symcon's documented non-module root folders:
// https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/
$root = dirname(__DIR__);
$ignored = ['libs', 'docs', 'imgs', 'tests', 'actions'];
$modules = [];
foreach (new DirectoryIterator($root) as $entry) {
    if (!$entry->isDir() || str_starts_with($entry->getFilename(), '.')) continue;
    $name = $entry->getFilename();
    if (in_array($name, $ignored, true)) continue;
    $folder = $entry->getPathname();
    foreach (['module.json', 'module.php'] as $required) {
        if (!is_file($folder . '/' . $required)) {
            throw new RuntimeException('Invalid Symcon module folder ' . $name . ': missing ' . $required
                . '. Place auxiliary files under an allowed folder such as libs or docs.');
        }
    }
    $definition = json_decode(file_get_contents($folder . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($definition) || empty($definition['id']) || empty($definition['name']) || empty($definition['prefix'])) {
        throw new RuntimeException('Incomplete module definition: ' . $name);
    }
    $modules[] = $name;
}
if (!$modules) throw new RuntimeException('No modules found in the library.');
echo 'Symcon library structure: ' . implode(', ', $modules) . "; auxiliary folders valid.\n";
