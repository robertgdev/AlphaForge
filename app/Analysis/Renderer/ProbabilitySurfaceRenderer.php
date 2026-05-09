<?php

namespace App\Analysis\Renderer;

use App\Analysis\Dto\OpenCrossProbabilityResult;

use function Safe\json_encode;

/**
 * Renders probability surface results as ASCII art for terminal output.
 */
final class ProbabilitySurfaceRenderer
{
    /**
     * @var array<int, int> ANSI foreground color codes for probability visualization
     *            Using 256-color mode for better color gradients
     */
    private const PROBABILITY_COLORS = [
        // 0-10%: Dark red (very low probability)
        0 => 196,  // Red
        // 10-20%: Red
        1 => 202,
        // 20-30%: Orange-red
        2 => 208,
        // 30-40%: Orange
        3 => 214,
        // 40-50%: Yellow-orange
        4 => 220,
        // 50-60%: Yellow
        5 => 226,
        // 60-70%: Yellow-green
        6 => 190,
        // 70-80%: Green
        7 => 118,
        // 80-90%: Bright green
        8 => 82,
        // 90-100%: Cyan-green (very high probability)
        9 => 46,
    ];

    /**
     * @var array<int, string> Block characters for probability levels
     *           Key is the probability threshold (multiplied by 10 to avoid float keys)
     */
    private const BLOCK_CHARS = [
        0 => '░',  // 0.0
        1 => '░',  // 0.1
        2 => '▒',  // 0.2
        3 => '▒',  // 0.3
        4 => '▓',  // 0.4
        5 => '▓',  // 0.5
        6 => '█',  // 0.6
        7 => '█',  // 0.7
        8 => '█',  // 0.8
        9 => '█',  // 0.9
        10 => '█', // 1.0
    ];

/**
      * Render a complete heatmap of the probability surface.
      *
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  int  $width  Maximum width for the output
      * @param  array<string, mixed>  $options  Render options (trim_zeros, max_distance)
      * @return string ASCII art heatmap
      */
     public function renderHeatmap(OpenCrossProbabilityResult $result, int $width = 80, array $options = []): string
    {
        $lines = [];
        $metadata = $result->metadata;
        $isVolatilityNormalized = $metadata['volatility_normalized'] ?? false;

        // Header - use sigma symbol for volatility normalized, percentage otherwise
        $bucketLabel = $isVolatilityNormalized
            ? sprintf('Bucket: %.2fσ', $metadata['bucket_size'])
            : sprintf('Bucket: %.2f%%', $metadata['bucket_size'] * 100);

        $lines[] = $this->center('Open-Cross Probability Heatmap', $width);
        $lines[] = $this->center(
            sprintf(
                'Block: %d min | %s | Samples: %s',
                $metadata['block_minutes'],
                $bucketLabel,
                number_format($result->totalObservations)
            ),
            $width
        );
        $lines[] = str_repeat('─', $width);
        $lines[] = '';

        // Get unique buckets and minutes
        $buckets = $result->getDistanceBuckets();
        $minutes = $result->getMinutesRemaining();

        // Apply bucket filtering
        $buckets = $this->filterBuckets($buckets, $result, $options);

        sort($buckets);
        rsort($minutes); // Descending order (most time first)

        if (empty($buckets) || empty($minutes)) {
            $lines[] = 'No data available for heatmap.';

            return implode("\n", $lines);
        }

        // Column headers (minutes remaining)
        $headerLine = 'Distance ↓   ';
        foreach ($minutes as $min) {
            $headerLine .= sprintf('%5d ', $min);
        }
        $lines[] = $headerLine;
        $lines[] = str_repeat('─', strlen($headerLine));

        // Data rows
        $matrix = $result->getHeatmapMatrix();

        foreach ($buckets as $bucket) {
            // Display as sigma for volatility normalized, percentage otherwise
            $row = $isVolatilityNormalized
                ? sprintf('%+7.2fσ  ', $bucket)
                : sprintf('%+7.2f%%  ', $bucket * 100);
            $hasData = false;

            // Convert to string key for matrix lookup (PHP truncates float keys)
            $bucketKey = (string) $bucket;

            foreach ($minutes as $min) {
                if (isset($matrix[$bucketKey][$min])) {
                    $data = $matrix[$bucketKey][$min];
                    $prob = $data['probability'];
                    $char = $this->getProbabilityChar($prob);
                    $row .= sprintf('  %s   ', $char);
                    $hasData = true;
                } else {
                    $row .= '  ·   ';
                }
            }

            if ($hasData) {
                $lines[] = $row;
            }
        }

        $lines[] = '';
        $lines[] = 'Legend: '.$this->renderColorLegend();
        $lines[] = '        · = no data';

        return implode("\n", $lines);
    }

