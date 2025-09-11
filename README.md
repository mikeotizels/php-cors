Mikeotizels PHP CORS Package
============================

Version 1.0.0 - July 2025

**Mikeotizels PHP CORS** is a lightweight service provider that enables Cross-Origin Resource Sharing (CORS) for PHP applications using the [PSR-7 HTTP Message Interface](https://www.php-fig.org/psr/psr-7/).

Inspired by [fruitcake/php-cors](https://github.com/fruitcake/php-cors), which is built on [Symfony's HttpFoundation](https://symfony.com/doc/current/components/http_foundation.html), this package offers a PSR-7 compatible implementation. It eliminates the need for converting Symfony request/response objects when working with PSR-based frameworks such as [Slim Framework](https://www.slimframework.com/), streamlining integration and reducing overhead.


## Installation

Require the package using Composer:

```bash
composer require mikeotizels/php-cors
```

## Configuration

| Option | Type | Default | Description |
|-----------|----------|------------|-------------------|
| allowedOrigins | array  |  `[]` | Matches the request origin.  |
| allowedOriginsPatterns | array  | `[]` | Matches the request origin with `preg_match()`. |
| allowedMethods |  array | `[]` |  Matches the request method. |
| allowedHeaders | array  | `[]` | Sets the Access-Control-Allow-Headers response header. |
|exposedHeaders |  array | `[]` | Sets the Access-Control-Expose-Headers response header.|
|supportsCredentials |  bool | `false` | Sets the Access-Control-Allow-Credentials header. |
|maxAge| int | `0` | Sets the Access-Control-Max-Age response header. |

You don't need to provide both _allowedOrigins_ and _allowedOriginsPatterns_. If one of the strings passed in the array matches, it is considered a valid origin. A wildcard in allowedOrigins is converted to a pattern.

The _allowedMethods_ and _allowedHeaders_ options are case-insensitive.

If `['*']` is set for _allowedOrigins_, _allowedMethods_, or _allowedHeaders_, all origins, methods, and headers are allowed, respectively.

All options can also be passed in snake_case (e.g., allowed_origins, allowed_methods, allowed_headers, etc.).

> Note: Allowing a single static origin improves cacheability.

## Usage

This package can be used as a stand-alone library or as a middleware in your framework.

---

### Example Usage: as a stand-alone library

```php
<?php
use Mikeotizels\Cors\CorsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Instantiate the object
$cors = new CorsService();

// Set options
$cors->setOptions([
    'allowedOrigins'         => ['http://localhost', 'https://*.example.com'],
    'allowedOriginsPatterns' => ['/localhost:\d/'],
    'allowedMethods'         => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowedHeaders'         => ['x-allowed-header', 'x-other-allowed-header'],
    'exposedHeaders'         => ['Content-Encoding'],
    'supportsCredentials'    => false,
    'maxAge'                 => 0
]);

// Handle preflight (OPTIONS) request
if ($cors->isPreflightRequest(Request $request);) {
    return $cors->handlePreflightRequest(Request $request);
}

// Handle the actual request...

// Add the actual headers
$cors->addActualRequestHeaders(Response $response, Request $request);
```

---

### Example Usage: as a middleware for slim framework

#### 1. Set up CORS Input Options

In **`app/settings.php`**:

```php
<?php
declare(strict_types=1);

use App\Settings\Settings;
use App\Settings\SettingsInterface;
use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        // Global Settings Object
        SettingsInterface::class => function () {
            return new Settings([
            	// other options (eg, for Logger)...

                'cors' => [    
                    'allowedOrigins' => ['*'],
                    'allowedOriginsPatterns' => [],
                    'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                    'allowedHeaders' => ['X-Requested-With', 'Content-Type', 'Accept', 'Origin', 'Authorization'],
                    'exposedHeaders' => [],
                    'supportsCredentials' => false,
                    'maxAge' => 3600
                ],
            ]);
        }
    ]);
};
```

#### 2. Register CorsService Container Binding

In **`app/dependencies.php`**:

```php
<?php
declare(strict_types=1);

use App\Settings\SettingsInterface;
use Mikeotizels\Cors\CorsService;
use Mikeotizels\Cors\CorsServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        // Bind the PSR-17 ResponseFactoryInterface to Slim's implementation
        ResponseFactoryInterface::class => function () {
            return new ResponseFactory();
        },

        // Bind CorsServiceInterface with options from SettingsInterface
        CorsServiceInterface::class => function (ContainerInterface $container) {
            /** @var SettingsInterface $settings */
            $settings     = $container->get(SettingsInterface::class);
            $corsSettings = $settings->get('cors');
            
            // Inject ResponseFactoryInterface and pass CorsInputOptions
            return new CorsService(
                $container->get(ResponseFactoryInterface::class),
                [
                    'allowedOrigins'         => $corsSettings['allowedOrigins']         ?? [],
                    'allowedOriginsPatterns' => $corsSettings['allowedOriginsPatterns'] ?? [],
                    'allowedMethods'         => $corsSettings['allowedMethods']         ?? [],
                    'allowedHeaders'         => $corsSettings['allowedHeaders']         ?? [],
                    'exposedHeaders'         => $corsSettings['exposedHeaders']         ?? [],
                    'supportsCredentials'    => $corsSettings['supportsCredentials']    ?? false,
                    'maxAge'                 => $corsSettings['maxAge']                 ?? 0
                ]
            );
        },
    ]);

    // other definitions (eg, for LoggerInterface)...
};
```

#### 3. Build a Slim Middleware for CORS

Create `src/Middleware/CorsMiddleware.php`:

```php
<?php
namespace App\Middleware;

use Mikeotizels\Cors\CorsServiceInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CorsMiddleware implements Middleware
{
    public function __construct(private readonly CorsServiceInterface $cors) {
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
    	// Handle preflight (OPTIONS) request
        if ($this->cors->isPreflightRequest($request)) {
            return $this->cors->handlePreflightRequest($request);
        }

        // Continue to next middleware/route
        $response = $handler->handle($request);

        // Add actual headers
        return $this->cors->addActualRequestHeaders($response, $request);
    }
}
```

> Note: You can also add logic for logging blocked origins and rate-limiting per origin.

#### 4. Register Middleware in Slim

In **`app/middleware.php`**:

```php
<?php
declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use Slim\App;

return function (App $app) {
    $app->add(CorsMiddleware::class);
    // other middleware...
};
```

#### 5. Clean the Slim ResponseEmitter

In `app/src/Response/ResponseEmitter.php` or wherever your Slim `ResponseEmitter` is:

Please make sure the class does not rewrite any headers; it should only emit a response.

```php
<?php
declare(strict_types=1);

namespace App\Response;

use Psr\Http\Message\ResponseInterface;
use Slim\ResponseEmitter as SlimResponseEmitter;

class ResponseEmitter extends SlimResponseEmitter
{
    /**
     * {@inheritdoc}
     */
    public function emit(ResponseInterface $response): void
    {
        if (ob_get_contents()) {
            ob_clean();
        }

        parent::emit($response);
    }
}
```

> Note: If you need to add other headers like those for cache control, you can create another middleware for that.

---

## How It Works

- **Dynamic config**: You can change `cors` options without touching middleware code.
- **Preflight requests** (`OPTIONS`) are intercepted and responded to automatically.
- **Actual requests** `Access-Control-*` headers are injected based on your settings.

## Licensing


This package is released under the MIT License. See the [LICENSE](LICENSE) file.

