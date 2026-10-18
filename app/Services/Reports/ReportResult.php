<?php

namespace App\Services\Reports;

/**
 * What a report produces, independent of how it is shown: summary cards, an
 * optional chart and one or more tables. The screen, CSV/XLSX/PDF exports and
 * the API all render this same object, so they can never disagree.
 *
 * Formats: money, int, qty, percent, days, date, text.
 */
class ReportResult
{
    public array $cards = [];
    public ?array $chart = null;
    public array $tables = [];
    public array $notes = [];

    public function card(string $label, $value, string $format = 'money', ?string $hint = null, string $accent = 'teal', string $icon = 'ri-bar-chart-line'): static
    {
        $this->cards[] = compact('label', 'value', 'format', 'hint', 'accent', 'icon');

        return $this;
    }

    /** $series = [['name' => 'Collected', 'data' => [..]], …] aligned with $labels. */
    public function chart(string $type, array $labels, array $series, string $format = 'money', ?string $title = null): static
    {
        $this->chart = compact('type', 'labels', 'series', 'format', 'title');

        return $this;
    }

    /**
     * $columns = [['key' => 'name', 'label' => 'Customer', 'format' => 'text'], …]
     * Rows are arrays keyed by column key; '_link' (url) links the first cell, '_muted' greys the row.
     * $totals: a row of the same shape, or true to sum every money/int/qty column.
     */
    public function table(string $key, string $title, array $columns, array $rows, array|bool|null $totals = null, ?string $note = null, ?string $empty = null): static
    {
        $columns = array_map(fn ($c) => $c + ['format' => 'text'], $columns);
        if ($totals === true) {
            $totals = [];
            foreach ($columns as $i => $c) {
                $totals[$c['key']] = in_array($c['format'], ['money', 'int', 'qty'], true)
                    ? round(array_sum(array_map(fn ($r) => (float) ($r[$c['key']] ?? 0), $rows)), 2)
                    : ($i === 0 ? 'Total' : null);
            }
        }
        $this->tables[$key] = compact('key', 'title', 'columns', 'rows', 'totals', 'note', 'empty');

        return $this;
    }

    public function note(string $text): static
    {
        $this->notes[] = $text;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'cards'  => $this->cards,
            'chart'  => $this->chart,
            'tables' => array_values(array_map(fn ($t) => [
                'key' => $t['key'], 'title' => $t['title'],
                'columns' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'format' => $c['format']], $t['columns']),
                'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(array_column($t['columns'], 'key'))), $t['rows']),
                'totals' => $t['totals'], 'note' => $t['note'],
            ], $this->tables)),
            'notes'  => $this->notes,
        ];
    }
}
