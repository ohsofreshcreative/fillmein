<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoImportFigmaRequest;
use App\Http\Requests\FetchFigmaFrameRequest;
use App\Models\Page;
use App\Models\Project;
use App\Services\FigmaQueueService;
use App\Support\Staff;
use Illuminate\Http\JsonResponse;

/**
 * Kolejka Figmy na liście podstron (JSON): pobranie frame'u w tle i masowa „Analiza i import pól”.
 */
class FigmaQueueController extends Controller
{
	public function __construct(private FigmaQueueService $queue)
	{
	}

	public function fetch(FetchFigmaFrameRequest $request, Project $project, Page $page): JsonResponse
	{
		return response()->json($this->queue->fetch($page, Staff::current()));
	}

	public function previews(FetchFigmaFrameRequest $request, Project $project, Page $page): JsonResponse
	{
		return response()->json($this->queue->previews($page));
	}

	public function import(AutoImportFigmaRequest $request, Project $project, Page $page): JsonResponse
	{
		return response()->json($this->queue->import($page, Staff::current()));
	}
}
