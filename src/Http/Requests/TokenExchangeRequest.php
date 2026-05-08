<?php

namespace Usjnet\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TokenExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['nullable', 'string'],
        ];
    }
}
