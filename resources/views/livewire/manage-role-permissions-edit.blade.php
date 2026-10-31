<div class="w-full max-w-6xl mx-auto px-4 py-4"
     wire:key="role-edit-{{ $roleKey }}-{{ $permissionsVersion }}"
     x-data="{
        permissions: $wire.$entangle('permissions', true),
        roleKey: @js($roleKey),
        childModules: ['pith_inventory_inward', 'pith_inventory_outward', 'pith_watering', 'close_location_stock'],
        dialog: {
            open: false,
            mode: 'alert',
            title: '',
            message: '',
            confirmLabel: 'OK',
            cancelLabel: 'Cancel',
            onConfirm: null,
        },
        showAlert(title, message) {
            this.dialog = {
                open: true,
                mode: 'alert',
                title,
                message,
                confirmLabel: 'OK',
                cancelLabel: 'Cancel',
                onConfirm: null,
            };
        },
        showConfirm(title, message, onConfirm) {
            this.dialog = {
                open: true,
                mode: 'confirm',
                title,
                message,
                confirmLabel: 'Continue',
                cancelLabel: 'Cancel',
                onConfirm,
            };
        },
        closeDialog() {
            this.dialog.open = false;
            this.dialog.onConfirm = null;
        },
        confirmDialog() {
            const fn = this.dialog.onConfirm;
            this.closeDialog();
            if (typeof fn === 'function') fn();
        },
        nonViewActions(moduleDef) {
            const cols = moduleDef.columns || [];
            const others = Object.keys(moduleDef.others || {});
            const viewCompanions = (moduleDef.column_companions && moduleDef.column_companions.view) || [];
            const nonViewCompanions = Object.entries(moduleDef.column_companions || {})
                .filter(([col]) => col !== 'view')
                .flatMap(([, list]) => list);
            return [...cols, ...others, ...nonViewCompanions].filter((a) => a !== 'view' && !viewCompanions.includes(a));
        },
        hasActiveNonView(moduleKey, moduleDef) {
            const perms = this.permissions[moduleKey] || {};
            return this.nonViewActions(moduleDef).some((a) => !!perms[a]);
        },
        hasActiveChildren() {
            return this.childModules.some((childKey) => {
                const child = this.permissions[childKey] || {};
                return Object.values(child).some((v) => !!v);
            });
        },
        unlockableActions(moduleKey, moduleDef) {
            const cols = moduleDef.columns || [];
            const others = Object.keys(moduleDef.others || {});
            const locks = (moduleDef.locks && moduleDef.locks[this.roleKey]) || {};
            return [...cols, ...others].filter((action) => {
                const lockKey = others.includes(action) ? ('others.' + action) : action;
                return locks[lockKey] !== false;
            });
        },
        applyCompanions(moduleKey, moduleDef, action, checked) {
            const companions = (moduleDef.column_companions && moduleDef.column_companions[action]) || [];
            companions.forEach((c) => { this.permissions[moduleKey][c] = checked; });
        },
        syncCompanions(moduleKey, moduleDef) {
            const map = moduleDef.column_companions || {};
            Object.keys(map).forEach((column) => {
                const parent = !!(this.permissions[moduleKey] && this.permissions[moduleKey][column]);
                (map[column] || []).forEach((c) => { this.permissions[moduleKey][c] = parent; });
            });
        },
        enforceViewDependency(moduleKey, moduleDef) {
            const cols = moduleDef.columns || [];
            if (!cols.includes('view')) return;
            if (!this.permissions[moduleKey]) this.permissions[moduleKey] = {};
            const perms = this.permissions[moduleKey];
            const others = Object.keys(moduleDef.others || {});
            const locks = (moduleDef.locks && moduleDef.locks[this.roleKey]) || {};
            const nonView = this.nonViewActions(moduleDef);

            const isLockedOff = (action) => {
                const lockKey = others.includes(action) ? ('others.' + action) : action;
                return locks[lockKey] === false;
            };
            const isLockedOn = (action) => {
                const lockKey = others.includes(action) ? ('others.' + action) : action;
                return locks[lockKey] === true;
            };

            const anyNonView = nonView.some((a) => !!perms[a]);
            if (anyNonView) {
                perms.view = true;
            }

            if (!perms.view) {
                nonView.forEach((a) => {
                    if (isLockedOn(a)) return;
                    if (isLockedOff(a)) return;
                    perms[a] = false;
                });
                if (nonView.some((a) => isLockedOn(a) || !!perms[a])) {
                    perms.view = true;
                }
            }
        },
        enforceParentChild() {
            if (!this.permissions.pith_inventory) this.permissions.pith_inventory = {};
            const parentOn = !!this.permissions.pith_inventory.view;
            if (!parentOn) {
                this.childModules.forEach((childKey) => {
                    if (!this.permissions[childKey]) return;
                    Object.keys(this.permissions[childKey]).forEach((action) => {
                        this.permissions[childKey][action] = false;
                    });
                });
                return;
            }
            if (this.hasActiveChildren()) {
                this.permissions.pith_inventory.view = true;
            }
        },
        ensureParentFromChildren() {
            if (this.hasActiveChildren()) {
                if (!this.permissions.pith_inventory) this.permissions.pith_inventory = {};
                this.permissions.pith_inventory.view = true;
            }
        },
        isFull(moduleKey, moduleDef) {
            const actions = this.unlockableActions(moduleKey, moduleDef);
            if (actions.length === 0) return false;
            const perms = this.permissions[moduleKey] || {};
            return actions.every((a) => !!perms[a]);
        },
        async applyToggleFull(moduleKey, moduleDef, checked) {
            if (!this.permissions[moduleKey]) this.permissions[moduleKey] = {};
            this.unlockableActions(moduleKey, moduleDef).forEach((a) => {
                this.permissions[moduleKey][a] = checked;
                this.applyCompanions(moduleKey, moduleDef, a, checked);
            });
            this.enforceViewDependency(moduleKey, moduleDef);
            this.syncCompanions(moduleKey, moduleDef);
            if (moduleKey === 'pith_inventory' && !checked) {
                this.enforceParentChild();
            } else {
                this.ensureParentFromChildren();
            }
            await $wire.toggleFull(moduleKey, checked);
        },
        async toggleFull(moduleKey, moduleDef, checked) {
            if (!checked && moduleKey === 'pith_inventory' && this.hasActiveChildren()) {
                this.showConfirm(
                    'Disable Pith Inventory?',
                    'Turning off Full for Pith Inventory will also disable Inward, Outward, and Close Stock for this role. You can turn them back on later if needed.',
                    () => this.applyToggleFull(moduleKey, moduleDef, false)
                );
                return false;
            }
            await this.applyToggleFull(moduleKey, moduleDef, checked);
            return true;
        },
        async applySetPerm(moduleKey, moduleDef, action, checked) {
            if (!this.permissions[moduleKey]) this.permissions[moduleKey] = {};
            this.permissions[moduleKey][action] = checked;
            this.applyCompanions(moduleKey, moduleDef, action, checked);
            this.enforceViewDependency(moduleKey, moduleDef);
            this.syncCompanions(moduleKey, moduleDef);
            if (moduleKey === 'pith_inventory' && action === 'view' && !checked) {
                this.enforceParentChild();
            } else {
                this.ensureParentFromChildren();
            }
            await $wire.setPermission(moduleKey, action, checked);
            return true;
        },
        async setPerm(moduleKey, moduleDef, action, checked) {
            // Block turning View off while write / Others permissions remain.
            if (action === 'view' && !checked && this.hasActiveNonView(moduleKey, moduleDef)) {
                this.showAlert(
                    'Cannot disable View',
                    'View cannot be turned off while Create, Edit, Delete, or other permissions are still enabled for this module. Turn those off first, then disable View.'
                );
                return false;
            }

            // Confirm cascading clear when disabling parent Pith Inventory View.
            if (moduleKey === 'pith_inventory' && action === 'view' && !checked && this.hasActiveChildren()) {
                this.showConfirm(
                    'Disable Pith Inventory?',
                    'Disabling Pith Inventory View will also turn off Inward, Outward, and Close Stock for this role. Continue?',
                    () => this.applySetPerm(moduleKey, moduleDef, action, false)
                );
                return false;
            }

            await this.applySetPerm(moduleKey, moduleDef, action, checked);
            return true;
        },
        isChecked(moduleKey, action) {
            return !!(this.permissions[moduleKey] && this.permissions[moduleKey][action]);
        }
     }"
     @keydown.escape.window="if (dialog.open) closeDialog()">
    {{-- Sticky bar inside Livewire root so wire:click binds (layout header does not). --}}
    <div class="flex items-center justify-between gap-3 w-full mb-4 pb-3 border-b border-gray-100">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('roles.index', ['team' => $teamId]) }}"
               class="text-gray-500 hover:text-indigo-600 transition shrink-0">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="border-l-4 border-amber-400 pl-3 min-w-0">
                <h2 class="text-lg font-semibold text-gray-900 truncate">{{ $roleName }}</h2>
            </div>
        </div>
        @if ($canEdit)
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" wire:click="resetToDefaults"
                        class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Reset to defaults
                </button>
                <button type="button" wire:click="save"
                        class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                    Save
                </button>
            </div>
        @else
            <p class="text-sm text-gray-500 italic shrink-0">Read-only view</p>
        @endif
    </div>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 text-green-800 px-3 py-2 text-sm">
            {{ session('success') }}
        </div>
    @endif

    @if (session('status'))
        <div class="mb-3 rounded-md bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-4 mb-4 bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <label class="sm:w-32 text-sm font-semibold text-gray-700">Role Name<span class="text-red-500">*</span></label>
            <div class="flex-1 max-w-xl">
                @if ($canEdit)
                    <input type="text" wire:model.blur="roleName"
                           class="w-full px-3 py-2 bg-white border border-slate-300 rounded-lg text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm font-normal @error('roleName') border-red-500 @enderror" />
                    @error('roleName')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                @else
                    <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-normal text-gray-800">
                        {{ $roleName }}
                    </div>
                @endif
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-start gap-2">
            <label class="sm:w-32 text-sm font-semibold text-gray-700 sm:mt-2">Description</label>
            <div class="flex-1 max-w-xl">
                @if ($canEdit)
                    <textarea wire:model.blur="roleDescription" rows="3"
                              class="w-full px-3 py-2 bg-white border border-slate-300 rounded-lg text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none shadow-sm font-normal"></textarea>
                @else
                    <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-normal text-gray-600 min-h-[2.5rem]">
                        {{ $roleDescription }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    @php
        $standardCols = ['view', 'create', 'edit', 'delete'];
    @endphp

    <div class="space-y-6">
        @foreach ($catalog as $sectionKey => $section)
            @php
                $sectionHasOthers = collect($section['modules'])->contains(
                    fn ($def) => ($def['others'] ?? []) !== []
                );
            @endphp
            <div>
                <h3 class="caps-label text-gray-800 mb-2.5">
                    {{ $section['label'] }}
                </h3>
                {{-- margin-right on card = More col width when section has no More, so FULL..DELETE align across all sections --}}
                <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm"
                     style="{{ $sectionHasOthers ? '' : 'margin-right: 80px;' }}">
                    <table class="w-full table-fixed border-collapse text-sm font-normal">
                        <colgroup>
                            <col />
                            <col style="width: 80px;" />
                            <col style="width: 80px;" />
                            <col style="width: 80px;" />
                            <col style="width: 80px;" />
                            <col style="width: 80px;" />
                            @if ($sectionHasOthers)
                                <col style="width: 80px;" />
                            @endif
                        </colgroup>
                        <thead>
                            <tr class="bg-slate-50 caps-label text-slate-700">
                                <th class="border border-slate-200 px-4 py-2.5 text-left font-semibold">Particulars</th>
                                <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">Full</th>
                                <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">View</th>
                                <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">Create</th>
                                <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">Edit</th>
                                <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">Delete</th>
                                @if ($sectionHasOthers)
                                    <th class="border border-slate-200 px-2 py-2.5 text-center font-semibold">More</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($section['modules'] as $moduleKey => $moduleDef)
                                @php
                                    $modulePerms = $permissions[$moduleKey] ?? [];
                                    $moduleColumns = $moduleDef['columns'] ?? [];
                                    $others = $moduleDef['others'] ?? [];
                                    $hasOthersActive = collect($others)->keys()->contains(fn ($a) => $modulePerms[$a] ?? false);
                                    $moduleDefJson = [
                                        'columns' => $moduleColumns,
                                        'others' => $others,
                                        'column_companions' => $moduleDef['column_companions'] ?? [],
                                        'locks' => $moduleDef['locks'] ?? [],
                                    ];
                                @endphp
                                <tr class="hover:bg-slate-50/40" wire:key="row-{{ $moduleKey }}">
                                    <td class="border border-slate-200 px-4 py-2 font-normal text-slate-700">{{ $moduleDef['label'] }}</td>
                                    <td class="border border-slate-200 px-2 py-2 text-center">
                                        @if ($canEdit && ($moduleColumns !== [] || $others !== []))
                                            <input type="checkbox"
                                                   :checked="isFull('{{ $moduleKey }}', @js($moduleDefJson))"
                                                   @change="
                                                       const wanted = $event.target.checked;
                                                       toggleFull('{{ $moduleKey }}', @js($moduleDefJson), wanted).then((ok) => {
                                                           if (ok === false) $event.target.checked = isFull('{{ $moduleKey }}', @js($moduleDefJson));
                                                       });
                                                   "
                                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                    @foreach ($standardCols as $col)
                                        @php
                                            $actionKey = $col;
                                            if ($col === 'edit' && in_array('update', $moduleColumns, true) && ! in_array('edit', $moduleColumns, true)) {
                                                $actionKey = 'update';
                                            }
                                            $enabled = in_array($actionKey, $moduleColumns, true) || ($col === 'edit' && in_array('update', $moduleColumns, true));
                                            $locked = $enabled && $canEdit && \App\Support\Pulse\TeamRolePermissions::isActionLocked($moduleDef, $roleKey, $actionKey);
                                        @endphp
                                        <td class="border border-slate-200 px-2 py-2 text-center">
                                            @if ($enabled)
                                                @if ($canEdit && ! $locked)
                                                    <input type="checkbox"
                                                           :checked="isChecked('{{ $moduleKey }}', '{{ $actionKey }}')"
                                                           @change="
                                                               const wanted = $event.target.checked;
                                                               setPerm('{{ $moduleKey }}', @js($moduleDefJson), '{{ $actionKey }}', wanted).then((ok) => {
                                                                   if (ok === false) $event.target.checked = isChecked('{{ $moduleKey }}', '{{ $actionKey }}');
                                                               });
                                                           "
                                                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                                @elseif ($canEdit && $locked)
                                                    <span class="inline-block w-3.5 h-3.5 bg-slate-100 rounded"></span>
                                                @else
                                                    <i class="fa-solid fa-check text-green-600 text-sm" x-show="isChecked('{{ $moduleKey }}', '{{ $actionKey }}')"></i>
                                                    <span class="text-slate-300" x-show="!isChecked('{{ $moduleKey }}', '{{ $actionKey }}')">—</span>
                                                @endif
                                            @else
                                                <span class="text-slate-300">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    @if ($sectionHasOthers)
                                        <td class="border border-slate-200 px-2 py-2 text-center">
                                            @if ($others !== [])
                                                <button type="button"
                                                        wire:click="openOthersModal('{{ $moduleKey }}')"
                                                        class="text-blue-600 hover:text-blue-800 text-xs font-normal hover:underline inline-flex items-center gap-1.5">
                                                    <span>More</span>
                                                    @if ($hasOthersActive)
                                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                                    @endif
                                                </button>
                                            @else
                                                <span class="text-slate-300">—</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>

    @if ($othersModalModule)
        @php $modalDef = config("pulse_role_permissions.modules.{$othersModalModule}"); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-5 relative">
                <button type="button" wire:click="closeOthersModal"
                        class="absolute top-3 right-3 text-gray-400 hover:text-gray-600">✕</button>
                <h3 class="text-base font-semibold text-gray-900 mb-3">
                    More — {{ $modalDef['label'] ?? $othersModalModule }}
                </h3>
                <div class="space-y-2">
                    @foreach ($modalDef['others'] ?? [] as $action => $meta)
                        @if (! \App\Support\Pulse\TeamRolePermissions::isActionLocked($modalDef, $roleKey, $action, 'others'))
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox"
                                       wire:model.live="othersModalValues.{{ $action }}"
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                <div>
                                    <span class="text-sm font-normal text-gray-800">{{ $meta['label'] }}</span>
                                    @if (! empty($meta['hint']))
                                        <p class="text-xs text-gray-500">{{ $meta['hint'] }}</p>
                                    @endif
                                </div>
                            </label>
                        @endif
                    @endforeach
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="closeOthersModal"
                            class="px-3 py-1.5 text-sm text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button" wire:click="applyOthersModal"
                            class="px-3 py-1.5 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                        Apply
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Permission rule dialogs (alert / confirm) --}}
    <div x-show="dialog.open"
         x-cloak
         class="fixed inset-0 z-[80] flex items-center justify-center p-4"
         style="display: none;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="role-perm-dialog-title">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]"
             @click="closeDialog()"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"></div>

        <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl border border-slate-100 overflow-hidden"
             @click.stop
             x-show="dialog.open"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-2 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 scale-95">
            <div class="flex items-start gap-3 px-5 pt-5 pb-3">
                <div class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center"
                     :class="dialog.mode === 'confirm' ? 'bg-amber-50 text-amber-600' : 'bg-indigo-50 text-indigo-600'">
                    <i class="fa-solid text-lg"
                       :class="dialog.mode === 'confirm' ? 'fa-triangle-exclamation' : 'fa-circle-info'"></i>
                </div>
                <div class="min-w-0 flex-1 pt-0.5">
                    <h3 id="role-perm-dialog-title"
                        class="text-base font-semibold text-slate-900 leading-snug"
                        x-text="dialog.title"></h3>
                    <p class="mt-2 text-sm text-slate-600 leading-relaxed"
                       x-text="dialog.message"></p>
                </div>
                <button type="button"
                        @click="closeDialog()"
                        class="shrink-0 -mt-1 -mr-1 w-8 h-8 inline-flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                        aria-label="Close">
                    <i class="fa-solid fa-xmark text-base"></i>
                </button>
            </div>

            <div class="px-5 pb-5 pt-2 flex items-center justify-end gap-2">
                <button type="button"
                        x-show="dialog.mode === 'confirm'"
                        @click="closeDialog()"
                        class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200 transition"
                        x-text="dialog.cancelLabel"></button>
                <button type="button"
                        @click="dialog.mode === 'confirm' ? confirmDialog() : closeDialog()"
                        class="px-4 py-2 text-sm font-semibold text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-1 transition shadow-sm"
                        :class="dialog.mode === 'confirm'
                            ? 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-400'
                            : 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-400'"
                        x-text="dialog.confirmLabel"></button>
            </div>
        </div>
    </div>
</div>
