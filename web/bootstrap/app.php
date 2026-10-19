<?php

use App\Http\Middleware\ActiveAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ActiveAccount::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([QueryException::class, PDOException::class]);
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && $request->is('login') && $request->isMethod('POST') && ! $request->expectsJson()) {
                return redirect()->route('login')->withErrors(['email' => 'Halaman masuk sudah kedaluwarsa. Silakan masukkan password kembali.']);
            }
        });
        $exceptions->render(function (QueryException $e, Request $request) {
            $conflict = in_array($e->getCode(), ['23505', '40001', '40P01'], true);
            $message = $conflict ? 'Data berubah atau transaksi sudah tercatat. Muat ulang sebelum mencoba lagi.' : 'Penyimpanan data belum dapat dihubungi. Periksa internet, lalu coba kembali.';

            return $request->expectsJson() ? response()->json(['message' => $message], $conflict ? 409 : 503) : response()->view('error', compact('message'), $conflict ? 409 : 503);
        });
        $exceptions->render(fn (PDOException $e) => response()->view('error', ['message' => 'Penyimpanan data belum dapat dihubungi. Periksa internet, lalu coba kembali.'], 503));
    })->create();
