<?php

namespace Usjnet\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallUsjnetSsoCommand extends Command
{
    protected $signature = 'usjnet-sso:install {--auth-mode= : Force USJNET_SSO_AUTH_USER_MODE: sso or system (non-interactive / CI)} {--web-middleware-alias= : Set USJNET_SSO_WEB_MIDDLEWARE_ALIAS (e.g. auth)}';

    protected $description = 'Interactive install: publish config, OAuth client, auth mode, frontend home URL, optional web middleware alias, and .env';

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

        $authMode = $this->promptAuthUserMode();

        if ($style === 'single') {
            $defaultFrontend = $appUrl.'/home';
            $frontendHome = rtrim((string) $this->ask(
                'USJNET_SSO_FRONTEND_HOME_URL (post-login landing in this app; same origin as APP_URL)',
                $defaultFrontend
            ), '/');
        } else {
            $frontendHome = rtrim((string) $this->ask('USJNET_SSO_FRONTEND_HOME_URL', 'http://127.0.0.1:3000/home'), '/');
        }
        $redirectUri = (string) $this->ask('USJNET_SSO_REDIRECT_URI', $appUrl.'/sso/spa/callback');
        $corsOrigins = $style === 'single'
            ? $appUrl
            : (string) $this->ask('CORS_ALLOWED_ORIGINS (comma-separated)', 'http://127.0.0.1:3000,http://localhost:3000');

        $webMwAlias = $this->promptWebMiddlewareAlias();

        $systemUserModel = null;
        $createSystemUserIfMissing = null;
        if ($authMode === 'system') {
            $systemUserModel = (string) $this->ask('USJNET_SSO_SYSTEM_USER_MODEL (Eloquent class)', (string) config('usjnet-sso.system_user_model', 'App\\Models\\User'));
            $createSystemUserIfMissing = $this->confirm('Create a local user row automatically if email is not in the database?', false);
        }

        $cookieSecure = $this->confirm('Use secure cookies (https only)?', false) ? 'true' : 'false';
        $cookieSameSite = (string) $this->choice('Cookie SameSite', ['lax', 'none', 'strict'], 'lax');
        $scope = trim((string) config('usjnet-sso.scope', 'view-user'));
        $scope = $scope !== '' ? $scope : 'view-user';

