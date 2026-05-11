<?php

namespace App\AlphaForge\Backtesting\WalkForward;

class WalkForwardExporter
{
    public function toCsv(WalkForwardAnalysis $analysis): string
    {
        if (empty($analysis->results)) {
            return '';
        }

        $paramKeys = [];
        foreach ($analysis->results as $result) {
            if (is_array($result->parameters)) {
                foreach (array_keys($result->parameters) as $key) {
                    $paramKeys[$key] = true;
                }
            }
        }
        $paramKeys = array_keys($paramKeys);
        sort($paramKeys);

        $headers = ['rank'];
        foreach ($paramKeys as $key) {
            $headers[] = "param_{$key}";
        }
        $headers = array_merge($headers, [
            'is_score', 'oos_score', 'degradation',
            'is_return_pct', 'oos_return_pct',
            'is_max_dd_pct', 'oos_max_dd_pct',
            'is_trades', 'oos_trades',
            'is_win_rate', 'oos_win_rate',
        ]);

        $rows = [implode(',', $headers)];

        foreach ($analysis->results as $result) {
            $row = [$result->rank];

            foreach ($paramKeys as $key) {
                $row[] = $result->parameters[$key] ?? '';
            }

            $row[] = $this->formatCsvValue($result->is_score);
            $row[] = $this->formatCsvValue($result->oos_score);
            $row[] = $this->formatCsvValue($result->score_degradation);
            $row[] = $this->formatCsvValue($result->is_statistics['total_return_percent'] ?? null);
            $row[] = $this->formatCsvValue($result->oos_statistics['total_return_percent'] ?? null);
            $row[] = $this->formatCsvValue($result->is_statistics['max_drawdown_percent'] ?? null);
            $row[] = $this->formatCsvValue($result->oos_statistics['max_drawdown_percent'] ?? null);
            $row[] = $this->formatCsvValue($result->is_statistics['total_trades'] ?? null);
            $row[] = $this->formatCsvValue($result->oos_statistics['total_trades'] ?? null);
            $row[] = $this->formatCsvValue($result->is_statistics['win_rate'] ?? null);
            $row[] = $this->formatCsvValue($result->oos_statistics['win_rate'] ?? null);

            $rows[] = implode(',', $row);
        }

        return implode("\n", $rows);
    }

    public function toJson(WalkForwardAnalysis $analysis): string
    {
        $data = [
            'classification' => $analysis->classification,
            'interpretation' => $analysis->interpretation,
            'walk_forward_efficiency' => $analysis->walkForwardEfficiency,
            'robust_count' => $analysis->robustCount,
            'robust_ratio' => $analysis->robustRatio,
            'avg_degradation' => $analysis->avgDegradation,
            'median_degradation' => $analysis->medianDegradation,
            'rank_correlation' => $analysis->rankCorrelation,
            'rank_stability_label' => $analysis->rankStabilityLabel,
            'reliable_count' => $analysis->reliableCount,
            'reliable_ratio' => $analysis->reliableRatio,
            'min_trades' => $analysis->minTrades,
            'results' => array_map(function ($r) {
                return [
                    'rank' => $r->rank,
                    'parameters' => $r->parameters,
                    'is_score' => $r->is_score,
                    'oos_score' => $r->oos_score,
                    'score_degradation' => $r->score_degradation,
                    'is_statistics' => $r->is_statistics,
                    'oos_statistics' => $r->oos_statistics,
                ];
            }, $analysis->results),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function formatCsvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) && (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n"))) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return (string) $value;
    }
}
