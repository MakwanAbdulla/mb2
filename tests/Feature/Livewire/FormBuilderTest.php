<?php

use App\Enums\QuestionType;
use App\Models\Form\Form;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormQuestion;
use App\Models\Form\FormSubmission;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalWaitlist;
use App\Models\Shop\Cart;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function formAdmin(): User
{
    $user = User::factory()->create();
    // createPermission is available on TestCase; call via test() helper here
    \Spatie\Permission\Models\Permission::findOrCreate('forms.manage', 'web');
    $user->givePermissionTo('forms.manage');
    return $user;
}

function emptyForm(User $user): Form
{
    return Form::create(['title' => 'Testformulär', 'created_by' => $user->id]);
}

function formWithQuestion(User $user, QuestionType $type = QuestionType::Text, array $questionAttrs = []): array
{
    $form = emptyForm($user);
    $q    = FormQuestion::create(array_merge([
        'form_id'    => $form->id,
        'type'       => $type,
        'label'      => 'Testfråga',
        'sort_order' => 0,
    ], $questionAttrs));
    return [$form, $q];
}

// ── Index ─────────────────────────────────────────────────────────────────────

test('index requires forms.manage permission', function () {
    $this->createPermission('forms.manage');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.forms.index'))
        ->assertForbidden();
});

test('index lists forms for admin', function () {
    $user = formAdmin();
    emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.index')
        ->assertOk()
        ->assertSee('Testformulär');
});

test('index delete removes form', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.index')
        ->call('delete', $form->id);

    expect(Form::count())->toBe(0);
});

// ── Create ────────────────────────────────────────────────────────────────────

test('create requires forms.manage permission', function () {
    $this->createPermission('forms.manage');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.forms.create'))
        ->assertForbidden();
});

test('create saves form and redirects to edit', function () {
    $user = formAdmin();
    $this->actingAs($user);

    Volt::test('admin.forms.create')
        ->set('formTitle', 'Mitt formulär')
        ->set('description', 'En beskrivning')
        ->call('save');

    $form = Form::first();
    expect($form)->not->toBeNull()
        ->and($form->title)->toBe('Mitt formulär')
        ->and($form->created_by)->toBe($user->id);
});

test('create validates required title', function () {
    $user = formAdmin();
    $this->actingAs($user);

    Volt::test('admin.forms.create')
        ->call('save')
        ->assertHasErrors(['formTitle']);
});

// ── Edit: mount ───────────────────────────────────────────────────────────────

test('edit loads form and questions', function () {
    $user = formAdmin();
    [$form] = formWithQuestion($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->assertOk()
        ->assertSet('formTitle', 'Testformulär');

    expect(count(Volt::test('admin.forms.edit', ['form' => $form])->get('questions')))->toBe(1);
});

// ── Edit: save ────────────────────────────────────────────────────────────────

test('save persists new text question', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'              => null,
            'type'             => QuestionType::Text->value,
            'label'            => 'Vad heter du?',
            'description'      => '',
            'is_required'      => true,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '',
            'number_max'       => '',
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [],
            'section_multi'       => false,
            'membership_type'     => '',
            'rental_item_type_id' => '',
            'options'             => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question)->not->toBeNull()
        ->and($question->label)->toBe('Vad heter du?')
        ->and($question->type)->toBe(QuestionType::Text)
        ->and($question->is_required)->toBeTrue();
});

test('save persists select question with options', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'              => null,
            'type'             => QuestionType::Select->value,
            'label'            => 'Välj ett alternativ',
            'description'      => '',
            'is_required'      => false,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '',
            'number_max'       => '',
            'product_id'       => '',
            'event_ids'        => [],
            'options'          => [
                ['_id' => null, 'text' => 'Alt 1'],
                ['_id' => null, 'text' => 'Alt 2'],
            ],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question)->not->toBeNull()
        ->and($question->type)->toBe(QuestionType::Select)
        ->and($question->options)->toHaveCount(2);
});

