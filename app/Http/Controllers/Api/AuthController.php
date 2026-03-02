<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PhpParser\Node\Expr\Print_;

class AuthController extends Controller
{
    public function redirectToGoogle()
    {
        $role = request()->get('role', 'user'); // default user

        return Socialite::driver('google')
            ->stateless()
            ->with([
                'state' => $role
            ])
            ->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')
            ->stateless()
            ->user();
        
        // $role = request()->get('state', 'user');
            
        $user = User::where('google_id', $googleUser->id)
            ->orWhere('email', $googleUser->email)
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);
        } else {
            $user->update([
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);
        }
        $token = $user->createToken('google-token')->plainTextToken;
        
        if ($user->role === 'admin') {
            $frontendUrl = config('app.frontend_admin_url') . '/oauth/callback';
        } else {
            $frontendUrl = config('app.frontend_user_url') . '/oauth/callback';
        }

        return redirect()->away(
            $frontendUrl . '?token=' . $token
        );
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required','email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Email atau password salah'
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user
        ]);
    }
}