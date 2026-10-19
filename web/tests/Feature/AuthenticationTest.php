<?php

namespace Tests\Feature;

use App\Livewire\Workspace;
use App\Models\User;
use App\Support\DatabaseGuard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseGuard::check(true);
        $this->admin = User::create(['name' => 'Pengurus Uji', 'email' => 'Operator.'.Str::uuid().'@example.test', 'password' => 'password-only-for-testing', 'role' => 'superadmin', 'active' => true]);
    }

    public function test_login_accepts_email_case_and_spaces_and_remember_choice(): void
    {
        $this->post('/login', ['email' => '  '.strtoupper($this->admin->email).'  ', 'password' => 'password-only-for-testing', 'remember' => '1'])
            ->assertRedirect('/app/dashboard')->assertCookie(Auth::guard()->getRecallerName());
        $this->assertAuthenticatedAs($this->admin);
        $this->assertNotEmpty($this->admin->fresh()->remember_token);
        $this->get('/login')->assertRedirect('/app/dashboard');
    }

    public function test_wrong_password_is_rejected_without_flashing_it(): void
    {
        $this->from('/login')->post('/login', ['email' => $this->admin->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_login_with_correct_password(): void
    {
        $this->admin->update(['active' => false]);
        $this->from('/login')->post('/login', ['email' => $this->admin->email, 'password' => 'password-only-for-testing'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_rate_limit_applies_across_email_case_and_recovers(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $email = $attempt % 2 ? strtoupper($this->admin->email) : strtolower($this->admin->email);
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password-only-for-testing'])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba lagi dalam satu menit.']);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password-only-for-testing'])->assertRedirect('/app/dashboard');
        $this->assertAuthenticatedAs($this->admin);
        $this->travelBack();
    }

    public function test_logout_removes_remember_cookie_and_protects_dashboard(): void
    {
        $login = $this->post('/login', ['email' => $this->admin->email, 'password' => 'password-only-for-testing', 'remember' => true])->assertRedirect('/app/dashboard');
        $cookieName = Auth::guard()->getRecallerName();
        $this->withCookie($cookieName, $login->getCookie($cookieName)->getValue())
            ->post('/logout')->assertRedirect('/login')->assertCookieExpired($cookieName);
        $this->assertGuest();
        $this->get('/app/dashboard')->assertRedirect('/login');
    }

    public function test_expired_login_form_redirects_to_a_fresh_form(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/login'), $response->headers->get('Location'));
        $this->assertSame('Halaman masuk sudah kedaluwarsa. Silakan masukkan password kembali.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_other_csrf_failures_still_reject_the_request(): void
    {
        $response = app(ExceptionHandler::class)->render(Request::create('/logout', 'POST'), new TokenMismatchException);
        $this->assertSame(419, $response->getStatusCode());
        $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);
        $this->assertSame(419, $response->getStatusCode());
    }

    public function test_admin_password_reset_revokes_persistent_login_token(): void
    {
        $other = User::create(['name' => 'Petugas Uji', 'email' => Str::uuid().'@example.test', 'password' => 'password-only-for-testing', 'role' => 'petugas', 'active' => true]);
        $other->setRememberToken('old-persistent-login-token');
        $other->save();
        Livewire::actingAs($this->admin)->test(Workspace::class, ['page' => 'settings'])
            ->call('editUser', $other->id)->set('form.password', 'changed-password-for-testing')->call('saveUser')->assertHasNoErrors();
        $this->assertNotSame('old-persistent-login-token', $other->fresh()->remember_token);
    }
}
