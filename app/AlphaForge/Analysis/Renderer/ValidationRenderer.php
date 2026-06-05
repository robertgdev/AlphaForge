<?php

namespace App\AlphaForge\Analysis\Renderer;

use App\AlphaForge\Analysis\Dto\Validation\ValidationResult;

use function Safe\json_encode;

/**
 * Renders validation results in multiple formats.
 */
final class ValidationRenderer
{
    /**
     * Render validation result as JSON.
     *
     * @param  ValidationResult  $result  Validation result
     * @param  int  $flags  JSON encoding flags
     */
    public function toJson(ValidationResult $result, int $flags = JSON_PRETTY_PRINT): string
    {
        return $result->toJson($flags);
    }

    /**
     * Render validation result as CSV.
     *
     * @param  ValidationResult  $result  Validation result
     */
    public function toCsv(ValidationResult $result): string
    {
        $sections = [];

        // Metadata section
        $sections[] = '# METADATA';
        $sections[] = 'key,value';
        foreach ($result->metadata as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $sections[] = sprintf('%s,"%s"', $key, $value);
        }
        $sections[] = '';

        // Acceptance criteria section
        $sections[] = '# ACCEPTANCE CRITERIA';
        $sections[] = 'criterion,status,value,threshold';
        foreach ($result->acceptanceCriteria as $criterion => $data) {
            $sections[] = sprintf(
                '%s,%s,%s,%s',
                $criterion,
                $data['status'],
                $data['value'] ?? '',
                $data['threshold'] ?? ''
            );
        }
        $sections[] = '';

        // Calibration section
        if ($result->calibration !== null) {
            $sections[] = '# CALIBRATION';
            $sections[] = $result->calibration->toCsv();
            $sections[] = '';
        }

        // Stability section
        if ($result->stability !== null) {
            $sections[] = '# ROLLING STABILITY';
            $sections[] = $result->stability->toCsv();
            $sections[] = '';
        }

        // Uncertainty section
        if ($result->uncertainty !== null) {
            $sections[] = '# UNCERTAINTY';
            $sections[] = $result->uncertainty->toCsv();
            $sections[] = '';
        }

        return implode("\n", $sections);
    }

    /**
     * Render validation result as Markdown.
     *
     * @param  ValidationResult  $result  Validation result
     */
    public function toMarkdown(ValidationResult $result): string
    {
        $lines = [];

        // Title
        $lines[] = '# Statistical Validation Report';
        $lines[] = '';
        $lines[] = sprintf('**Generated:** %s', $result->metadata['validation_timestamp'] ?? date('c'));
        $lines[] = '';

        // Metadata
        $lines[] = '## Configuration';
        $lines[] = '';
        $lines[] = '| Parameter | Value |';
        $lines[] = '|-----------|-------|';
        $lines[] = sprintf('| Exchange | %s |', strtoupper($result->metadata['exchange']));
        $lines[] = sprintf('| Market | %s |', $result->metadata['market']);
        $lines[] = sprintf('| Timeframe | %s |', $result->metadata['timeframe']);
        $lines[] = '';

        // Acceptance Criteria
        $lines[] = '## Acceptance Criteria';
        $lines[] = '';
        $lines[] = '| Criterion | Status | Value | Threshold |';
        $lines[] = '|-----------|--------|-------|-----------|';

        foreach ($result->acceptanceCriteria as $criterion => $data) {
            $status = $data['status'] === 'PASS' ? '✅ PASS' : '❌ FAIL';
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $this->formatCriterionName($criterion),
                $status,
                $data['value'] ?? '-',
                $data['threshold'] ?? '-'
            );
        }
        $lines[] = '';

        // Overall status
        $overallStatus = $result->isPassed() ? '✅ PASSED' : '❌ FAILED';
        $lines[] = sprintf('**Overall Status:** %s', $overallStatus);
        $lines[] = '';

        // Train/Test Results
        if ($result->trainTest !== null) {
            $lines[] = '## Train/Test Split';
            $lines[] = '';
            $lines[] = '### Periods';
            $lines[] = '';
            $trainTest = $result->trainTest;
            $lines[] = sprintf('- **Train Period:** %s to %s', $trainTest->trainPeriod['start'], $trainTest->trainPeriod['end']);
            $lines[] = sprintf('- **Test Period:** %s to %s', $trainTest->testPeriod['start'], $trainTest->testPeriod['end']);
            $lines[] = sprintf('- **Train Observations:** %s', number_format($trainTest->trainObservations));
            $lines[] = sprintf('- **Test Observations:** %s', number_format($trainTest->testObservations));
            $lines[] = '';
            $lines[] = '### Metrics';
            $lines[] = '';
            $lines[] = sprintf('- **Mean Predicted Probability:** %.4f', $trainTest->meanPredictedProbability);
            $lines[] = sprintf('- **Mean Realized Frequency:** %.4f', $trainTest->meanRealizedFrequency);
            $lines[] = sprintf('- **Absolute Calibration Error:** %.4f', $trainTest->absoluteCalibrationError);
            $lines[] = '';
        }

