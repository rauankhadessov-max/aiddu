<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DefaultWorkspaceProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, DefaultWorkspaceProvisioner $workspaceProvisioner): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'is_admin' => ['prohibited'],
            'role' => ['prohibited'],
            'user_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
        ]);

        try {
            $user = DB::transaction(function () use ($request, $workspaceProvisioner) {
                $user = new User([
                    'name' => $request->string('name')->trim()->toString(),
                    'email' => $request->string('email')->lower()->toString(),
                    'password' => Hash::make($request->string('password')->toString()),
                ]);
                $user->is_admin = false;
                $user->save();

                $workspaceProvisioner->provision($user);

                return $user;
            });
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'registration' => 'Не удалось подготовить рабочее дело. Регистрация не завершена. Попробуйте ещё раз позже.',
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('analyses.workflow.create');
    }
}
