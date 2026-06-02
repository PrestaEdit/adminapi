<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use League\OAuth2\Server\Exception\OAuthServerException;
use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Exception\ValidationException;
use PrestaEdit\ApiModule\OAuth2\ResourceServer;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;
use Psr\Http\Message\ServerRequestInterface;

class Dispatcher
{
    public function dispatch(ServerRequestInterface $psrRequest): Response
    {
        $request = new Request($psrRequest);

        // 1. Validate Bearer token
        try {
            $authenticatedRequest = ResourceServer::getInstance()
                ->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return Response::error($e->getHttpStatusCode(), $e->getMessage());
        }

        $tokenScopes = (array) $authenticatedRequest->getAttribute('oauth_scopes', []);

        // 2. Resolve route
        $path   = $this->extractApiPath($request->getPath());
        $method = $request->getMethod();

        // Self-introspection: any authenticated client may read its own info.
        // Handled here because it is not a CRUD resource route.
        if ($method === 'GET' && $path === '/api-client/infos') {
            return $this->clientInfos(
                (string) $authenticatedRequest->getAttribute('oauth_client_id', ''),
                $tokenScopes
            );
        }

        $resolved = ResourceRegistry::resolve($path, $method);
        if ($resolved === null) {
            return Response::error(404, "Route {$method} {$path} not found.");
        }

        [$resourceClass, $operation, $params] = $resolved;

        // 3. Check scope
        $definition    = $resourceClass::definition();
        $requiredScope = $definition['operations'][$operation]['scope'];

        if (!in_array($requiredScope, $tokenScopes, true)) {
            return Response::error(
                403,
                "Scope '{$requiredScope}' is required for this operation."
            );
        }

        // 4. Resolve shop context
        try {
            $context = ShopContextResolver::fromRequest($request);
        } catch (\InvalidArgumentException $e) {
            return Response::error(400, $e->getMessage());
        }

        // 5. Dispatch to resource handler
        $resource = new $resourceClass();

        try {
            switch ($operation) {
                case 'get':
                    return Response::ok($resource->get((int) $params['id'], $context));

                case 'list':
                    return Response::ok($resource->list($request->getQueryParams(), $context));

                case 'create':
                    return Response::created($resource->create($request->getBody(), $context));

                case 'update':
                    return Response::ok($resource->update((int) $params['id'], $request->getBody(), $context));

                case 'delete':
                    $resource->delete((int) $params['id'], $context);
                    return Response::noContent();

                case 'bulkDelete':
                    $resource->bulkDelete($request->getBody(), $context);
                    return Response::noContent();

                default:
                    return Response::error(405, "Operation '{$operation}' not supported.");
            }
        } catch (ResourceNotFoundException $e) {
            return Response::error(404, $e->getMessage());
        } catch (ValidationException $e) {
            return Response::validationError($e->getErrors());
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;
            error_log('[adminapi] dispatcher error: ' . $e->getMessage());
            return Response::error($status, 'An internal server error occurred.');
        }
    }

    /**
     * Returns the identity and granted scopes of the client owning the token.
     * Mirrors the official Admin API's GET /api-client/infos endpoint.
     *
     * @param string[] $tokenScopes
     */
    private function clientInfos(string $clientId, array $tokenScopes): Response
    {
        if ($clientId === '') {
            return Response::error(401, 'Unable to identify the authenticated client.');
        }

        $row = \Db::getInstance()->getRow(
            'SELECT `id`, `client_id`, `client_name`, `scopes`, `active`, `date_add`, `date_upd`
             FROM `' . _DB_PREFIX_ . 'adminapi_client`
             WHERE `client_id` = "' . pSQL($clientId) . '"'
        );
        if (!$row) {
            return Response::error(404, 'Authenticated client not found.');
        }

        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);

        return Response::ok([
            'apiClientId' => (int) $row['id'],
            'clientId'    => $row['client_id'],
            'clientName'  => $row['client_name'],
            'scopes'      => is_array($scopes) ? $scopes : [],
            'tokenScopes' => array_values($tokenScopes),
            'active'      => (bool) $row['active'],
            'dateAdd'     => $row['date_add'] ?? '',
            'dateUpd'     => $row['date_upd'] ?? '',
        ]);
    }

    /** Strips /admin-api prefix, e.g. /admin-api/products/42 → /products/42 */
    private function extractApiPath(string $fullPath): string
    {
        $pos = strpos($fullPath, '/admin-api');
        if ($pos === false) {
            return $fullPath;
        }
        return substr($fullPath, $pos + strlen('/admin-api')) ?: '/';
    }
}