        // Calibration Results
        if ($result->calibration !== null) {
            $lines[] = '## Calibration Test';
            $lines[] = '';
            $calibration = $result->calibration;
            $lines[] = sprintf('- **Brier Score:** %.4f', $calibration->brierScore);
            $lines[] = sprintf('- **Mean Absolute Calibration Error:** %.4f', $calibration->meanAbsoluteCalibrationError);
            $lines[] = sprintf('- **Max Calibration Deviation:** %.4f', $calibration->maxCalibrationDeviation);
            $lines[] = sprintf('- **Total Samples:** %s', number_format($calibration->totalSamples));
            $lines[] = sprintf('- **Is Calibrated:** %s', $calibration->isCalibrated ? 'Yes' : 'No');
            $lines[] = '';

            if (! empty($calibration->bins)) {
                $lines[] = '### Calibration Bins';
                $lines[] = '';
                $lines[] = '| Probability Bin | Samples | Avg Predicted | Observed Freq | Error |';
                $lines[] = '|-----------------|---------|---------------|---------------|-------|';

                foreach ($calibration->bins as $bin) {
                    $lines[] = sprintf(
                        '| %s | %s | %.4f | %.4f | %.4f |',
                        $bin->getBinLabel(),
                        number_format($bin->samples),
                        $bin->avgPredictedProbability,
                        $bin->observedFrequency,
                        $bin->calibrationError
                    );
                }
                $lines[] = '';
            }
        }

        // Stability Results
        if ($result->stability !== null) {
            $lines[] = '## Rolling Stability Test';
            $lines[] = '';
            $stability = $result->stability;
            $lines[] = sprintf('- **Overall Stability Score:** %.4f', $stability->overallStabilityScore);
            $lines[] = sprintf('- **Mean Correlation:** %.4f', $stability->meanCorrelation);
            $lines[] = sprintf('- **Max Drift:** %.4f', $stability->maxDrift);
            $lines[] = sprintf('- **Is Stable:** %s', $stability->isStable ? 'Yes' : 'No');
            $lines[] = '';

            if (! empty($stability->windows)) {
                $lines[] = '### Window Results';
                $lines[] = '';
                $lines[] = '| Window Start | Window End | Mean Diff | Max Diff | Correlation |';
                $lines[] = '|--------------|------------|-----------|----------|-------------|';

                foreach ($stability->windows as $window) {
                    $lines[] = sprintf(
                        '| %s | %s | %.4f | %.4f | %.4f |',
                        $window->windowStart,
                        $window->windowEnd,
                        $window->meanDifference,
                        $window->maxDifference,
                        $window->correlation
                    );
                }
                $lines[] = '';
            }
        }

        // Regime Sensitivity Results
        if ($result->regime !== null) {
            $lines[] = '## Regime Sensitivity Analysis';
            $lines[] = '';
            $regime = $result->regime;
            $lines[] = sprintf('- **Surface Distance:** %.4f', $regime->surfaceDistance);
            $lines[] = sprintf('- **Cross-Regime Stability:** %.4f', $regime->crossRegimeStability);
            $lines[] = sprintf('- **Is Explainable:** %s', $regime->isExplainable ? 'Yes' : 'No');
            $lines[] = '';
        }

        // Uncertainty Results
        if ($result->uncertainty !== null) {
            $lines[] = '## Uncertainty Estimation';
            $lines[] = '';
            $uncertainty = $result->uncertainty;
            $lines[] = sprintf('- **Total Buckets:** %s', number_format($uncertainty->totalBuckets));
            $lines[] = sprintf('- **Flagged Buckets:** %s (%.1f%%)',
                number_format($uncertainty->flaggedBuckets),
                $uncertainty->totalBuckets > 0 ? ($uncertainty->flaggedBuckets / $uncertainty->totalBuckets * 100) : 0
            );
            $lines[] = sprintf('- **Average Standard Error:** %.4f', $uncertainty->avgStandardError);
            $lines[] = '';
        }

        // Randomization Results
        if ($result->randomization !== null) {
            $lines[] = '## Randomized Baseline Comparison';
            $lines[] = '';
            $randomization = $result->randomization;
            $lines[] = sprintf('- **Mean Surface Difference:** %.4f', $randomization->meanSurfaceDifference);
            $lines[] = sprintf('- **Structural Deviation Score:** %.4f', $randomization->structuralDeviationScore);
            $lines[] = sprintf('- **Calibration Degradation:** %.4f', $randomization->calibrationDegradation);
            $lines[] = sprintf('- **Iterations:** %d', $randomization->iterations);
            $lines[] = sprintf('- **Significantly Different:** %s', $randomization->isSignificantlyDifferent ? 'Yes' : 'No');
            $lines[] = '';
        }

