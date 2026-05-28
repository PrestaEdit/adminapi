<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

use PrestaEdit\ApiModule\Resource\Contact\ContactResource;

class ResourceRegistry
{
    /** @var string[] All registered resource classes */
    private static array $resources = [
        ContactResource::class,
        // Other resources added in future tasks
    ];

    /** @var array<string, array<string, array{0:string,1:string,2:string[]}>>|null Route table, built on first call */
    private static ?array $routeTable = null;

    /** @var string[]|null All declared scopes */
    private static ?array $allScopes = null;

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Resolves path + method to [resourceClass, operation, params].
     * Returns null if no route matches.
     *
     * @return array{0:string,1:string,2:array<string,mixed>}|null
     */
    public static function resolve(string $path, string $method): ?array
    {
        $table = self::getRouteTable();

        // Normalize: strip trailing slash except root
        $path = rtrim($path, '/') ?: '/';

        foreach ($table as $pattern => $routes) {
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }
            if (!isset($routes[$method])) {
                continue;
            }
            [$class, $operation, $paramKeys] = $routes[$method];
            $params = [];
            foreach ($paramKeys as $key) {
                if (isset($matches[$key])) {
                    $params[$key] = $matches[$key];
                }
            }
            return [$class, $operation, $params];
        }

        return null;
    }

    public static function scopeExists(string $scope): bool
    {
        return in_array($scope, self::getAllScopes(), true);
    }

    public static function reset(): void
    {
        self::$routeTable = null;
        self::$allScopes  = null;
    }

    // ── Build ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, array{0:string,1:string,2:string[]}>>
     */
    private static function getRouteTable(): array
    {
        if (self::$routeTable !== null) {
            return self::$routeTable;
        }

        self::$routeTable = [];

        foreach (self::$resources as $class) {
            if (!class_exists($class)) {
                throw new \LogicException("ResourceRegistry: class {$class} is not loadable.");
            }
            $def        = $class::definition();
            $uriTpl     = $def['uriTemplate'];
            $operations = $def['operations'];

            foreach ($operations as $operation => $opDef) {
                $method    = strtoupper($opDef['method']);
                $uriSuffix = $opDef['uriSuffix'] ?? null;

                if ($uriSuffix !== null) {
                    // Fixed-suffix route: /contacts/bulk-delete
                    $pattern = self::buildPattern($uriTpl . $uriSuffix);
                    self::$routeTable[$pattern][$method] = [$class, $operation, []];
                } elseif (in_array($operation, ['get', 'update', 'delete'], true)) {
                    // Item route: /contacts/{id}
                    $pattern = self::buildPattern($uriTpl . '/(?P<id>[0-9]+)');
                    self::$routeTable[$pattern][$method] = [$class, $operation, ['id']];
                } elseif (in_array($operation, ['list', 'create'], true)) {
                    // Collection route: /contacts
                    $pattern = self::buildPattern($uriTpl);
                    self::$routeTable[$pattern][$method] = [$class, $operation, []];
                }
            }
        }

        return self::$routeTable;
    }

    private static function buildPattern(string $uri): string
    {
        return '#^' . $uri . '$#';
    }

    /**
     * @return string[]
     */
    private static function getAllScopes(): array
    {
        if (self::$allScopes !== null) {
            return self::$allScopes;
        }
        self::$allScopes = [];
        foreach (self::$resources as $class) {
            foreach ($class::definition()['operations'] as $opDef) {
                self::$allScopes[] = $opDef['scope'];
            }
        }
        return array_values(array_unique(self::$allScopes));
    }
}
