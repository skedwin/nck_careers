<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\PositionCriterion;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\NairobiDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Position::query()->with('criteria')->orderBy('sort_order')->orderBy('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            // Default Positions page: show open vacancies.
            if (! $request->boolean('all')) {
                $query->where('status', 'open');
            }
        }

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($q): void {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('reference_code', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));
        $paginator->through(fn (Position $position) => $this->serialize($position));

        return ApiResponse::success($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'reference_code' => ['required', 'string', 'max:64', 'unique:positions,reference_code'],
            'description' => ['nullable', 'string'],
            'department' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:64'],
            'vacancies' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'open', 'closed'])],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
        ]);

        $position = Position::query()->create([
            ...$validated,
            'status' => $validated['status'] ?? 'open',
            'vacancies' => $validated['vacancies'] ?? 1,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $this->auditLogger->log('position.created', $position, null, $position->toArray(), $request);

        return ApiResponse::success($this->serialize($position->load('criteria')), 'Position created.', 201);
    }

    public function show(Position $position): JsonResponse
    {
        $position->load('criteria');

        return ApiResponse::success($this->serialize($position));
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'reference_code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('positions', 'reference_code')->ignore($position->id)],
            'description' => ['nullable', 'string'],
            'department' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:64'],
            'vacancies' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'open', 'closed'])],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date'],
        ]);

        $old = $position->only(array_keys($validated));
        $position->fill($validated)->save();

        $this->auditLogger->log('position.updated', $position, $old, $position->only(array_keys($validated)), $request);

        return ApiResponse::success($this->serialize($position->load('criteria')), 'Position updated.');
    }

    public function syncCriteria(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'criteria' => ['required', 'array'],
            'criteria.*.code' => ['required', 'string', 'max:64'],
            'criteria.*.label' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string'],
            'criteria.*.is_mandatory' => ['nullable', 'boolean'],
            'criteria.*.weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'criteria.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        DB::transaction(function () use ($position, $validated): void {
            $position->criteria()->delete();

            foreach (array_values($validated['criteria']) as $index => $row) {
                PositionCriterion::query()->create([
                    'position_id' => $position->id,
                    'code' => $row['code'],
                    'label' => $row['label'],
                    'description' => $row['description'] ?? null,
                    'is_mandatory' => $row['is_mandatory'] ?? true,
                    'weight' => $row['weight'] ?? 1,
                    'sort_order' => $row['sort_order'] ?? ($index + 1),
                ]);
            }
        });

        $this->auditLogger->log('position.criteria_synced', $position, null, [
            'count' => count($validated['criteria']),
        ], $request);

        return ApiResponse::success($this->serialize($position->load('criteria')), 'Position criteria replaced.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Position $position): array
    {
        return [
            'id' => $position->id,
            'uuid' => $position->uuid,
            'title' => $position->title,
            'reference_code' => $position->reference_code,
            'description' => $position->description,
            'department' => $position->department,
            'grade' => $position->grade,
            'status' => $position->status,
            'vacancies' => $position->vacancies,
            'sort_order' => $position->sort_order,
            'opens_at' => NairobiDate::iso($position->opens_at),
            'closes_at' => NairobiDate::iso($position->closes_at),
            'created_at' => NairobiDate::iso($position->created_at),
            'updated_at' => NairobiDate::iso($position->updated_at),
            'criteria' => $position->relationLoaded('criteria')
                ? $position->criteria->sortBy('sort_order')->values()->map(fn ($c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'label' => $c->label,
                    'description' => $c->description,
                    'is_mandatory' => $c->is_mandatory,
                    'weight' => $c->weight,
                    'sort_order' => $c->sort_order,
                ])
                : [],
        ];
    }
}
