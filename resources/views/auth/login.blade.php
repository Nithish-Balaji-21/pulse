<style>
    .show-btn {
        color: #3B82F6;
        position: absolute;
        top: 50%;
        right: 8px;
        transform: translateY(-50%);
        border-radius: 12px;
        padding: 2px 5px 1px 5px;
        font-weight: 600;
    }
    .show-btn:hover {
        background-color: white;
    }
    .show-btn:focus {
        background-color: white;
        outline: auto;
    }
</style>
<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-application-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />

        @if (session('status'))
            <div class="mb-4 font-medium text-sm text-green-600">
                {{ session('status') }}
            </div>
        @endif

        <form id="login-form" method="POST">
            @csrf

           <div class="mt-4">
                <x-label for="username" value="{{ __('Email or Mobile Number') }}" />
                <x-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autofocus autocomplete="username" />
            </div>

            {{-- By default, show password field --}}
            <div id="password-wrapper" class="mt-4">
                <x-label for="password" value="{{ __('Password') }}" />
                <div class="relative">
                    <x-input id="password" class="block mt-1 w-full" type="password" name="password" autocomplete="current-password" />
                    <p class="show-btn text-sm cursor-pointer" tabindex="0" onclick="togglePassword()">
                        Show
                    </p>
                </div>
            </div>

            {{-- OTP field, initially hidden --}}
            <div id="otp-wrapper" class="mt-4 hidden">
                <x-label for="otp" value="{{ __('Enter OTP') }}" />
                <div class="relative">
                    <x-input id="otp" class="block mt-1 w-full" type="password" name="otp" />
                    <p class="show-btn text-sm cursor-pointer" tabindex="0" onclick="toggleOtp()">
                        Show
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-between mt-4">
                <button id="login-btn" type="button"
                    class="px-4 py-2 bg-indigo-500 text-white font-semibold rounded-lg shadow hover:bg-indigo-700 transition-colors">
                    Login
                </button>

                @if(!app()->environment('demo'))
                    @if(app()->environment('local') && (int) env('APP_SEND_EMAIL', 0) !== 1)
                        <span id="toggle-login-method" class="text-sm text-gray-400 cursor-not-allowed" title="OTP email disabled locally. Set APP_SEND_EMAIL=1 to enable.">
                            Login via OTP (disabled locally)
                        </span>
                    @else
                        <a href="#" id="toggle-login-method" clas
                        s="text-sm text-indigo-600 hover:underline">
                            Login via OTP
                        </a>
                    @endif
                @endif
            </div>

            <div id="message" class="text-red-500 mt-2"></div>

            {{-- Forgot password link --}}
            @if(!app()->environment('demo'))
                <div id="forgot-password-link" class="flex items-center justify-end mt-4">
                    @if (Route::has('password.request'))
                        <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                            {{ __('Forgot your password?') }}
                        </a>
                    @endif
                </div>
            @else
            <div id="demo-contact-section" class="mt-6 pt-4 border-t border-gray-100">
                <p class="caps-label text-center mb-3">
                    Demo Support & Enquiries
                </p>
                <div class="grid grid-cols-2 gap-2">
                    <!-- WhatsApp -->
                    <a href="https://wa.me/918015203494?text=Hi%21%20I%20am%20exploring%20the%20CoiioT%20Demo%20portal%20and%20would%20like%20to%20get%20in%20touch." 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="flex items-center gap-2 px-3 py-2.5 bg-emerald-50 hover:bg-emerald-100 active:bg-emerald-200 text-emerald-800 rounded-lg text-xs font-semibold border border-emerald-200 shadow-2xs hover:shadow-xs transition-all duration-150">
                        <svg class="w-4 h-4 text-emerald-600 shrink-0 fill-current" viewBox="0 0 24 24">
                            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/>
                        </svg>
                        <span class="truncate">+91 8015203494</span>
                    </a>

                    <!-- Phone Call -->
                    <a href="tel:+918015203494" 
                       class="flex items-center gap-2 px-3 py-2.5 bg-blue-50 hover:bg-blue-100 active:bg-blue-200 text-blue-800 rounded-lg text-xs font-semibold border border-blue-200 shadow-2xs hover:shadow-xs transition-all duration-150">
                        <svg class="w-4 h-4 text-blue-600 shrink-0 fill-current" viewBox="0 0 24 24">
                            <path d="M6.62 10.79a15.053 15.053 0 006.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                        <span class="truncate">+91 8015203494</span>
                    </a>

                    <!-- Email -->
                    <a href="mailto:support@coiiot.in" 
                       class="flex items-center gap-2 px-3 py-2.5 bg-indigo-50 hover:bg-indigo-100 active:bg-indigo-200 text-indigo-800 rounded-lg text-xs font-semibold border border-indigo-200 shadow-2xs hover:shadow-xs transition-all duration-150">
                        <svg class="w-4 h-4 text-indigo-600 shrink-0 fill-current" viewBox="0 0 24 24">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                        <span class="truncate">support@coiiot.in</span>
                    </a>

                    <!-- Website -->
                    <a href="https://coiiot.in" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="flex items-center gap-2 px-3 py-2.5 bg-slate-50 hover:bg-slate-100 active:bg-slate-200 text-slate-800 rounded-lg text-xs font-semibold border border-slate-200 shadow-2xs hover:shadow-xs transition-all duration-150">
                        <svg class="w-4 h-4 text-slate-600 shrink-0 fill-current" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                        </svg>
                        <span class="truncate">coiiot.in</span>
                    </a>
                </div>
            </div>
            @endif
        </form>
    </x-authentication-card>

    <script>
        function togglePassword() {
            // Select the password input and show button
            const passwordInput = document.getElementById('password');
            const showButton = document.querySelector('.show-btn');

            // Toggle the input type between 'password' and 'text'
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                showButton.textContent = 'Hide'; // Change button text
            } else {
                passwordInput.type = 'password';
                showButton.textContent = 'Show'; // Revert button text
            }
        }

        function toggleOtp() {
            const otpInput = document.getElementById('otp');
            const showBtn = otpInput.nextElementSibling; // .show-btn

            if (otpInput.type === 'password') {
                otpInput.type = 'text';
                showBtn.textContent = 'Hide';
            } else {
                otpInput.type = 'password';
                showBtn.textContent = 'Show';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const toggleLink = document.getElementById('toggle-login-method');
            const passwordWrapper = document.getElementById('password-wrapper');
            const otpWrapper = document.getElementById('otp-wrapper');
            const loginBtn = document.getElementById('login-btn');
            const messageDiv = document.getElementById('message');
            const usernameInput = document.getElementById('username');
            let usingOtp = false;

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loginBtn.click();
                }
            });

            usernameInput.addEventListener('input', function () {
                if (usingOtp) {
                    usingOtp = false;
                    otpWrapper.classList.add('hidden');
                    passwordWrapper.classList.remove('hidden');
                    toggleLink.textContent = 'Login via OTP';
                    messageDiv.textContent = ''; // clear any message
                }
            });

            let otpSending = false; // lock flag

            if (toggleLink) {
                toggleLink.addEventListener('click', function (e) {
                    e.preventDefault();

                    // Block any click while OTP is being sent
                    if (otpSending) return;

                    const mobileOrEmail = usernameInput.value.trim();

                    if (!usingOtp) { // currently in password mode, trying to switch to OTP
                        if (!mobileOrEmail) {
                            messageDiv.textContent = 'Please enter email or mobile number';
                            return; // do NOT switch
                        }

                        // Lock toggle and show waiting state — do NOT switch UI yet
                        otpSending = true;
                        toggleLink.style.pointerEvents = 'none';
                        toggleLink.style.opacity = '0.4';
                        messageDiv.classList.remove('text-red-500');
                        messageDiv.classList.add('text-indigo-500');
                        messageDiv.textContent = 'OTP is being initiated, please wait...';

                        // Send OTP
                        fetch('/send-otp', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ username: mobileOrEmail })
                        })
                        .then(async res => {
                            const data = await res.json();
                            const phone = '918015203494';

                            if (res.ok && data.success) {
                                // OTP sent — now switch UI to OTP mode
                                usingOtp = true;
                                passwordWrapper.classList.add('hidden');
                                otpWrapper.classList.remove('hidden');
                                toggleLink.textContent = 'Login via Password';
                                messageDiv.classList.remove('text-indigo-500', 'text-red-500');
                                messageDiv.classList.add('text-green-600');

                                if (mobileOrEmail.includes('@')) {
                                    messageDiv.textContent = 'Enter the OTP sent to your Email';
                                } else {
                                    messageDiv.innerHTML = `Enter the OTP sent to your WhatsApp.
                                        <br><span class="text-sm text-gray-600">
                                        (If you didn't receive it, <a href="https://wa.me/${phone}" target="_blank" class="text-[#83211b] font-semibold hover:underline">send a Hi on WhatsApp</a>)
                                        </span>`;
                                }

                                // Unlock toggle after 5 seconds
                                setTimeout(() => {
                                    otpSending = false;
                                    toggleLink.style.pointerEvents = 'auto';
                                    toggleLink.style.opacity = '1';
                                }, 5000);

                            } else {
                                // OTP failed — stay in password mode, show error, reset after 5s
                                messageDiv.classList.remove('text-indigo-500');
                                messageDiv.classList.add('text-red-500');
                                messageDiv.textContent = data.message || 'Failed to send OTP. Please try again.';

                                setTimeout(() => {
                                    otpSending = false;
                                    toggleLink.style.pointerEvents = 'auto';
                                    toggleLink.style.opacity = '1';
                                    messageDiv.textContent = '';
                                    messageDiv.classList.remove('text-red-500');
                                }, 5000);
                            }
                        })
                        .catch(() => {
                            // Network error — stay in password mode, show error, reset after 5s
                            messageDiv.classList.remove('text-indigo-500');
                            messageDiv.classList.add('text-red-500');
                            messageDiv.textContent = 'Error sending OTP. Please try again.';

                            setTimeout(() => {
                                otpSending = false;
                                toggleLink.style.pointerEvents = 'auto';
                                toggleLink.style.opacity = '1';
                                messageDiv.textContent = '';
                                messageDiv.classList.remove('text-red-500');
                            }, 5000);
                        });

                    } else { // currently in OTP mode, switch back to password mode
                        usingOtp = false;
                        otpWrapper.classList.add('hidden');
                        passwordWrapper.classList.remove('hidden');
                        toggleLink.textContent = 'Login via OTP';
                        messageDiv.textContent = '';
                        messageDiv.classList.remove('text-green-600', 'text-red-500', 'text-indigo-500');
                        messageDiv.classList.add('text-red-500');
                    }
                });
            } 

            loginBtn.addEventListener('click', function () {
                const username = usernameInput.value.trim();
                messageDiv.textContent = '';

                if (!username) {
                    messageDiv.textContent = 'Please enter email or mobile number.';
                    return;
                }

                if (usingOtp) {
                    const otp = document.getElementById('otp').value.trim();
                    if (!otp) {
                        messageDiv.textContent = 'Please enter OTP.';
                        return;
                    }

                    fetch('/verify-otp', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ username, otp })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = '/dashboard';
                        } else {
                            messageDiv.textContent = data.message || 'Invalid OTP';
                        }
                    })
                    .catch(() => messageDiv.textContent = 'Error verifying OTP');
                } else {
                    const password = document.getElementById('password').value.trim();
                    if (!password) {
                        messageDiv.textContent = 'Please enter password.';
                        return;
                    }

                    // Submit real form to Laravel
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '{{ route('login') }}';

                    ['_token', 'username', 'password'].forEach(name => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = name === '_token'
                            ? document.querySelector('meta[name="csrf-token"]').content
                            : (name === 'username' ? username : password);
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    </script>
</x-guest-layout>
