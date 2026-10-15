<?php

namespace Tests\Unit;

use App\Services\Admin\CsvExporter;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase
{
    public function test_plain_text_and_numbers_are_left_alone(): void
    {
        $this->assertSame('hello world', CsvExporter::cell('hello world'));
        $this->assertSame('player@example.com', CsvExporter::cell('player@example.com'));
        $this->assertSame('12', CsvExporter::cell(12));
        $this->assertSame('1.2500', CsvExporter::cell('1.2500'));
        $this->assertSame('-0.3000', CsvExporter::cell('-0.3000'));
        $this->assertSame('2026-10-13 10:00:00', CsvExporter::cell('2026-10-13 10:00:00'));
    }

    public function test_empty_values_become_empty_cells(): void
    {
        $this->assertSame('', CsvExporter::cell(null));
        $this->assertSame('', CsvExporter::cell(''));
        $this->assertSame('1', CsvExporter::cell(true));
        $this->assertSame('0', CsvExporter::cell(false));
    }

    public function test_cells_that_could_run_as_formulas_get_an_apostrophe(): void
    {
        $this->assertSame("'=SUM(A1:A9)", CsvExporter::cell('=SUM(A1:A9)'));
        $this->assertSame("'+cmd|' /C calc'!A0", CsvExporter::cell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3", CsvExporter::cell('-2+3'));
        $this->assertSame("'@SUM(1)", CsvExporter::cell('@SUM(1)'));
        $this->assertSame("'\t=1+1", CsvExporter::cell("\t=1+1"));
        $this->assertSame("'\r=1+1", CsvExporter::cell("\r=1+1"));
        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", CsvExporter::cell('=HYPERLINK("http://evil.test")'));
    }
}