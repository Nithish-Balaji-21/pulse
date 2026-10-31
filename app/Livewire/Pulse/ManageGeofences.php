<?php

namespace App\Livewire\Pulse;

use App\Models\Pulse\GeofenceLocation;
use App\Support\Pulse\GeofenceBoundary;
use App\Support\Pulse\GeofencingModule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

class ManageGeofences extends Component
{
    public int $teamId;

    /** @var array<int, array<string, mixed>> */
    public array $locations = [];

    public int $maxBoundaries = 5;

    public int $usedBoundaries = 0;

    public bool $editorOpen = false;

    public ?int $confirmingDeleteId = null;

    public ?int $confirmingStatusId = null;

    public ?int $focusedId = null;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless(Gate::allows('geofence_locations.view'), 403);

        $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        abort_unless(GeofencingModule::isActive($team), 403);

        $this->teamId = (int) $team->id;
        $this->resetForm();
        $this->loadLocations();
    }

    public function loadLocations(): void
    {
        $records = GeofenceLocation::query()
            ->where('team_id', $this->teamId)
            ->orderBy('name')
            ->get();

        $this->locations = $records
            ->map(fn (GeofenceLocation $location) => $location->toApiArray())
            ->values()
            ->all();
        $this->usedBoundaries = $records->count();
        $this->maxBoundaries = GeofencingModule::maxBoundaries($this->currentTeam());
        $this->dispatch('geofences-synced', locations: $this->locations, selectedId: $this->form['id'] ?? $this->focusedId);
    }

    public function startCreate(string $boundaryType = GeofenceBoundary::TYPE_CIRCLE): void
    {
        $this->clearFlash();

        if (! Gate::allows('geofence_locations.create')) {
            $this->errorMessage = 'You are not authorized to create geofence locations.';

            return;
        }

        if ($this->usedBoundaries >= $this->maxBoundaries) {
            $this->errorMessage = 'This factory has reached its maximum of '.$this->maxBoundaries.' geofence boundaries.';

            return;
        }

        $this->resetForm();
        $this->form['boundary_type'] = $boundaryType === GeofenceBoundary::TYPE_POLYGON
            ? GeofenceBoundary::TYPE_POLYGON
            : GeofenceBoundary::TYPE_CIRCLE;
        $this->editorOpen = true;
        $this->dispatch('geofence-draw-start', boundaryType: $this->form['boundary_type']);
    }

    public function edit(int $id): void
    {
        $this->clearFlash();
        $record = $this->find($id);
        if (! $record) {
            return;
        }

        $this->form = [
            'id' => $record->id,
            'name' => $record->name,
            'type' => $record->type,
            'boundary_type' => $record->boundary_type ?: GeofenceBoundary::TYPE_CIRCLE,
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'radius' => $record->radius,
            'polygon_coordinates' => $record->polygon_coordinates ?? [],
            'status' => $record->status,
        ];
        $this->editorOpen = true;
        $this->focusedId = $record->id;
        $this->dispatch('geofence-edit', location: $record->toApiArray());
    }

    public function focus(int $id): void
    {
        $record = $this->find($id);
        if (! $record) {
            return;
        }

        $this->focusedId = $record->id;
        $this->dispatch('geofence-focus', location: $record->toApiArray());
    }

    public function closeEditor(): void
    {
        $this->editorOpen = false;
        $this->resetForm();
        $this->resetValidation();
        $this->dispatch('geofence-editor-closed');
        $this->loadLocations();
    }

    /**
     * Called from the Leaflet map after each draw/edit so Livewire holds the geometry.
     *
     * @param  array<string, mixed>  $geometry
     */
    public function applyGeometry(array $geometry): void
    {
        if (isset($geometry['boundary_type'])) {
            $this->form['boundary_type'] = $geometry['boundary_type'];
        }
        if (array_key_exists('latitude', $geometry)) {
            $this->form['latitude'] = $geometry['latitude'];
        }
        if (array_key_exists('longitude', $geometry)) {
            $this->form['longitude'] = $geometry['longitude'];
        }
        if (array_key_exists('radius', $geometry)) {
            $this->form['radius'] = $geometry['radius'] === null || $geometry['radius'] === ''
                ? null
                : (int) $geometry['radius'];
        }
        if (array_key_exists('polygon_coordinates', $geometry)) {
            $this->form['polygon_coordinates'] = is_array($geometry['polygon_coordinates'])
                ? $geometry['polygon_coordinates']
                : [];
        }
    }

    public function updatedFormBoundaryType($value): void
    {
        if ($value === GeofenceBoundary::TYPE_CIRCLE) {
            $this->form['polygon_coordinates'] = [];
        }
        $this->dispatch('geofence-draw-start', boundaryType: $value);
    }

    public function save(): void
    {
        $this->clearFlash();
        $isCreate = empty($this->form['id']);
        $ability = $isCreate ? 'geofence_locations.create' : 'geofence_locations.edit';

        if (! Gate::allows($ability)) {
            $this->errorMessage = 'You are not authorized to manage geofence locations.';

            return;
        }

        if ($isCreate && $this->usedBoundaries >= $this->maxBoundaries) {
            $this->addError('form.name', 'This factory has reached its maximum of '.$this->maxBoundaries.' geofence boundaries.');

            return;
        }

        $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.type' => ['required', 'string', Rule::in(GeofenceLocation::TYPES)],
            'form.boundary_type' => ['required', 'string', Rule::in([GeofenceBoundary::TYPE_CIRCLE, GeofenceBoundary::TYPE_POLYGON])],
            'form.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'form.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'form.radius' => ['nullable', 'integer', 'min:'.GeofenceBoundary::MIN_CIRCLE_RADIUS_METERS],
            'form.polygon_coordinates' => ['nullable', 'array'],
            'form.status' => ['required', 'string', Rule::in([GeofenceLocation::STATUS_ACTIVE, GeofenceLocation::STATUS_INACTIVE])],
        ]);

        try {
            $geometry = GeofenceBoundary::normalize($this->form);
        } catch (InvalidArgumentException $e) {
            $this->addError('form.boundary_type', $e->getMessage());

            return;
        }

        $payload = [
            'name' => $this->form['name'],
            'type' => $this->form['type'],
            'status' => $this->form['status'],
            ...$geometry,
        ];

        if ($isCreate) {
            GeofenceLocation::create([
                'team_id' => $this->teamId,
                ...$payload,
            ]);
            $this->successMessage = 'Geofence boundary created.';
        } else {
            $record = $this->find((int) $this->form['id']);
            if (! $record) {
                $this->errorMessage = 'Geofence location not found for this factory.';

                return;
            }
            $record->update($payload);
            $this->successMessage = 'Geofence boundary updated.';
        }

        $this->editorOpen = false;
        $this->resetForm();
        $this->resetValidation();
        $this->dispatch('geofence-editor-closed');
        $this->loadLocations();
    }

    public function confirmToggleStatus(int $id): void
    {
        $this->confirmingDeleteId = null;
        $this->confirmingStatusId = $id;
    }

    public function cancelToggleStatus(): void
    {
        $this->confirmingStatusId = null;
    }

    public function toggleStatusConfirmed(): void
    {
        $id = $this->confirmingStatusId;
        $this->confirmingStatusId = null;
        if ($id === null) {
            return;
        }

        $this->clearFlash();
        if (! Gate::allows('geofence_locations.edit')) {
            $this->errorMessage = 'You are not authorized to manage geofence locations.';

            return;
        }

        $record = $this->find($id);
        if (! $record) {
            return;
        }

        $activating = $record->status !== GeofenceLocation::STATUS_ACTIVE;
        $record->update([
            'status' => $activating ? GeofenceLocation::STATUS_ACTIVE : GeofenceLocation::STATUS_INACTIVE,
        ]);
        $this->successMessage = $activating
            ? 'Geofence boundary activated.'
            : 'Geofence boundary deactivated.';

        if (! $activating && (int) ($this->form['id'] ?? 0) === $id) {
            $this->closeEditor();

            return;
        }

        $this->loadLocations();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingStatusId = null;
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteConfirmed(): void
    {
        $id = $this->confirmingDeleteId;
        $this->confirmingDeleteId = null;
        if ($id === null) {
            return;
        }

        $this->clearFlash();
        if (! Gate::allows('geofence_locations.delete')) {
            $this->errorMessage = 'You are not authorized to delete geofence locations.';

            return;
        }

        $record = $this->find($id);
        if (! $record) {
            return;
        }

        $record->delete();
        $this->successMessage = 'Geofence boundary deleted.';

        if ((int) ($this->form['id'] ?? 0) === $id) {
            $this->editorOpen = false;
            $this->resetForm();
            $this->dispatch('geofence-editor-closed');
        }
        if ((int) ($this->focusedId ?? 0) === $id) {
            $this->focusedId = null;
        }

        $this->loadLocations();
    }

    public function render()
    {
        return view('pulse::livewire.manage-geofences', [
            'canCreate' => Gate::allows('geofence_locations.create'),
            'canEdit' => Gate::allows('geofence_locations.edit'),
            'canDelete' => Gate::allows('geofence_locations.delete'),
            'atCap' => $this->usedBoundaries >= $this->maxBoundaries,
            'mapCenter' => $this->mapCenter(),
            'typeLabels' => $this->typeLabels(),
        ]);
    }

    /**
     * @return array{lat: float, lng: float}
     */
    protected function mapCenter(): array
    {
        return $this->currentTeam()?->coordinatePair() ?? ['lat' => 10.77, 'lng' => 77.08];
    }

    /**
     * @return array<string, string>
     */
    protected function typeLabels(): array
    {
        return [
            GeofenceLocation::TYPE_FACTORY => 'Factory',
            GeofenceLocation::TYPE_MATERIAL_SHOP => 'Material shop',
            GeofenceLocation::TYPE_SUPPLIER => 'Supplier',
            GeofenceLocation::TYPE_OTHER => 'Other',
        ];
    }

    protected function resetForm(): void
    {
        $this->form = [
            'id' => null,
            'name' => '',
            'type' => GeofenceLocation::TYPE_FACTORY,
            'boundary_type' => GeofenceBoundary::TYPE_CIRCLE,
            'latitude' => null,
            'longitude' => null,
            'radius' => 80,
            'polygon_coordinates' => [],
            'status' => GeofenceLocation::STATUS_ACTIVE,
        ];
    }

    protected function find(int $id): ?GeofenceLocation
    {
        return GeofenceLocation::query()
            ->where('team_id', $this->teamId)
            ->where('id', $id)
            ->first();
    }

    protected function currentTeam()
    {
        return app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
    }

    protected function clearFlash(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}
