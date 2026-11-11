<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\Survey\Survey;
use Illuminate\Http\Response;

class SurveyExportController extends Controller
{
    public function __invoke(Survey $survey): Response
    {
        abort_unless(auth()->user()?->hasPermissionTo('surveys.manage'), 403);

        $survey->load('questions.options', 'responses.details.option', 'responses.invitation');

        $questions = $survey->questions;

        $headers = ['ID', 'Datum', 'E-post'];
        foreach ($questions as $question) {
            $headers[] = $question->label;
        }

        $rows = [];
        foreach ($survey->responses as $response) {
            $row = [
                $response->id,
                $response->created_at->format('Y-m-d H:i'),
                $response->invitation?->email ?? ($survey->is_anonymous ? 'Anonym' : '—'),
            ];

            foreach ($questions as $question) {
                $details = $response->details->where('question_id', $question->id);

                if ($question->type === QuestionType::Checkbox || $question->type === QuestionType::Radio) {
                    $row[] = $details
                        ->map(fn ($d) => $d->option?->text ?? $d->option_id)
                        ->filter()
                        ->implode(', ');
                } else {
                    $row[] = $details->first()?->answer_text ?? '';
                }
            }

            $rows[] = $row;
        }

        $csv = implode("\n", array_map(
            fn ($row) => implode(';', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                $row
            )),
            array_merge([$headers], $rows)
        ));

        $filename = 'enkatsvar-' . str($survey->title)->slug() . '-' . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