test('save persists number question with min max', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'              => null,
            'type'             => QuestionType::Number->value,
            'label'            => 'Hur många?',
            'description'      => '',
            'is_required'      => false,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '1',
            'number_max'       => '100',
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [],
            'section_multi'       => false,
            'membership_type'     => '',
            'rental_item_type_id' => '',
            'options'             => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question->type)->toBe(QuestionType::Number)
        ->and($question->number_min)->toBe(1.0)
        ->and($question->number_max)->toBe(100.0);
});

test('save persists product question with product_id', function () {
    $user    = formAdmin();
    $form    = emptyForm($user);
    $product = Product::factory()->create(['name' => 'T-shirt']);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'              => null,
            'type'             => QuestionType::Product->value,
            'label'            => 'Vill du beställa?',
            'description'      => '',
            'is_required'      => false,
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '',
            'number_max'       => '',
            'product_id'       => (string) $product->id,
            'event_ids'        => [],
            'options'          => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question->type)->toBe(QuestionType::Product)
        ->and($question->product_id)->toBe($product->id);
});

test('save persists section question without is_required', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'              => null,
            'type'             => QuestionType::Section->value,
            'label'            => 'Avsnitt 1',
            'description'      => 'Avsnittsbeskrivning',
            'is_required'      => true, // should be forced to false
            'scale_min'        => 1,
            'scale_max'        => 5,
            'scale_icon'       => 'star',
            'datetime_variant' => 'date',
            'number_min'       => '',
            'number_max'       => '',
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [],
            'section_multi'       => false,
            'membership_type'     => '',
            'rental_item_type_id' => '',
            'options'             => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question->type)->toBe(QuestionType::Section)
        ->and($question->is_required)->toBeFalse();
});

test('save deletes questions removed from the list', function () {
    $user = formAdmin();
    [$form] = formWithQuestion($user);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', []) // remove all
        ->call('save');

    expect(FormQuestion::where('form_id', $form->id)->count())->toBe(0);
});

// ── Form submission (fill page) ───────────────────────────────────────────────

test('fill page requires authentication', function () {
    $user = formAdmin();
    $form = emptyForm($user);

    $this->get(route('forms.fill', $form))
        ->assertStatus(401);
});

test('submitting text form saves answers', function () {
    $user = formAdmin();
    [$form, $question] = formWithQuestion($user, QuestionType::Text);
    $this->actingAs(User::factory()->create());

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", 'Anna Andersson')
        ->call('submit');

    $submission = FormSubmission::where('form_id', $form->id)->first();
    expect($submission)->not->toBeNull();
    expect($submission->answers->first()->answer_text)->toBe('Anna Andersson');
});

test('submitting required field without value shows error', function () {
    $user = formAdmin();
    [$form, $question] = formWithQuestion($user, QuestionType::Text, ['is_required' => true]);
    $this->actingAs(User::factory()->create());

    Volt::test('pages.forms.fill', ['form' => $form])
        ->call('submit')
        ->assertHasErrors(["answers.{$question->id}"]);

    expect(FormSubmission::count())->toBe(0);
});

test('submitting email field validates email format', function () {
    $user = formAdmin();
    [$form, $question] = formWithQuestion($user, QuestionType::Email, ['is_required' => true]);
    $this->actingAs(User::factory()->create());

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", 'not-an-email')
        ->call('submit')
        ->assertHasErrors(["answers.{$question->id}"]);
});

test('submitting number validates range', function () {
    $user = formAdmin();
    [$form, $question] = formWithQuestion($user, QuestionType::Number, [
        'is_required' => true,
        'number_min'  => 1,
        'number_max'  => 10,
    ]);
    $this->actingAs(User::factory()->create());

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", '999')
        ->call('submit')
        ->assertHasErrors(["answers.{$question->id}"]);
});

test('product opted-in adds variant to cart', function () {
    $user    = User::factory()->create();
    $admin   = formAdmin();
    $product = Product::factory()->create(['name' => 'T-shirt', 'price_ore' => 15000]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'option_value' => 'M']);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'    => $form->id,
        'type'       => QuestionType::Product,
        'label'      => 'T-shirt?',
        'sort_order' => 0,
        'product_id' => $product->id,
    ]);

    $this->actingAs($user);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", ['opted_in' => '1', 'variant_id' => $variant->id])
        ->call('submit');

    $cart = Cart::where('user_id', $user->id)->first();
    expect($cart)->not->toBeNull();
    expect($cart->items)->toHaveCount(1);
    expect($cart->items->first()->cartable_id)->toBe($variant->id);
});

