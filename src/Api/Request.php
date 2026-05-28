<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use Psr\Http\Message\ServerRequestInterface;

class Request
{
    private ServerRequestInterface $psr;

    public function __construct(ServerRequestInterface $psr)
    {
        $this->psr = $psr;
    }

    public function getMethod(): string
    {
        return strtoupper($this->psr->getMethod());
    }

    /** URI path only, e.g. /admin-api/products/42 */
    public function getPath(): string
    {
        return $this->psr->getUri()->getPath();
    }

    public function getBearerToken(): ?string
    {
        $header = $this->psr->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return array<string, string|string[]> */
    public function getQueryParams(): array
    {
        return $this->psr->getQueryParams();
    }

    public function getQueryParam(string $key, ?string $default = null): ?string
    {
        return isset($this->psr->getQueryParams()[$key])
            ? (string) $this->psr->getQueryParams()[$key]
            : $default;
    }

    /** Parsed JSON body or form-data as array */
    public function getBody(): array
    {
        $body = $this->psr->getParsedBody();
        if (is_array($body) && !empty($body)) {
            return $body;
        }
        $raw = (string) $this->psr->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function getPsr(): ServerRequestInterface
    {
        return $this->psr;
    }
}
