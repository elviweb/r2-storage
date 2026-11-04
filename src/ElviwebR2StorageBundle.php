<?php

declare(strict_types=1);

namespace Elviweb\R2Storage;

use Elviweb\R2Storage\Storage\StorageFactory;
use Elviweb\R2Storage\Storage\StorageInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Symfony integration. Example (config/packages/elviweb_r2_storage.yaml):
 *
 *     elviweb_r2_storage:
 *         r2:
 *             endpoint: '%env(R2_ENDPOINT)%'
 *             access_key_id: '%env(R2_ACCESS_KEY_ID)%'
 *             secret_access_key: '%env(R2_SECRET_ACCESS_KEY)%'
 *         storages:
 *             public:
 *                 driver: '%env(STORAGE_DRIVER)%'   # local | r2
 *                 bucket: '%env(R2_PUBLIC_BUCKET)%'
 *                 public_url: '%env(R2_PUBLIC_URL)%'
 *                 directory: '%kernel.project_dir%/public/uploads'
 *
 * Then inject StorageInterface (the default storage) or StorageInterface $publicStorage
 * (by name), and R2Client for presigned upload links.
 */
final class ElviwebR2StorageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'elviweb_r2_storage';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('r2')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('endpoint')->defaultValue('')->info('https://<account-id>.r2.cloudflarestorage.com')->end()
                        ->scalarNode('access_key_id')->defaultValue('')->end()
                        ->scalarNode('secret_access_key')->defaultValue('')->end()
                        ->scalarNode('region')->defaultValue('auto')->end()
                        ->integerNode('retries')->defaultValue(2)->min(0)->max(5)->end()
                    ->end()
                ->end()
                ->scalarNode('default_storage')->defaultNull()->info('Name of the storage injected as StorageInterface (default: the first one)')->end()
                ->arrayNode('storages')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('driver')->defaultValue('local')->info('local or r2 (may come from an env var)')->end()
                            ->scalarNode('bucket')->defaultValue('')->end()
                            ->scalarNode('public_url')->defaultNull()->info('Public bucket address (custom domain); empty: private, links are presigned')->end()
                            ->scalarNode('directory')->defaultValue('%kernel.project_dir%/public/uploads')->end()
                            ->scalarNode('local_url')->defaultValue('/uploads')->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{r2: array{endpoint: string, access_key_id: string, secret_access_key: string, region: string, retries: int}, default_storage: ?string, storages: array<string, array{driver: string, bucket: string, public_url: ?string, directory: string, local_url: string}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('elviweb_r2_storage.config', R2Config::class)
            ->args([$config['r2']['endpoint'], $config['r2']['access_key_id'], $config['r2']['secret_access_key'], $config['r2']['region']]);
        $services->set('elviweb_r2_storage.client', R2Client::class)
            ->args([service('http_client'), service('elviweb_r2_storage.config'), null, $config['r2']['retries']]);
        $services->alias(R2Client::class, 'elviweb_r2_storage.client');

        $default = $config['default_storage'] ?? array_key_first($config['storages']);
        if (null !== $default && !isset($config['storages'][$default])) {
            throw new \InvalidArgumentException(sprintf('elviweb_r2_storage.default_storage "%s" is not one of the storages.', $default));
        }
        foreach ($config['storages'] as $name => $storage) {
            $id = 'elviweb_r2_storage.storage.'.$name;
            $services->set($id, StorageInterface::class)
                ->factory([StorageFactory::class, 'create'])
                ->args([$storage['driver'], service('elviweb_r2_storage.client'), $storage['bucket'], $storage['public_url'], $storage['directory'], $storage['local_url']])
                ->public();
            $builder->registerAliasForArgument($id, StorageInterface::class, $name.'Storage');
        }
        if (null !== $default) {
            $services->alias(StorageInterface::class, 'elviweb_r2_storage.storage.'.$default);
        }
    }
}
