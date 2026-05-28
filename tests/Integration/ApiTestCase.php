<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected static string $baseUrl;
    /** @var array<string, string> Token cache keyed by comma-joined scopes */
    private static array $tokenCache = [];
    protected static ?int $testClientId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://localhost', '/');
        static::createTestClient();
    }

    public static function tearDownAfterClass(): void
    {
        static::removeTestClient();
        parent::tearDownAfterClass();
    }

    private static function createTestClient(): void
    {
        $scopes = static::getAllScopes();
        \Db::getInstance()->insert('apimodule_client', [
            'client_id'     => 'test_client',
            'client_secret' => password_hash('test_secret', PASSWORD_BCRYPT),
            'client_name'   => 'Test Client',
            'scopes'        => json_encode($scopes),
            'active'        => 1,
            'date_add'      => date('Y-m-d H:i:s'),
            'date_upd'      => date('Y-m-d H:i:s'),
        ]);
        $row = \Db::getInstance()->getRow(
            "SELECT id FROM `" . _DB_PREFIX_ . "apimodule_client` WHERE client_id = 'test_client'"
        );
        static::$testClientId = $row ? (int) $row['id'] : null;
    }

    private static function removeTestClient(): void
    {
        \Db::getInstance()->delete('apimodule_client', "client_id = 'test_client'");
        self::$tokenCache = [];
    }

    /** @return string[] */
    protected static function getAllScopes(): array
    {
        return ['contact_read', 'contact_write'];
    }

    protected function getBearerToken(array $scopes): string
    {
        $key = implode(',', $scopes);
        if (!isset(self::$tokenCache[$key])) {
            $postFields = [
                'grant_type'    => 'client_credentials',
                'client_id'     => 'test_client',
                'client_secret' => 'test_secret',
            ];
            foreach ($scopes as $i => $scope) {
                $postFields['scope[' . $i . ']'] = $scope;
            }

            $ch = curl_init(static::$baseUrl . '/admin-api/access_token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
            ]);
            $body = (string) curl_exec($ch);
            curl_close($ch);

            $data = json_decode($body, true) ?? [];
            self::$tokenCache[$key] = (string) ($data['access_token'] ?? '');
        }
        return self::$tokenCache[$key];
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $scopes
     * @return array<string,mixed>
     */
    protected function request(
        string $method,
        string $path,
        array $data,
        array $scopes,
        int $expectedCode,
        bool $withToken = true
    ): array {
        $url     = static::$baseUrl . $path;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($withToken && !empty($scopes)) {
            $headers[] = 'Authorization: Bearer ' . $this->getBearerToken($scopes);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($data));
        }

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame($expectedCode, $status, "Expected HTTP {$expectedCode}, got {$status}. Body: {$body}");

        return json_decode($body, true) ?? [];
    }

    /** @return array<string,mixed> */
    protected function getItem(string $path, array $scopes, int $expectedCode = 200): array
    {
        return $this->request('GET', $path, [], $scopes, $expectedCode);
    }

    /** @return array<string,mixed> */
    protected function listItems(string $path, array $scopes, array $filters = [], int $expectedCode = 200): array
    {
        $url = $path . ($filters ? '?' . http_build_query($filters) : '');
        return $this->request('GET', $url, [], $scopes, $expectedCode);
    }

    /** @return array<string,mixed> */
    protected function createItem(string $path, array $data, array $scopes, int $expectedCode = 201): array
    {
        return $this->request('POST', $path, $data, $scopes, $expectedCode);
    }

    /** @return array<string,mixed> */
    protected function updateItem(string $path, array $data, array $scopes, int $expectedCode = 200): array
    {
        return $this->request('PATCH', $path, $data, $scopes, $expectedCode);
    }

    protected function deleteItem(string $path, array $scopes, int $expectedCode = 204): void
    {
        $this->request('DELETE', $path, [], $scopes, $expectedCode);
    }

    protected function requestWithoutToken(string $method, string $path, int $expectedCode = 401): array
    {
        return $this->request($method, $path, [], [], $expectedCode, false);
    }
}
