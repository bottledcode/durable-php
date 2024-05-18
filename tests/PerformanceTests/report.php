<?php
/*
 * Copyright ©2024 Robert Landers
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the “Software”), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT
 * OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
 * OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

function export_report(string $test, float $seconds): void
{
    $reportFile = __DIR__ . '/../../report.md';
    if (! file_exists($reportFile)) {
        $report = "## Performance Metrics\n\n";
        $report .= "| test | time (s) | memory usage |\n";
        $report .= "| ---- | -------- | ------------ |\n";
    } else {
        $report = file_get_contents($reportFile);
    }

    $usage = memory_get_peak_usage(true) / 1024 / 1024;

    $report .= sprintf("| %s | %s | %s |\n", $test, number_format($seconds, 2), number_format($usage));

    file_put_contents($reportFile, $report);
}
