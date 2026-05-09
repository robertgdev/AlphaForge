<?php

namespace App\AlphaForge\Http\Requests;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunBacktestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $timeframes = array_column(TimeframeEnum::cases(), 'value');

        return [
            'strategy' => ['required', 'string'],
            'symbols' => ['required', 'array', 'min:1'],
            'symbols.*' => ['required', 'string'],
            'timeframe' => ['required', 'string', Rule::in($timeframes)],
            'execution_timeframe' => ['nullable', 'string', Rule::in($timeframes)],
            'exchange' => ['required', 'string'],
            'initial_capital' => ['required', 'numeric', 'min:0'],
            'stake_currency' => ['nullable', 'string', 'max:10'],
            'strategy_inputs' => ['nullable', 'array'],
            'commission_config' => ['nullable', 'array'],
            'commission_config.type' => ['nullable', 'string', 'in:percentage,fixed'],
            'commission_config.rate' => ['nullable', 'numeric', 'min:0'],
            'commission_config.minimum' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'additional_timeframes' => ['nullable', 'array'],
            'additional_timeframes.*' => ['string', Rule::in($timeframes)],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            /** @var string $timeframeValue */
            $timeframeValue = $this->input('timeframe');
            /** @var string $executionTimeframeValue */
            $executionTimeframeValue = $this->input('execution_timeframe');

            if ($timeframeValue && $executionTimeframeValue) {
                $signalTf = TimeframeEnum::tryFrom($timeframeValue);
                $execTf = TimeframeEnum::tryFrom($executionTimeframeValue);

                if ($signalTf && $execTf && $execTf->toSeconds() >= $signalTf->toSeconds()) {
                    $validator->errors()->add(
                        'execution_timeframe',
                        'The execution timeframe must be lower (finer granularity) than the signal timeframe.'
                    );
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'symbols.required' => 'At least one trading symbol is required',
            'symbols.min' => 'At least one trading symbol is required',
            'timeframe.in' => 'The selected timeframe is invalid',
            'execution_timeframe.in' => 'The selected execution timeframe is invalid',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date',
        ];
    }
}
