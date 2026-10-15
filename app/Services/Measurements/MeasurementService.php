<?php

namespace App\Services\Measurements;

use App\Models\Customer;
use App\Models\FollowUpTask;
use App\Models\MeasurementSession;
use App\Models\MeasurementTemplateParameter;
use App\Models\MeasurementTemplateVersion;
use App\Models\MeasurementValue;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Measurement sessions: capture → submit → approve / reject → (correct).
 * SRS FR-MEA-007 … 012, §6.1 and §6.5.
 */
class MeasurementService
{
    public function start(Customer $customer, MeasurementTemplateVersion $version, array $attrs = []): MeasurementSession
    {
        if ($version->status === 'draft') {
            throw ValidationException::withMessages(['template' => 'Only published templates can be used for measuring.']);
        }

        return MeasurementSession::create([
            'customer_id'         => $customer->id,
            'template_version_id' => $version->id,
            'branch_id'           => $attrs['branch_id'] ?? CurrentTenant::membership()?->branch_id,
            'status'              => 'draft',
            'capture_method'      => $attrs['capture_method'] ?? 'staff_in_person',
            'display_unit'        => $attrs['display_unit'] ?? CurrentTenant::get()?->setting('measurement_display_unit', 'in') ?? 'in',
            'measured_at'         => $attrs['measured_at'] ?? now(),
            'measured_by'         => array_key_exists('measured_by', $attrs) ? $attrs['measured_by'] : auth()->id(),
            'notes'               => $attrs['notes'] ?? null,
        ]);
    }

