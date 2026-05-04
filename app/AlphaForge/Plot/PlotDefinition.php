<?php

namespace App\AlphaForge\Plot;

class PlotDefinition
{
    /**
     * @param  array  $plots  The plot series configurations
     * @param  array  $annotations  The annotations for the plot
     */
    public function __construct(
        public string $name,
        public bool $overlay,
        public array $plots = [],
        public array $annotations = [],
        public ?string $indicatorKey = null
    ) {}
}
