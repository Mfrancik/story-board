<?php

namespace App\Services\Production;

/**
 * How the board writes a production metric's number (SB-18), shared by the project tiles (RD-7) and the
 * sparkline's hover readout so a figure and its trend line never disagree. Pure: no database, no I/O.
 * It lived on the /prod page's action until RD-9 deleted that page.
 */
class MetricNumber
{
    /**
     * A number as the board writes it: whole numbers with thousands separators, anything else to at most two
     * places with trailing zeros dropped (1234 → "1,234", 2.5 → "2.5").
     */
    public static function format(float $value): string
    {
        return floor($value) === $value ? number_format($value) : rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
