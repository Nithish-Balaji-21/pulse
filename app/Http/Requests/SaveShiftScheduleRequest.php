<?php

namespace App\Http\Requests;

use App\Models\Pulse\Shift;
use App\Services\Pulse\ShiftScheduleService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class SaveShiftScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('shift_settings.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shifts' => ['required', 'array', 'min:1', 'max:3'],
            'shifts.*.id' => ['nullable', 'integer'],
            'shifts.*.name' => ['required', 'string', 'max:50'],
            'shifts.*.start_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'shifts.*.end_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'shifts.max' => 'Max 3 shifts only.',
            'shifts.*.name.required' => 'The shift name field is required.',
            'shifts.*.start_time.regex' => 'The start time must be a valid HH:MM time.',
            'shifts.*.end_time.regex' => 'The end time must be a valid HH:MM time.',
        ];
    }

    /**
     * Window validation (per-shift errors keyed by array index) against the team's anchor.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $teamId = $this->teamId();
                $anchor = Shift::anchorForTeam($teamId);

                $errors = app(ShiftScheduleService::class)
                    ->validateSchedule($this->input('shifts', []), $anchor, $teamId);

                foreach ($errors as $index => $message) {
                    $validator->errors()->add($index === 'schedule' ? 'shifts' : "shifts.{$index}", $message);
                }
            },
        ];
    }

    public function teamId(): int
    {
        return app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
    }
}
