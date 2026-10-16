<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AlgebraCardPack;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlgebraCardController extends Controller
{
    public function index(Request $request): Response
    {
        $packs = AlgebraCardPack::options();
        $selectedKey = (string) $request->query('pack', $packs[0]['key'] ?? '');
        $selected = AlgebraCardPack::find($selectedKey) ?? ($packs[0] ?? null);

        return Inertia::render('Admin/AlgebraCards/Index', [
            'packs' => $packs,
            'selectedPack' => $selected,
            'cards' => $selected ? AlgebraCardPack::cardsFor($selected['key']) : [],
        ]);
    }

    public function figure(string $pack, string $figure): BinaryFileResponse
    {
        $path = AlgebraCardPack::figurePath($pack, $figure);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
