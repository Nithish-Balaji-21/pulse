<div class="w-full min-h-screen bg-gray-50">
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                    <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                        <x-menu-icon name="labour" :size="28" class="text-white" />
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-900">Edit Attendance</h2>
                    <p class="text-sm text-gray-500 mt-0.5">{{ \Carbon\Carbon::parse($date)->format('l, ' . config('app.date_format')) }}</p>
                </div>
            </div>
            <a href="{{ route('attendance.index', ['team' => $teamId]) }}"
               class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm flex items-center gap-1.5 transition-colors duration-150">
                <i class="fa-solid fa-chevron-left fa-xs"></i>
                <span>Back to Calendar</span>
            </a>
        </div>
    </x-slot>

    <style>
        /* Hide HTML5 Up/Down Spinners */
        input.no-spinner::-webkit-outer-spin-button,
        input.no-spinner::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input.no-spinner[type=number] {
            -moz-appearance: textfield;
        }
    </style>

    <div class="w-full px-4 py-6">
        @if (session()->has('message'))
            <div class="mb-6 p-4 rounded-lg bg-green-50 border-l-4 border-green-500 flex items-center gap-3 animate-fade-in">
                <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-green-800 font-medium">{{ session('message') }}</span>
            </div>
        @endif

        @if (Auth::user()->canManageAttendance())
        <form wire:submit.prevent="saveAttendance" class="max-w-full">
            <!-- Search and Filter Bar -->
            <div class="bg-white rounded-xl shadow-xs border border-gray-200 p-4 mb-6">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex-1 min-w-[250px]">
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" 
                                   id="staffSearch"
                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                   placeholder="Search staff by name..."
                                   autocomplete="off">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-sm font-medium text-gray-700">Sort by:</label>
                        <select id="sortSelect" class="px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                            <option value="default">Default Order</option>
                            <option value="name-asc">Name (A-Z)</option>
                            <option value="name-desc">Name (Z-A)</option>
                            <option value="hours-desc">Hours (High to Low)</option>
                            <option value="hours-asc">Hours (Low to High)</option>
                            <option value="salary-desc">Salary (High to Low)</option>
                            <option value="salary-asc">Salary (Low to High)</option>
                        </select>
                    </div>
                    <button type="button" 
                            id="resetFilters"
                            class="px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-lg hover:bg-gray-200 transition-all">
                        <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Reset
                    </button>
                </div>
                <div id="searchResults" class="mt-2 text-sm text-gray-600 hidden">
                    <span id="searchResultText"></span>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.staff_border') }}1A;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.staff_border') }};">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="caps-label">Total Staff</p>
                            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="totalStaffCount">{{ count($staffsAttendance) }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.hours_border') }}1A;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.hours_border') }};">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="caps-label">Total Hours</p>
                            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="totalHoursCount">0</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.salary_border') }}1A;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.salary_border') }};">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="caps-label">Total Salary</p>
                            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="totalSalaryCount">₹0</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg" style="background-color: {{ config('colors.attendance.staff_border') }}1A;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: {{ config('colors.attendance.staff_border') }};">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="caps-label">Completion</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800" id="completionPercent">0%</p>
                                <span class="text-xs text-gray-600" id="completionCount">(0/0)</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                        <div id="completionBar" class="h-full rounded-full transition-all duration-500" style="width: 0%; background: linear-gradient(to right, {{ config('colors.attendance.staff_border') }}, {{ config('colors.attendance.hours_border') }});"></div>
                    </div>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="bg-white rounded-xl shadow-xs border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="sticky top-0 z-10">
                            <tr class="table-header text-xs border-b border-gray-300">
                                <th class="py-3 px-4 font-semibold text-left min-w-[200px]">Staff Name</th>
                                <th class="py-3 px-4 font-semibold text-left min-w-[160px]">Duration (Hrs)</th>
                                <th class="py-3 px-4 font-semibold text-right min-w-[120px]">Rate/Hr</th>
                                <th class="py-3 px-4 font-semibold text-right min-w-[140px]">Salary</th>
                                <th class="py-3 px-4 font-semibold text-left min-w-[400px]">Task Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($staffsAttendance as $index => $staff)
                                @php
                                    $hasAttendance = !empty($staff['duration']) && $staff['duration'] > 0;
                                    $staffBorderColor = config('colors.attendance.staff_border');
                                    $hoursBorderColor = config('colors.attendance.hours_border');
                                @endphp

                                <tr class="text-sm hover:bg-gray-50 transition-colors duration-150 attendance-row {{ $hasAttendance ? 'has-attendance' : '' }}" 
                                    data-index="{{ $index }}">
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-semibold text-sm"
                                                 style="background: linear-gradient(135deg, {{ $staffBorderColor }} 0%, {{ $hoursBorderColor }} 100%);">
                                                {{ strtoupper(substr($staff['staff_name'], 0, 2)) }}
                                            </div>
                                            <span class="text-gray-900">{{ $staff['staff_name'] }}</span>
                                        </div>
                                    </td>

                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <input type="number"
                                                   wire:model.lazy="staffsAttendance.{{ $index }}.duration"
                                                   class="duration-input no-spinner w-20 px-3 py-2 border border-gray-300 rounded-lg text-sm font-medium text-right focus:ring-2 focus:border-transparent transition-all"
                                                   style="focus:ring-color: {{ config('colors.attendance.hours_border') }};"
                                                   step="0.1" min="0" max="24"
                                                   placeholder="0.0"
                                                   data-index="{{ $index }}"
                                                   data-salary-rate="{{ $staff['salary_per_hr'] }}"
                                                   tabindex="{{ $index * 3 + 1 }}">
                                            <span class="text-xs font-medium text-gray-500">hrs</span>
                                        </div>
                                    </td>

                                    <td class="py-3 px-4 text-right text-nowrap">
                                        <span class="text-gray-500">₹</span>
                                        <span class="text-gray-900 tabular-nums">{{ \App\Services\NumberFormatterService::formatToIndianFormat($staff['salary_per_hr'], true) }}</span>
                                    </td>

                                    <td class="py-3 px-4">
                                        <div class="relative ms-auto w-28">
                                            <span class="absolute left-3 top-2.5 text-gray-500 font-medium pointer-events-none">₹</span>
                                            <input type="number"
                                                   wire:model.lazy="staffsAttendance.{{ $index }}.salary"
                                                   class="salary-input no-spinner w-28 pl-7 pr-3 py-2 border border-gray-300 rounded-lg text-sm font-semibold text-right focus:ring-2 focus:border-transparent transition-all"
                                                   style="color: {{ config('colors.attendance.salary_border') }};"
                                                   placeholder="0"
                                                   data-index="{{ $index }}"
                                                   tabindex="{{ $index * 3 + 2 }}">
                                        </div>
                                    </td>

                                    <td class="py-3 px-4">
                                        <input type="text" 
                                               wire:model.lazy="staffsAttendance.{{ $index }}.task_description"
                                               class="task-input w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                               placeholder="Enter detailed task description..."
                                               tabindex="{{ $index * 3 + 3 }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <button type="submit" id="saveButton" class="save-btn">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span id="saveButtonText">Save Attendance</span>
                </button>
                <a href="{{ route('attendance.index', ['team' => $teamId]) }}" class="cancel-btn">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Cancel
                </a>
            </div>

            <!-- Sticky Save Button (appears on scroll) -->
            <div id="stickySaveButton" class="fixed bottom-6 right-6 z-50 opacity-0 pointer-events-none transition-all duration-300 transform translate-y-4">
                <button type="submit" 
                        class="flex items-center gap-2 px-8 py-4 text-white font-semibold rounded-full shadow-2xl hover:shadow-3xl transition-colors duration-200"
                        style="background: linear-gradient(135deg, {{ config('colors.attendance.staff_border') }}, {{ config('colors.attendance.hours_border') }});">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>

        <script>
            // Track if we're currently updating to prevent loops
            let isUpdating = false;
            let allRows = [];

            function updateSummary() {
                if (isUpdating) return;
                
                let totalHours = 0;
                let totalSalary = 0;
                let completedCount = 0;
                let totalStaff = 0;

                document.querySelectorAll('.attendance-row').forEach(row => {
                    if (row.style.display === 'none') return; // Skip hidden rows
                    
                    totalStaff++;
                    const durationInput = row.querySelector('.duration-input');
                    const salaryInput = row.querySelector('.salary-input');
                    
                    if (durationInput && salaryInput) {
                        const duration = parseFloat(durationInput.value) || 0;
                        const salary = parseFloat(salaryInput.value) || 0;
                        
                        if (duration > 0) {
                            completedCount++;
                            totalHours += duration;
                            totalSalary += salary;
                            row.classList.add('has-attendance');
                            row.classList.remove('empty-state');
                        } else {
                            row.classList.remove('has-attendance');
                            row.classList.add('empty-state');
                        }
                    }
                });

                // Update the summary cards
                const hoursEl = document.getElementById('totalHoursCount');
                const salaryEl = document.getElementById('totalSalaryCount');
                const completionPercentEl = document.getElementById('completionPercent');
                const completionCountEl = document.getElementById('completionCount');
                const completionBarEl = document.getElementById('completionBar');

                if (hoursEl) hoursEl.textContent = totalHours.toFixed(1);
                if (salaryEl) {
                    salaryEl.textContent = formatToIndianRupees(totalSalary);
                }

                const completionPercent = totalStaff > 0 ? Math.round((completedCount / totalStaff) * 100) : 0;
                if (completionPercentEl) completionPercentEl.textContent = completionPercent + '%';
                if (completionCountEl) completionCountEl.textContent = `(${completedCount}/${totalStaff})`;
                if (completionBarEl) completionBarEl.style.width = completionPercent + '%';

                console.log('Summary updated:', { totalHours, totalSalary, completedCount, totalStaff });
            }

            // Auto-calculate salary when duration changes
            function autoCalculateSalary(durationInput) {
                const index = durationInput.dataset.index;
                const salaryRate = parseFloat(durationInput.dataset.salaryRate) || 0;
                const duration = parseFloat(durationInput.value) || 0;
                const salaryInput = document.querySelector(`.salary-input[data-index="${index}"]`);
                
                if (salaryInput && duration > 0) {
                    const calculatedSalary = Math.round(duration * salaryRate);
                    salaryInput.value = calculatedSalary;
                    
                    // Trigger Livewire update
                    salaryInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }

            // Search functionality
            function searchStaff(searchTerm) {
                const rows = document.querySelectorAll('.attendance-row');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const staffName = row.querySelector('td:first-child span').textContent.toLowerCase();
                    if (staffName.includes(searchTerm.toLowerCase())) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                const searchResults = document.getElementById('searchResults');
                const searchResultText = document.getElementById('searchResultText');
                
                if (searchTerm) {
                    searchResults.classList.remove('hidden');
                    searchResultText.textContent = `Showing ${visibleCount} of ${rows.length} staff members`;
                } else {
                    searchResults.classList.add('hidden');
                }

                updateSummary();
            }

            // Sort functionality
            function sortStaff(sortType) {
                const tbody = document.querySelector('.attendance-row').parentElement;
                const rows = Array.from(document.querySelectorAll('.attendance-row'));
                
                rows.sort((a, b) => {
                    switch(sortType) {
                        case 'name-asc':
                            return a.querySelector('td:first-child span').textContent.localeCompare(
                                b.querySelector('td:first-child span').textContent
                            );
                        case 'name-desc':
                            return b.querySelector('td:first-child span').textContent.localeCompare(
                                a.querySelector('td:first-child span').textContent
                            );
                        case 'hours-asc':
                            return (parseFloat(a.querySelector('.duration-input').value) || 0) - 
                                   (parseFloat(b.querySelector('.duration-input').value) || 0);
                        case 'hours-desc':
                            return (parseFloat(b.querySelector('.duration-input').value) || 0) - 
                                   (parseFloat(a.querySelector('.duration-input').value) || 0);
                        case 'salary-asc':
                            return (parseFloat(a.querySelector('.salary-input').value) || 0) - 
                                   (parseFloat(b.querySelector('.salary-input').value) || 0);
                        case 'salary-desc':
                            return (parseFloat(b.querySelector('.salary-input').value) || 0) - 
                                   (parseFloat(a.querySelector('.salary-input').value) || 0);
                        default:
                            return 0;
                    }
                });

                rows.forEach(row => tbody.appendChild(row));
                updateSummary();
            }

            // Keyboard navigation
            function setupKeyboardNavigation() {
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        const activeElement = document.activeElement;
                        
                        // If in an input field, move to next input
                        if (activeElement.classList.contains('duration-input') || 
                            activeElement.classList.contains('salary-input') || 
                            activeElement.classList.contains('task-input')) {
                            e.preventDefault();
                            
                            const allInputs = Array.from(document.querySelectorAll('.duration-input, .salary-input, .task-input'));
                            const currentIndex = allInputs.indexOf(activeElement);
                            
                            if (currentIndex < allInputs.length - 1) {
                                allInputs[currentIndex + 1].focus();
                            }
                        }
                    }
                });
            }

            // Sticky save button on scroll
            function setupStickySaveButton() {
                const stickyButton = document.getElementById('stickySaveButton');
                const saveButton = document.getElementById('saveButton');
                
                window.addEventListener('scroll', function() {
                    const saveButtonRect = saveButton.getBoundingClientRect();
                    
                    if (saveButtonRect.bottom < 0) {
                        stickyButton.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-4');
                        stickyButton.classList.add('opacity-100', 'pointer-events-auto', 'translate-y-0');
                    } else {
                        stickyButton.classList.add('opacity-0', 'pointer-events-none', 'translate-y-4');
                        stickyButton.classList.remove('opacity-100', 'pointer-events-auto', 'translate-y-0');
                    }
                });
            }

            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                console.log('Page loaded, initializing attendance manager...');
                
                setTimeout(() => {
                    updateSummary();
                    setupKeyboardNavigation();
                    setupStickySaveButton();
                    
                    // Add event listeners to all inputs
                    document.querySelectorAll('.duration-input').forEach(input => {
                        input.addEventListener('input', function() {
                            autoCalculateSalary(this);
                            updateSummary();
                        });
                        input.addEventListener('change', function() {
                            autoCalculateSalary(this);
                            updateSummary();
                        });
                        input.addEventListener('blur', function() {
                            updateSummary();
                        });
                    });

                    document.querySelectorAll('.salary-input, .task-input').forEach(input => {
                        input.addEventListener('input', updateSummary);
                        input.addEventListener('change', updateSummary);
                        input.addEventListener('blur', updateSummary);
                    });

                    // Search functionality
                    const searchInput = document.getElementById('staffSearch');
                    if (searchInput) {
                        searchInput.addEventListener('input', function(e) {
                            searchStaff(e.target.value);
                        });
                    }

                    // Sort functionality
                    const sortSelect = document.getElementById('sortSelect');
                    if (sortSelect) {
                        sortSelect.addEventListener('change', function(e) {
                            sortStaff(e.target.value);
                        });
                    }

                    // Reset filters
                    const resetButton = document.getElementById('resetFilters');
                    if (resetButton) {
                        resetButton.addEventListener('click', function() {
                            searchInput.value = '';
                            sortSelect.value = 'default';
                            searchStaff('');
                            sortStaff('default');
                        });
                    }
                    
                    console.log('Event listeners attached to', document.querySelectorAll('.duration-input, .salary-input, .task-input').length, 'inputs');
                }, 500);
            });
            
            // Update summary when Livewire updates
            document.addEventListener('livewire:initialized', () => {
                console.log('Livewire initialized');
                Livewire.hook('morph.updated', ({ el, component }) => {
                    console.log('Livewire morph updated');
                    setTimeout(updateSummary, 100);
                });
            });

            // Fallback for page updates
            window.addEventListener('load', function() {
                setTimeout(updateSummary, 1000);
            });

            // Form submission with loading state
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.querySelector('form');
                const saveButton = document.getElementById('saveButton');
                const saveButtonText = document.getElementById('saveButtonText');
                
                if (form && saveButton) {
                    form.addEventListener('submit', function() {
                        saveButton.disabled = true;
                        saveButton.classList.add('opacity-75', 'cursor-not-allowed');
                        saveButtonText.textContent = 'Saving...';
                        
                        // Add spinner
                        const spinner = document.createElement('svg');
                        spinner.className = 'animate-spin w-5 h-5';
                        spinner.innerHTML = '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>';
                        saveButton.querySelector('svg').replaceWith(spinner);
                    });
                }
            });
        </script>
        @endif
    </div>
</div>
