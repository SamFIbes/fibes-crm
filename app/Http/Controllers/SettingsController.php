<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\EscalationTier;
use App\Models\Holiday;
use App\Models\Team;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\WrapUpCode;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use App\Services\Tickets\EscalationLadder;
use App\Support\Access\AccessGate;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SettingsController extends Controller
{
    public function edit(Request $request, SettingsStore $settings, AccessGate $access, EscalationLadder $ladder): Response
    {
        $settings->ensureDefaults();
        $ladder->seed();
        $actor = $access->effective($request);

        return Inertia::render('Settings/Edit', [
            'company' => $settings->get('company'),
            'sla' => $settings->get('sla', config('fibes.sla')),
            'escalation' => $ladder->rule(),
            'tiers' => EscalationTier::query()->with(['team', 'members:id,name'])->orderBy('level')->get()->map(fn (EscalationTier $tier) => [
                'id' => $tier->id,
                'level' => $tier->level,
                'name_en' => $tier->name_en,
                'name_ar' => $tier->name_ar,
                'team_id' => $tier->team_id,
                'members' => $tier->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'on_duty' => (bool) $user->pivot->on_duty,
                ])->values(),
            ]),
            'teams' => Team::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']),
            'holidays' => Holiday::query()->orderBy('observed_on')->get()->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'observed_on' => $holiday->observed_on?->toDateString(),
                'name_en' => $holiday->name_en,
                'name_ar' => $holiday->name_ar,
                'kind' => $holiday->kind ?? 'fixed',
                'confirmed' => (bool) ($holiday->confirmed ?? true),
            ]),
            'categories' => TicketCategory::query()->orderBy('sort')->get(['id', 'key', 'name_en', 'name_ar', 'is_active']),
            'wrap_up_codes' => WrapUpCode::query()->orderBy('sort')->get(['id', 'key', 'name_en', 'name_ar', 'is_active']),
            'can_manage' => $actor instanceof User && $access->allows($actor, 'settings.manage'),
            'can_integrations' => $actor instanceof User && $access->allows($actor, 'integrations.manage'),
            'commercial' => app(CommercialSettings::class)->all(),
        ]);
    }

    public function updateCommercial(Request $request, CommercialSettings $commercial, AccessGate $access): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $request->validate([
            'commercial' => ['required', 'array'],
            'commercial.vat_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'commercial.fallback_rate' => ['required', 'numeric', 'min:1'],
            'commercial.proration' => ['required', 'in:daily,full'],
            'commercial.expiry_time' => ['required', 'date_format:H:i'],
        ]);
        $commercial->save($request->input('commercial'), $actor, $request);

        return back()->with('status', __('ui.settings_page.saved'));
    }

    public function update(Request $request, SettingsStore $settings, AccessGate $access, PhoneNumbers $phones): RedirectResponse
    {
        $settings->ensureDefaults();
        $data = $request->validate([
            'company.name' => ['required', 'string', 'max:120'],
            'company.name_ar' => ['required', 'string', 'max:120'],
            'company.email' => ['nullable', 'email', 'max:160'],
            'company.phone' => ['nullable', 'string', 'max:40'],
            'company.address_en' => ['nullable', 'string', 'max:240'],
            'company.address_ar' => ['nullable', 'string', 'max:240'],
            'sla.clock' => ['required', 'in:calendar'],
            'sla.support_hours' => ['required', 'string', 'max:40'],
            'sla.lead_first_response_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sla.lead_qualify_minutes' => ['nullable', 'integer', 'min:1', 'max:20160'],
            'sla.lead_handoff_minutes' => ['nullable', 'integer', 'min:1', 'max:20160'],
            'sla.priorities.P1.respond_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sla.priorities.P1.resolve_minutes' => ['required', 'integer', 'min:1', 'max:20160'],
            'sla.priorities.P2.respond_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sla.priorities.P2.resolve_minutes' => ['required', 'integer', 'min:1', 'max:20160'],
            'sla.priorities.P3.respond_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sla.priorities.P3.resolve_minutes' => ['required', 'integer', 'min:1', 'max:20160'],
            'sla.priorities.P4.respond_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'sla.priorities.P4.resolve_minutes' => ['required', 'integer', 'min:1', 'max:20160'],
        ]);

        $phone = trim((string) ($data['company']['phone'] ?? ''));
        $e164 = '';

        if ($phone !== '') {
            try {
                $e164 = $phones->normalize($phone);
            } catch (InvalidArgumentException) {
                return back()->withErrors(['company.phone' => __('ui.settings_page.phone_invalid')]);
            }
        }

        $actor = $access->effective($request);
        $company = [
            'name' => $data['company']['name'],
            'name_ar' => $data['company']['name_ar'],
            'email' => $data['company']['email'] ?? '',
            'phone_e164' => $e164,
            'phone_display' => $e164 === '' ? '' : $phones->display($e164),
            'address_en' => $data['company']['address_en'] ?? '',
            'address_ar' => $data['company']['address_ar'] ?? '',
        ];
        $sla = array_replace(config('fibes.sla'), $data['sla']);
        $sla['breach_notifies'] = 'supervisor';

        $settings->put('company', $company, $actor instanceof User ? $actor : null, $request);
        $settings->put('sla', $sla, $actor instanceof User ? $actor : null, $request);

        if ($actor instanceof User) {
            $actor->notify(new WorkspaceNotification(
                __('ui.notifications.settings_saved_title'),
                __('ui.notifications.settings_saved_body'),
            ));
        }

        return back()->with('status', __('ui.settings_page.saved'));
    }

    public function storeHoliday(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'observed_on' => ['required', 'date', 'unique:holidays,observed_on'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);

        $holiday = Holiday::query()->create($data + [
            'kind' => $request->string('kind')->toString() === 'islamic' ? 'islamic' : 'fixed',
            'confirmed' => $request->string('kind')->toString() !== 'islamic',
        ]);
        $actor = $access->effective($request);
        $audit->record('holiday.created', $actor instanceof User ? $actor : null, [
            'id' => $holiday->id,
            'observed_on' => $holiday->observed_on->toDateString(),
            'name_en' => $holiday->name_en,
        ], $request);

        return back()->with('status', __('ui.settings_page.holiday_saved'));
    }

    public function updateHoliday(Request $request, Holiday $holiday, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'observed_on' => ['required', 'date', Rule::unique('holidays', 'observed_on')->ignore($holiday->id)],
            'confirmed' => ['nullable', 'boolean'],
        ]);
        $holiday->observed_on = $data['observed_on'];
        $holiday->confirmed = (bool) ($data['confirmed'] ?? true);
        $holiday->save();
        $actor = $access->effective($request);
        $audit->record('holiday.updated', $actor instanceof User ? $actor : null, [
            'id' => $holiday->id,
            'observed_on' => $holiday->observed_on->toDateString(),
            'confirmed' => $holiday->confirmed,
        ], $request);

        return back()->with('status', __('ui.settings_page.holiday_saved'));
    }

    public function destroyHoliday(Request $request, Holiday $holiday, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $access->effective($request);
        $audit->record('holiday.deleted', $actor instanceof User ? $actor : null, [
            'id' => $holiday->id,
            'observed_on' => $holiday->observed_on->toDateString(),
            'name_en' => $holiday->name_en,
        ], $request);
        $holiday->delete();

        return back()->with('status', __('ui.settings_page.holiday_removed'));
    }

    public function storeCategory(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $key = str($data['name_en'])->slug('_')->limit(40, '')->toString();
        $category = TicketCategory::query()->create([
            'key' => $key === '' ? 'category_'.now()->timestamp : $key,
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'sort' => (int) TicketCategory::query()->max('sort') + 1,
        ]);
        $actor = $access->effective($request);
        $audit->record('ticket_category.created', $actor instanceof User ? $actor : null, ['id' => $category->id, 'key' => $category->key], $request);

        return back()->with('status', __('ui.settings_page.category_saved'));
    }

    public function updateEscalation(Request $request, SettingsStore $settings, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'on_warning' => ['required', 'in:none,next_tier'],
            'on_breach' => ['required', 'in:none,next_tier'],
        ]);
        $actor = $access->effective($request);
        $settings->put('escalation', $data, $actor instanceof User ? $actor : null, $request);

        return back()->with('status', __('ui.settings_page.escalation_saved'));
    }

    public function updateTier(Request $request, EscalationTier $tier, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'members' => ['array'],
            'members.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'members.*.on_duty' => ['required', 'boolean'],
        ]);
        $tier->fill([
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'team_id' => $data['team_id'] ?? null,
        ])->save();
        $sync = [];

        foreach ($data['members'] ?? [] as $member) {
            $sync[(int) $member['user_id']] = ['on_duty' => (bool) $member['on_duty']];
        }

        $tier->members()->sync($sync);
        $actor = $access->effective($request);
        $audit->record('escalation_tier.updated', $actor instanceof User ? $actor : null, [
            'tier_id' => $tier->id,
            'level' => $tier->level,
            'members' => count($sync),
        ], $request);

        return back()->with('status', __('ui.settings_page.tier_saved'));
    }

    public function storeWrapUp(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
        ]);
        $key = str($data['name_en'])->slug('_')->limit(40, '')->toString();
        $code = WrapUpCode::query()->create([
            'key' => $key === '' ? 'wrap_'.now()->timestamp : $key,
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'sort' => (int) WrapUpCode::query()->max('sort') + 1,
        ]);
        $actor = $access->effective($request);
        $audit->record('wrap_up_code.created', $actor instanceof User ? $actor : null, ['id' => $code->id, 'key' => $code->key], $request);

        return back()->with('status', __('ui.settings_page.wrap_saved'));
    }

    public function storeChecklist(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'ticket_category_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'job_type' => ['nullable', 'in:installation,repair,relocation,survey,maintenance'],
            'label_en' => ['required', 'string', 'max:160'],
            'label_ar' => ['required', 'string', 'max:160'],
        ]);
        $item = ChecklistTemplate::query()->create([
            'kind' => filled($data['job_type'] ?? null) ? 'job' : 'ticket',
            'job_type' => $data['job_type'] ?? null,
            'ticket_category_id' => $data['ticket_category_id'] ?? null,
            'label_en' => $data['label_en'],
            'label_ar' => $data['label_ar'],
            'required' => false,
            'sort' => (int) ChecklistTemplate::query()->max('sort') + 1,
        ]);
        $actor = $access->effective($request);
        $audit->record('checklist.created', $actor instanceof User ? $actor : null, ['id' => $item->id], $request);

        return back()->with('status', __('ui.settings_page.checklist_saved'));
    }
}
