<?php

namespace Usjnet\Sso;

use Illuminate\Http\Client\PendingRequest;
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
        $response = $this->client()->withToken($token)->get('/api/user');

        if ($response->failed()) {
            throw new HttpException($response->status(), 'Invalid or expired SSO token.');
        }

        return $response->json() ?: [];
    }

    public function logoutUser(?string $token = null): array
    {
        $request = $this->client();

        if (filled($token)) {
            $request = $request->withToken($token);
        }

        $passportLogout = $request->post('/api/auth/logout');
        $legacyLogout = $request->get('/api/user_logout');

        $hasSuccess = $passportLogout->successful() || $legacyLogout->successful();

        if (! $hasSuccess) {
            throw new HttpException(
                $passportLogout->status() ?: $legacyLogout->status(),
                Arr::get(
                    $passportLogout->json(),
                    'message',
                    Arr::get($legacyLogout->json(), 'message', 'Unable to logout from SSO.')
                )
            );
        }

        return [
            'status' => $hasSuccess ? 200 : 500,
            'body' => [
                'passport_logout' => [
                    'status' => $passportLogout->status(),
                    'successful' => $passportLogout->successful(),
                    'response' => $passportLogout->json() ?: $passportLogout->body(),
                ],
                'legacy_logout' => [
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