    /**
     * Render a color legend for the heatmap.
     *
     * @return string ANSI-colored legend
     */
    private function renderColorLegend(): string
    {
        $legend = '';

        for ($i = 0; $i <= 9; $i++) {
            $colorCode = self::PROBABILITY_COLORS[$i];
            $char = $i < 3 ? '░' : ($i < 5 ? '▒' : ($i < 7 ? '▓' : '█'));
            $legend .= sprintf("\e[38;5;%dm%s\e[0m", $colorCode, $char);
        }

        $legend .= ' = 0%';
        $legend = str_pad($legend, 20);
        $legend .= '... ';
        $legend .= '100%';

        return $legend;
    }

/**
      * Render a detailed view with probability bars.
      *
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  int  $width  Maximum width for bars
      * @param  array<string, mixed>  $options  Render options (trim_zeros, max_distance)
      * @return string ASCII art with probability bars
      */
     public function renderDetailedView(OpenCrossProbabilityResult $result, int $width = 80, array $options = []): string
    {
        $lines = [];
        $metadata = $result->metadata;
        $barWidth = $width - 40; // Reserve space for labels
        $isVolatilityNormalized = $metadata['volatility_normalized'] ?? false;

        // Header - use sigma symbol for volatility normalized, percentage otherwise
        $bucketLabel = $isVolatilityNormalized
            ? sprintf('Bucket: %.2fσ', $metadata['bucket_size'])
            : sprintf('Bucket: %.2f%%', $metadata['bucket_size'] * 100);

        $lines[] = $this->center('Open-Cross Probability Surface', $width);
        $lines[] = $this->center(
            sprintf(
                'Block: %d min | %s | Volatility Normalized: %s',
                $metadata['block_minutes'],
                $bucketLabel,
                $isVolatilityNormalized ? 'Yes' : 'No'
            ),
            $width
        );
        $lines[] = str_repeat('─', $width);
        $lines[] = '';

        // Group by distance bucket
        $grouped = [];
        foreach ($result->probabilitySurface as $point) {
            $bucket = $point->distanceBucket;
            $bucketKey = sprintf('%.6f', $bucket);
            if (! isset($grouped[$bucketKey])) {
                $grouped[$bucketKey] = [];
            }
            $grouped[$bucketKey][] = $point;
        }

        // Sort buckets
        $buckets = array_keys($grouped);
        $buckets = array_map('floatval', $buckets);

        // Apply bucket filtering
        $buckets = $this->filterBuckets($buckets, $result, $options);

        sort($buckets);

        foreach ($buckets as $bucket) {
            // Display as sigma for volatility normalized, percentage otherwise
            $distanceLabel = $isVolatilityNormalized
                ? sprintf('Distance: %+6.2fσ', $bucket)
                : sprintf('Distance: %+6.2f%%', $bucket * 100);
            $lines[] = $distanceLabel;
            $lines[] = str_repeat('─', 40);

            // Sort by minutes remaining (descending)
            $bucketKey = sprintf('%.6f', $bucket);
            usort($grouped[$bucketKey], function ($a, $b) {
                return $b->minutesRemaining <=> $a->minutesRemaining;
            });

            foreach ($grouped[$bucketKey] as $point) {
                $bar = $this->renderProbabilityBar($point->crossProbability, $barWidth);
                $confidence = $point->confidence === 'high' ? '●' : ($point->confidence === 'medium' ? '◐' : '○');

                $lines[] = sprintf(
                    '  %2d min: %s %5.1f%% (n=%s) %s',
                    $point->minutesRemaining,
                    $bar,
                    $point->crossProbability * 100,
                    number_format($point->samples),
                    $confidence
                );
            }

            $lines[] = '';
        }

        $lines[] = 'Legend: ● = high confidence  ◐ = medium confidence  ○ = low confidence';

        return implode("\n", $lines);
    }

