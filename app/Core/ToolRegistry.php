<?php

declare(strict_types=1);

namespace App\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ToolRegistry
{
    private array $tools = [];

    public function __construct(private readonly string $toolsDirectory)
    {
        $this->discover();
    }

    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $slug): ?array
    {
        return $this->tools[$slug] ?? null;
    }

    private function discover(): void
    {
        $manifests = glob($this->toolsDirectory . '/*/manifest.php') ?: [];
        sort($manifests, SORT_STRING);
        $usedShorthands = [];

        foreach ($manifests as $manifestFile) {
            $toolDirectory = dirname($manifestFile);
            $folder = basename($toolDirectory);
            $manifest = require $manifestFile;

            if (!is_array($manifest)) {
                throw new RuntimeException(sprintf('%s must return an array.', $manifestFile));
            }

            if (!isset($manifest['shorthand']) || trim((string) $manifest['shorthand']) === '') {
                continue;
            }

            $slug = $folder;
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new RuntimeException(sprintf('Invalid tool slug in %s.', $manifestFile));
            }

            $shorthand = strtoupper(trim((string) $manifest['shorthand']));
            if (!preg_match('/^[A-Z0-9]{3,}$/', $shorthand)) {
                throw new RuntimeException(sprintf('Invalid shorthand for tool "%s".', $slug));
            }

            if (isset($usedShorthands[$shorthand])) {
                throw new RuntimeException(sprintf(
                    'Shorthand "%s" is already used by tool "%s".',
                    $shorthand,
                    $usedShorthands[$shorthand]
                ));
            }

            $usedShorthands[$shorthand] = $slug;

            foreach (['title', 'description'] as $required) {
                if (!isset($manifest[$required])) {
                    throw new RuntimeException(sprintf('Missing "%s" in %s.', $required, $manifestFile));
                }
            }

            $indexFile = $toolDirectory . '/index.html';
            if (!is_file($indexFile)) {
                throw new RuntimeException(sprintf('Missing index.html for tool "%s".', $slug));
            }

            $routes = $manifest['routes'] ?? [];
            if (!is_array($routes)) {
                throw new RuntimeException(sprintf('Invalid routes in %s.', $manifestFile));
            }

            $controllerFile = $toolDirectory . '/Controller.php';
            $controllerClass = $manifest['controller'] ?? null;

            if ($controllerClass !== null && (!is_string($controllerClass) || !is_file($controllerFile))) {
                throw new RuntimeException(sprintf('Missing controller for tool "%s".', $slug));
            }

            $manifest['directory'] = $toolDirectory;
            $manifest['slug'] = $slug;
            $manifest['index_file'] = $indexFile;
            $manifest['routes'] = $routes;
            $manifest['controller'] = $controllerClass;
            $manifest['controller_file'] = $controllerClass !== null ? $controllerFile : null;
            $manifest['url'] = '/tools/' . $slug;
            $manifest['shorthand'] = $shorthand;
            $manifest['asset_version'] = $this->directoryVersion($toolDirectory);
            $manifest['assets'] = $this->discoverAssets($slug, $toolDirectory, $manifest['asset_version']);
            $this->tools[$slug] = $manifest;
        }

        uasort($this->tools, static fn (array $a, array $b): int =>
            ($a['order'] ?? 100) <=> ($b['order'] ?? 100)
                ?: strcasecmp($a['title'], $b['title'])
        );
    }

    private function discoverAssets(string $slug, string $directory, int $version): array
    {
        $assetUrl = '/tools/' . $slug . '/assets/';

        return [
            'css' => is_file($directory . '/tool.css')
                ? $assetUrl . 'tool.css?v=' . $version
                : null,
            'js' => is_file($directory . '/tool.js')
                ? $assetUrl . 'tool.js?v=' . $version
                : null,
        ];
    }

    private function directoryVersion(string $directory): int
    {
        $version = filemtime($directory) ?: 1;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $version = max($version, $file->getMTime());
        }

        return $version;
    }
}
