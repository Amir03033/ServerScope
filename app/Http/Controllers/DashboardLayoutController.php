<?php

namespace App\Http\Controllers;

use App\Support\DashboardWidgets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saves (or resets) the signed-in person's own dashboard layout. Only the view changes. */
class DashboardLayoutController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $ids = array_keys(DashboardWidgets::WIDGETS);
        $data = $request->validate([
            'preset' => ['nullable', Rule::in(array_keys(DashboardWidgets::PRESETS))],
            'order' => ['array', 'max:'.count($ids)],
            'order.*' => ['string', Rule::in($ids)],
            'sizes' => ['array'],
            'sizes.*' => ['string', Rule::in(DashboardWidgets::SIZES)],
            'hidden' => ['array'],
            'hidden.*' => ['string', Rule::in($ids)],
        ]);

        $layout = isset($data['preset']) ? DashboardWidgets::preset($data['preset']) : DashboardWidgets::normalize($data);

        $request->user()->forceFill(['dashboard_layout' => $layout])->save();

        return response()->json(['layout' => $layout]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->forceFill(['dashboard_layout' => null])->save();

        return response()->json(['layout' => DashboardWidgets::normalize(null)]);
    }
}
