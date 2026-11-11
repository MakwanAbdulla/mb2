<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SigningStatus;
use App\Enums\SignMethod;
use App\Enums\WikiReadAccess;
use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\Invoice;
use App\Models\Content\Mailing;
use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Organization\Section;
use App\Models\Poll;
use App\Models\Shop\Discount;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'event' => [
                'brands' => [Event::BRAND_ARMAGEDON, Event::BRAND_BSK],
                'statuses' => [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED, Event::STATUS_CANCELLED, Event::STATUS_COMPLETED],
                'visibilities' => [Event::VISIBILITY_PUBLIC, Event::VISIBILITY_MEMBERS_ONLY, Event::VISIBILITY_SECTION_ONLY, Event::VISIBILITY_INVITE_ONLY],
                'locations' => [Event::LOCATION_ROOM, Event::LOCATION_VENUE_APPLICATION, Event::LOCATION_EXTERNAL, Event::LOCATION_DIGITAL],
                'registration' => [Event::REGISTRATION_STANDARD, Event::REGISTRATION_DROPIN, Event::REGISTRATION_BOTH],
                'waitlist' => [Event::WAITLIST_NONE, Event::WAITLIST_MANUAL],
                'admission' => [Event::ADMISSION_ON_PAYMENT, Event::ADMISSION_AUTOMATIC, Event::ADMISSION_MANUAL],
                'organizer_roles' => [Event::ORGANIZER_ADMIN, Event::ORGANIZER_HELPER],
                'revenue_models' => [Event::REVENUE_NONE, Event::REVENUE_PERCENTAGE, Event::REVENUE_PER_PARTICIPANT, Event::REVENUE_PER_EVENT, Event::REVENUE_PER_OCCURRENCE],
            ],
            'event_registration' => [
                'statuses' => [
                    EventRegistration::STATUS_PENDING_PAYMENT,
                    EventRegistration::STATUS_PENDING_APPROVAL,
                    EventRegistration::STATUS_CONFIRMED,
                    EventRegistration::STATUS_WAITLISTED,
                    EventRegistration::STATUS_CANCELLED,
                    EventRegistration::STATUS_TRANSFERRED,
                ],
            ],
            'meeting' => [
                'types' => array_keys(MeetingTemplate::TYPES),
                'statuses' => array_keys(Meeting::STATUSES),
            ],
            'meeting_agenda_item' => [
                'item_types' => ['standard', 'vote', 'election', 'info'],
            ],
            'poll' => [
                'types' => array_keys(Poll::TYPES),
                'statuses' => array_keys(Poll::STATUSES),
                'results' => array_keys(Poll::RESULTS),
            ],
            'submission' => [
                'types' => array_keys(Submission::TYPES),
                'statuses' => array_keys(Submission::STATUSES),
            ],
            'signing_request' => [
                'statuses' => array_map(fn (SigningStatus $s) => $s->value, SigningStatus::cases()),
                'sign_methods' => array_map(fn (SignMethod $m) => $m->value, SignMethod::cases()),
            ],
            'wiki_space' => [
                'read_access' => array_map(fn (WikiReadAccess $a) => $a->value, WikiReadAccess::cases()),
            ],
            'mailing' => [
                'statuses' => [Mailing::STATUS_DRAFT, Mailing::STATUS_SCHEDULED, Mailing::STATUS_SENDING, Mailing::STATUS_SENT, Mailing::STATUS_CANCELLED],
            ],
            'section' => [
                'visibilities' => [Section::VISIBILITY_PUBLIC, Section::VISIBILITY_MEMBERS, Section::VISIBILITY_PRIVATE, Section::VISIBILITY_SECRET],
                'membership_types' => [Section::MEMBERSHIP_MANDATORY, Section::MEMBERSHIP_OPEN, Section::MEMBERSHIP_APPLICATION, Section::MEMBERSHIP_CLOSED, Section::MEMBERSHIP_ADMIN_ONLY],
            ],
            'product' => [
                'types' => [Product::TYPE_PHYSICAL, Product::TYPE_DIGITAL],
                'statuses' => [Product::STATUS_DRAFT, Product::STATUS_PUBLISHED, Product::STATUS_ARCHIVED],
            ],
            'order' => [
                'statuses' => [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_REFUNDED, Order::STATUS_CANCELLED],
                'payment_methods' => [Order::METHOD_STRIPE, Order::METHOD_SWISH_HANDEL, Order::METHOD_SWISH, Order::METHOD_BANKGIRO, Order::METHOD_MANUAL, Order::METHOD_FREE],
            ],
            'discount' => [
                'types' => [Discount::TYPE_PERCENTAGE, Discount::TYPE_FIXED],
                'applies_to' => [Discount::APPLIES_MEMBERSHIP, Discount::APPLIES_EVENT, Discount::APPLIES_SHOP, Discount::APPLIES_ALL],
                'conditions' => [Discount::CONDITION_FIRST_MEMBERSHIP],
            ],
            'invoice' => [
                'types' => [Invoice::TYPE_PAYABLE, Invoice::TYPE_RECEIVABLE],
                'statuses' => [Invoice::STATUS_DRAFT, Invoice::STATUS_PENDING_APPROVAL, Invoice::STATUS_APPROVED, Invoice::STATUS_POSTED, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED],
                'payment_methods' => [Invoice::PM_BANKGIRO, Invoice::PM_PLUSGIRO, Invoice::PM_BANK_TRANSFER, Invoice::PM_SWISH],
            ],
            'account' => [
                'types' => [Account::TYPE_ASSET, Account::TYPE_LIABILITY, Account::TYPE_EQUITY, Account::TYPE_INCOME, Account::TYPE_EXPENSE],
            ],
        ]);
    }
}
