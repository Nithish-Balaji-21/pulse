<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OTPService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class UserController extends Controller
{
    public function index($teamId = null)
    {
        $teamId = $teamId ?? (app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id);

        $users = DB::table('users')
            ->join('team_user', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.team_id', $teamId)
            ->select('users.id', 'users.name', 'team_user.role', 'users.email', 'users.mobile', 'team_user.team_id as current_team_id', 'users.is_active', 'users.is_superadmin')
            ->get();

        return response()->json($users, 200);
    }

    public function sendOtp(Request $request, OTPService $otpService)
    {
        $request->validate([
            'username' => 'required|string', // username here can be mobile or email
        ]);

        $identifier = $request->input('username');
        // Check if the user exists
        $user = User::where('email', $identifier)
            ->orWhere('mobile', $identifier)
            ->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'This email or mobile number is not registered.',
            ], 404);
        }

        try {
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            $method = $isEmail ? 'email' : 'whatsapp';
            $sent = $otpService->sendOTP($identifier, $method);
            if ($sent) {
                return response()->json([
                    'success' => true,
                    'message' => $isEmail ? 'email' : 'mobile',
                ]);
            } else {
                // fallback for other failure
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send OTP',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send OTP: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP',
            ], 500);
        }
    }

    // Verify the OTP submitted by user.
    public function verifyOtp(Request $request, OTPService $otpService)
    {
        $request->validate([
            'username' => 'required|string', // can be mobile or email
            'otp' => 'required|digits:6',
        ]);

        $identifier = $request->input('username');
        $otp = (int) $request->input('otp');

        try {
            $isValid = $otpService->verifyOTP($identifier, $otp);

            if ($isValid) {
                // Find user by mobile or email
                $user = User::where('mobile', $identifier)
                    ->orWhere('email', $identifier)
                    ->first();

                if ($user) {
                    Auth::login($user);  // log the user in

                    return response()->json([
                        'success' => true,
                        'message' => 'OTP verified! Redirecting...',
                        'redirect' => '/dashboard',
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found',
                    ], 404);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired OTP',
                ], 422);
            }
        } catch (\Exception $e) {
            Log::error('Failed to verify OTP: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error verifying OTP',
            ], 500);
        }
    }

    public function toggleActivity($id)
    {
        if (! Gate::allows('manage_users.edit')) {
            return response()->json(['error' => 'You are not authorized to toggle active status.'], 403);
        }

        $user = User::findOrFail($id);
        $user->is_active = ! $user->is_active;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'User status updated successfully.',
            'is_active' => $user->is_active,
        ]);
    }

    // For Api's future use
    // public function store(Request $request, CreatesNewUsers $createsNewUsers)
    // {
    //     $currentTeam = Auth::user()->currentTeam;

    //     if (!$currentTeam) {
    //         Log::error('Current team is not set for the authenticated user.');
    //         return response()->json(['error' => 'Current team is not set.'], 400);
    //     }

    //     $currentTeamId = $currentTeam->id;

    //     try {
    //         // Validate the request
    //         $validatedData = $request->validate(
    //             [
    //                 'name' => 'required|string|max:255',
    //                 'email' => 'required|string|email|max:255|unique:users,email',
    //                 'mobile' => 'required|string|max:10|unique:users,mobile',
    //             ],
    //             [
    //                 'name.required' => 'The name field is required.',
    //                 'email.required' => 'The email field is required.',
    //                 'mobile.required' => 'The phone number field is required.',
    //             ]
    //         );

    //         // Check if the user already exists
    //         $user = User::where('email', $validatedData['email'])->first();

    //         if ($user) {
    //             $user->update(['current_team_id' => $currentTeamId]);
    //         } else {
    //             dd($validatedData);
    //             $user = $createsNewUsers->create($validatedData);
    //             $user->teams()->attach($currentTeamId, ['role' => 'member']);
    //         }
    //     } catch (\Exception $e) {
    //         Log::error('Failed to create or update user: ' . $e->getMessage());
    //         return response()->json(['error' => 'Failed to create or update user.'], 500);
    //     }

    //     return response()->json(['message' => 'User created or updated successfully.'], 201);
    // }

    // public function delete($id)
    // {
    //     try {
    //         $user = User::findOrFail($id);

    //         $user->teams()->detach($user->current_team_id);
    //         $user->delete();

    //         return response()->json(['message' => 'User deleted successfully.'], 200);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to delete user: ' . $e->getMessage());
    //         return response()->json(['error' => 'Failed to delete user.'], 500);
    //     }
    // }

    public function getContext()
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['guest' => true]);
        }

        return response()->json([
            'userId' => $user->id,
            'teamId' => $user->current_team_id,
            'lastLoggedInAt' => $user->last_login_at,
            'logoUrl' => asset('images/logo.png'),
            'userIsAdmin' => $user->is_admin, // uses getIsAdminAttribute on User model
        ]);
    }
}
