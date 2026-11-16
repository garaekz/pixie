<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Actions\EstimateGeneration;
use App\Actions\ListGenerations;
use App\Actions\RetryGeneration;
use App\Actions\ShowGeneration;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\GenerationIndexRequest;
use App\Http\Requests\GenerationRequest;
use App\Http\Requests\RetryGenerationRequest;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function dashboard(GenerationIndexRequest $request, ListGenerations $generations): Response
    {
        return Inertia::render('Dashboard', $generations->handle($request->user(), $request->status(), recent: true));
    }

    public function index(GenerationIndexRequest $request, ListGenerations $generations): Response
    {
        return Inertia::render('Generations/Index', $generations->handle($request->user(), $request->status()));
    }

    public function show(Generation $generation, ShowGeneration $show): Response
    {
        return Inertia::render('Generations/Show', $show->handle($generation));
    }

    public function estimate(EstimateGenerationRequest $request, EstimateGeneration $estimate): JsonResponse
    {
        return response()->json($estimate->handle($request->validated()));
    }

    public function store(GenerationRequest $request, CreateGeneration $create): RedirectResponse
    {
        $create->handle($request->user(), $request->generationData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation queued. Your final charge will use actual token usage.']);

        return to_route('dashboard');
    }

    public function retry(RetryGenerationRequest $request, Generation $generation, RetryGeneration $retry): RedirectResponse
    {
        $retry->handle($generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Checking the original request again. No new generation was created.']);

        return back(302, [], route('dashboard'));
    }
}
