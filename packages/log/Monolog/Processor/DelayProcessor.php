<?php

namespace Draw\Component\Log\Monolog\Processor;

class DelayProcessor
{
    private ?float $start = null;

    public function __construct(private string $key = 'delay')
    {
    }

    public function __invoke(array $records): array
    {
        if (null === $this->start) {
            $this->start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        }

        $records['extra'][$this->key] = number_format(microtime(true) - $this->start, 2);

        return $records;
    }

    public function reset(): void
    {
        $this->start = microtime(true);
    }
}
