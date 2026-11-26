<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Rules\ValidGstin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company details that head every PDF and exported report. One row, so
 * the header the administrator sets here is the header everywhere.
 */
class AdminCompanySettingsController extends Controller
{
    /**
     * Current company details, read for the settings form and for the PDF
     * header the mobile app renders.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(CompanySetting::current())]);
    }

    /**
     * Replace the details the exports are headed with.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'gstin' => ['nullable', 'string', 'max:20', new ValidGstin],
        ]);

        $settings = CompanySetting::current();
        $settings->fill($validated)->save();

        return response()->json([
            'data' => $this->payload($settings),
            'message' => 'Company details saved.',
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(CompanySetting $settings): array
    {
        return [
            'name' => $settings->name,
            'address' => $settings->address,
            'phone' => $settings->phone,
            'email' => $settings->email,
            'gstin' => $settings->gstin,
        ];
    }
}
