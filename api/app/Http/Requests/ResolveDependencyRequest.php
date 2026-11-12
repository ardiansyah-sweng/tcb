<?php

namespace App\Http\Requests;

use App\Models\Dependency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /dependencies/{id}: mark an external blocker resolved. A dependency on
 * another task is resolved when that task becomes Done (DependencyService,
 * R1-12), so it cannot be resolved by hand; delete it instead.
 */
class ResolveDependencyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:resolved'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status wajib diisi: resolved.',
            'status.in' => 'Status dependensi hanya bisa diubah menjadi resolved.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Dependency $dependency */
            $dependency = $this->route('dependency');
            if (! $validator->errors()->has('status') && ! $dependency->isExternal()) {
                $validator->errors()->add(
                    'status',
                    'Dependensi ke task lain teratasi otomatis saat task itu Done. Hapus dependensinya bila tidak perlu ditunggu lagi.',
                );
            }
        }];
    }
}
