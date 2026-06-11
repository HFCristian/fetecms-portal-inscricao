<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orientador\UpdatePerfilRequest;
use App\Http\Resources\UserResource;
use App\Services\OrientadorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PerfilController extends Controller
{
    public function __construct(private readonly OrientadorService $orientadores) {}

    public function show(Request $request): UserResource
    {
        $user = $request->user();
        $this->ensureOrientador($user);

        return UserResource::make($user->load(
            'orientadorProfile.estado', 'orientadorProfile.cidade',
            'orientadorProfile.area', 'orientadorProfile.subarea', 'orientadorProfile.instituicao',
        ));
    }

    public function update(UpdatePerfilRequest $request): UserResource
    {
        $user = $request->user();
        $this->ensureOrientador($user);

        // O usuário só altera o PRÓPRIO perfil: sempre operamos sobre o autenticado.
        $user = $this->orientadores->updatePerfil($user, $request->validated());

        return UserResource::make($user);
    }

    private function ensureOrientador($user): void
    {
        if (! $user->isOrientador()) {
            throw new AccessDeniedHttpException('Apenas orientadores possuem perfil de orientador.');
        }
    }
}
