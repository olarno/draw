<?php

namespace Draw\Bundle\FrameworkExtraBundle\DependencyInjection\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Draw\Component\OpenApi\Cleaner\ReferenceCleanerInterface;
use Draw\Component\OpenApi\Controller\OpenApiController;
use Draw\Component\OpenApi\EventListener\RequestQueryParameterFetcherListener;
use Draw\Component\OpenApi\EventListener\RequestValidationListener;
use Draw\Component\OpenApi\EventListener\ResponseApiExceptionListener;
use Draw\Component\OpenApi\EventListener\ResponseSerializerListener;
use Draw\Component\OpenApi\EventListener\SchemaAddDefaultHeadersListener;
use Draw\Component\OpenApi\EventListener\SchemaSorterListener;
use Draw\Component\OpenApi\EventListener\TagCleanerListener;
use Draw\Component\OpenApi\EventListener\UnReferenceCleanerListener;
use Draw\Component\OpenApi\Exception\ConstraintViolationListException;
use Draw\Component\OpenApi\Extraction\ExtractionContext;
use Draw\Component\OpenApi\Extraction\Extractor\Caching\LoadFromCacheExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\Caching\StoreInCacheExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\Constraint\BaseConstraintExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\Constraint\ConstraintExtractionContext;
use Draw\Component\OpenApi\Extraction\Extractor\Doctrine\InheritanceExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\JmsSerializer\Event\PropertyExtractedEvent;
use Draw\Component\OpenApi\Extraction\Extractor\JmsSerializer\PropertiesExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\JmsSerializer\TypeHandler\TypeToSchemaHandlerInterface;
use Draw\Component\OpenApi\Extraction\Extractor\OpenApi\VersionLinkDocumentationExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\PhpDoc\OperationExtractor;
use Draw\Component\OpenApi\Extraction\Extractor\TypeSchemaExtractor;
use Draw\Component\OpenApi\Extraction\ExtractorInterface;
use Draw\Component\OpenApi\Naming\AliasesClassNamingFilter;
use Draw\Component\OpenApi\OpenApi;
use Draw\Component\OpenApi\Request\ParamConverter\DeserializeBodyParamConverter;
use Draw\Component\OpenApi\Request\ValueResolver\RequestBody;
use Draw\Component\OpenApi\Request\ValueResolver\RequestBodyValueResolver;
use Draw\Component\OpenApi\SchemaBuilder\SchemaBuilderInterface;
use Draw\Component\OpenApi\SchemaBuilder\SymfonySchemaBuilder;
use Draw\Component\OpenApi\Scope;
use Draw\Component\OpenApi\Serializer\Construction\DoctrineObjectConstructor;
use Draw\Component\OpenApi\Serializer\Serialization;
use Draw\Component\OpenApi\Versioning\RouteDefaultApiRouteVersionMatcher;
use Draw\Component\OpenApi\Versioning\RouteVersionMatcherInterface;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use Metadata\MetadataFactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class OpenApiIntegration implements IntegrationInterface
{
    use IntegrationTrait;

    public function getConfigSectionName(): string
    {
        return 'open_api';
    }

    public function addConfiguration(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->append($this->createOpenApiNode())
                ->append($this->createRequestNode())
                ->append($this->createResponseNode())
            ->end();
    }

    public function load(array $config, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        $container
            ->registerForAutoconfiguration(ReferenceCleanerInterface::class)
            ->addTag('draw.open_api.reference_cleaner');

        $this->configOpenApi($config['openApi'], $loader, $container);
        $this->configResponse($config['response'], $loader, $container);
        $this->configRequest($config['request'], $loader, $container);

        $this->removeDefinitions(
            $container,
            [
                Serialization::class,
                BaseConstraintExtractor::class,
                ExtractionContext::class,
                ConstraintExtractionContext::class,
                PropertyExtractedEvent::class,
                Scope::class,
            ]
        );

        $this->renameDefinitions(
            $container,
            OpenApi::class,
            'draw.open_api'
        );

        $this->renameDefinitions(
            $container,
            'Draw\\Component\\OpenApi\\Request\\ParamConverter\\',
            'draw.open_api.param_converter.'
        );

        $this->renameDefinitions(
            $container,
            'Draw\\Component\\OpenApi\\Extraction\\Extractor\\',
            'draw.open_api.extractor.'
        );

        $this->renameDefinitions(
            $container,
            'Draw\\Component\\OpenApi\\Serializer\\',
            'draw.open_api.jms_serializer.'
        );

        $this->renameDefinitions(
            $container,
            'Draw\\Component\\OpenApi\\',
            'draw.open_api.'
        );
    }

    private function configOpenApi(array $config, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config)) {
            return;
        }

        $container->setAlias(
            PropertyNamingStrategyInterface::class,
            'jms_serializer.naming_strategy'
        );

        $container->setAlias(
            MetadataFactoryInterface::class,
            'jms_serializer.metadata_factory'
        );

        $container->setParameter('draw_open_api.root_schema', $config['schema']);

        $container
            ->registerForAutoconfiguration(ExtractorInterface::class)
            ->addTag(ExtractorInterface::class);

        $container
            ->registerForAutoconfiguration(TypeToSchemaHandlerInterface::class)
            ->addTag(TypeToSchemaHandlerInterface::class);

        $definition = (new Definition())
            ->setAutowired(true)
            ->setAutoconfigured(true);

        $openApiComponentDir = \dirname((new \ReflectionClass(OpenApi::class))->getFileName());

        $exclude = [
            $openApiComponentDir.'/Event/',
            $openApiComponentDir.'/EventListener/{Request,Response}*',
            $openApiComponentDir.'/Exception/',
            $openApiComponentDir.'/Request/',
            $openApiComponentDir.'/Schema/',
            $openApiComponentDir.'/SchemaBuilder/',
            $openApiComponentDir.'/Tests/',
        ];

        if (!$config['caching_enabled']) {
            $exclude[] = $openApiComponentDir.'/Extraction/Extractor/Caching/';
        }

        $loader->registerClasses(
            $definition,
            'Draw\\Component\\OpenApi\\',
            $openApiComponentDir,
            $exclude
        );

        $loader->registerClasses(
            $definition->addTag('controller.service_arguments'),
            'Draw\\Component\\OpenApi\\Controller\\',
            $openApiComponentDir.'/Controller'
        );

        $container
            ->getDefinition(TagCleanerListener::class)
            ->setArgument('$tagsToClean', $config['tags_to_clean']);

        $container
            ->getDefinition(UnReferenceCleanerListener::class)
            ->setArgument('$referenceCleaners', new TaggedIteratorArgument('draw.open_api.reference_cleaner'));

        if ($this->isConfigEnabled($container, $config['versioning'])) {
            $container
                ->getDefinition(VersionLinkDocumentationExtractor::class)
                ->setArgument('$versions', $config['versioning']['versions']);

            $container
                ->setAlias(RouteVersionMatcherInterface::class, RouteDefaultApiRouteVersionMatcher::class);
        } else {
            $container->removeDefinition(VersionLinkDocumentationExtractor::class);
            $container->removeDefinition(RouteDefaultApiRouteVersionMatcher::class);
        }

        if ($this->isConfigEnabled($container, $config['scoped'])) {
            $scopes = [];
            foreach ($config['scoped']['scopes'] as $scope) {
                $scopes[] = (new Definition(Scope::class))
                    ->setArgument('$name', $scope['name'])
                    ->setArgument('$tags', $scope['tags'] ?: null);
            }

            $container
                ->getDefinition(OpenApi::class)
                ->setArgument('$scopes', $scopes);
        }

        if (!class_exists(DoctrineBundle::class)) {
            $container->removeDefinition(InheritanceExtractor::class);
        }

        $container
            ->setDefinition(
                'draw.open_api.schema_builder',
                new Definition(SymfonySchemaBuilder::class)
            )
            ->setAutowired(true)
            ->setAutoconfigured(true);

        if ($config['caching_enabled']) {
            $arguments = [
                '$debug' => new Parameter('kernel.debug'),
                '$cacheDirectory' => new Parameter('kernel.cache_dir'),
            ];

            $container
                ->getDefinition(LoadFromCacheExtractor::class)
                ->setArguments($arguments);

            $container
                ->getDefinition(StoreInCacheExtractor::class)
                ->setArguments($arguments);
        }

        $container
            ->setAlias(
                SchemaBuilderInterface::class,
                'draw.open_api.schema_builder'
            );

        $container
            ->getDefinition(OpenApi::class)
            ->setArgument('$extractors', new TaggedIteratorArgument(ExtractorInterface::class));

        $container
            ->getDefinition(OpenApiController::class)
            ->setArgument('$sandboxUrl', $config['sandbox_url']);

        $container
            ->getDefinition(PropertiesExtractor::class)
            ->setArgument('$typeToSchemaHandlers', new TaggedIteratorArgument(TypeToSchemaHandlerInterface::class));

        if (!$config['headers']) {
            $container->removeDefinition(SchemaAddDefaultHeadersListener::class);
        } else {
            $container
                ->getDefinition(SchemaAddDefaultHeadersListener::class)
                ->setArgument('$headers', $config['headers']);
        }

        if (!$config['sort_schema']) {
            $container->removeDefinition(SchemaSorterListener::class);
        }

        if (!$config['definitionAliases']) {
            $container->removeDefinition(AliasesClassNamingFilter::class);
        } else {
            $config['classNamingFilters'][] = AliasesClassNamingFilter::class;
            $container
                ->getDefinition(AliasesClassNamingFilter::class)
                ->setArgument('$definitionAliases', $config['definitionAliases']);
        }

        $container
            ->getDefinition(DoctrineObjectConstructor::class)
            ->setArgument(
                '$fallbackConstructor',
                new Reference('jms_serializer.unserialize_object_constructor')
            );

        $namingFilterServices = [];
        foreach (array_unique($config['classNamingFilters']) as $serviceName) {
            $namingFilterServices[] = new Reference($serviceName);
        }

        $container
            ->getDefinition(TypeSchemaExtractor::class)
            ->setArgument('$classNamingFilters', $namingFilterServices);

        $container->setAlias(
            'jms_serializer.object_constructor',
            'draw.open_api.jms_serializer.construction.doctrine_object_constructor'
        );

        $container->setAlias(
            'jms_serializer.unserialize_object_constructor',
            'draw.open_api.jms_serializer.construction.simple_object_constructor'
        );
    }

    private function configResponse(array $config, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config)) {
            return;
        }

        $openApiComponentDir = \dirname((new \ReflectionClass(OpenApi::class))->getFileName());

        $definition = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $loader->registerClasses(
            $definition,
            'Draw\\Component\\OpenApi\\EventListener\\',
            $openApiComponentDir.'/EventListener/{Response}*',
        );

        $container->setParameter('draw_open_api.response.serialize_null', $config['serializeNull']);

        $container
            ->getDefinition(ResponseSerializerListener::class)
            ->setArgument('$serializeNull', new Parameter('draw_open_api.response.serialize_null'));

        $this->configResponseExceptionHandler($config['exceptionHandler'], $loader, $container);
    }

    private function configResponseExceptionHandler(array $config, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config)) {
            $container->removeDefinition(ResponseApiExceptionListener::class);

            return;
        }

        $codes = [];
        foreach ($config['exceptionsStatusCodes'] as $exceptionsStatusCodes) {
            $codes[$exceptionsStatusCodes['class']] = $exceptionsStatusCodes['code'];
        }

        if ($config['useDefaultExceptionsStatusCodes']) {
            $codes[ConstraintViolationListException::class] = 400;
            $codes[AccessDeniedException::class] = 403;
        }

        $container->setParameter('draw_open_api.response.exception_status_codes', $codes);

        $container->getDefinition(ResponseApiExceptionListener::class)
            ->setArgument(
                '$debug',
                new Parameter('kernel.debug')
            )
            ->setArgument(
                '$errorCodes',
                $codes
            )
            ->setArgument(
                '$violationKey',
                $config['violationKey']
            );

        $operationExtractorDefinition = $container->getDefinition(OperationExtractor::class);

        foreach ($codes as $exceptionClass => $code) {
            $operationExtractorDefinition
                ->addMethodCall(
                    'registerExceptionResponseCodes',
                    [$exceptionClass, $code]
                );
        }
    }

    private function configRequest(array $config, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config)) {
            return;
        }

        $openApiComponentDir = \dirname((new \ReflectionClass(OpenApi::class))->getFileName());

        $definition = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);

        $loader->registerClasses(
            $definition,
            'Draw\\Component\\OpenApi\\EventListener\\',
            $openApiComponentDir.'/EventListener/{Request}*',
        );

        $loader->registerClasses(
            $definition,
            'Draw\\Component\\OpenApi\\Request\\',
            $openApiComponentDir.'/Request',
        );

        $container->removeDefinition(RequestBody::class);

        $container
            ->getDefinition(RequestValidationListener::class)
            ->setArgument(
                '$prefixes',
                $config['validation']['pathPrefixes'] ??
                ['query' => '$.query', 'body' => '$.body']
            );

        if (!$config['queryParameter']['enabled']) {
            $container->removeDefinition(RequestQueryParameterFetcherListener::class);
        }

        if (!$config['bodyDeserialization']['enabled']) {
            $container->removeDefinition(DeserializeBodyParamConverter::class);
            $container->removeDefinition(RequestBodyValueResolver::class);
        } else {
            $container->getDefinition(DeserializeBodyParamConverter::class)
                ->addTag('request.param_converter', ['converter' => 'draw_open_api.request_body']);
        }
    }

    private function createOpenApiNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('openApi'))
            ->canBeDisabled()
            ->children()
                ->scalarNode('sandbox_url')->defaultValue('/open-api/sandbox')->end()
                ->booleanNode('caching_enabled')->defaultTrue()->end()
                ->booleanNode('sort_schema')->defaultFalse()->end()
                ->arrayNode('tags_to_clean')
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
                ->append($this->createVersioningNode())
                ->append($this->createScopedNode())
                ->append($this->createSchemaNode())
                ->append($this->createHeadersNode())
                ->append($this->createDefinitionAliasesNode())
                ->append($this->createNamingFiltersNode())
            ->end();
    }

    private function createVersioningNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('versioning'))
            ->canBeEnabled()
            ->children()
                ->arrayNode('versions')
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
            ->end();
    }

    private function createScopedNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('scoped'))
            ->canBeEnabled()
            ->children()
                ->arrayNode('scopes')
                    ->requiresAtLeastOneElement()
                    ->beforeNormalization()
                    ->always(function ($config) {
                        foreach ($config as $name => $configuration) {
                            if (!isset($configuration['name'])) {
                                $config[$name]['name'] = $name;
                            }
                        }

                        return $config;
                    })
                    ->end()
                    ->useAttributeAsKey('name', false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')
                                ->validate()
                                    ->ifTrue(fn ($value) => \is_int($value))
                                    ->thenInvalid('You must specify a name for the scope. Can be via the attribute or the key.')
                                ->end()
                                ->isRequired()
                            ->end()
                            ->arrayNode('tags')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function createRequestNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('request'))
            ->canBeDisabled()
            ->children()
                ->arrayNode('validation')
                    ->children()
                        ->arrayNode('pathPrefixes')
                            ->children()
                                ->scalarNode('query')->defaultValue('$.query')->end()
                                ->scalarNode('body')->defaultValue('$.body')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('queryParameter')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('bodyDeserialization')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('userRequestInterceptedException')
                    ->canBeEnabled()
                ->end()
            ->end();
    }

    private function createResponseNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('response'))
            ->canBeDisabled()
            ->children()
                ->booleanNode('serializeNull')->defaultTrue()->end()
                ->arrayNode('exceptionHandler')
                    ->canBeDisabled()
                    ->children()
                        ->booleanNode('useDefaultExceptionsStatusCodes')->defaultTrue()->end()
                        ->arrayNode('exceptionsStatusCodes')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('class')->isRequired()->end()
                                    ->integerNode('code')->isRequired()->end()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('violationKey')->defaultValue('errors')->end()
                    ->end()
                ->end()
            ->end();
    }

    private function createDefinitionAliasesNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('definitionAliases'))
            ->defaultValue([])
            ->arrayPrototype()
                ->children()
                    ->scalarNode('class')->isRequired()->end()
                    ->scalarNode('alias')->isRequired()->end()
                ->end()
            ->end();
    }

    private function createNamingFiltersNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('classNamingFilters'))
                ->defaultValue([AliasesClassNamingFilter::class])
                ->scalarPrototype()
            ->end();
    }

    private function createSchemaNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('schema'))
            ->normalizeKeys(false)
            ->ignoreExtraKeys(false)
            ->children()
                ->scalarNode('swagger')->defaultValue('2.0')->end()
                ->arrayNode('info')
                    ->children()
                        ->scalarNode('version')->defaultValue('1.0')->end()
                        ->scalarNode('contact')->end()
                        ->scalarNode('termsOfService')->end()
                        ->scalarNode('description')->end()
                        ->scalarNode('title')->end()
                    ->end()
                ->end()
                ->scalarNode('basePath')->end()
            ->end();
    }

    private function createHeadersNode(): ArrayNodeDefinition
    {
        return (new ArrayNodeDefinition('headers'))
            ->arrayPrototype()
                ->normalizeKeys(false)
                ->ignoreExtraKeys(false)
            ->end();
    }
}
