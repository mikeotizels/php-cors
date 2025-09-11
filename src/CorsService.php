<?php declare(strict_types=1);
/**
 * This file is part of the Mikeotizels PHP CORS package.
 *
 * (c) 2025 Michael Otieno <mikeotizels@gmail.com>
 *
 * For the full copyright and license information, please view 
 * the LICENSE file that was distributed with this source code.
 */

namespace Mikeotizels\Cors;

use Mikeotizels\Cors\CorsServiceInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A simple PSR-7 CORS service implementation
 * 
 * Derived from fruitcake/php-cors which uses symfony/http-foundation components.
 * Mirrored to PSR-7 compatible implementation to simplify everything and avoid 
 * Symfony to PSR requests/responses conversion when used with PSR applications
 * like Slim framework.
 * 
 * @see https://github.com/fruitcake/php-cors
 * @see https://github.com/asm89/stack-cors
 * 
 * @phpstan-type CorsInputOptions array {
 *  allowedOrigins?: string[],
 *  allowedOriginsPatterns?: string[],
 *  allowedMethods?: string[],
 *  allowedHeaders?: string[],
 *  exposedHeaders?: string[]|false,
 *  supportsCredentials?: bool,
 *  maxAge?: int|null,
 *  allowed_origins?: string[],
 *  allowed_origins_patterns?: string[],
 *  allowed_methods?: string[],
 *  allowed_headers?: string[],
 *  exposed_headers?: string[]|false,
 *  supports_credentials?: bool,
 *  max_age?: int|null
 * }
 */
class CorsService implements CorsServiceInterface
{
    /** @var string[] */
    private array $allowedOrigins = [];
    /** @var string[] */
    private array $allowedOriginsPatterns = [];
    /** @var string[] */
    private array $allowedMethods = [];
    /** @var string[] */
    private array $allowedHeaders = [];
    /** @var string[] */
    private array $exposedHeaders = [];
    private bool $supportsCredentials = false;
    private ?int $maxAge = 0;

    private bool $allowAllOrigins = false;
    private bool $allowAllMethods = false;
    private bool $allowAllHeaders = false;
    
    /**
     * @param ResponseFactoryInterface $responseFactory
     * @param CorsInputOptions $options
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        array $options = []
    ) {
        if ($options) {
            $this->setOptions($options);
        }
    }

    /**
     * @phpstan-param array {
     *  allowedOrigins?: string[],
     *  allowedOriginsPatterns?: string[],
     *  allowedMethods?: string[],
     *  allowedHeaders?: string[],
     *  exposedHeaders?: string[]|false,
     *  supportsCredentials?: bool,
     *  maxAge?: int|null,
     *  allowed_origins?: string[],
     *  allowed_origins_patterns?: string[],
     *  allowed_methods?: string[],
     *  allowed_headers?: string[],
     *  exposed_headers?: string[]|false,
     *  supports_credentials?: bool,
     *  max_age?: int|null
     * } $options
     */
    public function setOptions(array $options): void
    {
        $this->allowedOrigins = 
               $options['allowedOrigins'] ?? $options['allowed_origins'] ?? $this->allowedOrigins;
        $this->allowedOriginsPatterns =
               $options['allowedOriginsPatterns'] ?? $options['allowed_origins_patterns'] ?? $this->allowedOriginsPatterns;
        $this->allowedMethods = 
              $options['allowedMethods'] ?? $options['allowed_methods'] ?? $this->allowedMethods;
        $this->allowedHeaders = 
               $options['allowedHeaders'] ?? $options['allowed_headers'] ?? $this->allowedHeaders;
        $this->supportsCredentials =
               $options['supportsCredentials'] ?? $options['supports_credentials'] ?? $this->supportsCredentials;

        $exposedHeaders = $options['exposedHeaders'] ?? $options['exposed_headers'] ?? $this->exposedHeaders;
        $this->exposedHeaders = $exposedHeaders === false ? [] : $exposedHeaders;

        $maxAge = $this->maxAge;
        if (array_key_exists('maxAge', $options)) {
            $maxAge = $options['maxAge'];
        } elseif (array_key_exists('max_age', $options)) {
            $maxAge = $options['max_age'];
        }
        $this->maxAge = $maxAge === null ? null : (int) $maxAge;

        $this->normalizeOptions();
    }

    private function normalizeOptions(): void
    {
        // Normalize case
        $this->allowedMethods = array_map('strtoupper', $this->allowedMethods);
        $this->allowedHeaders = array_map('strtolower', $this->allowedHeaders);

        // Normalize ['*'] to true
        $this->allowAllOrigins = in_array('*', $this->allowedOrigins, true);
        $this->allowAllMethods = in_array('*', $this->allowedMethods, true);
        $this->allowAllHeaders = in_array('*', $this->allowedHeaders, true);

        // Transform wildcard pattern
        if (!$this->allowAllOrigins) {
            foreach ($this->allowedOrigins as $origin) {
                if (strpos($origin, '*') !== false) {
                    $this->allowedOriginsPatterns[] = $this->convertWildcardToPattern($origin);
                }
            }
        }
    }

