# Toolbox

A small Fat-Free Framework application for independent development utilities.

## Local setup

Point the web server document root at `public/`.

Debug output is enabled automatically on `.test` hosts and disabled elsewhere. Set `APP_DEBUG=1` or `APP_DEBUG=0` to override it.

## Add a tool

Create a folder under `app/tools/<slug>/` containing:

```text
manifest.php
index.html
Controller.php (optional)
tool.css      (optional)
tool.js       (optional)
```

The folder name is the tool slug, and `index.html` is automatically registered as its `GET` entry page. The manifest only needs to describe the tool:

```php
return [
    'shorthand' => 'EXP',
    'title' => 'Example',
    'description' => 'What the tool does.',
];
```

`shorthand` must be a unique three-character combination of letters and numbers. It is normalized to uppercase. Tool folders without a shorthand are intentionally skipped during discovery.

Add a controller only when PHP needs to prepare data or handle a request. The route map still limits which methods are callable:

```php
return [
    // ...
    'controller' => App\Tools\Example\Controller::class,
    'routes' => [
        'GET /status' => 'status',
        'POST /run' => 'run',
    ],
];
```

When the optional controller has an `index()` method, it runs automatically before `index.html` is rendered. Other routes remain explicit and may reference additional HTML templates stored in the same tool folder.

See `app/examples/ToolController.php` for a small controller example.

The shared layout automatically includes `tool.css` in the document head and `tool.js` before the closing body tag when those files exist. Public files in the tool folder remain available through the virtual `/tools/<slug>/assets/<file>` route. PHP and HTML files are never served by that route.

