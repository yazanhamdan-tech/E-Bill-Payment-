<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:admin']);
    }

    /**
     * Display a listing of permissions.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $allowedSorts = ['name', 'id', 'created_at'];
        $sort = $request->query('sort', 'name');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'name';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $query = Permission::query();
        $search = $request->query('q');
        if ($search !== null && $search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $paginator = $query->orderBy($sort, $direction)->paginate($perPage);
        $permissions = $paginator->items();

        $grouped = collect($permissions)->groupBy(function ($permission) {
            return $this->getPermissionCategory($permission->name);
        });

        return response()->json([
            'permissions' => $permissions,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'grouped' => $grouped,
        ]);
    }

    /**
     * Store a newly created permission.
     */
    public function store(Request $request): JsonResponse
    {
        $name = $this->normalizePermissionName($request->input('name'));
        $this->validatePermissionName($name);

        $permission = Permission::create(['name' => $name]);

        return response()->json([
            'message' => 'Permission created successfully',
            'permission' => $permission,
        ], 201);
    }

    /**
     * Display the specified permission.
     */
    public function show(Permission $permission): JsonResponse
    {
        $permission->load(['roles:id,name']);

        return response()->json([
            'permission' => $permission,
        ]);
    }

    /**
     * Update the specified permission.
     */
    public function update(Request $request, Permission $permission): JsonResponse
    {
        $name = $this->normalizePermissionName($request->input('name'));
        $this->validatePermissionName($name, $permission->id);

        $permission->update(['name' => $name]);

        return response()->json([
            'message' => 'Permission updated successfully',
            'permission' => $permission,
        ]);
    }

    /**
     * Remove the specified permission.
     */
    public function destroy(Permission $permission): JsonResponse
    {
        if ($permission->roles()->exists()) {
            $permission->roles()->detach();
        }

        $permission->delete();

        return response()->json([
            'message' => 'Permission deleted successfully',
        ]);
    }

    private function getPermissionCategory(string $name): string
    {
        if (strpos($name, ' ') !== false) {
            return explode(' ', $name, 2)[0];
        }

        if (strpos($name, '.') !== false) {
            return explode('.', $name, 2)[0];
        }

        if (strpos($name, ':') !== false) {
            return explode(':', $name, 2)[0];
        }

        return 'other';
    }

    private function normalizePermissionName(?string $name): string
    {
        $name = trim((string) $name);
        return preg_replace('/\s+/', ' ', $name) ?? '';
    }

    private function validatePermissionName(string $name, ?int $ignoreId = null): void
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('permissions', 'name')->ignore($ignoreId),
            ],
        ];

        Validator::make(['name' => $name], $rules)->validate();
    }
}

