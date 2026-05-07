<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SystemSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Only admins can manage system settings
        $this->middleware(function ($request, $next) {
            if (!Auth::user()->hasRole('admin')) {
                return response()->json(['message' => 'This action is unauthorized.'], 403);
            }
            return $next($request);
        });
    }

    /**
     * Get all system settings grouped by category
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->get('category');
        
        $query = SystemSetting::query();
        
        if ($category) {
            $query->where('category', $category);
        }

        $settings = $query->orderBy('category')->orderBy('key')->get();

        // Group by category
        $grouped = $settings->groupBy('category')->map(function ($items) {
            return $items->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'key' => $setting->key,
                    'value' => $setting->getTypedValue(),
                    'type' => $setting->type,
                    'category' => $setting->category,
                    'label' => $setting->label,
                    'description' => $setting->description,
                    'is_public' => $setting->is_public,
                    'created_at' => $setting->created_at,
                    'updated_at' => $setting->updated_at,
                ];
            });
        });

        return response()->json([
            'settings' => $grouped,
            'categories' => SystemSetting::distinct('category')->pluck('category'),
        ]);
    }

    /**
     * Get a single setting by key
     */
    public function show(string $key): JsonResponse
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        return response()->json([
            'id' => $setting->id,
            'key' => $setting->key,
            'value' => $setting->getTypedValue(),
            'type' => $setting->type,
            'category' => $setting->category,
            'label' => $setting->label,
            'description' => $setting->description,
            'is_public' => $setting->is_public,
            'created_at' => $setting->created_at,
            'updated_at' => $setting->updated_at,
        ]);
    }

    /**
     * Create or update a system setting
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|max:255|regex:/^[a-z0-9_]+$/',
            'value' => 'nullable',
            'type' => ['required', Rule::in(['string', 'number', 'boolean', 'json'])],
            'category' => 'required|string|max:255',
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $setting = SystemSetting::updateOrCreate(
            ['key' => $request->key],
            [
                'type' => $request->type,
                'category' => $request->category,
                'label' => $request->label,
                'description' => $request->description,
                'is_public' => $request->boolean('is_public', false),
            ]
        );

        $setting->setTypedValue($request->value);
        $setting->save();

        return response()->json([
            'message' => 'Setting saved successfully.',
            'setting' => [
                'id' => $setting->id,
                'key' => $setting->key,
                'value' => $setting->getTypedValue(),
                'type' => $setting->type,
                'category' => $setting->category,
                'label' => $setting->label,
                'description' => $setting->description,
                'is_public' => $setting->is_public,
            ],
        ], 201);
    }

    /**
     * Update multiple settings at once
     */
    public function updateBulk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updated = [];
        $errors = [];

        foreach ($request->settings as $settingData) {
            try {
                $setting = SystemSetting::where('key', $settingData['key'])->first();
                
                if (!$setting) {
                    $errors[] = "Setting '{$settingData['key']}' not found.";
                    continue;
                }

                $setting->setTypedValue($settingData['value']);
                $setting->save();

                $updated[] = [
                    'key' => $setting->key,
                    'value' => $setting->getTypedValue(),
                ];
            } catch (\Exception $e) {
                $errors[] = "Failed to update '{$settingData['key']}': " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => count($updated) . ' setting(s) updated successfully.',
            'updated' => $updated,
            'errors' => $errors,
        ]);
    }

    /**
     * Update a single setting
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'value' => 'nullable',
            'type' => [Rule::in(['string', 'number', 'boolean', 'json'])],
            'category' => 'string|max:255',
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('type')) {
            $setting->type = $request->type;
        }
        if ($request->has('category')) {
            $setting->category = $request->category;
        }
        if ($request->has('label')) {
            $setting->label = $request->label;
        }
        if ($request->has('description')) {
            $setting->description = $request->description;
        }
        if ($request->has('is_public')) {
            $setting->is_public = $request->boolean('is_public');
        }

        if ($request->has('value')) {
            $setting->setTypedValue($request->value);
        }

        $setting->save();

        return response()->json([
            'message' => 'Setting updated successfully.',
            'setting' => [
                'id' => $setting->id,
                'key' => $setting->key,
                'value' => $setting->getTypedValue(),
                'type' => $setting->type,
                'category' => $setting->category,
                'label' => $setting->label,
                'description' => $setting->description,
                'is_public' => $setting->is_public,
            ],
        ]);
    }

    /**
     * Delete a system setting
     */
    public function destroy(string $key): JsonResponse
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            return response()->json(['message' => 'Setting not found.'], 404);
        }

        $setting->delete();

        return response()->json(['message' => 'Setting deleted successfully.']);
    }

    /**
     * Get public settings (accessible without authentication)
     */
    public function getPublic(): JsonResponse
    {
        $settings = SystemSetting::getPublicSettings();

        return response()->json(['settings' => $settings]);
    }
}
