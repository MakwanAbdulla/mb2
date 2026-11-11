<?php

use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use App\Models\Form\Form;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormSubmission;
use App\Models\Organization\SectionMembership;
use App\Models\Rental\RentalWaitlist;
use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Formulär');

new class extends Component {
    public Form $form;
    public bool $done = false;

    // answers: question_id => value or array (checkbox) or assoc (product)
    public array $answers = [];

    public function mount(Form $form): void
    {
        abort_unless(Auth::check(), 401);

        $this->form = $form->load('questions.options', 'questions.product.variants', 'questions.rentalItemType');
    }

    public function submit(): void
    {
        $rules = $this->buildRules();
        if (! empty($rules)) {
            $this->validate($rules);
        }

        $submission = FormSubmission::create([
            'form_id'    => $this->form->id,
            'user_id'    => Auth::id(),
            'ip_address' => request()->ip(),
        ]);

        foreach ($this->form->questions as $question) {
            $type   = $question->type;
            $answer = $this->answers[$question->id] ?? null;

            if ($type->isStructural()) {
                continue;
            }

            if ($type === QuestionType::Checkbox && is_array($answer)) {
                foreach (array_filter($answer) as $optionId) {
                    FormAnswer::create([
                        'submission_id' => $submission->id,
                        'question_id'   => $question->id,
                        'option_id'     => $optionId,
                    ]);
                }
                continue;
            }

            if ($type === QuestionType::Radio || $type === QuestionType::Select) {
                FormAnswer::create([
                    'submission_id' => $submission->id,
                    'question_id'   => $question->id,
                    'option_id'     => $answer ?: null,
                ]);
                continue;
            }

            if ($type->isProduct()) {
                $this->processProductAnswer($submission, $question, $answer ?? []);
                continue;
            }

            if ($type->isEventSelection()) {
                $this->processEventAnswer($submission, $question, $answer);
                continue;
            }

            if ($type->isSectionSelection()) {
                $this->processSectionAnswer($submission, $question, $answer);
                continue;
            }

            if ($type->isMembership()) {
                $this->processMembershipAnswer($submission, $question, $answer);
                continue;
            }

            if ($type->isRentalWaitlist()) {
                $this->processRentalWaitlistAnswer($submission, $question, $answer);
                continue;
            }

            FormAnswer::create([
                'submission_id' => $submission->id,
                'question_id'   => $question->id,
                'answer_text'   => is_string($answer) ? $answer : null,
            ]);
        }

        AuditLog::record(
            action: 'form_submitted',
            area: 'forms',
            subject: $this->form->title,
        );

        $this->done = true;
    }

    private function processProductAnswer(FormSubmission $submission, $question, array $answer): void
    {
        $optedIn   = ($answer['opted_in'] ?? '0') === '1';
        $variantId = $answer['variant_id'] ?? null;
        $product   = $question->product;

        if (! $product) {
            return;
        }

        $variant = $variantId
            ? ProductVariant::find($variantId)
            : $product->defaultVariant();

        FormAnswer::create([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
            'answer_text'   => json_encode([
                'opted_in'     => $optedIn,
                'variant_id'   => $variant?->id,
                'variant_label' => $variant?->option_value ?: $product->name,
            ]),
        ]);

        if ($optedIn && $variant) {
            $cart = Cart::firstOrCreate(
                ['user_id' => Auth::id(), 'order_id' => null],
                [
                    'user_id'       => Auth::id(),
                    'session_token' => \Illuminate\Support\Str::uuid(),
                    'expires_at'    => now()->addHours(24),
                ],
            );

            $existing = $cart->items()
                ->where('cartable_type', ProductVariant::class)
                ->where('cartable_id', $variant->id)
                ->first();

            if ($existing) {
                $existing->increment('quantity');
            } else {
                CartItem::create([
                    'cart_id'        => $cart->id,
                    'cartable_type'  => ProductVariant::class,
                    'cartable_id'    => $variant->id,
                    'quantity'       => 1,
                    'unit_price_ore' => $product->priceFor(Auth::user()->isMember()),
                ]);
            }
        }
    }

    private function processEventAnswer(FormSubmission $submission, $question, ?string $eventId): void
    {
        FormAnswer::create([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
            'answer_text'   => $eventId,
        ]);

        if (! $eventId) {
            return;
        }

        $event = Event::find($eventId);
        if (! $event || ! $event->isPublished()) {
            return;
        }

        $alreadyRegistered = EventRegistration::where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->whereNotIn('status', [EventRegistration::STATUS_CANCELLED])
            ->exists();

        if ($alreadyRegistered) {
            return;
        }

        $tier = $event->priceTiers()->orderBy('price_ore')->first();

        EventRegistration::create([
            'event_id'       => $event->id,
            'user_id'        => Auth::id(),
            'price_tier_id'  => $tier?->id,
            'status'         => $tier && $tier->price_ore > 0
                ? EventRegistration::STATUS_PENDING_PAYMENT
                : EventRegistration::STATUS_CONFIRMED,
            'check_in_token' => \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function processSectionAnswer(FormSubmission $submission, $question, mixed $answer): void
    {
        $selected = $question->section_multi
            ? (is_array($answer) ? array_filter($answer) : [])
            : ($answer ? [$answer] : []);

        FormAnswer::create([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
            'answer_text'   => json_encode(array_values(array_map('intval', $selected))),
        ]);

        $member = Auth::user()->member;
        if (! $member) {
            return;
        }

        foreach ($selected as $sectionId) {
            $already = SectionMembership::where('section_id', $sectionId)
                ->where('member_id', $member->id)
                ->whereNull('left_at')
                ->exists();

            if (! $already) {
                SectionMembership::create([
                    'section_id' => (int) $sectionId,
                    'member_id'  => $member->id,
                    'status'     => SectionMembership::STATUS_PENDING,
                    'joined_at'  => now()->toDateString(),
                ]);
            }
        }
    }

    private function processMembershipAnswer(FormSubmission $submission, $question, mixed $answer): void
    {
        $optedIn = $answer === '1' || $answer === 1 || $answer === true;

        FormAnswer::create([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
            'answer_text'   => $optedIn ? '1' : '0',
        ]);

        if (! $optedIn) {
            return;
        }

        $membershipType = $question->membership_type ?? 'standard';

        // Find a product representing this membership type to add to cart.
        // We look for a product whose name matches the membership_type, or fall back
        // to the first membership-category product. This is a best-effort approach.
        $product = Product::where('name', 'like', "%{$membershipType}%")->first();

        if (! $product) {
            return;
        }

        $variant = $product->defaultVariant();
        if (! $variant) {
            return;
        }

        $cart = Cart::firstOrCreate(
            ['user_id' => Auth::id(), 'order_id' => null],
            [
                'user_id'       => Auth::id(),
                'session_token' => \Illuminate\Support\Str::uuid(),
                'expires_at'    => now()->addHours(24),
            ],
        );

        $existing = $cart->items()
            ->where('cartable_type', ProductVariant::class)
            ->where('cartable_id', $variant->id)
            ->first();

        if (! $existing) {
            CartItem::create([
                'cart_id'        => $cart->id,
                'cartable_type'  => ProductVariant::class,
                'cartable_id'    => $variant->id,
                'quantity'       => 1,
                'unit_price_ore' => $product->priceFor(Auth::user()->isMember()),
            ]);
        }
    }

    private function processRentalWaitlistAnswer(FormSubmission $submission, $question, mixed $answer): void
    {
        $optedIn = $answer === '1' || $answer === 1 || $answer === true;

        FormAnswer::create([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
            'answer_text'   => $optedIn ? '1' : '0',
        ]);

        if (! $optedIn || ! $question->rental_item_type_id) {
            return;
        }

        $member = Auth::user()->member;
        if (! $member) {
            return;
        }

        $already = RentalWaitlist::where('rental_item_type_id', $question->rental_item_type_id)
            ->where('member_id', $member->id)
            ->exists();

        if (! $already) {
            RentalWaitlist::create([
                'rental_item_type_id' => $question->rental_item_type_id,
                'member_id'           => $member->id,
            ]);
        }
    }

    private function buildRules(): array
    {
        $rules = [];

        foreach ($this->form->questions as $question) {
            $type = $question->type;
            $key  = "answers.{$question->id}";

            if ($type->isStructural()) {
                continue;
            }

            if ($type->isProduct()) {
                if ($question->is_required) {
                    $rules["{$key}.opted_in"] = 'required|in:0,1';
                }
                $rules["{$key}.variant_id"] = 'nullable|integer|exists:product_variants,id';
                continue;
            }

            if ($type->isEventSelection()) {
                $rules[$key] = $question->is_required ? 'required|integer|exists:events,id' : 'nullable|integer|exists:events,id';
                continue;
            }

            if ($type->isSectionSelection()) {
                if ($question->section_multi) {
                    $rules[$key]        = $question->is_required ? 'required|array|min:1' : 'nullable|array';
                    $rules["{$key}.*"]  = 'integer|exists:sections,id';
                } else {
                    $rules[$key] = $question->is_required ? 'required|integer|exists:sections,id' : 'nullable|integer|exists:sections,id';
                }
                continue;
            }

            if ($type->isMembership() || $type->isRentalWaitlist()) {
                $rules[$key] = $question->is_required ? 'required|in:0,1' : 'nullable|in:0,1';
                continue;
            }

            if ($type === QuestionType::Radio || $type === QuestionType::Select) {
                $rules[$key] = $question->is_required ? 'required|integer|exists:form_question_options,id' : 'nullable|integer|exists:form_question_options,id';
                continue;
            }

            if ($type === QuestionType::Checkbox) {
                $rules[$key]   = 'nullable|array';
                $rules["{$key}.*"] = 'integer|exists:form_question_options,id';
                continue;
            }

            if ($type === QuestionType::Email) {
                $rules[$key] = $question->is_required ? 'required|email:rfc,dns' : 'nullable|email';
                continue;
            }

            if ($type === QuestionType::Number) {
                $numberRule = $question->is_required ? 'required|numeric' : 'nullable|numeric';
                if ($question->number_min !== null) {
                    $numberRule .= "|min:{$question->number_min}";
                }
                if ($question->number_max !== null) {
                    $numberRule .= "|max:{$question->number_max}";
                }
                $rules[$key] = $numberRule;
                continue;
            }

            $rules[$key] = $question->is_required ? 'required|string|max:5000' : 'nullable|string|max:5000';
        }

        return $rules;
    }
};

?>

<div class="mx-auto max-w-2xl py-6">
    <h1 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">{{ $form->title }}</h1>

    @if($done)
        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-800/30 dark:bg-green-900/20">
            <x-bootstrap-icon name="check-circle" class="mx-auto mb-3 size-10 text-green-600 dark:text-green-400" />
            <p class="text-base font-medium text-green-800 dark:text-green-300">Tack! Ditt svar har registrerats.</p>
        </div>
    @else
        <form wire:submit="submit" class="mt-6 space-y-6">
            @include('livewire.pages.forms._form-display', [
                'form'    => $form,
                'preview' => false,
                'answers' => $answers,
            ])

            <div class="pt-2">
                <button type="submit" class="btn btn-primary">Skicka in</button>
            </div>
        </form>
    @endif
</div>
