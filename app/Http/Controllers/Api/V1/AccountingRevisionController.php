<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalSeries;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountingRevisionController extends Controller
{
    private function authorise(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole('revisor') || $request->user()->isAdmin(),
            403
        );
    }

    // GET /accounting/revision
    public function index(Request $request): JsonResponse
    {
        $this->authorise($request);

        $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'series'         => 'sometimes|string|max:10',
            'review'         => 'sometimes|in:all,unreviewed,reviewed,commented',
            'date_from'      => 'sometimes|date',
            'date_to'        => 'sometimes|date',
            'per_page'       => 'sometimes|integer|min:1|max:200',
        ]);

        $fiscalYearId = $request->integer('fiscal_year_id');
        $filterSeries = $request->input('series', '');
        $filterReview = $request->input('review', 'all');
        $dateFrom     = $request->input('date_from', '');
        $dateTo       = $request->input('date_to', '');
        $perPage      = $request->integer('per_page', 50);

        // Build base query (used for both entries and stats)
        $baseQuery = fn () => JournalEntry::where('fiscal_year_id', $fiscalYearId)
            ->when($filterSeries, fn ($q) => $q->where('series', $filterSeries))
            ->when($dateFrom,     fn ($q) => $q->where('date', '>=', $dateFrom))
            ->when($dateTo,       fn ($q) => $q->where('date', '<=', $dateTo));

        // Stats — not filtered by review status
        $base  = $baseQuery();
        $stats = [
            'total'     => (clone $base)->count(),
            'reviewed'  => (clone $base)->whereNotNull('reviewed_at')->count(),
            'commented' => (clone $base)->whereNotNull('review_note')->where('review_note', '!=', '')->count(),
        ];

        // Entries — also filtered by review status
        $entries = $baseQuery()
            ->when($filterReview === 'unreviewed', fn ($q) => $q->whereNull('reviewed_at'))
            ->when($filterReview === 'reviewed',   fn ($q) => $q->whereNotNull('reviewed_at'))
            ->when($filterReview === 'commented',  fn ($q) => $q->whereNotNull('review_note')->where('review_note', '!=', ''))
            ->with(['reviewedBy:id,name', 'createdBy:id,name'])
            ->addSelect('journal_entries.*')
            ->addSelect(DB::raw(
                '(SELECT SUM(debit) FROM journal_lines WHERE journal_entry_id = journal_entries.id AND superseded_at IS NULL) AS total_debit'
            ))
            ->addSelect(DB::raw(
                '(SELECT COUNT(*) FROM accounting_documents WHERE journal_entry_id = journal_entries.id) AS document_count'
            ))
            ->orderBy('date')
            ->orderBy('series')
            ->orderBy('number')
            ->paginate($perPage);

        $data = $entries->map(fn ($e) => [
            'id'             => $e->id,
            'fiscal_year_id' => $e->fiscal_year_id,
            'series'         => $e->series,
            'number'         => $e->number,
            'date'           => $e->date->toDateString(),
            'description'    => $e->description,
            'is_locked'      => $e->is_locked,
            'source_type'    => $e->source_type,
            'source_id'      => $e->source_id,
            'total_debit'    => (int) $e->total_debit,
            'document_count' => (int) $e->document_count,
            'is_reviewed'    => $e->isReviewed(),
            'review_status'  => $e->isReviewed() ? 'reviewed' : 'unreviewed',
            'review_note'    => $e->review_note,
            'reviewed_by'    => $e->reviewedBy ? ['id' => $e->reviewedBy->id, 'name' => $e->reviewedBy->name] : null,
            'reviewed_at'    => $e->reviewed_at?->toIso8601String(),
            'has_been_edited'=> $e->hasBeenEdited(),
            'created_by'     => $e->createdBy ? ['id' => $e->createdBy->id, 'name' => $e->createdBy->name] : null,
            'created_at'     => $e->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data'  => $data,
            'stats' => $stats,
            'meta'  => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'per_page'     => $entries->perPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }

    // POST /accounting/revision/{journalEntry}/review
    public function review(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->authorise($request);

        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $note = trim($data['note'] ?? '');

        $journalEntry->update([
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note ?: null,
        ]);

        AuditLog::record('revision_entry_reviewed', 'accounting', $journalEntry->description, $journalEntry);

        return response()->json([
            'message' => 'Journal entry marked as reviewed.',
            'data'    => [
                'is_reviewed' => true,
                'reviewed_by' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'reviewed_at' => $journalEntry->fresh()->reviewed_at->toIso8601String(),
                'review_note' => $journalEntry->review_note,
            ],
        ]);
    }

    // DELETE /accounting/revision/{journalEntry}/review
    public function unapprove(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->authorise($request);

        $journalEntry->update([
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        AuditLog::record('revision_entry_unapproved', 'accounting', $journalEntry->description, $journalEntry);

        return response()->json([
            'message' => 'Review removed.',
            'data'    => ['is_reviewed' => false],
        ]);
    }

    // POST /accounting/revision/bulk-approve
    public function bulkApprove(Request $request): JsonResponse
    {
        $this->authorise($request);

        $data = $request->validate([
            'entry_ids'   => 'required|array',
            'entry_ids.*' => 'integer|exists:journal_entries,id',
        ]);

        $ids = $data['entry_ids'];
        $now = Carbon::now();

        $approved = JournalEntry::whereIn('id', $ids)
            ->whereNull('reviewed_at')
            ->count();

        JournalEntry::whereIn('id', $ids)
            ->whereNull('reviewed_at')
            ->update([
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ]);

        AuditLog::record('revision_bulk_approved', 'accounting', $approved . ' verifikationer', null);

        return response()->json([
            'data' => ['approved' => $approved],
        ]);
    }
}
