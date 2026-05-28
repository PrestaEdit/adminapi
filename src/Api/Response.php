<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use Psr\Http\Message\ResponseInterface;

class Response
{
    private int $statusCode;
    /** @var array<string, mixed>|null */
    private ?array $data;
    /** @var array<string, string> */
    private array $headers = ['Content-Type' => 'application/json; charset=utf-8'];

    private function __construct(int $statusCode, ?array $data)
    {
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public static function ok(array $data): self
    {
        return new self(200, $data);
    }

    public static function created(array $data): self
    {
        return new self(201, $data);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function error(int $status, string $detail): self
    {
        return new self($status, [
            'type'   => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title'  => 'An error occurred',
            'status' => $status,
            'detail' => $detail,
        ]);
    }

    public static function validationError(array $errors): self
    {
        return new self(422, [
            'type'       => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title'      => 'An error occurred',
            'status'     => 422,
            'detail'     => 'Validation failed',
            'violations' => $errors,
        ]);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function toPsr(ResponseInterface $base): ResponseInterface
    {
        $response = $base->withStatus($this->statusCode);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($this->data !== null) {
            $encoded = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                error_log('[apimodule] json_encode failed: ' . json_last_error_msg());
                $encoded = '{"error":"Response encoding failed"}';
            }
            $response->getBody()->write($encoded);
        }
        return $response;
    }
}