    /**
     * Render a cross-section at a specific minutes remaining value.
     *
     * @param  OpenCrossProbabilityResult  $result  Analysis result
     * @param  int  $minutesRemaining  Minutes remaining value
     * @param  int  $width  Chart width
     * @return string ASCII art cross-section chart
     */
    public function renderCrossSection(
        OpenCrossProbabilityResult $result,
        int $minutesRemaining,
        int $width = 80
    ): string {
        $lines = [];
        $chartWidth = $width - 20;
        $chartHeight = 12;

        // Header
        $lines[] = $this->center(
            sprintf('Cross-Section at %d Minutes Remaining', $minutesRemaining),
            $width
        );
        $lines[] = str_repeat('─', $width);
        $lines[] = '';

        // Get cross-section data
        $crossSection = $result->getCrossSection($minutesRemaining);

        if (empty($crossSection)) {
            $lines[] = sprintf('No data available for %d minutes remaining.', $minutesRemaining);

            return implode("\n", $lines);
        }

        // Sort by distance bucket
        usort($crossSection, function ($a, $b) {
            return $a->distanceBucket <=> $b->distanceBucket;
        });

        // Find min/max for scaling
        $minBucket = min(array_map(fn ($p) => $p->distanceBucket, $crossSection));
        $maxBucket = max(array_map(fn ($p) => $p->distanceBucket, $crossSection));
        $bucketRange = $maxBucket - $minBucket;

        if ($bucketRange == 0) {
            $bucketRange = 1;
        }

        // Create chart grid
        $grid = array_fill(0, $chartHeight, array_fill(0, $chartWidth, ' '));

        // Plot points
        foreach ($crossSection as $point) {
            $x = (int) (($point->distanceBucket - $minBucket) / $bucketRange * ($chartWidth - 1));
            $y = (int) ((1 - $point->crossProbability) * ($chartHeight - 1));

            $x = max(0, min($chartWidth - 1, $x));
            $y = max(0, min($chartHeight - 1, $y));

            $char = $point->confidence === 'high' ? '●' : ($point->confidence === 'medium' ? '◐' : '○');
            $grid[$y][$x] = $char;
        }

        // Draw Y-axis labels
        $lines[] = 'Probability';
        for ($y = 0; $y < $chartHeight; $y++) {
            $prob = (1 - $y / ($chartHeight - 1)) * 100;
            $row = sprintf('%5.0f%% │', $prob);
            $row .= implode('', $grid[$y]);
            $lines[] = $row;
        }

        // X-axis
        $lines[] = '      └'.str_repeat('─', $chartWidth).' Distance from Open';

        // X-axis labels
        $labelLine = '       ';
        $labelLine .= sprintf('%+5.1f%%', $minBucket * 100);
        $labelLine .= str_repeat(' ', $chartWidth - 14);
        $labelLine .= sprintf('%+5.1f%%', $maxBucket * 100);
        $lines[] = $labelLine;

        $lines[] = '';
        $lines[] = '● = high confidence (n≥100)  ◐ = medium (n≥30)  ○ = low (n<30)';

        return implode("\n", $lines);
    }

/**
      * Render a summary of the analysis results.
      *
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  int  $width  Maximum width
      * @param  array<string, mixed>  $options  Render options (trim_zeros, max_distance)
      * @return string Summary text
      */
     public function renderSummary(OpenCrossProbabilityResult $result, int $width = 80, array $options = []): string
    {
        $lines = [];
        $metadata = $result->metadata;
        $isVolatilityNormalized = $metadata['volatility_normalized'] ?? false;

        $lines[] = $this->center('Analysis Summary', $width);
        $lines[] = str_repeat('─', $width);
        $lines[] = '';

        // Basic stats
        $lines[] = sprintf('Exchange:              %s', strtoupper($metadata['exchange']));
        $lines[] = sprintf('Market:                %s', $metadata['market']);
        $lines[] = sprintf('Timeframe:             %s', $metadata['timeframe']);
        $lines[] = sprintf('Block Duration:        %d minutes', $metadata['block_minutes']);

        // Bucket size display - sigma or percentage
        $bucketSizeLabel = $isVolatilityNormalized
            ? sprintf('Bucket Size:           %.4fσ', $metadata['bucket_size'])
            : sprintf('Bucket Size:           %.4f (%.2f%%)', $metadata['bucket_size'], $metadata['bucket_size'] * 100);
        $lines[] = $bucketSizeLabel;
        $lines[] = '';
        $lines[] = sprintf('Total Blocks Analyzed: %s', number_format($result->totalBlocksAnalyzed));
        $lines[] = sprintf('Total Observations:    %s', number_format($result->totalObservations));
        $lines[] = sprintf('Surface Points:        %s', number_format(count($result->probabilitySurface)));
        $lines[] = '';
        $lines[] = sprintf('Volatility Normalized: %s', $isVolatilityNormalized ? 'Yes' : 'No');
        $lines[] = sprintf('Symmetric Merge:       %s', $metadata['merge_symmetric'] ? 'Yes' : 'No');
        $lines[] = '';

        // Memory usage
        $peakMemoryFormatted = $metadata['peak_memory_formatted'] ?? 'N/A';
        $lines[] = sprintf('Peak Memory Usage:     %s', $peakMemoryFormatted);
        $lines[] = '';

        // Key insights
        $lines[] = str_repeat('─', $width);
        $lines[] = 'Key Insights:';
        $lines[] = '';

        $highest = $result->getHighestProbability();
        $lowest = $result->getLowestProbability();

        if ($highest !== null) {
            $distanceLabel = $isVolatilityNormalized
                ? sprintf('%+6.2fσ', $highest->distanceBucket)
                : sprintf('%+6.2f%%', $highest->distanceBucket * 100);
            $lines[] = sprintf(
                '  Highest cross rate: %s distance, %2d min remaining (%.1f%%)',
                $distanceLabel,
                $highest->minutesRemaining,
                $highest->crossProbability * 100
            );
        }

        if ($lowest !== null) {
            $distanceLabel = $isVolatilityNormalized
                ? sprintf('%+6.2fσ', $lowest->distanceBucket)
                : sprintf('%+6.2f%%', $lowest->distanceBucket * 100);
            $lines[] = sprintf(
                '  Lowest cross rate:  %s distance, %2d min remaining (%.1f%%)',
                $distanceLabel,
                $lowest->minutesRemaining,
                $lowest->crossProbability * 100
            );
        }

        // Find interesting patterns
        $lines[] = '';
        $lines[] = 'Notable Patterns:';

        // Find high probability crosses at small distances
        $smallDistanceHighProb = array_filter(
            $result->probabilitySurface,
            fn ($p) => abs($p->distanceBucket) <= 0.002 && $p->crossProbability > 0.5 && $p->confidence === 'high'
        );

        if (! empty($smallDistanceHighProb)) {
            $lines[] = '  • Price near open (>0.2%) has >50% cross probability';
        }

        // Find time decay pattern
        $timeDecay = $this->analyzeTimeDecay($result);
        if ($timeDecay !== null) {
            $lines[] = sprintf('  • %s', $timeDecay);
        }

        return implode("\n", $lines);
    }

/**
      * Filter distance buckets based on render options.
      *
      * @param  array<int, float>  $buckets  Array of distance buckets
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  array<string, mixed>  $options  Render options (trim_zeros, max_distance)
      * @return array<int, float> Filtered buckets
      */
     private function filterBuckets(array $buckets, OpenCrossProbabilityResult $result, array $options): array
    {
        $maxDistance = $options['max_distance'] ?? null;
        $trimZeros = $options['trim_zeros'] ?? false;

        // If max_distance is specified, limit to ±N buckets from zero
        if ($maxDistance !== null) {
            $buckets = array_filter($buckets, fn ($b) => abs($b) <= $maxDistance * ($result->metadata['bucket_size'] ?? 0.001));
        }

        // If trim_zeros is enabled, find the range where probability > 0
        if ($trimZeros) {
            $buckets = $this->trimZeroBuckets($buckets, $result);
        }

        return array_values($buckets);
    }

/**
      * Trim buckets where all probabilities are zero, keeping a margin.
      *
      * @param  array<int, float>  $buckets  Array of distance buckets
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  int  $margin  Number of buckets to keep as margin (default 5)
      * @return array<int, float> Trimmed buckets
      */
     private function trimZeroBuckets(array $buckets, OpenCrossProbabilityResult $result, int $margin = 5): array
    {
        if (empty($buckets)) {
            return $buckets;
        }

        $bucketSize = $result->metadata['bucket_size'] ?? 0.001;
        $matrix = $result->getHeatmapMatrix();

        // Find the furthest bucket from zero that has any non-zero probability
        $maxNonZeroBucket = 0;

        foreach ($buckets as $bucket) {
            // Convert to string key for matrix lookup (PHP truncates float keys)
            $bucketKey = (string) $bucket;

            if (! isset($matrix[$bucketKey])) {
                continue;
            }

            foreach ($matrix[$bucketKey] as $data) {
                if ($data['probability'] > 0.001) { // Use small threshold for "non-zero"
                    if (abs($bucket) > abs($maxNonZeroBucket)) {
                        $maxNonZeroBucket = $bucket;
                    }
                    break; // Only need one non-zero value per bucket
                }
            }
        }

        // Calculate the limit with margin
        $limit = abs($maxNonZeroBucket) + ($margin * $bucketSize);

        // Filter buckets to the range
        return array_filter($buckets, fn ($b) => abs($b) <= $limit);
    }

