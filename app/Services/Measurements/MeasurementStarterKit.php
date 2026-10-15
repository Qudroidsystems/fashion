<?php

namespace App\Services\Measurements;

use App\Models\MeasurementParameter;
use App\Models\MeasurementTemplate;
use App\Models\Tenant;
use App\Support\Measurements\Units;
use App\Support\Tenancy\CurrentTenant;

/**
 * Ready-to-use measurements for a new fashion house: common parameters and
 * four published templates. Every item can be edited or disabled afterwards.
 * Safe to run again — existing codes are left alone.
 */
class MeasurementStarterKit
{
    /** code => [label, area, type, min in, max in, how to measure, bilateral] */
    public const PARAMETERS = [
        'neck'          => ['Neck',                 'upper', 'length', 11, 24, 'Around the base of the neck, one finger under the tape.'],
        'shoulder'      => ['Shoulder',             'upper', 'length', 12, 24, 'From the tip of one shoulder bone to the other, across the back.'],
        'chest'         => ['Chest / bust',         'upper', 'length', 26, 64, 'Around the fullest part of the chest or bust, tape level under the arms.'],
        'under_bust'    => ['Under-bust',           'upper', 'length', 22, 56, 'Around the rib cage just below the bust.'],
        'bust_point'    => ['Shoulder to bust point', 'upper', 'length', 7, 16, 'From the shoulder (near the neck) down to the bust point.'],
        'waist'         => ['Waist',                'full',  'length', 20, 60, 'Around the natural waist (narrowest part), tape snug but not tight.'],
        'hip'           => ['Hip',                  'lower', 'length', 26, 70, 'Around the fullest part of the hips and seat.'],
        'top_length'    => ['Top / shirt length',   'upper', 'length', 18, 40, 'From the highest point of the shoulder down to where the top should end.'],
        'gown_length'   => ['Gown / dress length',  'full',  'length', 30, 70, 'From the highest point of the shoulder to the desired hem.'],
        'sleeve_length' => ['Sleeve length',        'upper', 'length', 6, 30, 'From the shoulder tip down the arm to where the sleeve should end.', true],
        'round_sleeve'  => ['Round sleeve (bicep)', 'upper', 'length', 8, 24, 'Around the fullest part of the upper arm.', true],
        'wrist'         => ['Wrist / cuff',         'upper', 'length', 5, 12, 'Around the wrist bone (add ease for cuffs).', true],
        'back_width'    => ['Back width',           'upper', 'length', 11, 22, 'Across the back between the armpits.'],
        'trouser_length'=> ['Trouser length',       'lower', 'length', 30, 48, 'From the waist down the side of the leg to the ankle or desired length.'],
        'inseam'        => ['Inseam',               'lower', 'length', 20, 38, 'From the crotch down the inside leg to the ankle.'],
        'thigh'         => ['Thigh',                'lower', 'length', 14, 40, 'Around the fullest part of the thigh.', true],
        'knee'          => ['Knee',                 'lower', 'length', 11, 26, 'Around the knee with the leg slightly bent.'],
        'trouser_bottom'=> ['Trouser bottom',       'lower', 'length', 6, 14, 'Around the ankle opening as the customer likes it.'],
        'crotch'        => ['Crotch depth / rise',  'lower', 'length', 7, 16, 'Seated: from the waist down to the chair seat, at the side.'],
        'skirt_length'  => ['Skirt length',         'lower', 'length', 14, 48, 'From the waist to the desired hem.'],
        'cap_size'      => ['Cap / head',           'other', 'length', 19, 25, 'Around the head above the ears and eyebrows.'],
    ];

    public const EXTRA = [
        'posture' => ['Posture notes', 'other', 'select', null, null, 'Choose what best describes the posture.', false, ['Upright', 'Rounded shoulders', 'Sway back', 'Forward head', 'Uneven shoulders']],
        'fit_preference' => ['Fit preference', 'other', 'select', null, null, 'How the customer likes clothes to fit.', false, ['Fitted', 'Regular', 'Relaxed / free']],
        'remarks' => ['Remarks', 'other', 'text', null, null, 'Anything the tailor should know.', false],
    ];

    /** template code => [name, category, audience, [codes], optional codes] */
    public const TEMPLATES = [
        'womens_gown'   => ['Women’s gown / dress', 'Gown', 'female',
            ['shoulder', 'chest', 'under_bust', 'bust_point', 'waist', 'hip', 'gown_length', 'sleeve_length', 'round_sleeve'], ['neck', 'posture', 'fit_preference', 'remarks']],
        'top_shirt'     => ['Top / shirt / kaftan top', 'Top', 'any',
            ['neck', 'shoulder', 'chest', 'waist', 'top_length', 'sleeve_length', 'round_sleeve'], ['wrist', 'back_width', 'fit_preference', 'remarks']],
        'trouser'       => ['Trouser', 'Trouser', 'any',
            ['waist', 'hip', 'trouser_length', 'thigh', 'knee', 'trouser_bottom'], ['inseam', 'crotch', 'fit_preference', 'remarks']],
        'native_full'   => ['Native full set (agbada / senator)', 'Native', 'male',
            ['neck', 'shoulder', 'chest', 'waist', 'top_length', 'sleeve_length', 'round_sleeve', 'hip', 'trouser_length', 'thigh', 'knee', 'trouser_bottom'], ['cap_size', 'wrist', 'fit_preference', 'remarks']],
        'skirt'         => ['Skirt', 'Skirt', 'female',
            ['waist', 'hip', 'skirt_length'], ['knee', 'fit_preference', 'remarks']],
    ];

    public function __construct(protected TemplateService $templates)
    {
    }

    public function install(Tenant $tenant): void
    {
        CurrentTenant::run($tenant, function () {
            $order = 0;
            foreach (self::PARAMETERS + self::EXTRA as $code => $def) {
                [$label, $area, $type, $min, $max, $how] = $def;
                MeasurementParameter::firstOrCreate(['code' => $code], [
                    'label'        => $label,
                    'body_area'    => $area,
                    'data_type'    => $type,
                    'is_bilateral' => (bool) ($def[6] ?? false),
                    'options'      => $def[7] ?? null,
                    'min_mm'       => $min !== null ? Units::toMm($min, 'in') : null,
                    'max_mm'       => $max !== null ? Units::toMm($max, 'in') : null,
                    'instructions' => $how,
                    'sort_order'   => $order++,
                ]);
            }

            $params = MeasurementParameter::pluck('id', 'code');

            foreach (self::TEMPLATES as $code => [$name, $category, $audience, $required, $optional]) {
                if (MeasurementTemplate::where('code', $code)->exists()) {
                    continue;
                }
                $items = collect($required)->map(fn ($c) => ['parameter_id' => $params[$c], 'is_required' => true])
                    ->merge(collect($optional)->map(fn ($c) => ['parameter_id' => $params[$c], 'is_required' => false]))
                    ->all();

                $template = $this->templates->create([
                    'name' => $name, 'code' => $code, 'garment_category' => $category, 'audience' => $audience,
                ], $items);
                $this->templates->publish($template->draft()->first(), 'Starter template');
            }
        });
    }
}
