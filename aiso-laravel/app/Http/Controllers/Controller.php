<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\AppUser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /** The authenticated user (identity and role come from the database, never from the client). */
    protected function actor(Request $request): AppUser
    {
        $user = $request->user();
        if (!$user instanceof AppUser) {
            throw new ApiException(401, 'Unauthenticated.');
        }

        return $user;
    }

    /** @return array<string, mixed> the validated input; a failure is answered with 400 (see bootstrap/app.php) */
    protected function input(Request $request, array $rules): array
    {
        return $request->validate($rules);
    }
}
