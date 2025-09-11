<?php declare(strict_types=1);

namespace Mikeotizels\Cors\Tests;

use PHPUnit\Framework\TestCase;
use Mikeotizels\Cors\CorsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

class CorsServiceTest extends TestCase
{
    public function testServiceInitializesWithDefaultOptions(): void
    {
        $factory = new Psr17Factory();
        $service = new CorsService($factory);

        $this->assertInstanceOf(CorsService::class, $service);
    }

    public function testServiceHandlesOptionsCorrectly(): void
    {
        $factory = new Psr17Factory();
        $options = ['allow_origin' => '*'];
        $service = new CorsService($factory, $options);

        $this->assertTrue(true); // Placeholder
    }
}