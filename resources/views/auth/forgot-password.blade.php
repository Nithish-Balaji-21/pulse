<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
             <img src="{{ asset('images/logo-tagline.png') }}" alt="Logo" width="250" height="auto" class="mt-2">
        </x-slot>

        <div class="mb-4 text-sm text-gray-600">
            {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
        </div>

         @if(app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1)
            <div class="mb-4 text-sm font-medium" style="color: #ce7716;">
                ⚠ Local environment: SMTP may not be configured. Reset link may not be delivered.
            </div>
        @endif

        @session('status')
            <div class="mb-4 font-medium text-sm text-green-600">
                {{ $value }}
            </div>
        @endsession

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="block">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div class="flex items-center justify-between mt-4">
                <x-button type="button" onclick="window.location.href='/'">
                    {{ __('BACK TO LOGIN') }}
                </x-button>

                  @if(app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1)
                    <x-button disabled class="ml-4 opacity-50 cursor-not-allowed">
                        {{ __('Email Password Reset Link') }}
                    </x-button>
                @else
                    <x-button onclick="this.disabled=true; this.innerText='Sending...'; this.form.submit();" class="ml-4">
                        {{ __('Email Password Reset Link') }}
                    </x-button>
                @endif
            </div>
        </form>
         <div id="loading-overlay" style="display:none; position:fixed; inset:0; background:rgba(255,255,255,0.75); z-index:999; align-items:center; justify-content:center;">
            <p style="font-size:14px; font-weight:600; color:#83211b;">Sending reset link, please wait...</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
