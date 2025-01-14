<?php

namespace Draw\Component\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class DecoratedLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private LoggerInterface $logger,
        private array $defaultContext = [],
        private string $decorateMessage = '{message}',
    ) {
        if (!str_contains($this->decorateMessage, '{message}')) {
            $this->decorateMessage .= ' {message}';
        }
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logger->log(
            $level,
            str_replace('{message}', $message, $this->decorateMessage),
            array_merge($this->defaultContext, $context)
        );
    }
}
