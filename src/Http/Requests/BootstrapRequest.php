<?php

namespace Usjnet\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BootstrapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bootstrap' => ['required', 'string', 'uuid'],
        ];
    }
}
