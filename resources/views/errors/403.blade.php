{{-- resources/views/errors/403.blade.php --}}
<x-app-layout>
    <div class="min-h-screen flex flex-col justify-center items-center bg-gray-100">
        <h1 class="text-5xl font-semibold tabular-nums text-red-600 mb-4">403</h1>
        <p class="text-lg text-gray-800 mb-6">
            You do not have permission to access this page.
        </p>
        <a href="{{ route('dashboard.redirect') }}" class="px-4 py-2 bg-blue-600 text-gray-700 rounded hover:bg-blue-700 transition">
            Go to Dashboard
        </a>
    </div>
</x-app-layout>
