<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MonthlySumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year_month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        return $key ? $data[$key] ?? $default : $data;
    }
}
