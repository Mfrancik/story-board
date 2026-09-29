<?php

namespace App\Services\Production;

/**
 * Every enabled metric of one project read in one production session (the
 * shape SB-18's dashboard and snapshots consume): the connection check that
 * gated the read, and a reading per metric key in position order. When the
 * check is not ok, `readings` is empty — nothing was run.
 */
final readonly class ProductionRead
{
    /**
     * @param  array<string, MetricReading>  $readings  keyed by ProdMetric::$key
     */
    public function __construct(
        public ConnectionCheck $check,
        public array $readings,
    ) {}
}
