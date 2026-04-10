<?php

namespace App\Http\Controllers\Web;

use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia controller for world-related player-facing pages.
 *
 * Thin by contract: every method validates (via FormRequest), calls a
 * WorldService method, and renders an Inertia page. No game logic inline.
 *
 * The mirror of this controller lives at App\Http\Controllers\Api\V1\WorldController
 * and calls the same WorldService methods — both layers must stay in sync.
 */
class WorldController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
    ) {}

    /**
     * Placeholder debug view that renders the configured world shape.
     * Phase 1 replaces this with the real Map view at /map.
     */
    public function info(): Response
    {
        return Inertia::render('Debug/WorldInfo', [
            'world' => $this->world->getWorldInfo(),
        ]);
    }
}
