<?php

namespace Usjnet\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallUsjnetSsoCommand extends Command
{
    protected $signature = 'usjnet-sso:install';

    protected $description = 'Interactive install: publish config, ask project style, and write recommended SSO env keys';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'usjnet-sso-config']);

        $this->newLine();
        $style = $this->choice(
            'Select project style',
            [
                'separate' => 'Frontend and Backend are separate apps/domains',
                'single' => 'Frontend and Backend are in one Laravel app/domain',
            ],
            'separate'
        );

        $appUrl = rtrim((string) $this->ask('APP_URL (backend URL)', (string) config('app.url', 'http://127.0.0.1:8000')), '/');
        $ssoBaseUrl = rtrim((string) $this->ask('SSO base URL', (string) config('usjnet-sso.base_url', 'http://127.0.0.1:8001')), '/');
        $clientId = (string) $this->ask('USJNET_SSO_CLIENT_ID', (string) config('usjnet-sso.client_id', ''));
        $clientSecret = (string) $this->secret('USJNET_SSO_CLIENT_SECRET (input hidden)');

        $frontendHome = $style === 'single'
            ? $appUrl.'/home'
            : rtrim((string) $this->ask('USJNET_SSO_FRONTEND_HOME_URL', 'http://127.0.0.1:3000/home'), '/');
        $redirectUri = (string) $this->ask('USJNET_SSO_REDIRECT_URI', $appUrl.'/sso/spa/callback');
        $corsOrigins = $style === 'single'
            ? $appUrl
            : (string) $this->ask('CORS_ALLOWED_ORIGINS (comma-separated)', 'http://127.0.0.1:3000,http://localhost:3000');

        $this->upsertEnv('APP_URL', $appUrl);
        $this->upsertEnv('USJNET_SSO_BASE_URL', $ssoBaseUrl);
        $this->upsertEnv('USJNET_SSO_CLIENT_ID', $clientId);
        $this->upsertEnv('USJNET_SSO_CLIENT_SECRET', $clientSecret);
        $this->upsertEnv('USJNET_SSO_REDIRECT_URI', $redirectUri);
        $this->upsertEnv('USJNET_SSO_FRONTEND_HOME_URL', $frontendHome);
        $this->upsertEnv('CORS_ALLOWED_ORIGINS', $corsOrigins);
        $scope = trim((string) config('usjnet-sso.scope', 'view-user'));
        $this->upsertEnv('USJNET_SSO_SCOPE', $scope !== '' ? $scope : 'view-user');
        $this->upsertEnv('USJNET_SSO_COOKIE_SECURE', $this->confirm('Use secure cookies (https only)?', false) ? 'true' : 'false');
        $this->upsertEnv('USJNET_SSO_COOKIE_SAME_SITE', (string) $this->choice('Cookie SameSite', ['lax', 'none', 'strict'], 'lax'));
        $this->ensureCorsConfigExists();

        $this->newLine();
        $this->info('Environment values written to .env successfully.');
        if ($style === 'single') {
            $this->line('Single-app mode: USJNET_SSO_FRONTEND_HOME_URL set automatically to '.$frontendHome);
        }
        $this->newLine();
        $this->info('Next steps (required):');
        $this->line('  1. Register OAuth redirect URI at SSO exactly as: '.$redirectUri);
        $this->line('  2. Exclude SSO cookies from encryption: Laravel 11+ in bootstrap/app.php (encryptCookies except); Laravel 9–10 in app/Http/Middleware/EncryptCookies::$except.');
        $this->line('  3. In config/cors.php set supports_credentials=true and allowed_origins includes: '.$corsOrigins.' (installer creates config/cors.php if missing).');
        $this->line('  4. Middleware alias sso.token is already registered by package.');
        $this->line('  5. Run: php artisan config:clear');

        return self::SUCCESS;
    }

    private function upsertEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            return;
        }

        $raw = (string) file_get_contents($path);
        $escaped = $this->escapeEnvValue($value);

        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        if (preg_match($pattern, $raw) === 1) {
            $raw = (string) preg_replace($pattern, $key.'='.$escaped, $raw);
        } else {
            if ($raw !== '' && ! Str::endsWith($raw, "\n")) {
                $raw .= "\n";
            }
            $raw .= $key.'='.$escaped."\n";
        }

        file_put_contents($path, $raw);
    }

    private function escapeEnvValue(string $value): string
    {
        $needsQuotes = str_contains($value, ' ') || str_contains($value, '#') || str_contains($value, ',');
        if (! $needsQuotes) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }

    private function ensureCorsConfigExists(): void
    {
        $path = config_path('cors.php');
        if (file_exists($path)) {
            return;
        }

        $template = <<<'PHP'
<?php

$origins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://127.0.0.1:3000,http://localhost:3000'))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
PHP;

        file_put_contents($path, $template.PHP_EOL);
        $this->info('Created missing file: config/cors.php');
    }
}
