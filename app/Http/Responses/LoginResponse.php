<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        // Sempre redirecionar para o dashboard ou home após login,
        // ignorando URLs "intended" que possam ser chamadas de API/JSON
        return $request->wantsJson()
                    ? response()->json(['two_factor' => false])
                    : redirect()->route('dashboard'); // Ou redirect('/')
    }
}
