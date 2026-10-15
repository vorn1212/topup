<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CsvExporter;
use App\Services\Admin\ReportService;
use App\Services\AuditService;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales, profit, deposits and top lists. Read-only; needs reports.view.
 */
class ReportController extends Controller
{
    /** The CSV files that can be downloaded. */
    public const EXPORTS = ['daily', 'games', 'products', 'customers', 'orders'];

    public function index(Request $request, ReportService $reports): View
    {
        $range = $reports->resolveRange($request->query('preset'), $request->query('from'), $request->query('to'));
        $from = $range['from'];
        $to = $range['to'];

        return view('admin.reports.index', [
            'range' => $range,
            'presets' => ReportService::PRESETS,
            'summary' => $reports->summary($from, $to),
            'daily' => $reports->daily($from, $to)->reverse()->values(),
            'games' => $reports->topGames($from, $to),
            'products' => $reports->topProducts($from, $to),
            'customers' => $reports->topCustomers($from, $to),
            'reports' => $reports,
            'exports' => self::EXPORTS,
        ]);
    }

    public function export(Request $request, string $type, ReportService $reports, CsvExporter $csv, AuditService $audit): StreamedResponse
    {
        abort_unless(in_array($type, self::EXPORTS, true), 404);

        $range = $reports->resolveRange($request->query('preset'), $request->query('from'), $request->query('to'));
        $from = $range['from'];
        $to = $range['to'];
        $fromText = $from->format('Y-m-d');
        $toText = $to->format('Y-m-d');

        // Every download is recorded: who exported what, for which period.
        $audit->log('report.exported', null, [], ['type' => $type, 'from' => $fromText, 'to' => $toText]);

        $filename = "report-{$type}-{$fromText}_{$toText}.csv";

        return match ($type) {
            'daily' => $csv->download(
                $filename,
                ['Day', 'Orders completed', 'Revenue', 'Provider cost', 'Profit', 'Deposits paid', 'Deposited'],
                $reports->daily($from, $to)->map(fn (array $r) => [
                    $r['day'], $r['orders'], $r['revenue'], $r['cost'], $r['profit'], $r['deposits'], $r['deposited'],
                ]),
            ),
            'games' => $csv->download(
                $filename,
                ['Game id', 'Game', 'Orders completed', 'Revenue', 'Provider cost', 'Profit'],
                $reports->topGames($from, $to, 1000)->map(fn (array $r) => [
                    $r['id'], $r['name'], $r['orders'], $r['revenue'], $r['cost'], $r['profit'],
                ]),
            ),
            'products' => $csv->download(
                $filename,
                ['Product id', 'Game', 'Package', 'Orders completed', 'Revenue', 'Provider cost', 'Profit'],
                $reports->topProducts($from, $to, 1000)->map(fn (array $r) => [
                    $r['id'], $r['game'], $r['name'], $r['orders'], $r['revenue'], $r['cost'], $r['profit'],
                ]),
            ),
            'customers' => $csv->download(
                $filename,
                ['User id', 'Name', 'E-mail', 'Orders completed', 'Revenue', 'Provider cost', 'Profit'],
                $reports->topCustomers($from, $to, 1000)->map(fn (array $r) => [
                    $r['id'], $r['name'], $r['email'], $r['orders'], $r['revenue'], $r['cost'], $r['profit'],
                ]),
            ),
            'orders' => $csv->download(
                $filename,
                ['Order number', 'Completed at', 'Customer e-mail', 'Game', 'Package', 'Price', 'Provider cost', 'Profit', 'Currency'],
                $this->orderRows($reports, $from, $to),
            ),
        };
    }

    /**
     * @return Generator<int,array<int,mixed>>
     */
    private function orderRows(ReportService $reports, Carbon $from, Carbon $to): Generator
    {
        foreach ($reports->exportOrders($from, $to)->lazyById(500) as $order) {
            yield [
                $order->order_number,
                $order->completed_at?->format('Y-m-d H:i:s'),
                $order->user?->email,
                $order->game_name,
                $order->product_name,
                (string) $order->selling_price,
                (string) $order->provider_cost,
                (string) $order->profit,
                $order->currency,
            ];
        }
    }
}