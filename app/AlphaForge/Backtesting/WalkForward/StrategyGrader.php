<?php

namespace App\AlphaForge\Backtesting\WalkForward;

final readonly class StrategyGrader
{
    /**
     * Grade a walk-forward analysis with a composite 1-5 star score.
     *
     * Weights: economic 40%, robustness 30%, risk 20%, optimization 10%.
     * Robustness acts as a floor: overall rating cannot exceed the robustness band.
     *
     * @return array{score: float, stars: string, label: string, stars_by_category: array<string, string>, breakdown: array<string, float>}
     */
    public static function grade(WalkForwardAnalysis $analysis): array
    {
        $economic = self::gradeEconomic($analysis);
        $robustness = self::gradeRobustness($analysis);
        $risk = self::gradeRisk($analysis);
        $optimization = self::gradeOptimization($analysis);

        $total = (0.40 * $economic) + (0.30 * $robustness) + (0.20 * $risk) + (0.10 * $optimization);

        $maxScore = self::determineGate($analysis);
        $total = min($total, $maxScore);

        $total = min($total, self::robustnessFloor($robustness));

        $overallStars = self::scoreToStars($total);
        $overallLabel = self::scoreToLabel($total);

        return [
            'score' => round($total, 1),
            'stars' => $overallStars,
            'label' => $overallLabel,
            'stars_by_category' => [
                'economic' => self::scoreToStars($economic),
                'robustness' => self::scoreToStars($robustness),
                'risk' => self::scoreToStars($risk),
                'optimization' => self::scoreToStars($optimization),
            ],
            'breakdown' => [
                'economic' => round($economic, 0),
                'robustness' => round($robustness, 0),
                'risk' => round($risk, 0),
                'optimization' => round($optimization, 0),
            ],
        ];
    }

    /**
     * Robustness acts as a ceiling on the final score.
     * A strategy with weak/likely_overfit robustness should not exceed 2 stars
     * regardless of how good the other metrics look.
     */
    private static function robustnessFloor(float $robustness): float
    {
        if ($robustness >= 80) {
            return 100;
        }
        if ($robustness >= 60) {
            return 79;
        }
        if ($robustness >= 40) {
            return 59;
        }
        if ($robustness >= 20) {
            return 39;
        }

        return 19;
    }

    /**
     * Convert a 0-100 score to a 1-5 star string (unicode ★).
     */
    private static function scoreToStars(float $score): string
    {
        if ($score >= 80) {
            return '★★★★★';
        }
        if ($score >= 60) {
            return '★★★★☆';
        }
        if ($score >= 40) {
            return '★★★☆☆';
        }
        if ($score >= 20) {
            return '★★☆☆☆';
        }

        return '★☆☆☆☆';
    }

    /**
     * Convert a 0-100 score to a human-readable label.
     */
    private static function scoreToLabel(float $score): string
    {
        if ($score >= 80) {
            return '(5/5) Exceptional across performance, robustness, and risk';
        }
        if ($score >= 60) {
            return '(4/5) Strong strategy; merits further validation';
        }
        if ($score >= 40) {
            return '(3/5) Promising research candidate';
        }
        if ($score >= 20) {
            return '(2/5) Some merit but not investment-worthy';
        }

        return '(1/5) Poor; likely unusable';
    }

    private static function gradeEconomic(WalkForwardAnalysis $analysis): float
    {
        if (! $analysis->benchmarkHasData) {
            $oosReturn = $analysis->medianOosReturn;

            return self::clamp(abs($oosReturn) * 5, 0, 100);
        }

        $captureRatio = $analysis->captureRatio;

        if ($captureRatio <= 0) {
            return 0;
        }

        if ($captureRatio >= 50) {
            return 100;
        }
        if ($captureRatio >= 20) {
            return 40 + (($captureRatio - 20) / 30) * 60;
        }
        if ($captureRatio >= 10) {
            return 20 + (($captureRatio - 10) / 10) * 20;
        }

        return max(10, $captureRatio * 2);
    }

    private static function gradeRobustness(WalkForwardAnalysis $analysis): float
    {
        $robustRatioPct = $analysis->robustRatio * 100;

        $rankCorrelation = $analysis->rankCorrelation ?? 0.0;
        $rankScore = max(0, $rankCorrelation * 100);

        $stabilityScore = match ($analysis->stabilityClassification) {
            'excellent' => 25,
            'good' => 18,
            'moderate' => 10,
            'weak' => 5,
            'likely_overfit' => 0,
            default => 0,
        };

        $score = ($robustRatioPct * 0.5) + ($rankScore * 0.3) + $stabilityScore;

        return self::clamp($score, 0, 100);
    }

    private static function gradeRisk(WalkForwardAnalysis $analysis): float
    {
        $maxDD = abs($analysis->medianOosMaxDd);

        if ($maxDD <= 0) {
            $ddScore = 100;
        } elseif ($maxDD < 5) {
            $ddScore = 100;
        } elseif ($maxDD < 10) {
            $ddScore = 80;
        } elseif ($maxDD < 20) {
            $ddScore = 50;
        } elseif ($maxDD < 30) {
            $ddScore = 30;
        } else {
            $ddScore = 10;
        }

        if ($analysis->suspiciousSharpe) {
            $ddScore *= 0.5;
        }

        $oosSharpe = $analysis->medianOosSharpe;
        if ($analysis->benchmarkHasData && $analysis->benchmarkSharpe > 0) {
            $sharpeRatio = $oosSharpe / $analysis->benchmarkSharpe;
            if ($sharpeRatio >= 1.5) {
                $sharpeScore = 100;
            } elseif ($sharpeRatio >= 1.0) {
                $sharpeScore = 70;
            } elseif ($sharpeRatio >= 0.5) {
                $sharpeScore = 40;
            } else {
                $sharpeScore = 10;
            }
        } else {
            if ($oosSharpe >= 2.0) {
                $sharpeScore = 100;
            } elseif ($oosSharpe >= 1.0) {
                $sharpeScore = 70;
            } elseif ($oosSharpe >= 0.5) {
                $sharpeScore = 40;
            } else {
                $sharpeScore = 10;
            }
        }

        if ($analysis->suspiciousSharpe) {
            $sharpeScore *= 0.5;
        }

        return self::clamp(($ddScore * 0.5) + ($sharpeScore * 0.5), 0, 100);
    }

    private static function gradeOptimization(WalkForwardAnalysis $analysis): float
    {
        $penalty = 0;

        $boundaryWarnings = count($analysis->boundaryWarnings);
        $penalty += min(60, $boundaryWarnings * 20);

        $medDegradation = abs($analysis->medianDegradation);
        if ($medDegradation > 50) {
            $penalty += 20;
        } elseif ($medDegradation > 20) {
            $penalty += 10;
        } elseif ($medDegradation > 10) {
            $penalty += 5;
        }

        return self::clamp(100 - $penalty, 0, 100);
    }

    private static function determineGate(WalkForwardAnalysis $analysis): float
    {
        if (! $analysis->benchmarkHasData) {
            return 100;
        }

        $oosReturn = $analysis->medianOosReturn;
        $benchmarkReturn = $analysis->benchmarkReturn;

        if ($oosReturn <= 0) {
            return 19;
        }

        if ($benchmarkReturn > 0 && $oosReturn < 0.25 * $benchmarkReturn) {
            return 39;
        }

        if ($analysis->beatBuyHoldCount === 0) {
            return 59;
        }

        return 100;
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}