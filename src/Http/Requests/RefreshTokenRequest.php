<?php

namespace Usjnet\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('refresh_token')) {
            $fromCookie = $this->cookie((string) config('usjnet-sso.refresh_token_cookie', 'sso_refresh_token'));
            if (is_string($fromCookie) && $fromCookie !== '') {
                $this->merge(['refresh_token' => $fromCookie]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string'],
            'scope' => ['nullable', 'string'],
        ];
    }
}
