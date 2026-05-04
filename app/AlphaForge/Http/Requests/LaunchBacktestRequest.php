<?php

namespace App\AlphaForge\Http\Requests;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaunchBacktestRequest extends FormRequest
{
    public function rules(): array
    {
        $timeframeValues = array_column(TimeframeEnum::cases(), 'value');

        return [
            'strategy_alias' => ['required', 'string'],
            'symbols' => ['required', 'array', 'min:1'],
            'symbols.*' => ['required', 'string'],
            'timeframe' => ['required', 'string', Rule::in($timeframeValues)],
            'execution_timeframe' => ['nullable', 'string', Rule::in($timeframeValues)],
            'exchange' => ['nullable', 'string'],
            'initial_capital' => ['nullable', 'numeric', 'min:0'],
            'stake_currency' => ['nullable', 'string', 'size:3'],
            'inputs' => ['nullable', 'array'],
            'commission' => ['nullable', 'array'],
            'commission.type' => ['nullable', 'string', 'in:percentage,fixed_per_trade,fixed_per_unit'],
            'commission.rate' => ['nullable', 'numeric', 'min:0'],
            'commission.amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $timeframeValue = $this->input('timeframe');
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

    public function messages(): array
    {
        return [
            'strategy_alias.required' => 'A strategy alias is required.',
            'symbols.required' => 'At least one symbol is required.',
            'symbols.min' => 'At least one symbol must be specified.',
            'timeframe.required' => 'A timeframe is required.',
            'timeframe.in' => 'The selected timeframe is invalid.',
            'execution_timeframe.in' => 'The selected execution timeframe is invalid.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
        ];
    }
}
