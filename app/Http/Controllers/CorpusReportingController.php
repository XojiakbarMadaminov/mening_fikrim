<?php

namespace App\Http\Controllers;

use App\Services\Reporting\CampaignReportingService;
use App\Services\Reporting\CorpusReportingTools;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CorpusReportingController extends Controller
{
    public function campaigns(CampaignReportingService $reports)
    {
        return response()->json($reports->campaigns());
    }

    public function campaign(string $id, Request $request, CampaignReportingService $reports)
    {
        $data = $request->validate(['format' => 'sometimes|in:json,markdown', 'snapshot' => 'sometimes|in:current,final']);
        $report = ($data['snapshot'] ?? 'current') === 'final' ? $reports->finalReport($id) : $reports->campaign($id);

        return ($data['format'] ?? 'json') === 'markdown' ? response($reports->markdown($report), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']) : response()->json($report);
    }

    public function statistics(CampaignReportingService $reports)
    {
        return response()->json($reports->corpus());
    }

    public function quality(CampaignReportingService $reports)
    {
        return response()->json($reports->quality());
    }

    public function discovery(Request $request, CampaignReportingService $reports)
    {
        $data = $request->validate(['campaign_id' => 'sometimes|regex:/^[a-z0-9-]{1,60}$/']);

        return response()->json($reports->discovery($data['campaign_id'] ?? null));
    }

    public function catalog(CorpusReportingTools $tools)
    {
        return response()->json(['tools' => $tools->catalog(), 'read_only' => true]);
    }

    public function tool(string $tool, Request $request, CorpusReportingTools $tools)
    {
        try {
            return response()->json($tools->call($tool, $request->query()));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function assistant(Request $request, CorpusReportingTools $tools)
    {
        $data = $request->validate(['question' => 'required|string|max:500', 'campaign_id' => 'sometimes|regex:/^[a-z0-9-]{1,60}$/']);
        try {
            return response()->json($tools->answer($data['question'], $data['campaign_id'] ?? null));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