    /**
     * Create a pattern for a wildcard, based on Str::is() from Laravel
     *
     * @see https://github.com/laravel/framework/blob/5.5/src/Illuminate/Support/Str.php
     * @param string $pattern
     * @return string
     */
    private function convertWildcardToPattern(string $pattern): string
    {
        $pattern = preg_quote($pattern, '#');

        // Asterisks are translated into zero-or-more regular expression wildcards
        // to make it convenient to check if the strings starts with the given
        // pattern such as "*.example.com", making any string check convenient.
        $pattern = str_replace('\*', '.*', $pattern);

        return '#^' . $pattern . '\z#u';
    }

    public function isCorsRequest(Request $request): bool
    {
        return $request->hasHeader('Origin');
    }

    public function isPreflightRequest(Request $request): bool
    {
        return $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');
    }

    /**
     * Create a 204 preflight response with appropriate CORS headers.
     */
    public function handlePreflightRequest(Request $request): Response
    {
        $response = $this->responseFactory->createResponse(204);
        return $this->addPreflightRequestHeaders($response, $request);
    }

    public function addPreflightRequestHeaders(Response $response, Request $request): Response
    {
        $response = $this->configureAllowedOrigin($response, $request);

        if ($response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $this->configureAllowedMethods($response, $request);
            $response = $this->configureAllowedHeaders($response, $request);
            $response = $this->configureAllowCredentials($response);
            $response = $this->configureMaxAge($response);
        }

        return $response;
    }

    public function isOriginAllowed(Request $request): bool
    {
        if ($this->allowAllOrigins) {
            return true;
        }

        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return false;
        }

        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        foreach ($this->allowedOriginsPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    public function addActualRequestHeaders(Response $response, Request $request): Response
    {
        $response = $this->configureAllowedOrigin($response, $request);

        if ($response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $this->configureAllowCredentials($response);
            $response = $this->configureExposedHeaders($response);
        }

        return $response;
    }

    private function configureAllowedOrigin(Response $response, Request $request): Response
    {
        if ($this->allowAllOrigins && !$this->supportsCredentials) {
            // Safe + cacheable: allow everything
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        if ($this->isSingleOriginAllowed()) {
            $origin = array_values($this->allowedOrigins)[0] ?? '';
            if ($origin !== '') {
                // Single origins can be safely set
                return $response->withHeader('Access-Control-Allow-Origin', $origin);
            }
            return $response;
        }

        // For dynamic headers, set the requested Origin when set and allowed
        if ($this->isCorsRequest($request) && $this->isOriginAllowed($request)) {
            $origin   = $request->getHeaderLine('Origin');
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        }
        
        // Ensure caches vary by Origin to prevent mix‑ups
        return $this->varyHeader($response, 'Origin');
    }

    private function isSingleOriginAllowed(): bool
    {
        if ($this->allowAllOrigins || count($this->allowedOriginsPatterns) > 0) {
            return false;
        }

        return count($this->allowedOrigins) === 1;
    }

    private function configureAllowedMethods(Response $response, Request $request): Response
    {
        if ($this->allowAllMethods) {
            $allowMethods = strtoupper($request->getHeaderLine('Access-Control-Request-Method'));
            $response     = $this->varyHeader($response, 'Access-Control-Request-Method');
        } else {
            $allowMethods = implode(', ', $this->allowedMethods);
        }

        return $response->withHeader('Access-Control-Allow-Methods', $allowMethods);
    }

    private function configureAllowedHeaders(Response $response, Request $request): Response
    {
        if ($this->allowAllHeaders) {
            $allowHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
            $response     = $this->varyHeader($response, 'Access-Control-Request-Headers');
        } else {
            $allowHeaders = implode(', ', $this->allowedHeaders);
        }

        return $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
    }

    private function configureAllowCredentials(Response $response): Response
    {
        if ($this->supportsCredentials) {
            return $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        return $response;
    }

    private function configureExposedHeaders(Response $response): Response
    {
        if (!empty($this->exposedHeaders)) {
            return $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }
        return $response;
    }

    private function configureMaxAge(Response $response): Response
    {
        if ($this->maxAge !== null) {
            return $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }
        return $response;
    }

    public function varyHeader(Response $response, string $header): Response
    {
        if (!$response->hasHeader('Vary')) {
            return $response->withHeader('Vary', $header);
        }

        $current = $response->getHeaderLine('Vary');
        $parts   = array_map('trim', explode(',', $current));

        if (!in_array($header, $parts, true)) {
            $parts[] = $header;
            return $response->withHeader('Vary', implode(', ', $parts));
        }

        return $response;
    }
}