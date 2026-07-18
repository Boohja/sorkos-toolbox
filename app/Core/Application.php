<?php

declare(strict_types=1);

namespace App\Core;

use Base;
use ErrorException;
use Template;

final class Application
{
    private ToolRegistry $tools;

    public function __construct(
        private readonly Base $f3,
        private readonly string $rootDirectory
    ) {
        $this->tools = new ToolRegistry($rootDirectory . '/app/tools');
    }

    public function run(): void
    {
        $this->configure();
        $this->registerCoreRoutes();
        $this->registerToolRoutes();
        $this->f3->run();
    }

    private function configure(): void
    {
        $debugSetting = getenv('APP_DEBUG');
        $isLocalHost = str_ends_with(strtolower((string) $this->f3->get('HOST')), '.test');
        $debug = $debugSetting === false
            ? $isLocalHost
            : filter_var($debugSetting, FILTER_VALIDATE_BOOL);

        $this->f3->set('DEBUG', $debug ? 3 : 0);
        $this->f3->set('TEMP', $this->rootDirectory . '/var/tmp/');
        $this->f3->set('UI', $this->rootDirectory . '/app/');
        $this->f3->set('ESCAPE', true);
        $this->f3->set('APP_NAME', 'Toolbox');
        $this->f3->set('CURRENT_YEAR', date('Y'));
        $this->f3->set('ASSET_VERSION', filemtime($this->rootDirectory . '/public/assets/app.css') ?: 1);
        $this->f3->set('TOOLS', $this->tools->all());
        $this->f3->set('TOOL_PAGE', false);
        $this->f3->set('TOOL_CSS', null);
        $this->f3->set('TOOL_JS', null);
        $this->f3->set('ONERROR', function (Base $f3): void {
            $error = $f3->get('ERROR');
            $f3->set('pageTitle', ($error['code'] ?? 500) . ' - Toolbox');
            $f3->set('content', 'views/error.html');
            echo Template::instance()->render('views/layout.html');
        });
    }

    private function registerCoreRoutes(): void
    {
        $this->f3->route('GET /', function (Base $f3): void {
            $f3->set('pageTitle', 'Toolbox');
            $f3->set('content', 'views/home.html');
            echo Template::instance()->render('views/layout.html');
        });

        $this->f3->route('GET /tools/@tool/assets/*', function (Base $f3, array $params): void {
            $this->serveToolAsset($params['tool'], $params['*'] ?? '');
        });
    }

    private function registerToolRoutes(): void
    {
        foreach ($this->tools->all() as $tool) {
            $controller = null;

            if (is_string($tool['controller'])) {
                require_once $tool['controller_file'];
                $controllerClass = $tool['controller'];
                $controller = new $controllerClass();
            }

            $this->f3->route(
                'GET /tools/' . $tool['slug'],
                function (Base $f3, array $params) use ($controller, $tool): void {
                    if ($controller && method_exists($controller, 'index')) {
                        $controller->index($f3, $params);
                    }

                    $this->renderToolView($tool, 'index.html');
                }
            );

            foreach ($tool['routes'] as $definition => $route) {
                [$method, $path] = array_pad(preg_split('/\s+/', trim($definition), 2), 2, '/');
                $path = $path === '/' ? '' : '/' . ltrim($path, '/');
                $handler = is_array($route) ? ($route['handler'] ?? null) : $route;
                $view = is_array($route) ? ($route['view'] ?? null) : null;

                if ($handler !== null && (!is_string($handler) || !$controller || !method_exists($controller, $handler))) {
                    throw new ErrorException(sprintf(
                        'Handler %s::%s does not exist.',
                        $tool['controller'] ?? '(no controller)',
                        (string) $handler
                    ));
                }

                if ($handler === null && !is_string($view)) {
                    throw new ErrorException(sprintf(
                        'Route "%s" for tool "%s" needs a handler or a view.',
                        $definition,
                        $tool['slug']
                    ));
                }

                $this->f3->route(
                    $method . ' /tools/' . $tool['slug'] . $path,
                    function (Base $f3, array $params) use ($controller, $handler, $tool, $view): void {
                        if ($handler !== null) {
                            $controller->{$handler}($f3, $params);
                        }

                        if (is_string($view)) {
                            $this->renderToolView($tool, $view);
                        }
                    }
                );
            }
        }
    }

    private function renderToolView(array $tool, string $view): void
    {
        $toolRoot = realpath($tool['directory']);
        $viewFile = $toolRoot ? realpath($toolRoot . '/' . ltrim($view, '/\\')) : false;

        if (
            !$toolRoot
            || !$viewFile
            || !str_starts_with($viewFile, $toolRoot . DIRECTORY_SEPARATOR)
            || pathinfo($viewFile, PATHINFO_EXTENSION) !== 'html'
        ) {
            throw new ErrorException(sprintf('Invalid view for tool "%s".', $tool['slug']));
        }

        $relativeView = str_replace('\\', '/', substr($viewFile, strlen($this->rootDirectory . '/app/')));
        $this->f3->set('pageTitle', $tool['title'] . ' - ' . $this->f3->get('APP_NAME'));
        $this->f3->set('TOOL', $tool);
        $this->f3->set('TOOL_PAGE', true);
        $this->f3->set('TOOL_CSS', $tool['assets']['css']);
        $this->f3->set('TOOL_JS', $tool['assets']['js']);
        $this->f3->set('content', $relativeView);
        echo Template::instance()->render('views/layout.html');
    }

    private function serveToolAsset(string $slug, string $relativePath): void
    {
        $tool = $this->tools->get($slug);
        $toolRoot = $tool ? realpath($tool['directory']) : false;
        $assetName = ltrim(str_replace('\\', '/', $relativePath), '/');
        $asset = $toolRoot && $assetName !== '' && !str_contains($assetName, '/')
            ? realpath($toolRoot . '/' . $assetName)
            : false;

        if (!$toolRoot || !$asset || !str_starts_with($asset, $toolRoot . DIRECTORY_SEPARATOR) || !is_file($asset)) {
            $this->f3->error(404);
            return;
        }

        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
        ];
        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));

        if (!isset($types[$extension])) {
            $this->f3->error(404);
            return;
        }

        header('Content-Type: ' . $types[$extension]);
        header('Cache-Control: public, max-age=3600');
        readfile($asset);
    }
}
