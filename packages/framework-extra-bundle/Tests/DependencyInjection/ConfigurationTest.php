<?php

namespace Draw\Bundle\FrameworkExtraBundle\Tests\DependencyInjection;

use Draw\Bundle\FrameworkExtraBundle\DependencyInjection\Configuration;
use Draw\Component\Tester\DependencyInjection\ConfigurationTestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ConfigurationTest extends ConfigurationTestCase
{
    public function createConfiguration(): ConfigurationInterface
    {
        return new Configuration();
    }

    public function getDefaultConfiguration(): array
    {
        return [
            'symfony_console_path' => null,
            'configuration' => [
                'enabled' => false,
            ],
            'jwt_encoder' => [
                'enabled' => false,
                'algorithm' => 'HS256',
            ],
            'log' => [
                'enabled' => false,
                'enable_all_processors' => false,
                'processor' => [
                    'console_command' => [
                        'enabled' => false,
                        'key' => 'command',
                        'includeArguments' => true,
                        'includeOptions' => false,
                    ],
                    'delay' => [
                        'enabled' => false,
                        'key' => 'delay',
                    ],
                    'request_headers' => [
                        'enabled' => false,
                        'key' => 'request_headers',
                        'onlyHeaders' => [],
                        'ignoreHeaders' => [],
                    ],
                    'token' => [
                        'enabled' => false,
                        'key' => 'token',
                    ],
                ],
            ],
            'logger' => [
                'enabled' => false,
                'slow_request' => [
                    'enabled' => false,
                    'default_duration' => 10000,
                    'request_matchers' => [],
                ],
            ],
            'messenger' => [
                'enabled' => true,
                'entity_class' => 'App\Entity\MessengerMessage',
                'tag_entity_class' => 'App\Entity\MessengerMessageTag',
                'async_routing_configuration' => [
                    'enabled' => false,
                ],
                'broker' => [
                    'enabled' => false,
                    'receivers' => [],
                    'default_options' => [],
                ],
                'application_version_monitoring' => [
                    'enabled' => false,
                ],
                'doctrine_message_bus_hook' => [
                    'enabled' => false,
                ],
            ],
            'process' => [
                'enabled' => true,
            ],
            'security' => [
                'enabled' => true,
            ],
            'tester' => [
                'enabled' => true,
            ],
            'versioning' => [
                'enabled' => false,
            ],
        ];
    }

    public function provideTestInvalidConfiguration(): iterable
    {
        yield [
            ['invalid' => true],
            'Unrecognized option invalid under draw_framework_extra. Available options are configuration, jwt_encoder, log, logger, messenger, process, security, symfony_console_path, tester, versioning.',
        ];
    }
}