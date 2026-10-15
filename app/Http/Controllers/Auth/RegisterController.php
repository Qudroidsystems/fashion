<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Self sign-up: creates a new fashion house (tenant) with the person signing
 * up as its Owner. Only available in multi-tenant mode with self sign-up on;
 * in single mode staff accounts are created by the owner from Users.
 */
class RegisterController extends Controller
{
    use RegistersUsers;

    protected $redirectTo = '/dashboard';

    public function __construct(protected TenantProvisioner $provisioner)
    {
        $this->middleware('guest');
        $this->middleware(function (Request $request, $next) {
            abort_unless(static::enabled(), 404);

            return $next($request);
        });
    }

    public static function enabled(): bool
    {
        return config('tenancy.mode') !== 'single' && config('tenancy.self_signup');
    }

    protected function validator(array $data)
    {
        return Validator::make($data, [
            'business_name' => ['required', 'string', 'max:120'],
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone_number'  => ['nullable', 'string', 'max:30'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
        ], [], ['business_name' => 'fashion house name']);
    }

    protected function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name'         => $data['name'],
                'email'        => $data['email'],
                'phone_number' => $data['phone_number'] ?? null,
                'password'     => $data['password'],
            ]);

            $this->provisioner->create([
                'name'  => $data['business_name'],
                'phone' => $data['phone_number'] ?? null,
            ], $user);

            return $user;
        });
    }
}
