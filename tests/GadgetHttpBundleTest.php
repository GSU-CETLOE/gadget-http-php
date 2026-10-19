<?php

declare(strict_types=1);

namespace Gadget\Http\Tests;

use Gadget\Http\DependencyInjection\GadgetHttpExtension;
use Gadget\Http\GadgetHttpBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class GadgetHttpBundleTest extends TestCase
{
    public function testIsASymfonyBundle(): void
    {
        self::assertInstanceOf(Bundle::class, new GadgetHttpBundle());
    }


    public function testExposesTheGadgetHttpExtension(): void
    {
        $extension = (new GadgetHttpBundle())->getContainerExtension();

        self::assertInstanceOf(GadgetHttpExtension::class, $extension);
        self::assertSame('gadget_http', $extension->getAlias());
    }
}
