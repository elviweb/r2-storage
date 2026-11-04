<?php

declare(strict_types=1);

namespace Elviweb\R2Storage;

use Elviweb\R2Storage\Storage\StorageFactory;
use Elviweb\R2Storage\Storage\StorageInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
 *
 * Extends HttpKernel's AbstractBundle, which works on Symfony 6.4 to 8.x; Symfony 8.1 marks its
 * BundleInterface deprecated in favour of DependencyInjection\Kernel, which 6.4 and 7.x don't have.
 *
 * @psalm-suppress DeprecatedInterface
 */
final class ElviwebR2StorageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'elviweb_r2_storage';

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        // Built without ->end() so every step keeps its type for static analysis.
        /** @var ArrayNodeDefinition $root the root of an "array" tree (the default) */
        $root = $definition->rootNode();
        $options = $root->children();

        $r2 = $options->arrayNode('r2')->addDefaultsIfNotSet()->children();
        $r2->scalarNode('endpoint')->defaultValue('')->info('https://<account-id>.r2.cloudflarestorage.com');
        $r2->scalarNode('access_key_id')->defaultValue('');
        $r2->scalarNode('secret_access_key')->defaultValue('');
        $r2->scalarNode('region')->defaultValue('auto');
        $r2->integerNode('retries')->defaultValue(2)->min(0)->max(5);

        $options->scalarNode('default_storage')->defaultNull()->info('Name of the storage injected as StorageInterface (default: the first one)');

        $storage = $options->arrayNode('storages')->useAttributeAsKey('name')->arrayPrototype()->children();
        $storage->scalarNode('driver')->defaultValue('local')->info('local or r2 (may come from an env var)');
        $storage->scalarNode('bucket')->defaultValue('');
        $storage->scalarNode('public_url')->defaultNull()->info('Public bucket address (custom domain); empty: private, links are presigned');
        $storage->scalarNode('directory')->defaultValue('%kernel.project_dir%/public/uploads');
        $storage->scalarNode('local_url')->defaultValue('/uploads');
    }

    /**
     * Symfony has already validated $config against configure(), hence the precise shape; the
     * parameter names differ between Symfony versions, so they can't match all of them.
     *
     * @psalm-suppress MoreSpecificImplementedParamType, ParamNameMismatch
     *
     * @param array{r2: array{endpoint: string, access_key_id: string, secret_access_key: string, region: string, retries: int}, default_storage: ?string, storages: array<string, array{driver: string, bucket: string, public_url: ?string, directory: string, local_url: string}>} $config
     */
    #[\Override]
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