test('product opted-out does not add to cart', function () {
    $user    = User::factory()->create();
    $admin   = formAdmin();
    $product = Product::factory()->create(['price_ore' => 15000]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'    => $form->id,
        'type'       => QuestionType::Product,
        'label'      => 'T-shirt?',
        'sort_order' => 0,
        'product_id' => $product->id,
    ]);

    $this->actingAs($user);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", ['opted_in' => '0', 'variant_id' => null])
        ->call('submit');

    expect(Cart::count())->toBe(0);
    expect(FormSubmission::count())->toBe(1);
});

test('section type skips answer saving', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    FormQuestion::create([
        'form_id'    => $form->id,
        'type'       => QuestionType::Section,
        'label'      => 'Rubrik',
        'sort_order' => 0,
    ]);
    $filler = User::factory()->create();
    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->call('submit');

    $submission = FormSubmission::where('form_id', $form->id)->first();
    expect($submission)->not->toBeNull()
        ->and($submission->answers)->toHaveCount(0);
});

// ── QuestionType enum ─────────────────────────────────────────────────────────

test('QuestionType select hasOptions returns true', function () {
    expect(QuestionType::Select->hasOptions())->toBeTrue();
});

test('QuestionType section isStructural returns true', function () {
    expect(QuestionType::Section->isStructural())->toBeTrue()
        ->and(QuestionType::Text->isStructural())->toBeFalse();
});

test('QuestionType all new types have labels', function () {
    $types = [
        QuestionType::Select,
        QuestionType::Number,
        QuestionType::Email,
        QuestionType::Section,
        QuestionType::Product,
        QuestionType::EventSelection,
        QuestionType::SectionSelection,
        QuestionType::Membership,
        QuestionType::RentalWaitlist,
    ];

    foreach ($types as $type) {
        expect($type->label())->toBeString()->not->toBeEmpty();
    }
});

// ── SectionSelection ──────────────────────────────────────────────────────────

test('save persists section_selection question with config', function () {
    $user    = formAdmin();
    $form    = emptyForm($user);
    $section = Section::factory()->create(['parent_id' => null]);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'                 => null,
            'type'                => QuestionType::SectionSelection->value,
            'label'               => 'Välj sektion',
            'description'         => '',
            'is_required'         => false,
            'scale_min'           => 1,
            'scale_max'           => 5,
            'scale_icon'          => 'star',
            'datetime_variant'    => 'date',
            'number_min'          => '',
            'number_max'          => '',
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [(string) $section->id],
            'section_multi'       => true,
            'membership_type'     => '',
            'rental_item_type_id' => '',
            'options'             => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question->type)->toBe(QuestionType::SectionSelection)
        ->and($question->section_ids)->toContain($section->id)
        ->and($question->section_multi)->toBeTrue();
});

test('submitting section_selection creates pending section memberships', function () {
    $admin   = formAdmin();
    $filler  = User::factory()->create();
    $member  = Member::factory()->create(['user_id' => $filler->id]);
    $section = Section::factory()->create(['parent_id' => null]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'       => $form->id,
        'type'          => QuestionType::SectionSelection,
        'label'         => 'Vilka sektioner?',
        'sort_order'    => 0,
        'section_multi' => true,
    ]);

    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", [(string) $section->id])
        ->call('submit');

    expect(SectionMembership::where('section_id', $section->id)
        ->where('member_id', $member->id)
        ->where('status', SectionMembership::STATUS_PENDING)
        ->exists()
    )->toBeTrue();
});