    /**
     * Render a single probability bar.
     *
     * @param  float  $probability  Probability value (0-1)
     * @param  int  $width  Bar width
     * @return string ASCII bar
     */
    private function renderProbabilityBar(float $probability, int $width): string
    {
        $filled = (int) round($probability * $width);
        $filled = max(0, min($width, $filled));

        return '['.str_repeat('█', $filled).str_repeat('░', $width - $filled).']';
    }

/**
      * Get the colored character for a probability value.
      *
      * @param  float  $probability  Probability value (0-1)
      * @return string ANSI-colored character
      */
     private function getProbabilityChar(float $probability): string
     {
         $prob = max(0, min(1, $probability));

         // Get block character
         $char = '█';
         foreach (self::BLOCK_CHARS as $threshold => $blockChar) {
             if ($prob <= ($threshold / 10)) {
                 $char = $blockChar;
                 break;
             }
         }

         // Get color index (0-9 for 10 deciles)
         $colorIndex = (int) min(9, floor($prob * 10));
         $colorCode = self::PROBABILITY_COLORS[$colorIndex];

         // Return 256-color ANSI character
         return sprintf("\e[38;5;%dm%s\e[0m", $colorCode, $char);
     }

    /**
     * Center text within a given width.
     *
     * @param  string  $text  Text to center
     * @param  int  $width  Total width
     * @return string Centered text
     */
    private function center(string $text, int $width): string
    {
        $padding = max(0, ($width - strlen($text)) / 2);

        return str_repeat(' ', (int) $padding).$text;
    }

