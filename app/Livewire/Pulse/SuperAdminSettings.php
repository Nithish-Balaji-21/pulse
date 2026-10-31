<?php

namespace App\Livewire\Pulse;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class SuperAdminSettings extends Component
{
    public string $newEmail = '';

    public array $technicalAlertEmails = [];

    public array $coiiotSettings = [];

    public bool $isTableAvailable = true;

    public ?string $tableError = null;

    public bool $hasSettingTypeColumn = false;

    public bool $hasRemarksColumn = false;

    public bool $hasCreatedAtColumn = false;

    public bool $hasUpdatedAtColumn = false;

    public ?int $editId = null;

    public string $editSettingKey = '';

    public string $editSettingType = '';

    public string $editSettingValue = '';

    public string $editRemarks = '';

    public int $editIsActive = 1;

    public bool $isEditModalOpen = false;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $this->hasSettingTypeColumn = Schema::hasColumn('pulse_superadmin_settings', 'setting_type');
            $this->hasRemarksColumn = Schema::hasColumn('pulse_superadmin_settings', 'remarks');
            $this->hasCreatedAtColumn = Schema::hasColumn('pulse_superadmin_settings', 'created_at');
            $this->hasUpdatedAtColumn = Schema::hasColumn('pulse_superadmin_settings', 'updated_at');

            $this->coiiotSettings = DB::table('pulse_superadmin_settings')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->all();

            $technicalSetting = DB::table('pulse_superadmin_settings')
                ->where('setting_key', 'TECHNICAL_ALERT_EMAILS')
                ->where('is_active', 1)
                ->orderByDesc('id')
                ->first();

            $this->technicalAlertEmails = $this->parseEmails($technicalSetting->setting_value ?? null);
            $this->isTableAvailable = true;
            $this->tableError = null;
        } catch (QueryException $e) {
            $this->coiiotSettings = [];
            $this->technicalAlertEmails = [];
            $this->isTableAvailable = false;
            $this->tableError = $e->getMessage();
        }
    }

    public function editSetting(int $id): void
    {
        if (! $this->isTableAvailable) {
            session()->flash('error', 'pulse_superadmin_settings table is not available.');

            return;
        }

        $row = DB::table('pulse_superadmin_settings')->where('id', $id)->first();

        if (! $row) {
            session()->flash('error', 'Setting row not found.');

            return;
        }

        $this->editId = (int) $row->id;
        $this->editSettingKey = (string) ($row->setting_key ?? '');
        $this->editSettingType = (string) ($row->setting_type ?? '');
        $this->editSettingValue = (string) ($row->setting_value ?? '');
        $this->editRemarks = (string) ($row->remarks ?? '');
        $this->editIsActive = (int) ($row->is_active ?? 0);
        $this->isEditModalOpen = true;
    }

    public function closeEditModal(): void
    {
        $this->reset([
            'editId',
            'editSettingKey',
            'editSettingType',
            'editSettingValue',
            'editRemarks',
            'editIsActive',
            'isEditModalOpen',
        ]);
        $this->editIsActive = 1;
    }

    public function updateSetting(): void
    {
        $this->validate([
            'editId' => ['required', 'integer'],
            'editSettingKey' => ['required', 'string', 'max:100'],
            'editSettingValue' => ['nullable', 'string'],
            'editIsActive' => ['required', 'in:0,1'],
            'editSettingType' => ['nullable', 'string', 'max:50'],
            'editRemarks' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = [
            'setting_key' => trim($this->editSettingKey),
            'setting_value' => $this->editSettingValue,
            'is_active' => (int) $this->editIsActive,
        ];

        if ($this->hasSettingTypeColumn) {
            $payload['setting_type'] = trim($this->editSettingType) !== '' ? trim($this->editSettingType) : null;
        }
        if ($this->hasRemarksColumn) {
            $payload['remarks'] = trim($this->editRemarks) !== '' ? trim($this->editRemarks) : null;
        }
        if ($this->hasUpdatedAtColumn) {
            $payload['updated_at'] = now();
        }

        DB::table('pulse_superadmin_settings')
            ->where('id', $this->editId)
            ->update($payload);

        $this->closeEditModal();
        $this->loadData();
        session()->flash('success', 'Pulse setting updated successfully.');
    }

    public function addEmail(): void
    {
        $this->validate([
            'newEmail' => ['required', 'email'],
        ]);

        if (! $this->isTableAvailable) {
            session()->flash('error', 'pulse_superadmin_settings table is not available.');

            return;
        }

        $normalizedEmails = collect($this->technicalAlertEmails)->map(fn ($email) => strtolower(trim($email)));
        $newEmail = strtolower(trim($this->newEmail));

        if ($normalizedEmails->contains($newEmail)) {
            session()->flash('error', 'This email is already in TECHNICAL_ALERT_EMAILS.');

            return;
        }

        $updatedEmails = $this->technicalAlertEmails;
        $updatedEmails[] = $this->newEmail;

        $this->saveTechnicalAlertEmails($updatedEmails);
        $this->newEmail = '';
        $this->loadData();
        session()->flash('success', 'Email added to Pulse settings.');
    }

    public function removeEmail(int $index): void
    {
        if (! isset($this->technicalAlertEmails[$index])) {
            return;
        }

        $updatedEmails = $this->technicalAlertEmails;
        $updatedEmails[] = $this->newEmail;
        unset($updatedEmails[$index]);

        $this->saveTechnicalAlertEmails(array_values($updatedEmails));
        $this->loadData();
        session()->flash('success', 'Email removed from Pulse settings.');
    }

    private function saveTechnicalAlertEmails(array $emails): void
    {
        $cleanEmails = collect($emails)
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();

        $value = json_encode($cleanEmails);

        DB::transaction(function () use ($value) {
            DB::table('pulse_superadmin_settings')
                ->where('setting_key', 'TECHNICAL_ALERT_EMAILS')
                ->update(['is_active' => 0]);

            $latest = DB::table('pulse_superadmin_settings')
                ->where('setting_key', 'TECHNICAL_ALERT_EMAILS')
                ->orderByDesc('id')
                ->first();

            if ($latest) {
                DB::table('pulse_superadmin_settings')
                    ->where('id', $latest->id)
                    ->update([
                        'setting_value' => $value,
                        'is_active' => 1,
                    ]);
            } else {
                DB::table('pulse_superadmin_settings')->insert([
                    'setting_key' => 'TECHNICAL_ALERT_EMAILS',
                    'setting_value' => $value,
                    'is_active' => 1,
                ]);
            }
        });
    }

    private function parseEmails(?string $setting): array
    {
        if (! $setting) {
            return [];
        }

        $decoded = json_decode($setting, true);
        $rawRecipients = is_array($decoded) ? $decoded : explode(',', $setting);

        return collect($rawRecipients)
            ->map(fn ($email) => trim((string) $email))
            ->map(fn ($email) => trim($email, " \t\n\r\0\x0B\"'[]"))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    public function render()
    {
        // Dynamically group your setting records by setting_type for the Zoho grid view template
        $groupedSettings = collect();

        if ($this->isTableAvailable && ! empty($this->coiiotSettings)) {
            if ($this->hasSettingTypeColumn) {
                $groupedSettings = collect($this->coiiotSettings)->groupBy('setting_type');
            } else {
                $groupedSettings = collect(['General Settings' => $this->coiiotSettings]);
            }
        }

        return view('pulse::livewire.superadmin-settings', [
            'groupedSettings' => $groupedSettings,
        ]);
    }
}
