<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaEdit\ApiModule\Api\OpenApiGenerator;

/**
 * Browsable Swagger UI for the Admin API.
 *
 * Mirrors the official PrestaShop design: the OAuth2 surface (/admin-api/*)
 * exposes no public docs, while the interactive Swagger UI is served only in
 * the authenticated back-office context (app/config/admin/config.yml sets
 * enable_swagger_ui: true). Being a back-office controller, access is gated by
 * the employee login + admin security token. The OpenAPI spec is inlined so no
 * token-protected fetch is needed; "Authorize" then uses the clientCredentials
 * flow against /admin-api/access_token.
 */
class AdminAdminapiDocController extends ModuleAdminController
{
    /** Pinned swagger-ui-dist version served from the unpkg CDN. */
    private const SWAGGER_UI_VERSION = '5.17.14';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $autoload = _PS_MODULE_DIR_ . 'adminapi/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    public function initContent(): void
    {
        $spec = (string) json_encode(
            (new OpenApiGenerator())->generate(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $this->renderSwaggerPage($spec);
        exit;
    }

    private function renderSwaggerPage(string $specJson): string
    {
        $v = self::SWAGGER_UI_VERSION;

        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Admin API — Documentation</title>'
            . '<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@' . $v . '/swagger-ui.css">'
            . '<style>body{margin:0;background:#fafafa}</style></head>'
            . '<body><div id="swagger-ui"></div>'
            . '<script src="https://unpkg.com/swagger-ui-dist@' . $v . '/swagger-ui-bundle.js" crossorigin></script>'
            . '<script src="https://unpkg.com/swagger-ui-dist@' . $v . '/swagger-ui-standalone-preset.js" crossorigin></script>'
            . '<script>window.onload=function(){window.ui=SwaggerUIBundle({'
            . 'spec:' . $specJson . ','
            . 'dom_id:"#swagger-ui",deepLinking:true,'
            . 'presets:[SwaggerUIBundle.presets.apis,SwaggerUIStandalonePreset],'
            . 'plugins:[SwaggerUIBundle.plugins.DownloadUrl],layout:"StandaloneLayout"'
            . '});};</script></body></html>';
    }
}
