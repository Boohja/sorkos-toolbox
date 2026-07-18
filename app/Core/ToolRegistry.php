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

            $slug = $folder;
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new RuntimeException(sprintf('Invalid tool slug in %s.', $manifestFile));
            }

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
            $manifest['shorthand'] = $this->createShorthand($manifest['title'], $usedShorthands);
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

    private function createShorthand(string $title, array &$used): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', trim($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $shorthand = '';

        foreach ($words as $word) {
            $character = function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);
            $shorthand .= function_exists('mb_strtoupper') ? mb_strtoupper($character) : strtoupper($character);

            $length = function_exists('mb_strlen') ? mb_strlen($shorthand) : strlen($shorthand);
            if ($length >= 3) {
                break;
            }
        }

        $length = function_exists('mb_strlen') ? mb_strlen($shorthand) : strlen($shorthand);
        $shorthand .= str_repeat('0', max(0, 3 - $length));
        $shorthand = function_exists('mb_substr') ? mb_substr($shorthand, 0, 3) : substr($shorthand, 0, 3);

        if (isset($used[$shorthand])) {
            $prefix = function_exists('mb_substr') ? mb_substr($shorthand, 0, 2) : substr($shorthand, 0, 2);
            $available = null;

            for ($suffix = 0; $suffix <= 9; $suffix++) {
                $candidate = $prefix . $suffix;

                if (!isset($used[$candidate])) {
                    $available = $candidate;
                    break;
                }
            }

            if ($available === null) {
                throw new RuntimeException(sprintf('No shorthand remains available for tool "%s".', $title));
            }

            $shorthand = $available;
        }

        $used[$shorthand] = true;
        return $shorthand;
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
