<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use PrestaEdit\ApiModule\Resource\ResourceRegistry;

class OpenApiGenerator
{
    private const OPENAPI_VERSION = '3.0.3';
    private const API_VERSION     = '1.0.0';
    private const TOKEN_URL       = '/admin-api/access_token';

    /**
     * @return array<string,mixed>
     */
    public function generate(): array
    {
        return [
            'openapi'    => self::OPENAPI_VERSION,
            'info'       => [
                'title'       => 'PrestaShop Admin API (adminapi)',
                'description' => 'OAuth2-secured Admin API — port of ps_apiresources for PS 1.7/8.',
                'version'     => self::API_VERSION,
            ],
            'servers'    => [
                ['url' => '/admin-api', 'description' => 'Admin API base path'],
            ],
            'paths'      => $this->buildPaths(),
            'components' => [
                'securitySchemes' => [
                    'oauth2' => [
                        'type'  => 'oauth2',
                        'flows' => [
                            'clientCredentials' => [
                                'tokenUrl' => self::TOKEN_URL,
                                'scopes'   => $this->buildScopeMap(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function buildScopeMap(): array
    {
        $map = [];
        foreach (ResourceRegistry::allScopes() as $scope) {
            $map[$scope] = 'Grants ' . str_replace('_', ' ', $scope) . ' access.';
        }
        ksort($map);
        return $map;
    }

    /**
     * @return array<string, array<string, array<string,mixed>>>
     */
    private function buildPaths(): array
    {
        $paths = [];

        foreach (ResourceRegistry::getResourceClasses() as $class) {
            $def        = $class::definition();
            $uriTpl     = $def['uriTemplate'];
            $shortName  = $this->shortName($class);
            $operations = $def['operations'];

            foreach ($operations as $operation => $opDef) {
                $httpMethod = strtolower($opDef['method']);
                $scope      = $opDef['scope'];
                $uriSuffix  = $opDef['uriSuffix'] ?? null;

                if ($uriSuffix !== null) {
                    $path = $uriTpl . $uriSuffix;
                } elseif (in_array($operation, ['get', 'update', 'delete'], true)) {
                    $path = $uriTpl . '/{id}';
                } else { // list, create
                    $path = $uriTpl;
                }

                $paths[$path][$httpMethod] = $this->buildOperation($operation, $shortName, $scope, $path);
            }
        }

        // Special self-introspection endpoint (not a CRUD resource route).
        $paths['/api-client/infos']['get'] = $this->clientInfosOperation();

        ksort($paths);
        return $paths;
    }

    /**
     * GET /api-client/infos — identity and scopes of the authenticated client.
     * Available to any valid token, so no specific scope is required.
     *
     * @return array<string,mixed>
     */
    private function clientInfosOperation(): array
    {
        return [
            'operationId' => 'getApiClientInfos',
            'tags'        => ['ApiClient'],
            'summary'     => 'Get information about the authenticated API client',
            'security'    => [['oauth2' => []]],
            'responses'   => [
                '200' => [
                    'description' => 'OK',
                    'content'     => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'apiClientId' => ['type' => 'integer'],
                                    'clientId'    => ['type' => 'string'],
                                    'clientName'  => ['type' => 'string'],
                                    'scopes'      => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'tokenScopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'active'      => ['type' => 'boolean'],
                                    'dateAdd'     => ['type' => 'string'],
                                    'dateUpd'     => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                '401' => ['description' => 'Missing or invalid access token'],
                '404' => ['description' => 'Authenticated client not found'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOperation(string $operation, string $shortName, string $scope, string $path): array
    {
        $op = [
            'operationId' => $operation . $shortName,
            'tags'        => [$shortName],
            'security'    => [['oauth2' => [$scope]]],
            'responses'   => $this->responsesFor($operation),
        ];

        if ($operation === 'list') {
            $op['parameters'] = $this->paginationParameters();
        }

        if (strpos($path, '{id}') !== false) {
            $op['parameters'] = array_merge($op['parameters'] ?? [], [[
                'name'     => 'id',
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'integer'],
            ]]);
        }

        return $op;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function responsesFor(string $operation): array
    {
        $common = [
            '401' => ['description' => 'Missing or invalid access token'],
            '403' => ['description' => 'Insufficient scope'],
        ];

        switch ($operation) {
            case 'create':
                return ['201' => ['description' => 'Created']] + ['422' => ['description' => 'Validation failed']] + $common;
            case 'delete':
            case 'bulkDelete':
                return ['204' => ['description' => 'No Content']] + ['404' => ['description' => 'Not found']] + $common;
            case 'update':
                return ['200' => ['description' => 'OK']] + ['404' => ['description' => 'Not found']] + ['422' => ['description' => 'Validation failed']] + $common;
            case 'get':
                return ['200' => ['description' => 'OK']] + ['404' => ['description' => 'Not found']] + $common;
            case 'list':
            default:
                return ['200' => ['description' => 'OK']] + $common;
        }
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function paginationParameters(): array
    {
        return [
            ['name' => 'limit',     'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100]],
            ['name' => 'offset',    'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 0, 'minimum' => 0]],
            ['name' => 'orderBy',   'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'sortOrder', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc']],
        ];
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        $base  = end($parts);
        return preg_replace('/Resource$/', '', $base) ?: $base;
    }
}