        // Cross-Period Results
        if ($result->crossPeriod !== null) {
            $lines[] = '## Cross-Period Comparison';
            $lines[] = '';
            $crossPeriod = $result->crossPeriod;
            $lines[] = sprintf('- **Periods Analyzed:** %s', implode(', ', $crossPeriod->periods));
            $lines[] = sprintf('- **Monotonicity Preserved:** %s', $crossPeriod->monotonicityPreserved ? 'Yes' : 'No');
            $lines[] = sprintf('- **Overall Persistence:** %.4f', $crossPeriod->overallPersistence);
            $lines[] = '';
        }

        // Simulation Results
        if ($result->simulation !== null) {
            $lines[] = '## Strategy Simulation';
            $lines[] = '';
            $simulation = $result->simulation;
            $lines[] = sprintf('- **Total Trades:** %s', number_format($simulation->totalTrades));
            $lines[] = sprintf('- **Win Rate:** %.2f%%', $simulation->winRate * 100);
            $lines[] = sprintf('- **Expected Value:** %.6f', $simulation->expectedValue);
            $lines[] = sprintf('- **Sharpe Ratio:** %.4f', $simulation->sharpeRatio);
            $lines[] = sprintf('- **Max Drawdown:** %.2f%%', $simulation->maxDrawdown * 100);
            $lines[] = sprintf('- **Performance Stability:** %.4f', $simulation->performanceStability);
            $lines[] = sprintf('- **Is Profitable:** %s', $simulation->isProfitable ? 'Yes' : 'No');
            $lines[] = '';
        }

        // Footer
        $lines[] = '---';
        $lines[] = '*Generated by AlphaForge Statistical Validation Framework*';

        return implode("\n", $lines);
    }

    /**
     * Render a summary for terminal output.
     *
     * @param  ValidationResult  $result  Validation result
     * @param  int  $width  Terminal width
     */
    public function renderSummary(ValidationResult $result, int $width = 80): string
    {
        $lines = [];

        $lines[] = $this->center('Statistical Validation Report', $width);
        $lines[] = str_repeat('─', $width);
        $lines[] = '';

        // Metadata
        $lines[] = sprintf('Exchange:     %s', strtoupper($result->metadata['exchange']));
        $lines[] = sprintf('Market:       %s', $result->metadata['market']);
        $lines[] = sprintf('Timeframe:    %s', $result->metadata['timeframe']);
        $lines[] = '';

        // Acceptance Criteria Summary
        $lines[] = str_repeat('─', $width);
        $lines[] = 'Acceptance Criteria:';
        $lines[] = '';

        foreach ($result->acceptanceCriteria as $criterion => $data) {
            $status = $data['status'] === 'PASS' ? '✓' : '✗';
            $color = $data['status'] === 'PASS' ? "\e[32m" : "\e[31m";
            $reset = "\e[0m";

            $lines[] = sprintf(
                '  %s%s%s %s: %s (threshold: %s)',
                $color,
                $status,
                $reset,
                $this->formatCriterionName($criterion),
                $data['value'] ?? '-',
                $data['threshold'] ?? '-'
            );
        }

        $lines[] = '';

        // Overall status
        $overallStatus = $result->isPassed() ? 'PASSED' : 'FAILED';
        $color = $result->isPassed() ? "\e[32m" : "\e[31m";
        $reset = "\e[0m";
        $lines[] = sprintf('Overall Status: %s%s%s', $color, $overallStatus, $reset);
        $lines[] = '';

        // Key metrics
        $lines[] = str_repeat('─', $width);
        $lines[] = 'Key Metrics:';
        $lines[] = '';

        if ($result->trainTest !== null) {
            $lines[] = sprintf('  Calibration Error: %.4f', $result->trainTest->absoluteCalibrationError);
        }

        if ($result->stability !== null) {
            $lines[] = sprintf('  Stability Score:   %.4f', $result->stability->overallStabilityScore);
        }

        if ($result->simulation !== null) {
            $lines[] = sprintf('  Sharpe Ratio:      %.4f', $result->simulation->sharpeRatio);
            $lines[] = sprintf('  Win Rate:          %.2f%%', $result->simulation->winRate * 100);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Center text within a given width.
     *
     * @param  string  $text  Text to center
     * @param  int  $width  Total width
     */
    private function center(string $text, int $width): string
    {
        $padding = max(0, (int) (($width - strlen($text)) / 2));

        return str_repeat(' ', $padding).$text;
    }

    /**
     * Format a criterion name for display.
     *
     * @param  string  $criterion  Criterion name
     */
    private function formatCriterionName(string $criterion): string
    {
        return ucwords(str_replace('_', ' ', $criterion));
    }
}
