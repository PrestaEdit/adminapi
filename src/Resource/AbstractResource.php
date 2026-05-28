<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

abstract class AbstractResource
{
    // ── Localized fields ────────────────────────────────────────────────────

    /**
     * Converts [id_lang => value] to [locale => value].
     * e.g. [1 => 'T-Shirt', 2 => 'T-Shirt FR'] → ['en-US' => 'T-Shirt', 'fr-FR' => 'T-Shirt FR']
     *
     * @param array<int,string> $psLangArray
     * @return array<string,string>
     */
    protected function getLocalizedField(array $psLangArray): array
    {
        $result = [];
        foreach (\Language::getLanguages(false, false, false) as $lang) {
            $locale = $lang['locale'];
            $result[$locale] = $psLangArray[(int) $lang['id_lang']] ?? '';
        }
        return $result;
    }

    /**
     * Builds [id_lang => value] from [locale => value] for ObjectModel.
     *
     * @param array<string,string> $localizedData
     * @return array<int,string>
     */
    protected function buildPsLocalizedField(array $localizedData): array
    {
        $result = [];
        foreach (\Language::getLanguages(false, false, false) as $lang) {
            $locale = $lang['locale'];
            if (isset($localizedData[$locale])) {
                $result[(int) $lang['id_lang']] = $localizedData[$locale];
            }
        }
        return $result;
    }

    // ── Decimals ────────────────────────────────────────────────────────────

    /**
     * Serializes a decimal to a 6-decimal string (never float).
     *
     * @param mixed $value
     */
    protected function decimal($value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    // ── Pagination ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters
     * @return array{limit:int,offset:int,orderBy:string,sortOrder:string}
     */
    protected function getPaginationParams(array $filters, string $defaultOrderBy = 'id'): array
    {
        $limit     = max(1, min(100, (int) ($filters['limit']    ?? 20)));
        $offset    = max(0, (int) ($filters['offset']   ?? 0));
        $orderBy   = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($filters['orderBy'] ?? $defaultOrderBy));
        $sortOrder = strtolower($filters['sortOrder'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        return compact('limit', 'offset', 'orderBy', 'sortOrder');
    }

    /** Applies LIMIT/OFFSET to a DbQuery */
    protected function applyPagination(\DbQuery $query, array $filters, string $defaultOrderBy = 'id'): void
    {
        $p = $this->getPaginationParams($filters, $defaultOrderBy);
        $query->limit($p['limit'], $p['offset']);
    }

    /** Applies ORDER BY to a DbQuery with column mapping */
    protected function applySort(\DbQuery $query, array $filters, string $defaultColumn, array $columnMap): void
    {
        $p      = $this->getPaginationParams($filters);
        $column = $columnMap[$p['orderBy']] ?? $defaultColumn;
        $query->orderBy("{$column} {$p['sortOrder']}");
    }

    /**
     * Counts total rows of a DbQuery via subquery.
     * Do NOT use clone + select() — DbQuery::select() appends, not replaces.
     */
    protected function countQuery(\DbQuery $query): int
    {
        $row = \Db::getInstance()->getRow(
            'SELECT COUNT(*) AS n FROM (' . $query->build() . ') AS subcount'
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Builds the standard paginated response.
     *
     * @param array<array<string,mixed>> $items
     * @param array<string,mixed> $filters
     */
    protected function paginatedResponse(array $items, int $total, array $filters): array
    {
        $p = $this->getPaginationParams($filters);
        return [
            'items'      => $items,
            'totalItems' => $total,
            'offset'     => $p['offset'],
            'limit'      => $p['limit'],
            'orderBy'    => $p['orderBy'],
            'sortOrder'  => strtolower($p['sortOrder']),
        ];
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @param string[] $requiredFields
     * @throws \PrestaEdit\ApiModule\Exception\ValidationException
     */
    protected function requireFields(array $data, array $requiredFields): void
    {
        $errors = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = ['This field is required.'];
            }
        }
        if (!empty($errors)) {
            throw new \PrestaEdit\ApiModule\Exception\ValidationException($errors);
        }
    }

    // ── Bulk delete ─────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    public function bulkDelete(array $data, array $context): void
    {
        throw new \RuntimeException('bulkDelete not implemented for ' . static::class, 405);
    }
}
