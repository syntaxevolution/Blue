<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorldInfoResource;

/**
 * REST controller mirror of Web\WorldController for the /api/v1/world/*
 * surface. Future mobile client hits these endpoints; web hits Inertia.
 *
 * Both call the same WorldService methods — keep the two controllers in
 * lockstep. Thin by contract: validate → service → JSON resource.
 */
class WorldController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
    ) {}

    public function info(): WorldInfoResource
    {
        return new WorldInfoResource($this->world->getWorldInfo());
    }
}
