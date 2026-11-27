<?php

namespace App\Http\Controllers;

use App\Brand\Brand;
use App\Models\Team;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** GET /brand/logo: the current company's logo, to its members only (it is stored privately). */
class BrandLogoController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $team = $request->user()?->currentTeam;
        $kit = $team instanceof Team ? Brand::forTeam($team) : null;
        $path = $kit ? Brand::logoPath($kit) : null;
        abort_if($path === null, 404);

        $response = response()->file($path);
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
