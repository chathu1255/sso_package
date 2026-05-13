<?php

namespace Usjnet\Sso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Usjnet\Sso\Models\SsoToken;

class SsoAuthService
{
    public function exchangeAuthorizationCode(string $code, ?string $redirectUri = null): SsoToken
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => trim((string) ($redirectUri ?? config('usjnet-sso.redirect_uri'))),
            'client_id' => trim((string) config('usjnet-sso.client_id')),
            'client_secret' => trim((string) config('usjnet-sso.client_secret')),
        ]);
    }

    public function requestPasswordToken(string $username, string $password, string $scope = ''): SsoToken
    {
        return $this->requestToken([
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'scope' => $scope,
            'client_id' => trim((string) config('usjnet-sso.client_id')),
            'client_secret' => trim((string) config('usjnet-sso.client_secret')),
        ]);
    }

    public function refreshToken(string $refreshToken, string $scope = ''): SsoToken
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => $scope,
            'client_id' => trim((string) config('usjnet-sso.client_id')),
            'client_secret' => trim((string) config('usjnet-sso.client_secret')),
        ]);
    }

    public function validateAccessToken(string $token): array
    {
        $path = trim((string) config('usjnet-sso.access_token_validation_path', '/api/user'));
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
            $path = '/api/user';
        }

        $response = $this->client()
            ->withToken($token)
            ->withHeaders([
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ])
            ->get($path);

        if ($response->failed()) {
            throw new HttpException($response->status(), 'Invalid or expired SSO token.');
        }

        return $response->json() ?: [];
    }

    /**
     * Validates the access token with the IdP at most once per HTTP request (middleware may run multiple times).
     *
     * @return array<string, mixed>
     */
    public function validateAccessTokenForHttpRequest(Request $request, string $token): array
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            throw new HttpException(401, 'Invalid or expired SSO token.');
        }

        $cacheKey = 'usjnet_sso.access_token_user.'.sha1($trimmed);
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $user = $this->validateAccessToken($trimmed);
        $request->attributes->set($cacheKey, $user);

        return $user;
    }

    public function logoutUser(?string $token = null): array
    {
        $request = $this->client();

        if (filled($token)) {
            $request = $request->withToken($token);
        }

        $postPath = trim((string) config('usjnet-sso.sso_logout_post_path', '/api/logout_passort_user'));
        if ($postPath === '' || ! str_starts_with($postPath, '/') || str_contains($postPath, '..')) {
            $postPath = '/api/logout_passort_user';
        }

        $passportLogout = $request->post($postPath);

        $getPath = config('usjnet-sso.sso_logout_get_path');
        $getPath = is_string($getPath) ? trim($getPath) : '';
        if ($getPath !== '' && (! str_starts_with($getPath, '/') || str_contains($getPath, '..'))) {
            $getPath = '';
        }
        $legacyLogout = $getPath !== ''
            ? $request->get($getPath)
            : null;

        $hasSuccess = $passportLogout->successful() || ($legacyLogout !== null && $legacyLogout->successful());

        if (! $hasSuccess) {
            $status = $passportLogout->status() ?: ($legacyLogout !== null ? $legacyLogout->status() : 0) ?: 500;

            throw new HttpException(
                $status,
                Arr::get(
                    $passportLogout->json(),
                    'message',
                    $legacyLogout !== null
                        ? Arr::get($legacyLogout->json(), 'message', 'Unable to logout from SSO.')
                        : 'Unable to logout from SSO.'
                )
            );
        }

        return [
            'status' => $hasSuccess ? 200 : 500,
            'body' => [
                'passport_logout' => [
                    'path' => $postPath,
                    'status' => $passportLogout->status(),
                    'successful' => $passportLogout->successful(),
                    'response' => $passportLogout->json() ?: $passportLogout->body(),
                ],
                'legacy_logout' => $legacyLogout === null ? null : [
                    'path' => $getPath,
                    'status' => $legacyLogout->status(),
                    'successful' => $legacyLogout->successful(),
                    'response' => $legacyLogout->json() ?: $legacyLogout->body(),
                ],
            ],
        ];
    }

    public function authorizeUrl(string $state, ?string $redirectUri = null, bool $forceLogin = false): string
    {
        $query = [
            'client_id' => trim((string) config('usjnet-sso.client_id')),
            'redirect_uri' => trim((string) ($redirectUri ?? config('usjnet-sso.redirect_uri'))),
            'response_type' => 'code',
            'scope' => config('usjnet-sso.scope', ''),
            'state' => $state,
        ];

        $prompt = $forceLogin
            ? 'login'
            : trim((string) config('usjnet-sso.authorize_prompt', ''));
        if ($prompt !== '') {
            $query['prompt'] = $prompt;
        }

        return rtrim((string) config('usjnet-sso.base_url'), '/').'/oauth/authorize?'.http_build_query($query);
    }

    protected function requestToken(array $payload): SsoToken
    {
        if (isset($payload['client_id'])) {
            $payload['client_id'] = trim((string) $payload['client_id']);
        }
        if (isset($payload['client_secret'])) {
            $payload['client_secret'] = trim((string) $payload['client_secret']);
        }

        $response = $this->client()->asForm()->post('/oauth/token', $payload);

        if ($response->failed()) {
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $message = Arr::get($body, 'error_description')
                ?: Arr::get($body, 'message')
                ?: Arr::get($body, 'error')
                ?: 'Unable to authenticate against SSO.';
            throw new HttpException($response->status(), is_string($message) ? $message : 'Unable to authenticate against SSO.');
        }

        return SsoToken::fromArray($response->json() ?: []);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('usjnet-sso.base_url'), '/'))
            ->acceptJson()
            ->timeout((int) config('usjnet-sso.timeout', 15));
    }
}
