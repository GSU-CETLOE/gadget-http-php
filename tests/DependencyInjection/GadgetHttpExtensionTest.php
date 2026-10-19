<?php

declare(strict_types=1);

namespace Gadget\Http\Tests\DependencyInjection;

use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\ApiClient;
use Gadget\Http\Client\Client;
use Gadget\Http\Client\MiddlewareHandler;
use Gadget\Http\Cookie\CookieJar;
use Gadget\Http\DependencyInjection\GadgetHttpExtension;
use Gadget\Http\Message\MessageFactory;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class GadgetHttpExtensionTest extends TestCase
{
    private static function loadedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new GadgetHttpExtension())->load([], $container);
        return $container;
    }


    public function testRegistersTheConcreteClassesAsAutowiredServices(): void
    {
        $container = self::loadedContainer();

        $ids = [
            Client::class,
            MiddlewareHandler::class,
            MessageFactory::class,
            RequestBuilder::class,
            CookieJar::class,
        ];
        foreach ($ids as $id) {
            self::assertTrue($container->hasDefinition($id), "{$id} should be registered");
            $definition = $container->getDefinition($id);
            self::assertTrue($definition->isAutowired(), "{$id} should be autowired");
            self::assertTrue($definition->isAutoconfigured(), "{$id} should be autoconfigured");
        }
    }


    public function testSkipsTheAbstractBaseClasses(): void
    {
        $container = self::loadedContainer();

        self::assertFalse($container->hasDefinition(ApiClient::class));
        self::assertFalse($container->hasDefinition(MessageHandler::class));
    }


    public function testCompiledContainerBuildsAClientFromTheApplicationsPsrImplementations(): void
    {
        $container = self::loadedContainer();
        $container->register(ClientInterface::class, GuzzleClient::class);
        $container->register(HttpFactory::class, HttpFactory::class);
        foreach (
            [
                RequestFactoryInterface::class,
                ResponseFactoryInterface::class,
                ServerRequestFactoryInterface::class,
                StreamFactoryInterface::class,
                UploadedFileFactoryInterface::class,
                UriFactoryInterface::class,
            ] as $factoryInterface
        ) {
            $container->setAlias($factoryInterface, HttpFactory::class);
        }
        $container->register(CacheItemPoolInterface::class, ArrayAdapter::class);
        $container->register(CacheItemPool::class, CacheItemPool::class)->setAutowired(true);
        $container->getDefinition(Client::class)->setPublic(true);

        $container->compile();

        $client = $container->get(Client::class);
        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(MessageFactory::class, $client->getMessageFactory());
        self::assertSame([], $client->getCookieJar()->getCookies());
    }
}
