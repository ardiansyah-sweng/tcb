<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesContextInput;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/** POST /tasks/{id}/contexts (schema ContextInput). */
class StoreContextRequest extends FormRequest
{
    use ValidatesContextInput;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::contextRules();
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        /** @var Task $task */
        $task = $this->route('task');

        return [$this->contextUnitCheck('', $task)];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::contextMessages();
    }
}
