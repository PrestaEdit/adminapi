<?php
declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use League\OAuth2\Server\Exception\OAuthServerException;
use PrestaEdit\ApiModule\OAuth2\AuthorizationServer;

class AdminapiApiModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        $factory    = new Psr17Factory();
        $creator    = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $psrRequest = $creator->fromGlobals();
        $psrResponse = $factory->createResponse();

        // Normalise scope[] → scope (space-separated) for client_credentials grant
        $body = $psrRequest->getParsedBody();
        if (is_array($body) && isset($body['scope']) && is_array($body['scope'])) {
            $body['scope'] = implode(' ', $body['scope']);
            $psrRequest = $psrRequest->withParsedBody($body);
        }

        $uri = $psrRequest->getUri()->getPath();

        // Token endpoint — PHP 7.4 compatible suffix check (no str_ends_with)
        $tokenSuffix = '/admin-api/access_token';
        if (substr($uri, -strlen($tokenSuffix)) === $tokenSuffix) {
            try {
                $response = AuthorizationServer::getInstance()
                    ->respondToAccessTokenRequest($psrRequest, $psrResponse);
            } catch (OAuthServerException $e) {
                $response = $e->generateHttpResponse($psrResponse);
            } catch (\Throwable $e) {
                error_log('[adminapi] token endpoint error: ' . $e->getMessage());
                $stream = $factory->createStream(
                    (string) json_encode([
                        'error'             => 'server_error',
                        'error_description' => 'An internal server error occurred.',
                    ])
                );
                $response = $psrResponse
                    ->withStatus(500)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($stream);
            }
            $this->sendPsrResponse($response);
            return;
        }

        // API resources — Dispatcher (Task 4)
        $dispatcher = new \PrestaEdit\ApiModule\Api\Dispatcher();
        $psrResponse2 = $dispatcher->dispatch($psrRequest);
        $this->sendPsrResponse($psrResponse2->toPsr($psrResponse));
    }

    private function sendPsrResponse(\Psr\Http\Message\ResponseInterface $response): void
    {
        foreach (['Content-Type', 'Expires', 'Cache-Control', 'Pragma', 'X-Powered-By'] as $h) {
            header_remove($h);
        }
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}", false);
            }
        }
        echo $response->getBody();
        exit;
    }
}
