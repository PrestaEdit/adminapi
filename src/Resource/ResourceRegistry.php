<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

use PrestaEdit\ApiModule\Resource\Contact\ContactResource;
use PrestaEdit\ApiModule\Resource\Zone\ZoneResource;
use PrestaEdit\ApiModule\Resource\Hook\HookResource;
use PrestaEdit\ApiModule\Resource\TaxRulesGroup\TaxRulesGroupResource;
use PrestaEdit\ApiModule\Resource\SearchEngine\SearchEngineResource;
use PrestaEdit\ApiModule\Resource\SearchAlias\SearchAliasResource;
use PrestaEdit\ApiModule\Resource\WebserviceKey\WebserviceKeyResource;
use PrestaEdit\ApiModule\Resource\Title\TitleResource;
use PrestaEdit\ApiModule\Resource\Profile\ProfileResource;
use PrestaEdit\ApiModule\Resource\Tax\TaxResource;
use PrestaEdit\ApiModule\Resource\Country\CountryResource;
use PrestaEdit\ApiModule\Resource\Tab\TabResource;
use PrestaEdit\ApiModule\Resource\Manufacturer\ManufacturerResource;
use PrestaEdit\ApiModule\Resource\Supplier\SupplierResource;
use PrestaEdit\ApiModule\Resource\Store\StoreResource;
use PrestaEdit\ApiModule\Resource\Address\AddressResource;
use PrestaEdit\ApiModule\Resource\AttributeGroup\AttributeGroupResource;
use PrestaEdit\ApiModule\Resource\Attribute\AttributeResource;
use PrestaEdit\ApiModule\Resource\Feature\FeatureResource;
use PrestaEdit\ApiModule\Resource\FeatureValue\FeatureValueResource;
use PrestaEdit\ApiModule\Resource\CustomerGroup\CustomerGroupResource;
use PrestaEdit\ApiModule\Resource\Customer\CustomerResource;
use PrestaEdit\ApiModule\Resource\Category\CategoryResource;

class ResourceRegistry
{
    /** @var string[] All registered resource classes */
    private static array $resources = [
        ContactResource::class,
        ZoneResource::class,
        HookResource::class,
        TaxRulesGroupResource::class,
        SearchEngineResource::class,
        SearchAliasResource::class,
        WebserviceKeyResource::class,
        TitleResource::class,
        ProfileResource::class,
        TaxResource::class,
        CountryResource::class,
        TabResource::class,
        ManufacturerResource::class,
        SupplierResource::class,
        StoreResource::class,
        AddressResource::class,
        AttributeGroupResource::class,
        AttributeResource::class,
        FeatureResource::class,
        FeatureValueResource::class,
        CustomerGroupResource::class,
        CustomerResource::class,
        CategoryResource::class,
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
