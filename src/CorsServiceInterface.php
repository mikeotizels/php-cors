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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface CorsServiceInterface
{
    public function setOptions(array $options): void;

    public function isCorsRequest(ServerRequestInterface $request): bool;

    public function isPreflightRequest(ServerRequestInterface $request): bool;

    public function handlePreflightRequest(ServerRequestInterface $request): ResponseInterface;

    public function addPreflightRequestHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface;

    public function isOriginAllowed(ServerRequestInterface $request): bool;

    public function addActualRequestHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface;

    public function varyHeader(ResponseInterface $response, string $header): ResponseInterface;
}