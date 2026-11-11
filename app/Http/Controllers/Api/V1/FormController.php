<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormResource;
use App\Models\AuditLog;
use App\Models\Form\Form;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormQuestionOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class FormController extends Controller
{
    /**
     * GET /api/v1/forms
     */
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()?->hasPermissionTo('forms.manage'), 403);

        $forms = Form::withCount(['questions', 'submissions'])
            ->latest()
            ->paginate(25);

        return FormResource::collection($forms);
    }

    /**
     * POST /api/v1/forms
     */
    public function store(Request $request): FormResource
    {
        abort_unless($request->user()?->hasPermissionTo('forms.manage'), 403);

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $form = Form::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'created_by'  => $request->user()->id,
        ]);

        AuditLog::record(action: 'form_created', area: 'forms', subject: $form->title, auditable: $form);

        return new FormResource($form->loadCount(['questions', 'submissions']));
    }

    /**
     * GET /api/v1/forms/{form}
     */
    public function show(Request $request, Form $form): FormResource
    {
        abort_unless($request->user()?->hasPermissionTo('forms.manage'), 403);

        $form->load('questions.options');

        return new FormResource($form);
    }

    /**
     * PUT /api/v1/forms/{form}
     */
    public function update(Request $request, Form $form): FormResource
    {
        abort_unless($request->user()?->hasPermissionTo('forms.manage'), 403);

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'questions'   => 'sometimes|array',
            'questions.*.type'        => 'required|string|in:' . implode(',', array_column(\App\Enums\QuestionType::cases(), 'value')),
            'questions.*.label'       => 'nullable|string|max:500',
            'questions.*.description' => 'nullable|string|max:2000',
            'questions.*.is_required' => 'boolean',
            'questions.*.sort_order'  => 'integer|min:0',
            'questions.*.scale_min'   => 'nullable|integer',
            'questions.*.scale_max'   => 'nullable|integer',
            'questions.*.scale_icon'  => 'nullable|string|in:star,heart,thumb,number',
            'questions.*.datetime_variant' => 'nullable|string|in:date,time,datetime',
            'questions.*.number_min'  => 'nullable|numeric',
            'questions.*.number_max'  => 'nullable|numeric',
            'questions.*.product_id'          => 'nullable|integer|exists:products,id',
            'questions.*.event_ids'            => 'nullable|array',
            'questions.*.event_ids.*'          => 'integer|exists:events,id',
            'questions.*.section_ids'          => 'nullable|array',
            'questions.*.section_ids.*'        => 'integer|exists:sections,id',
            'questions.*.section_multi'        => 'boolean',
            'questions.*.membership_type'      => 'nullable|string|max:100',
            'questions.*.rental_item_type_id'  => 'nullable|integer|exists:rental_item_types,id',
            'questions.*.options'              => 'nullable|array',
            'questions.*.options.*.text'       => 'required|string|max:255',
        ]);

        $form->update(array_filter([
            'title'       => $data['title'] ?? $form->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $form->description,
        ]));

        if (isset($data['questions'])) {
            $keptIds = [];
            foreach ($data['questions'] as $i => $q) {
                $type = \App\Enums\QuestionType::from($q['type']);
                $attrs = [
                    'type'             => $type,
                    'label'            => $q['label'] ?? '',
                    'description'      => $q['description'] ?? null,
                    'is_required'      => $type->isStructural() ? false : ($q['is_required'] ?? false),
                    'sort_order'       => $q['sort_order'] ?? $i,
                    'scale_min'        => $type->isScale() ? ($q['scale_min'] ?? 1) : null,
                    'scale_max'        => $type->isScale() ? ($q['scale_max'] ?? 5) : null,
                    'scale_icon'       => $type->isScale() ? ($q['scale_icon'] ?? 'star') : null,
                    'datetime_variant' => $type->isDatetime() ? ($q['datetime_variant'] ?? 'date') : null,
                    'number_min'       => $type === \App\Enums\QuestionType::Number ? ($q['number_min'] ?? null) : null,
                    'number_max'       => $type === \App\Enums\QuestionType::Number ? ($q['number_max'] ?? null) : null,
                    'product_id'          => $type->isProduct() ? ($q['product_id'] ?? null) : null,
                    'event_ids'           => $type->isEventSelection() ? ($q['event_ids'] ?? null) : null,
                    'section_ids'         => $type->isSectionSelection() ? ($q['section_ids'] ?? null) : null,
                    'section_multi'       => $type->isSectionSelection() ? ($q['section_multi'] ?? false) : false,
                    'membership_type'     => $type->isMembership() ? ($q['membership_type'] ?? null) : null,
                    'rental_item_type_id' => $type->isRentalWaitlist() ? ($q['rental_item_type_id'] ?? null) : null,
                ];

                if (isset($q['id'])) {
                    $question = FormQuestion::find($q['id']);
                    $question?->update($attrs);
                    $keptIds[] = $q['id'];
                } else {
                    $question  = $form->questions()->create($attrs);
                    $keptIds[] = $question->id;
                }

                if ($type->hasOptions() && $question && isset($q['options'])) {
                    $question->options()->delete();
                    foreach ($q['options'] as $j => $opt) {
                        if (trim($opt['text']) === '') {
                            continue;
                        }
                        $question->options()->create(['text' => $opt['text'], 'sort_order' => $j]);
                    }
                }
            }

            $form->questions()->whereNotIn('id', $keptIds)->each(function ($q) {
                $q->options()->delete();
                $q->delete();
            });
        }

        AuditLog::record(action: 'form_updated', area: 'forms', subject: $form->title, auditable: $form);

        return new FormResource($form->load('questions.options'));
    }

    /**
     * DELETE /api/v1/forms/{form}
     */
    public function destroy(Request $request, Form $form): JsonResponse
    {
        abort_unless($request->user()?->hasPermissionTo('forms.manage'), 403);

        $title = $form->title;
        $form->delete();

        AuditLog::record(action: 'form_deleted', area: 'forms', subject: $title);

        return response()->json(null, 204);
    }
}
