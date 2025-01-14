<?php

namespace Draw\Bundle\FrameworkExtraBundle\Bridge\Monolog\Processor;

use Symfony\Component\HttpFoundation\RequestStack;

class RequestHeadersProcessor
{
    protected const UPPER = '_ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    protected const LOWER = '-abcdefghijklmnopqrstuvwxyz';

    private ?array $onlyHeaders;

    private ?array $ignoreHeaders;

    public function __construct(
        private ?RequestStack $requestStack = null,
        array $onlyHeaders = [],
        array $ignoreHeaders = [],
        private string $key = 'request_headers'
    ) {
        $this->onlyHeaders = array_flip(array_map($this->normalizeHeaderName(...), $onlyHeaders));
        $this->ignoreHeaders = array_flip(array_map($this->normalizeHeaderName(...), $ignoreHeaders));
    }

    public function __invoke(array $records): array
    {
        if (null === $this->requestStack) {
            return $records;
        }

        if (!$request = $this->requestStack->getMainRequest()) {
            return $records;
        }

        $headers = $request->headers->all();

        if ($this->onlyHeaders) {
            $headers = array_intersect_key($headers, $this->onlyHeaders);
        }

        if ($this->ignoreHeaders) {
            $headers = array_diff_key($headers, $this->ignoreHeaders);
        }

        $records['extra'][$this->key] = $headers;

        return $records;
    }

    public function normalizeHeaderName(string $header): string
    {
        return strtr($header, self::UPPER, self::LOWER);
    }
}
