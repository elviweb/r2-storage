<?php

declare(strict_types=1);

namespace Elviweb\R2Storage\Tests\Unit;

use Elviweb\R2Storage\ElviwebR2StorageBundle;
use Elviweb\R2Storage\R2Client;
use Elviweb\R2Storage\Storage\LocalStorage;
use Elviweb\R2Storage\Storage\R2Storage;
use Elviweb\R2Storage\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

final class BundleTest extends TestCase
{
    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $env
     */
    private function container(array $config, array $env = []): ContainerBuilder
    {
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->set('http_client', new MockHttpClient());
        $extension = (new ElviwebR2StorageBundle())->getContainerExtension();
        \assert(null !== $extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('elviweb_r2_storage', $config);
        $container->compile(true);

        return $container;
    }

    public function testNamedStoragesWithTheDriverFromAnEnvVariable(): void
    {
        $container = $this->container([
            'r2' => ['endpoint' => '%env(TEST_R2_ENDPOINT)%', 'access_key_id' => 'AKID', 'secret_access_key' => 'SECRET'],
            'storages' => [
                'public' => ['driver' => '%env(TEST_STORAGE_DRIVER)%', 'bucket' => 'site-media', 'public_url' => 'https://media.example.com'],
                'private' => ['driver' => 'r2', 'bucket' => 'site-private'],
            ],
        ], ['TEST_R2_ENDPOINT' => 'https://acc.r2.cloudflarestorage.com', 'TEST_STORAGE_DRIVER' => 'local']);

        self::assertInstanceOf(LocalStorage::class, $container->get('elviweb_r2_storage.storage.public'), 'driver from the env variable');
        self::assertInstanceOf(R2Storage::class, $container->get('elviweb_r2_storage.storage.private'));
        $private = $container->get('elviweb_r2_storage.storage.private');
        self::assertInstanceOf(R2Storage::class, $private);
        self::assertInstanceOf(R2Client::class, $private->client());
    }

    public function testAutowiringAliases(): void
    {
        // Checked before compiling: unused private aliases are removed then (apps use them first).
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $extension = (new ElviwebR2StorageBundle())->getContainerExtension();
        \assert(null !== $extension);
        $extension->load([['storages' => ['public' => ['driver' => 'local'], 'private' => ['driver' => 'r2', 'bucket' => 'site-private']]]], $container);

        self::assertSame('elviweb_r2_storage.storage.public', (string) $container->getAlias(StorageInterface::class), 'the first storage is the default');
        self::assertTrue($container->hasAlias(StorageInterface::class.' $privateStorage'), 'injectable by name');
        self::assertTrue($container->hasAlias(R2Client::class));
    }

    public function testUnknownDefaultStorageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->container(['default_storage' => 'nope', 'storages' => ['public' => ['driver' => 'local']]]);
    }
}