    /**
     * Analyze time decay pattern in the results.
     *
     * @param  OpenCrossProbabilityResult  $result  Analysis result
     * @return string|null Pattern description or null
     */
private function analyzeTimeDecay(OpenCrossProbabilityResult $result): ?string
     {
         // Group by distance bucket and check if probability decreases with time
         $grouped = [];

         foreach ($result->probabilitySurface as $point) {
             if ($point->confidence !== 'high') {
                 continue;
             }

             $bucketKey = sprintf('%.6f', $point->distanceBucket);
             if (! isset($grouped[$bucketKey])) {
                 $grouped[$bucketKey] = [];
             }
             $grouped[$bucketKey][] = $point;
         }

        $decayCount = 0;
        $totalBuckets = 0;

        foreach ($grouped as $points) {
            if (count($points) < 3) {
                continue;
            }

            $totalBuckets++;

            // Sort by minutes remaining (descending)
            usort($points, fn ($a, $b) => $b->minutesRemaining <=> $a->minutesRemaining);

            // Check if probability generally decreases
            $decreasing = 0;
            for ($i = 1; $i < count($points); $i++) {
                if ($points[$i]->crossProbability < $points[$i - 1]->crossProbability) {
                    $decreasing++;
                }
            }

            if ($decreasing > count($points) / 2) {
                $decayCount++;
            }
        }

        if ($totalBuckets > 0 && $decayCount / $totalBuckets > 0.7) {
            return 'Cross probability decreases as time runs out (time decay observed)';
        }

        return null;
    }

/**
      * Render an interactive HTML heatmap with Plotly.js.
      *
      * @param  OpenCrossProbabilityResult  $result  Analysis result
      * @param  array<string, mixed>  $options  Render options (trim_zeros, max_distance)
      * @return string Self-contained HTML document
      */
     public function renderHtml(OpenCrossProbabilityResult $result, array $options = []): string
    {
        $metadata = $result->metadata;
        $matrix = $result->getHeatmapMatrix();
        $isVolatilityNormalized = $metadata['volatility_normalized'] ?? false;

        // Get buckets and minutes
        $buckets = $result->getDistanceBuckets();
        $buckets = $this->filterBuckets($buckets, $result, $options);
        sort($buckets); // Ascending: negative to positive

        $minutes = $result->getMinutesRemaining();
        sort($minutes); // Ascending: 1 to max

        // Build data arrays for Plotly
        // X-axis = Minutes remaining (ascending 1 to max), Y-axis = Distance buckets (ascending negative to positive)
        // Y-axis will be reversed in layout to show negative at top
        $zData = [];
        $textData = [];
        $customData = [];

        foreach ($buckets as $bucket) {
            $bucketKey = (string) $bucket;
            $row = [];
            $textRow = [];
            $customRow = [];

            foreach ($minutes as $min) {
                if (isset($matrix[$bucketKey][$min])) {
                    $data = $matrix[$bucketKey][$min];
                    $row[] = $data['probability'] * 100;
                    $textRow[] = sprintf(
                        'Probability: %.1f%%<br>Samples: %s<br>Confidence: %s',
                        $data['probability'] * 100,
                        number_format($data['samples']),
                        ucfirst($data['confidence'])
                    );
                    $customRow[] = [
                        'probability' => $data['probability'],
                        'samples' => $data['samples'],
                        'confidence' => $data['confidence'],
                    ];
                } else {
                    $row[] = null;
                    $textRow[] = 'No data';
                    $customRow[] = null;
                }
            }

            $zData[] = $row;
            $textData[] = $textRow;
            $customData[] = $customRow;
        }

        // Prepare x-axis labels (minutes remaining) - ascending
        $xLabels = array_map(fn ($m) => (string) $m, $minutes);

// Prepare y-axis labels (distance buckets) - ascending
         // Use sigma for volatility normalized, percentage otherwise
         $yLabels = $isVolatilityNormalized
             ? array_map(fn ($b) => sprintf('%+.2fσ', $b), $buckets)
             : array_map(fn ($b) => sprintf('%+.2f%%', $b * 100), $buckets);

        // Bucket size label for subtitle (defined before use)
        $bucketLabel = $isVolatilityNormalized
            ? sprintf('Bucket: %.2fσ', $metadata['bucket_size'])
            : sprintf('Bucket: %.2f%%', $metadata['bucket_size'] * 100);

        // Build HTML
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open-Cross Probability Heatmap</title>
    <script src="https://cdn.plot.ly/plotly-2.27.0.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #e0e0e0;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #00d4ff, #00ff88);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .header .subtitle {
            color: #888;
            font-size: 0.95rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 15px 20px;
            border-left: 3px solid #00d4ff;
        }
        .stat-card .label {
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .value {
            font-size: 1.4rem;
            font-weight: 600;
            color: #fff;
            margin-top: 5px;
        }
        .chart-container {
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .chart-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #fff;
        }
        #heatmap {
            width: 100%;
            height: 600px;
        }
        .insights {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .insights h2 {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #00d4ff;
        }
        .insights ul {
            list-style: none;
            padding-left: 0;
        }
        .insights li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            color: #ccc;
        }
        .insights li:last-child {
            border-bottom: none;
        }
        .insights li::before {
            content: '→';
            color: #00ff88;
            margin-right: 10px;
        }
        .legend-section {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .legend-section h2 {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: #00d4ff;
        }
        .color-scale {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .color-gradient {
            flex: 1;
            height: 30px;
            background: linear-gradient(90deg,
                #ff0000 0%,
                #ff6600 20%,
                #ffcc00 40%,
                #ccff00 50%,
                #66ff00 60%,
                #00ff66 80%,
                #00ffcc 100%
            );
            border-radius: 5px;
        }
        .color-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 0.85rem;
            color: #888;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Open-Cross Probability Analysis</h1>
            <div class="subtitle">
HTML;

        $html .= sprintf(
            '%s | %s | %s | Block: %d min | %s',
            strtoupper($metadata['exchange']),
            $metadata['market'],
            $metadata['timeframe'],
            $metadata['block_minutes'],
            $bucketLabel
        );

        $html .= <<<'HTML'
            </div>
        </div>

        <div class="stats-grid">
HTML;

        // Add stat cards
        $stats = [
            ['label' => 'Total Blocks', 'value' => number_format($result->totalBlocksAnalyzed)],
            ['label' => 'Total Observations', 'value' => number_format($result->totalObservations)],
            ['label' => 'Surface Points', 'value' => number_format(count($result->probabilitySurface))],
            ['label' => 'Volatility Normalized', 'value' => $isVolatilityNormalized ? 'Yes' : 'No'],
            ['label' => 'Peak Memory', 'value' => $metadata['peak_memory_formatted'] ?? 'N/A'],
        ];

        foreach ($stats as $stat) {
            $html .= sprintf(
                '<div class="stat-card"><div class="label">%s</div><div class="value">%s</div></div>',
                $stat['label'],
                $stat['value']
            );
        }

        $html .= <<<'HTML'
        </div>

        <div class="chart-container">
            <div class="chart-title">Probability Heatmap: P(cross | distance, time_remaining)</div>
            <div id="heatmap"></div>
        </div>

        <div class="legend-section">
            <h2>Color Scale</h2>
            <div class="color-scale">
                <span>Low</span>
                <div class="color-gradient"></div>
                <span>High</span>
            </div>
            <div class="color-labels">
                <span>0% (Unlikely to cross)</span>
                <span>50%</span>
                <span>100% (Very likely to cross)</span>
            </div>
        </div>
HTML;

        // Add insights section
        $html .= '<div class="insights"><h2>Key Insights</h2><ul>';

        $highest = $result->getHighestProbability();
        $lowest = $result->getLowestProbability();

        if ($highest !== null) {
            $distanceLabel = $isVolatilityNormalized
                ? sprintf('%+.2fσ', $highest->distanceBucket)
                : sprintf('%+.2f%%', $highest->distanceBucket * 100);
            $html .= sprintf(
                '<li>Highest cross probability: <strong>%.1f%%</strong> at distance <strong>%s</strong> with <strong>%d</strong> min remaining</li>',
                $highest->crossProbability * 100,
                $distanceLabel,
                $highest->minutesRemaining
            );
        }

        if ($lowest !== null) {
            $distanceLabel = $isVolatilityNormalized
                ? sprintf('%+.2fσ', $lowest->distanceBucket)
                : sprintf('%+.2f%%', $lowest->distanceBucket * 100);
            $html .= sprintf(
                '<li>Lowest cross probability: <strong>%.1f%%</strong> at distance <strong>%s</strong> with <strong>%d</strong> min remaining</li>',
                $lowest->crossProbability * 100,
                $distanceLabel,
                $lowest->minutesRemaining
            );
        }

        $timeDecay = $this->analyzeTimeDecay($result);
        if ($timeDecay !== null) {
            $html .= sprintf('<li>%s</li>', $timeDecay);
        }

        $html .= '</ul></div>';

        $html .= '<div class="footer">Generated by AlphaForge Open-Cross Probability Engine</div>';
        $html .= '</div>';

        // Add Plotly.js code
        $html .= '<script>';
        $html .= 'var data = [{';
        $html .= '    type: "heatmap",';
        $html .= '    z: '.json_encode($zData).',';
        $html .= '    x: '.json_encode($xLabels).',';
        $html .= '    y: '.json_encode($yLabels).',';
        $html .= '    text: '.json_encode($textData).',';
        $html .= '    hoverinfo: "text",';
        $html .= '    colorscale: [';
        $html .= '        [0, "#ff0000"],';
        $html .= '        [0.2, "#ff6600"],';
        $html .= '        [0.4, "#ffcc00"],';
        $html .= '        [0.5, "#ccff00"],';
        $html .= '        [0.6, "#66ff00"],';
        $html .= '        [0.8, "#00ff66"],';
        $html .= '        [1, "#00ffcc"]';
        $html .= '    ],';
        $html .= '    reversescale: false,';
        $html .= '    showscale: true,';
        $html .= '    colorbar: {';
        $html .= '        title: "Cross Probability (%)",';
        $html .= '        titleside: "right",';
        $html .= '        tickformat: ".0f",';
        $html .= '        tickfont: { color: "#ccc" },';
        $html .= '        titlefont: { color: "#ccc" }';
        $html .= '    },';
        $html .= '    hoverongaps: false';
        $html .= '}];';

        $html .= 'var layout = {';
        $html .= '    paper_bgcolor: "rgba(0,0,0,0)",';
        $html .= '    plot_bgcolor: "rgba(0,0,0,0)",';
        $html .= '    xaxis: {';
        $html .= '        title: "Minutes Remaining in Block",';
        $html .= '        titlefont: { color: "#ccc" },';
        $html .= '        tickfont: { color: "#ccc" },';
        $html .= '        side: "bottom"';
        $html .= '    },';
        $html .= '    yaxis: {';
        $html .= '        title: "Distance from Block Open",';
        $html .= '        titlefont: { color: "#ccc" },';
        $html .= '        tickfont: { color: "#ccc" },';
        $html .= '        autorange: "reversed"';
        $html .= '    },';
        $html .= '    margin: { l: 100, r: 100, t: 30, b: 60 },';
        $html .= '    height: 600';
        $html .= '};';

        $html .= 'var config = {';
        $html .= '    responsive: true,';
        $html .= '    displayModeBar: true,';
        $html .= '    modeBarButtonsToRemove: ["lasso2d", "select2d"],';
        $html .= '    displaylogo: false';
        $html .= '};';

        $html .= 'Plotly.newPlot("heatmap", data, layout, config);';
        $html .= '</script>';

        $html .= '</body></html>';

        return $html;
    }
}
