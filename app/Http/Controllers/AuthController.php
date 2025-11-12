<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Helpers\ApiResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            return ApiResponse::error('Unauthorized', [], 401);
        }

        $user = auth()->user()->load('role');

        return ApiResponse::success('Login successful', [
            'token' => $token,
            'user' => $user
        ]);
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return ApiResponse::success('Logout successful');
        } catch (\Exception $e) {
            return ApiResponse::error('Logout failed', ['error' => $e->getMessage()], 500);
        }
    }

public function sendOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $otp = rand(100000, 999999); 
    Cache::put('otp_' . $request->email, $otp, now()->addMinutes(5));

    // Send OTP via mail
    Mail::raw("Your OTP is: $otp", function ($message) use ($request) {
        $message->to($request->email)
                ->subject('EPM TAS OTP code');
    });

    return ApiResponse::success('OTP sent to your email.', [
        'email' => $request->email,
        'otp_sent' => true,
        'expires_in_minutes' => 5
    ]);
}


public function confirmOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'otp' => 'required|digits:6',
    ]);

    $cachedOtp = Cache::get('otp_' . $request->email);

    if (!$cachedOtp || $cachedOtp != $request->otp) {
        return ApiResponse::error('Invalid or expired OTP.', [], 401);
    }

    // OTP is valid
    Cache::put('otp_confirmed_' . $request->email, true, now()->addMinutes(10));

    return ApiResponse::success('OTP verified successfully.', [
        'email' => $request->email,
        'otp_verified' => true,
        'valid_for_minutes' => 10
    ]);
}




public function resetPassword(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:users,email',
        'new_password' => 'required|min:6|confirmed',
    ]);

    // Check if OTP was confirmed
    if (!Cache::get('otp_confirmed_' . $request->email)) {
        return ApiResponse::error('OTP verification required.', [], 401);
    }

    $user = User::where('email', $request->email)->first();
    $user->password = Hash::make($request->new_password);
    $user->save();

    // Clear OTP
    Cache::forget('otp_' . $request->email);
    Cache::forget('otp_confirmed_' . $request->email);

    return ApiResponse::success('Password reset successfully.', [
        'email' => $request->email,
        'password_reset' => true
    ]);
}
}