        $envWritten = false;
        $envWritten = $this->upsertEnv('APP_URL', $appUrl) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_BASE_URL', $ssoBaseUrl) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_CLIENT_ID', $clientId) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_CLIENT_SECRET', $clientSecret) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_AUTH_USER_MODE', $authMode) || $envWritten;

        if ($authMode === 'system' && $systemUserModel !== null) {
            $envWritten = $this->upsertEnv('USJNET_SSO_SYSTEM_USER_MODEL', $systemUserModel) || $envWritten;
            $envWritten = $this->upsertEnv('USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING', $createSystemUserIfMissing ? 'true' : 'false') || $envWritten;
        }

        if ($webMwAlias !== null && $webMwAlias !== '') {
            $envWritten = $this->upsertEnv('USJNET_SSO_WEB_MIDDLEWARE_ALIAS', $webMwAlias) || $envWritten;
        }

        $envWritten = $this->upsertEnv('USJNET_SSO_REDIRECT_URI', $redirectUri) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_FRONTEND_HOME_URL', $frontendHome) || $envWritten;
        $envWritten = $this->upsertEnv('CORS_ALLOWED_ORIGINS', $corsOrigins) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_SCOPE', $scope) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_COOKIE_SECURE', $cookieSecure) || $envWritten;
        $envWritten = $this->upsertEnv('USJNET_SSO_COOKIE_SAME_SITE', $cookieSameSite) || $envWritten;
        $this->ensureCorsConfigExists();

        $this->newLine();
        if ($envWritten) {
            $this->info('Environment values written to .env successfully.');
        } else {
            $this->warn('No .env file at '.base_path('.env').' — nothing was saved. Create .env (copy from .env.example), paste the block below, then run: php artisan config:clear');
            $this->newLine();
            $suggestedLines = array_merge(
                [
                    'APP_URL' => $appUrl,
                    'USJNET_SSO_BASE_URL' => $ssoBaseUrl,
                    'USJNET_SSO_CLIENT_ID' => $clientId,
                    'USJNET_SSO_CLIENT_SECRET' => $clientSecret,
                    'USJNET_SSO_AUTH_USER_MODE' => $authMode,
                ],
                $authMode === 'system' && $systemUserModel !== null ? [
                    'USJNET_SSO_SYSTEM_USER_MODEL' => $systemUserModel,
                    'USJNET_SSO_CREATE_SYSTEM_USER_IF_MISSING' => $createSystemUserIfMissing ? 'true' : 'false',
                ] : [],
                $webMwAlias !== null && $webMwAlias !== '' ? ['USJNET_SSO_WEB_MIDDLEWARE_ALIAS' => $webMwAlias] : [],
                [
                    'USJNET_SSO_REDIRECT_URI' => $redirectUri,
                    'USJNET_SSO_FRONTEND_HOME_URL' => $frontendHome,
                    'CORS_ALLOWED_ORIGINS' => $corsOrigins,
                    'USJNET_SSO_SCOPE' => $scope,
                    'USJNET_SSO_COOKIE_SECURE' => $cookieSecure,
                    'USJNET_SSO_COOKIE_SAME_SITE' => $cookieSameSite,
                ]
            );
            $this->line($this->formatSuggestedEnvBlock($suggestedLines));
            $this->newLine();
            $this->comment('(USJNET_SSO_CLIENT_SECRET is shown because .env was missing — remove this block from logs after pasting.)');
        }
        $this->newLine();
        $this->info('Next steps (required):');
        $this->line('  1. Register OAuth redirect URI at SSO exactly as: '.$redirectUri);
        $this->line('     If login shows token_exchange_failed / Client authentication failed: USJNET_SSO_CLIENT_ID and USJNET_SSO_CLIENT_SECRET must match a confidential OAuth client on the SSO server (no extra spaces in .env).');
        $this->line('  2. Exclude SSO cookies from encryption: Laravel 11+ in bootstrap/app.php (encryptCookies except); Laravel 9–10 in app/Http/Middleware/EncryptCookies::$except.');
        $this->line('  3. In config/cors.php set supports_credentials=true and allowed_origins includes: '.$corsOrigins.' (installer creates config/cors.php if missing).');
        $this->line('  4. Middleware: sso.web / sso.token are registered; if you set USJNET_SSO_WEB_MIDDLEWARE_ALIAS, remove Laravel’s default `auth` alias if it conflicts.');
        $this->line('  5. Optional: USJNET_SSO_INVALID_SESSION_REDIRECT=frontend to send users to SPA login when SSO token dies; USJNET_SSO_TOKEN_VALIDATION_PATH if your IdP uses a non-default profile URL.');
        $this->line('  6. Run: php artisan config:clear');

        return self::SUCCESS;
    }

    /**
     * @return 'sso'|'system'
     */
    private function promptAuthUserMode(): string
    {
        $opt = $this->option('auth-mode');
        if (is_string($opt) && trim($opt) !== '') {
            $m = strtolower(trim($opt));
            if (in_array($m, ['sso', 'system'], true)) {
                $this->line('Auth user mode from <info>--auth-mode</info>: <fg=cyan>'.$m.'</>');

                return $m;
            }
            $this->warn('Invalid --auth-mode (use sso or system). Prompting instead.');
        }

        if (! $this->input->isInteractive()) {
            $this->warn('Non-interactive terminal: defaulting to <fg=cyan>sso</>. Set USJNET_SSO_AUTH_USER_MODE in .env or pass <info>--auth-mode=system</info>.');

            return 'sso';
        }

        $this->newLine();
        $this->line(' <options=bold>Auth user mode</> — how Auth::user() is set after SSO login:');
        $this->line('   sso     → GenericUser from SSO /api/user JSON (no local users row required)');
        $this->line('   system  → your Eloquent User model, matched by email from SSO profile');
        $default = strtolower(trim((string) config('usjnet-sso.auth_user_mode', 'sso')));
        if (! in_array($default, ['sso', 'system'], true)) {
            $default = 'sso';
        }
        $answer = strtolower(trim((string) $this->ask('Type sso or system', $default)));
        if (! in_array($answer, ['sso', 'system'], true)) {
            $this->warn('Unrecognised answer; using sso.');

            return 'sso';
        }

        return $answer;
    }

    /**
     * Optional extra alias for EnsureSsoWebAuthenticated (e.g. auth). Null = do not write .env key.
     */
    private function promptWebMiddlewareAlias(): ?string
    {
        $opt = $this->option('web-middleware-alias');
        if (is_string($opt) && trim($opt) !== '') {
            $a = trim($opt);
            if (strtolower($a) === 'sso.web') {
                $this->warn('Ignoring --web-middleware-alias=sso.web (already registered as sso.web).');

                return null;
            }
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $a) === 1) {
                $this->line('Web middleware alias from <info>--web-middleware-alias</info>: <fg=cyan>'.$a.'</>');

                return $a;
            }
            $this->warn('Invalid --web-middleware-alias ignored (use letters, numbers, underscore, hyphen).');

            return null;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $this->newLine();
        $this->line(' <options=bold>Optional web middleware alias</> — same as <fg=cyan>sso.web</> but lets you use a short name in routes (e.g. <fg=cyan>auth</>).');
        $default = trim((string) (config('usjnet-sso.web_middleware_alias') ?? ''));
        $answer = trim((string) $this->ask('USJNET_SSO_WEB_MIDDLEWARE_ALIAS (leave empty to skip; common: auth)', $default));
        if ($answer === '') {
            return null;
        }
        if (strtolower($answer) === 'sso.web') {
            $this->warn('"sso.web" is already the default alias; skipping extra env key.');

            return null;
        }
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $answer) !== 1) {
            $this->warn('Invalid alias (use letters, numbers, underscore, hyphen only); skipping.');

            return null;
        }

        return $answer;
    }

    private function upsertEnv(string $key, string $value): bool
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            return false;
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

        return true;
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function formatSuggestedEnvBlock(array $lines): string
    {
        $parts = [];
        foreach ($lines as $key => $value) {
            $parts[] = $key.'='.$this->escapeEnvValue((string) $value);
        }

        return implode("\n", $parts);
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
