<?php

namespace App\AlphaForge\Http\Requests;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitTradeSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $timeframes = array_column(TimeframeEnum::cases(), 'value');

        return [
            'exchange' => ['required', 'string', 'max:50'],
            'symbol' => ['required', 'string', 'max:50'],
            'direction' => ['required', 'string', 'in:LONG,SHORT'],
            'entry_price' => ['required', 'numeric', 'gt:0'],
            'stop_loss' => ['required', 'numeric', 'gt:0'],
            'take_profit' => ['required', 'numeric', 'gt:0'],
            'trailing_stop_enabled' => ['nullable', 'boolean'],
            'trailing_stop_percent' => ['nullable', 'numeric', 'gt:0', 'required_if:trailing_stop_enabled,true'],
            'entry_timestamp' => ['nullable', 'integer', 'min:0'],
            'timeframe' => ['required', 'string', Rule::in($timeframes)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $direction = $this->input('direction');
            $entryPrice = (float) $this->input('entry_price');
            $stopLoss = (float) $this->input('stop_loss');
            $takeProfit = (float) $this->input('take_profit');

            if ($direction === 'LONG') {
                if ($stopLoss >= $entryPrice) {
                    $validator->errors()->add(
                        'stop_loss',
                        'For LONG positions, stop loss must be below entry price.'
                    );
                }
                if ($takeProfit <= $entryPrice) {
                    $validator->errors()->add(
                        'take_profit',
                        'For LONG positions, take profit must be above entry price.'
                    );
                }
            }

            if ($direction === 'SHORT') {
                if ($stopLoss <= $entryPrice) {
                    $validator->errors()->add(
                        'stop_loss',
                        'For SHORT positions, stop loss must be above entry price.'
                    );
                }
                if ($takeProfit >= $entryPrice) {
                    $validator->errors()->add(
                        'take_profit',
                        'For SHORT positions, take profit must be below entry price.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'direction.in' => 'Direction must be either LONG or SHORT.',
            'entry_price.gt' => 'Entry price must be greater than zero.',
            'stop_loss.gt' => 'Stop loss must be greater than zero.',
            'take_profit.gt' => 'Take profit must be greater than zero.',
            'trailing_stop_percent.required_if' => 'Trailing stop percentage is required when trailing stop is enabled.',
            'timeframe.in' => 'The selected timeframe is invalid.',
        ];
    }
}