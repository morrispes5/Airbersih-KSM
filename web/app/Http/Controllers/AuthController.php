<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function form()
    {
        return Auth::check() ? redirect('/app/dashboard') : view('auth.login');
    }

    public function login(Request $r)
    {
        $r->merge(['email' => is_string($r->input('email')) ? strtolower(trim($r->input('email'))) : $r->input('email')]);
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'sometimes|boolean']);
        $key = hash('sha256', $v['email'].'|'.$r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Terlalu banyak percobaan. Coba lagi dalam satu menit.'])->onlyInput('email');
        }
        $credentials = ['email' => fn ($query) => $query->whereRaw('lower(email) = ?', [$v['email']]), 'password' => $v['password'], 'active' => true];
        if (! Auth::attempt($credentials, $r->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Email atau password tidak sesuai.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended('/app/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
