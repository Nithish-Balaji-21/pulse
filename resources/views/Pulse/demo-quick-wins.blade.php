<x-app-layout>
    <x-slot name="header">
        <x-breadcrumb :items="[
            ['label' => 'Dashboard', 'url' => route('dashboard', ['team' => $currentTeam ?? 1])],
            ['label' => 'Quick Wins Demo', 'url' => '#']
        ]" />
    </x-slot>

    <div class="py-6 px-6" x-data="{ showModal: false, showLoading: false }">
        <!-- Page Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-800 mb-2">Quick Wins Demo Page</h1>
            <p class="text-gray-600">This page demonstrates all the quick win features implemented in the application.</p>
        </div>

        <!-- Toast Notifications Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-bell text-blue-600 mr-2"></i>
                Toast Notifications
            </h2>
            <p class="text-gray-600 mb-4">Click the buttons below to see different toast notification types:</p>
            <div class="flex gap-3 flex-wrap">
                <button onclick="window.toast.success('Operation completed successfully!')" class="add-btn">
                    <i class="fa-solid fa-check-circle"></i> Success Toast
                </button>
                <button onclick="window.toast.error('An error occurred!')" class="delete-btn">
                    <i class="fa-solid fa-times-circle"></i> Error Toast
                </button>
                <button onclick="window.toast.warning('Please check your input!')" class="warn-btn">
                    <i class="fa-solid fa-exclamation-triangle"></i> Warning Toast
                </button>
                <button onclick="window.toast.info('Here is some information.')" class="edit-btn">
                    <i class="fa-solid fa-info-circle"></i> Info Toast
                </button>
            </div>
        </div>

        <!-- Loading States Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-spinner text-blue-600 mr-2"></i>
                Loading States
            </h2>
            <p class="text-gray-600 mb-4">Click to show a loading overlay:</p>
            <button @click="showLoading = true; setTimeout(() => showLoading = false, 3000)" class="add-btn">
                <i class="fa-solid fa-sync"></i> Show Loading (3 seconds)
            </button>
            
            <x-loading-overlay :show="false" message="Processing your request..." x-show="showLoading" />
        </div>

        <!-- Tooltips Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-comment-dots text-blue-600 mr-2"></i>
                Tooltips
            </h2>
            <p class="text-gray-600 mb-4">Hover over the buttons to see tooltips in different positions:</p>
            <div class="flex gap-4 flex-wrap">
                <x-tooltip text="This is a top tooltip" position="top">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded-lg">
                        <i class="fa-solid fa-arrow-up"></i> Top
                    </button>
                </x-tooltip>
                
                <x-tooltip text="This is a bottom tooltip" position="bottom">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded-lg">
                        <i class="fa-solid fa-arrow-down"></i> Bottom
                    </button>
                </x-tooltip>
                
                <x-tooltip text="This is a left tooltip" position="left">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded-lg">
                        <i class="fa-solid fa-arrow-left"></i> Left
                    </button>
                </x-tooltip>
                
                <x-tooltip text="This is a right tooltip" position="right">
                    <button class="px-4 py-2 bg-gray-600 text-white rounded-lg">
                        <i class="fa-solid fa-arrow-right"></i> Right
                    </button>
                </x-tooltip>
            </div>
        </div>

        <!-- Confirmation Modal Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-question-circle text-blue-600 mr-2"></i>
                Confirmation Modals
            </h2>
            <p class="text-gray-600 mb-4">Click to show a confirmation modal:</p>
            <button @click="showModal = true" class="delete-btn">
                <i class="fa-solid fa-trash"></i> Show Delete Confirmation
            </button>
            
            <x-confirmation-modal
                :show="false"
                x-show="showModal"
                title="Delete Record"
                message="Are you sure you want to delete this record? This action cannot be undone."
                confirmText="Yes, Delete"
                cancelText="Cancel"
                type="danger"
                onConfirm="window.toast.success('Record deleted!'); showModal = false"
                onCancel="showModal = false"
            />
        </div>

        <!-- Search Input Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-search text-blue-600 mr-2"></i>
                Search Input with Icon
            </h2>
            <p class="text-gray-600 mb-4">Enhanced search input with icon:</p>
            <div class="search-input relative max-w-md">
                <i class="fa-solid fa-search search-icon"></i>
                <input 
                    type="text" 
                    class="input-type w-full pl-10"
                    placeholder="Search anything..." 
                />
            </div>
        </div>

        <!-- Button Styles Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-hand-pointer text-blue-600 mr-2"></i>
                Button Styles
            </h2>
            <p class="text-gray-600 mb-4">Consistent button styles throughout the application:</p>
            <div class="flex gap-3 flex-wrap">
                <button class="add-btn">
                    <i class="fa-solid fa-plus"></i> Add Button
                </button>
                <button class="cancel-btn">
                    <i class="fa-solid fa-times"></i> Cancel Button
                </button>
                <button class="delete-btn">
                    <i class="fa-solid fa-trash"></i> Delete Button
                </button>
                <button class="export-btn">
                    <i class="fa-solid fa-download"></i> Export Button
                </button>
            </div>
        </div>

        <!-- Export Table Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 table-container">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-file-export text-blue-600 mr-2"></i>
                Exportable Table
            </h2>
            <p class="text-gray-600 mb-4">Export buttons will appear above this table automatically:</p>
            
            <table class="w-full border" data-exportable data-export-name="demo-data">
                <thead class="sticky-header">
                    <tr class="table-header text-sm">
                        <th class="py-3 px-4 text-left">Name</th>
                        <th class="py-3 px-4 text-left">Email</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-center">Role</th>
                        <th class="py-3 px-4 text-center actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-4">John Doe</td>
                        <td class="py-3 px-4">john@example.com</td>
                        <td class="py-3 px-4 text-center">
                            <span class="status-badge status-on">Active</span>
                        </td>
                        <td class="py-3 px-4 text-center">Admin</td>
                        <td class="py-3 px-4 text-center actions">
                            <button class="text-blue-600 hover:text-blue-800">Edit</button>
                        </td>
                    </tr>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-4">Jane Smith</td>
                        <td class="py-3 px-4">jane@example.com</td>
                        <td class="py-3 px-4 text-center">
                            <span class="status-badge status-on">Active</span>
                        </td>
                        <td class="py-3 px-4 text-center">User</td>
                        <td class="py-3 px-4 text-center actions">
                            <button class="text-blue-600 hover:text-blue-800">Edit</button>
                        </td>
                    </tr>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-3 px-4">Bob Johnson</td>
                        <td class="py-3 px-4">bob@example.com</td>
                        <td class="py-3 px-4 text-center">
                            <span class="status-badge status-off">Inactive</span>
                        </td>
                        <td class="py-3 px-4 text-center">User</td>
                        <td class="py-3 px-4 text-center actions">
                            <button class="text-blue-600 hover:text-blue-800">Edit</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Helper Functions Demo -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-code text-blue-600 mr-2"></i>
                JavaScript Helper Functions
            </h2>
            <p class="text-gray-600 mb-4">Try out the helper functions:</p>
            <div class="flex gap-3 flex-wrap">
                <button onclick="window.copyToClipboard('Hello from Coir Power!')" class="add-btn">
                    <i class="fa-solid fa-copy"></i> Copy to Clipboard
                </button>
                <button onclick="window.scrollToTop()" class="add-btn">
                    <i class="fa-solid fa-arrow-up"></i> Scroll to Top
                </button>
                <button onclick="alert('Currency: ' + window.formatCurrency(123456.78))" class="add-btn">
                    <i class="fa-solid fa-rupee-sign"></i> Format Currency
                </button>
                <button onclick="alert('Number: ' + window.formatNumber(1234567, 2))" class="add-btn">
                    <i class="fa-solid fa-hashtag"></i> Format Number
                </button>
            </div>
        </div>

        <!-- Documentation -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg shadow-md p-6 border-l-4 border-blue-500">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fa-solid fa-book text-blue-600 mr-2"></i>
                Documentation
            </h2>
            <p class="text-gray-700 mb-3">
                For detailed implementation instructions and usage examples, please refer to:
            </p>
            <ul class="list-disc list-inside text-gray-700 space-y-2">
                <li><strong>QUICK_WINS_IMPLEMENTATION.txt</strong> - Complete usage guide</li>
                <li><strong>QUICK_WINS_SUMMARY.txt</strong> - Overview of all features</li>
                <li><strong>/resources/views/components/</strong> - Reusable components</li>
                <li><strong>/public/js/</strong> - JavaScript utilities</li>
            </ul>
        </div>
    </div>
</x-app-layout>
