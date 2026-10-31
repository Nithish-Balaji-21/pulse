<?php

namespace App\Http\Controllers\Pulse\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OTPService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    protected $otpService;

    public function __construct(OTPService $otpService)
    {
        $this->otpService = $otpService;
    }

    public function login(Request $request)
    {
        if (! $request->otp) {
            return $this->loginWithPassword($request);
        }

        return $this->loginWithPhoneOTP($request);
    }

    private function loginWithPassword(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->username)
            ->orWhere('mobile', $request->username)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->current_team_id == null) {
            return response()->json(['message' => 'Your account is not assigned to any factory.'], 400);
        }

        if ($request->fcm_token) {
            DB::table('user_device_tokens')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'device_id' => $request->device_id,
                ],
                [
                    'fcm_token' => $request->fcm_token,
                    'device_platform' => $request->device_platform,
                ]
            );
        }

        return $this->generateLoginResponse($user);
    }

    private function loginWithPhoneOTP(Request $request)
    {
        $request->validate([
            'username' => 'required|digits:10',
            'otp' => 'required|digits:6',
        ]);

        $user = User::where('mobile', $request->username)
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User does not exist. Please contact CoiioT support.',
            ], 404);
        }

        // Default identifier = mobile
        $identifier = $user->mobile;

        // If internal user, OTP was sent to email instead
        if ($user->is_internal == 1) {
            $identifier = $user->email;
        }

        if (! $this->otpService->verifyOTP($identifier, $request->otp)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP!',
            ], 400);
        }

        if ($request->fcm_token) {
            DB::table('user_device_tokens')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'device_id' => $request->device_id,
                ],
                [
                    'fcm_token' => $request->fcm_token,
                    'device_platform' => $request->device_platform,
                ]
            );
        }

        return $this->generateLoginResponse($user);
    }

    public function sendOTP(Request $request)
    {
        $phone = $request->mobile_number;
        $user = User::where('mobile', $phone)->first();
        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User does not exist. Please contact CoiioT support.',
            ], 403);
        }

        // Default channel -> whatsapp
        $channel = 'whatsapp';

        // If internal user -> use email
        if ($user->is_internal == 1) {
            $channel = 'email';
        }

        if (! $this->otpService->sendOTP($channel === 'email' ? $user->email : $phone, $channel)) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP. Please try again later.',
            ], 500);
        }

        Log::info("OTP sent to {$channel} for user {$user->id}");

        return response()->json([
            'status' => true,
            'channel' => $channel,
            'message' => $channel === 'email'
                ? 'OTP sent to registered email.'
                : 'OTP sent to WhatsApp.',
        ], 200);
    }

    private function generateLoginResponse(User $user)
    {
        if (! $user->currentTeam) {
            return response()->json([
                'status' => false,
                'message' => 'No factory assigned to this user',
            ], 400);
        }

        $currentTeamId = $user->currentTeam->id;

        $role = DB::table('team_user')
            ->where('team_id', $currentTeamId)
            ->where('user_id', $user->id)
            ->value('role');

        // Example team logic (adjust based on your application)
        $team_module = 'pith';
        if ($user->currentTeam->has_fibre_module) {
            $team_module = 'fibre';
        } elseif ($user->currentTeam->has_growbags_module) {
            $team_module = 'growbags';
        }

        $factories = [];
        $teamBadges = [];
        $allTeams = $user->allTeams();

        $demoUserEmail = env('DEMO_USER_EMAIL');

        foreach ($allTeams as $team) {
            $name = $team->name;

            // Custom display names for demo account
            if ($user->email === $demoUserEmail) {
                if ($team->id == 2) {
                    $name = 'Demo Pith Factory';
                } elseif ($team->id == 9) {
                    $name = 'Demo Fibre Factory';
                }
            }

            $moduleBadges = [];
            if ($user->is_superadmin) {
                if ($team->has_pith_module) {
                    $moduleBadges[] = 'pith';
                }
                if ($team->has_fibre_module) {
                    $moduleBadges[] = 'fibre';
                }
                if ($team->has_growbags_module) {
                    $moduleBadges[] = 'growbag';
                }
            }
            $teamBadges[$team->id] = $moduleBadges;

            $factories[$team->id] = $name;
        }

        // Handle current team name override as well
        $currentTeamName = $user->currentTeam->name;
        if ($user->email === $demoUserEmail) {
            if ($user->currentTeam->id == 2) {
                $currentTeamName = 'Demo Pith Factory';
            } elseif ($user->currentTeam->id == 9) {
                $currentTeamName = 'Demo Fibre Factory';
            }
        }

        Log::info('Login response:', [
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $user->name,
            'teams' => $factories,
            'current_team' => $user->currentTeam ? $user->currentTeam->name : null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Login Successful',
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => [
                'name' => $user->name,
                'is_superadmin' => (bool) $user->is_superadmin,
            ],
            'user_teams' => $factories,
            'user_teams_badges' => $teamBadges,
            'current_team' => [
                'id' => $currentTeamId,
                'name' => $currentTeamName,
                'module' => $team_module,
                'role' => $role,
                'is_active' => (bool) $user->currentTeam->is_active,
                'license_valid_till' => $user->currentTeam->license_valid_till,
            ],
            'permissions' => $user->currentTeamPermissions(),
        ], 200);
    }

    /**
     * Logout method for revoking the current token.
     */
    public function logout(Request $request)
    {
        if (! $request->user()) {
            return response()->json([
                'status' => false,
                'message' => 'Already logged out',
            ], 200);
        }

        if ($request->device_id) {
            DB::table('user_device_tokens')
                ->where('user_id', $request->user()->id)
                ->where('device_id', $request->device_id)
                ->delete();
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logout successful',
        ], 200);
    }

    public function refreshFcm(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_id' => 'required|string',
            'device_platform' => 'required|string',
        ]);

        $user = $request->user();

        DB::table('user_device_tokens')->updateOrInsert(
            [
                'user_id' => $user->id,
                'device_id' => $request->device_id,
            ],
            [
                'fcm_token' => $request->fcm_token,
                'device_platform' => $request->device_platform,
            ]
        );

        return response()->json(['message' => 'Device info stored successfully']);
    }
}