    /**
     * Save values typed in $unit.
     * $input: [template_parameter_id => ['both' => '38.5'] | ['left' => '24', 'right' => '24.25']]
     * Empty strings remove a value. Out-of-range lengths are kept but flagged (FR-MEA-012).
     */
    public function saveValues(MeasurementSession $session, array $input, string $unit, array $notes = []): void
    {
        if (! $session->isEditable()) {
            throw new \LogicException('Only draft or rejected sessions can be edited.');
        }
        if (! Units::isValid($unit)) {
            throw ValidationException::withMessages(['display_unit' => 'Unknown unit.']);
        }

        $params = $session->templateVersion->parameters->keyBy('id');
        $errors = [];

        DB::transaction(function () use ($session, $input, $unit, $notes, $params, &$errors) {
            foreach ($params as $tp) {
                foreach ($tp->sides() as $side) {
                    $raw = $input[$tp->id][$side] ?? ($input[$tp->code][$side] ?? null);
                    $raw = is_string($raw) ? trim($raw) : $raw;
                    $where = ['session_id' => $session->id, 'template_parameter_id' => $tp->id, 'side' => $side];

                    if ($raw === null || $raw === '') {
                        if (array_key_exists($tp->id, $input) || array_key_exists($tp->code, $input)) {
                            MeasurementValue::where($where)->delete();
                        }
                        continue;
                    }

                    $row = ['parameter_code' => $tp->code, 'validation_state' => 'ok',
                        'note' => $notes[$tp->id] ?? null,
                        'value_mm' => null, 'number_value' => null, 'original_value' => null, 'original_unit' => null, 'text_value' => null];

                    switch ($tp->data_type) {
                        case 'length':
                            $num = $this->parseNumber($raw);
                            if ($num === null || $num <= 0) {
                                $errors["values.{$tp->id}.{$side}"] = "{$tp->label}: enter a positive number.";
                                continue 2;
                            }
                            $mm = Units::toMm($num, $unit);
                            $row['value_mm'] = $mm;
                            $row['original_value'] = $num;
                            $row['original_unit'] = $unit;
                            if (($tp->min_mm !== null && $mm < $tp->min_mm) || ($tp->max_mm !== null && $mm > $tp->max_mm)) {
                                $row['validation_state'] = 'out_of_range';
                            }
                            break;
                        case 'number':
                            $num = $this->parseNumber($raw);
                            if ($num === null) {
                                $errors["values.{$tp->id}.{$side}"] = "{$tp->label}: enter a number.";
                                continue 2;
                            }
                            $row['number_value'] = $row['original_value'] = $num;
                            break;
                        case 'select':
                            if ($tp->options && ! in_array($raw, $tp->options, true)) {
                                $errors["values.{$tp->id}.{$side}"] = "{$tp->label}: choose one of the options.";
                                continue 2;
                            }
                            $row['text_value'] = mb_substr($raw, 0, 255);
                            break;
                        case 'boolean':
                            $row['text_value'] = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'yes' : 'no';
                            break;
                        default:
                            $row['text_value'] = mb_substr($raw, 0, 255);
                    }

                    MeasurementValue::updateOrCreate($where, $row);
                }
            }

            $session->update(['display_unit' => $unit]);
        });

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Draft/rejected → submitted for review. Required values must be present (§6.1 step 4–5). */
    public function submit(MeasurementSession $session): MeasurementSession
    {
        if (! $session->isEditable()) {
            throw new \LogicException('This session has already been submitted.');
        }

        $missing = $this->missingRequired($session);
        if ($missing) {
            throw ValidationException::withMessages(['values' => 'Still missing: ' . implode(', ', $missing) . '.']);
        }

        $comparison = $this->compare($session);
        $flagged = $comparison->where('flagged', true)->count();
        $outOfRange = $session->values()->where('validation_state', 'out_of_range')->count();

        $session->update([
            'status'           => 'submitted',
            'submitted_at'     => now(),
            'rejection_reason' => null,
            'change_summary'   => [
                'compared_with' => $comparison->first()['previous_session'] ?? null,
                'flagged'       => $comparison->where('flagged', true)->pluck('label')->values()->all(),
                'out_of_range'  => $outOfRange,
            ],
        ]);

        // Mark flagged values on the session itself (fresh each submission).
        MeasurementValue::where('session_id', $session->id)->where('validation_state', 'flagged')->update(['validation_state' => 'ok']);
        foreach ($comparison->where('flagged', true) as $row) {
            MeasurementValue::where('session_id', $session->id)->where('parameter_code', $row['code'])->where('side', $row['side'])
                ->where('validation_state', 'ok')->update(['validation_state' => 'flagged']);
        }

        // One review task per session when something looks off (scenario 34: deduplicated).
        if ($flagged || $outOfRange) {
            FollowUpTask::firstOrCreate(
                ['source_key' => 'measurement-review:' . $session->public_id],
                [
                    'customer_id' => $session->customer_id,
                    'title'       => 'Review measurement changes' . ($flagged ? " ({$flagged} changed)" : '') . ($outOfRange ? " ({$outOfRange} out of range)" : ''),
                    'details'     => 'A submitted measurement session differs from the last approved one or has unusual values. A change is a prompt to check, not proof the customer\'s body changed.',
                    'due_at'      => now()->addDay(),
                    'assigned_to' => $session->customer?->assigned_to ?? $session->measured_by,
                    'status'      => 'open',
                ]
            );
        }

        AuditLogger::log('measurement.session.submitted', $session->customer, description: $session->templateVersion->label . ' submitted for review');

        return $session->fresh();
    }

    /** submitted → approved; becomes read-only (FR-MEA-007/008). */
    public function approve(MeasurementSession $session, User $verifier): MeasurementSession
    {
        if ($session->status !== 'submitted') {
            throw new \LogicException('Only submitted sessions can be approved.');
        }

        return DB::transaction(function () use ($session, $verifier) {
            $session->update(['status' => 'approved', 'verified_by' => $verifier->id, 'verified_at' => now()]);

            if ($session->supersedes_id) {
                MeasurementSession::whereKey($session->supersedes_id)->where('status', 'approved')->first()?->update(['status' => 'superseded']);
            }

            FollowUpTask::where('source_key', 'measurement-review:' . $session->public_id)->open()
                ->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => $verifier->id]);

            $session->customer?->touchActivity();
            AuditLogger::log('measurement.session.approved', $session->customer,
                description: $session->templateVersion->label . ' approved' . ($session->supersedes_id ? ' (correction)' : ''));

            return $session->fresh();
        });
    }

    public function reject(MeasurementSession $session, User $verifier, string $reason): MeasurementSession
    {
        if ($session->status !== 'submitted') {
            throw new \LogicException('Only submitted sessions can be rejected.');
        }

        $session->update(['status' => 'rejected', 'verified_by' => $verifier->id, 'verified_at' => now(), 'rejection_reason' => $reason]);
        AuditLogger::log('measurement.session.rejected', $session->customer, description: 'Rejected: ' . $reason);

        return $session->fresh();
    }

    /** FR-MEA-008: correcting an approved session creates a superseding draft with the same values. */
    public function correct(MeasurementSession $approved, string $reason): MeasurementSession
    {
        if ($approved->status !== 'approved') {
            throw new \LogicException('Only approved sessions can be corrected.');
        }

        return DB::transaction(function () use ($approved, $reason) {
            $new = MeasurementSession::create([
                'customer_id'         => $approved->customer_id,
                'template_version_id' => $approved->template_version_id,
                'branch_id'           => $approved->branch_id,
                'status'              => 'draft',
                'capture_method'      => 'staff_in_person',
                'display_unit'        => $approved->display_unit,
                'measured_at'         => now(),
                'measured_by'         => auth()->id(),
                'supersedes_id'       => $approved->id,
                'correction_reason'   => $reason,
                'notes'               => $approved->notes,
            ]);

            foreach ($approved->values as $v) {
                $new->values()->create($v->only([
                    'template_parameter_id', 'parameter_code', 'side', 'value_mm', 'number_value',
                    'original_value', 'original_unit', 'text_value', 'note',
                ]) + ['validation_state' => 'ok']);
            }

            return $new;
        });
    }

    /** The latest approved session of the customer before $session (any template). */
    public function previousApproved(MeasurementSession $session): ?MeasurementSession
    {
        return MeasurementSession::where('customer_id', $session->customer_id)
            ->where('id', '!=', $session->id)
            ->whereIn('status', ['approved'])
            ->orderByDesc('verified_at')->orderByDesc('id')
            ->with('values.templateParameter')
            ->first();
    }

    /**
     * Compare a session with the previous approved one (§6.5).
     * Rows match on parameter code + side. Length values compare in canonical mm;
     * a different data type is "not comparable" rather than silently compared.
     */
    public function compare(MeasurementSession $session, ?MeasurementSession $previous = null): Collection
    {
        $previous ??= $this->previousApproved($session);
        $session->loadMissing('values.templateParameter', 'templateVersion.parameters');
        $defaultPct = (float) (CurrentTenant::get()?->setting('measurement_change_threshold_percent', 5) ?? 5);

        $prevValues = $previous ? $previous->values->keyBy(fn ($v) => $v->key()) : collect();
        $rows = collect();

        foreach ($session->templateVersion->parameters as $tp) {
            foreach ($tp->sides() as $side) {
                $cur = $session->values->first(fn ($v) => $v->template_parameter_id === $tp->id && $v->side === $side);
                $old = $prevValues->get($tp->code . ':' . $side);
                $oldTp = $old?->templateParameter;

                $row = [
                    'code' => $tp->code, 'label' => $tp->label . ($side !== 'both' ? ' (' . $side . ')' : ''), 'side' => $side,
                    'data_type' => $tp->data_type, 'current' => $cur, 'previous' => $old,
                    'comparable' => true, 'delta_mm' => null, 'delta_pct' => null, 'flagged' => false,
                    'previous_session' => $previous?->public_id,
                ];

                if (! $cur || ! $old) {
                    $row['comparable'] = false;
                } elseif ($oldTp && $oldTp->data_type !== $tp->data_type) {
                    $row['comparable'] = false;     // definition changed: don't compare unlike values
                } elseif ($tp->isLength() && $cur->value_mm !== null && $old->value_mm !== null) {
                    $delta = round($cur->value_mm - $old->value_mm, 2);
                    $row['delta_mm'] = $delta;
                    $row['delta_pct'] = $old->value_mm > 0 ? round($delta / $old->value_mm * 100, 1) : null;
                    $row['flagged'] = $tp->change_threshold_mm !== null
                        ? abs($delta) > $tp->change_threshold_mm
                        : ($row['delta_pct'] !== null && abs($row['delta_pct']) > $defaultPct);
                } else {
                    $row['flagged'] = (string) ($cur->text_value ?? $cur->number_value) !== (string) ($old->text_value ?? $old->number_value);
                }

                $rows->push($row);
            }
        }

        return $rows;
    }

    /** Labels of required values still empty. */
    public function missingRequired(MeasurementSession $session): array
    {
        $have = $session->values()->get()->map(fn ($v) => $v->template_parameter_id . ':' . $v->side)->flip();
        $missing = [];
        foreach ($session->templateVersion->parameters as $tp) {
            if (! $tp->is_required) {
                continue;
            }
            foreach ($tp->sides() as $side) {
                if (! $have->has($tp->id . ':' . $side)) {
                    $missing[] = $tp->label . ($side !== 'both' ? " ($side)" : '');
                }
            }
        }

        return $missing;
    }

    /** "38", "38.5", "38,5", "38 1/2", "38½" → float. */
    public function parseNumber(string|int|float $raw): ?float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        $s = str_replace([',', '½', '¼', '¾'], ['.', ' 1/2', ' 1/4', ' 3/4'], trim((string) $raw));
        if (preg_match('/^(\d+(?:\.\d+)?)?\s*(?:(\d+)\/(\d+))?$/', $s, $m) && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
            $whole = isset($m[1]) && $m[1] !== '' ? (float) $m[1] : 0.0;
            $frac = ! empty($m[2]) && ! empty($m[3]) ? (int) $m[2] / (int) $m[3] : 0.0;

            return $whole + $frac;
        }

        return null;
    }
}