test('submitting section_selection does not duplicate existing membership', function () {
    $admin   = formAdmin();
    $filler  = User::factory()->create();
    $member  = Member::factory()->create(['user_id' => $filler->id]);
    $section = Section::factory()->create(['parent_id' => null]);

    SectionMembership::create([
        'section_id' => $section->id,
        'member_id'  => $member->id,
        'status'     => SectionMembership::STATUS_ACTIVE,
        'joined_at'  => now(),
    ]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'       => $form->id,
        'type'          => QuestionType::SectionSelection,
        'label'         => 'Sektioner',
        'sort_order'    => 0,
        'section_multi' => true,
    ]);

    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", [(string) $section->id])
        ->call('submit');

    expect(SectionMembership::where('section_id', $section->id)
        ->where('member_id', $member->id)
        ->count()
    )->toBe(1);
});

// ── RentalWaitlist ────────────────────────────────────────────────────────────

test('save persists rental_waitlist question with rental_item_type_id', function () {
    $user = formAdmin();
    $form = emptyForm($user);
    $rit  = RentalItemType::create(['name' => 'Borrmaskin', 'price_per_year' => 50000]);
    $this->actingAs($user);

    Volt::test('admin.forms.edit', ['form' => $form])
        ->set('questions', [[
            '_id'                 => null,
            'type'                => QuestionType::RentalWaitlist->value,
            'label'               => 'Intresse för borrmaskin?',
            'description'         => '',
            'is_required'         => false,
            'scale_min'           => 1,
            'scale_max'           => 5,
            'scale_icon'          => 'star',
            'datetime_variant'    => 'date',
            'number_min'          => '',
            'number_max'          => '',
            'product_id'          => '',
            'event_ids'           => [],
            'section_ids'         => [],
            'section_multi'       => false,
            'membership_type'     => '',
            'rental_item_type_id' => (string) $rit->id,
            'options'             => [],
        ]])
        ->call('save');

    $question = FormQuestion::where('form_id', $form->id)->first();
    expect($question->type)->toBe(QuestionType::RentalWaitlist)
        ->and($question->rental_item_type_id)->toBe($rit->id);
});

test('submitting rental_waitlist opted-in creates waitlist entry', function () {
    $admin  = formAdmin();
    $filler = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $filler->id]);
    $rit    = RentalItemType::create(['name' => 'Borrmaskin', 'price_per_year' => 50000]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'             => $form->id,
        'type'                => QuestionType::RentalWaitlist,
        'label'               => 'Intresse?',
        'sort_order'          => 0,
        'rental_item_type_id' => $rit->id,
    ]);

    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", '1')
        ->call('submit');

    expect(RentalWaitlist::where('rental_item_type_id', $rit->id)
        ->where('member_id', $member->id)
        ->exists()
    )->toBeTrue();

    $answer = FormAnswer::where('question_id', $question->id)->first();
    expect($answer->answer_text)->toBe('1');
});

test('submitting rental_waitlist opted-out does not create entry', function () {
    $admin  = formAdmin();
    $filler = User::factory()->create();
    Member::factory()->create(['user_id' => $filler->id]);
    $rit = RentalItemType::create(['name' => 'Borrmaskin', 'price_per_year' => 50000]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'             => $form->id,
        'type'                => QuestionType::RentalWaitlist,
        'label'               => 'Intresse?',
        'sort_order'          => 0,
        'rental_item_type_id' => $rit->id,
    ]);

    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", '0')
        ->call('submit');

    expect(RentalWaitlist::count())->toBe(0);
});

test('submitting rental_waitlist does not duplicate existing entry', function () {
    $admin  = formAdmin();
    $filler = User::factory()->create();
    $member = Member::factory()->create(['user_id' => $filler->id]);
    $rit    = RentalItemType::create(['name' => 'Borrmaskin', 'price_per_year' => 50000]);

    RentalWaitlist::create(['rental_item_type_id' => $rit->id, 'member_id' => $member->id]);

    $form     = emptyForm($admin);
    $question = FormQuestion::create([
        'form_id'             => $form->id,
        'type'                => QuestionType::RentalWaitlist,
        'label'               => 'Intresse?',
        'sort_order'          => 0,
        'rental_item_type_id' => $rit->id,
    ]);

    $this->actingAs($filler);

    Volt::test('pages.forms.fill', ['form' => $form])
        ->set("answers.{$question->id}", '1')
        ->call('submit');

    expect(RentalWaitlist::count())->toBe(1);
});
