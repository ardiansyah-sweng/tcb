<?php

namespace App\Http\Requests;

use App\Models\Objective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Schema ObjectiveUpdate in docs/tcb_openapi.yaml. Every field is optional,
 * but title and the period dates cannot be emptied.
 */
class UpdateObjectiveRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'period_start' => ['bail', 'sometimes', 'required', 'date_format:Y-m-d'],
            'period_end' => ['bail', 'sometimes', 'required', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'required', 'in:active,closed,archived'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreObjectiveRequest::MESSAGES;
    }

    /**
     * A partial update is checked against the stored dates, so moving only
     * one end of the period cannot leave period_end before period_start.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['period_start', 'period_end'])) {
                    return;
                }

                /** @var Objective $objective */
                $objective = $this->route('objective');
                $start = $this->input('period_start', $objective->period_start->toDateString());
                $end = $this->input('period_end', $objective->period_end->toDateString());

                if ($end < $start) {
                    $validator->errors()->add('period_end', StoreObjectiveRequest::PERIOD_END_BEFORE_START);
                }
            },
        ];
    }
}
