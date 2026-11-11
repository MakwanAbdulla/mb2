<?php

namespace App\Listeners;

use App\Models\Content\MailLog;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Address;

class LogSentMail
{
    /**
     * Maps Mailable class short names to mail_type slugs matching MailTemplateService::TEMPLATES.
     * Extend this as new Mailables are added.
     */
    /**
     * Sensitive types where body_html must not be stored (may contain one-time tokens).
     */
    private const SENSITIVE_TYPES = [
        'VerifyEmail',
        'ResetPassword',
        'AccountDeletionApproved',
    ];

    private const TYPE_MAP = [
        // Membership
        'OrderInvoiceMail'              => 'membership_invoice',
        'PaymentReminderMail'           => 'payment_reminder',
        'MembershipRenewalMail'         => 'membership_renewal',
        'MembershipActivated'           => 'membership_activated',
        'MembershipApplied'             => 'membership_applied',
        // Events
        'EventRegistrationMail'         => 'event_registration',
        // Bookings
        'BookingConfirmed'              => 'booking_confirmed',
        'BookingReminder'               => 'booking_reminder',
        'BookingStatusChanged'          => 'booking_status_changed',
        'RentalAssignedMail'            => 'rental_assigned',
        'RentalTerminatedMail'          => 'rental_terminated',
        'VenueApplicationDecision'      => 'venue_application_decision',
        'VenueApplicationReceived'      => 'venue_application_received',
        // Economy
        'ArInvoiceMail'                 => 'ar_invoice',
        'InvoiceMatchedMail'            => 'invoice_matched',
        'MissingReceiptMail'            => 'missing_receipt',
        // Shop
        'OrderReadyForPickupMail'       => 'shop_order_pickup',
        'OrderShippedMail'              => 'shop_order_shipped',
        'ShopOrderConfirmationMail'     => 'shop_order_confirmation',
        'ShopOrderAdminNotificationMail'=> 'shop_order_admin',
        // Surveys
        'SurveyInvitationMail'          => 'survey_invitation',
        // Account
        'AccountDeletionApproved'       => 'account_deletion_approved',
        'AccountDeletionReceived'       => 'account_deletion_received',
        'AccountDeletionRejected'       => 'account_deletion_rejected',
        // Contact
        'ContactConfirmationMail'       => 'contact_confirmation',
        // Mailings (bulk)
        'MailingMail'                   => 'mailing',
    ];

    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        $toAddresses = $message->getTo();
        if (empty($toAddresses)) {
            return;
        }

        // Resolve mail_type and mailable_class from the sent Mailable (if available)
        $mailableClass = null;
        $mailType      = null;
        $memberId      = null;
        $userId        = null;
        $shortName     = null;

        /** @var Mailable|null $mailable */
        $mailable = $event->data['mailable'] ?? null;
        if ($mailable) {
            $mailableClass = get_class($mailable);
            $shortName     = class_basename($mailable);
            $mailType      = self::TYPE_MAP[$shortName] ?? null;

            // Try to extract member/user from well-known Mailable properties
            $memberId = $mailable->member?->id ?? null;
            $userId   = $mailable->member?->user_id ?? null;

            if (! $userId && isset($mailable->order)) {
                $userId = $mailable->order->user_id ?? null;
                if (! $memberId && $userId) {
                    $memberId = Member::where('user_id', $userId)->value('id');
                }
            }
        }

        // Create one log entry per recipient
        foreach ($toAddresses as $address) {
            /** @var Address $address */
            $email = $address->getAddress();
            $name  = $address->getName() ?: null;

            // Attempt to resolve user/member from email if not already known
            $resolvedUserId   = $userId;
            $resolvedMemberId = $memberId;

            if (! $resolvedUserId) {
                $user = User::where('email', $email)->first();
                $resolvedUserId   = $user?->id;
                $resolvedMemberId = $resolvedMemberId ?? ($user ? Member::where('user_id', $user->id)->value('id') : null);
            }

            if (! $resolvedMemberId) {
                $resolvedMemberId = Member::where('email', $email)->value('id');
            }

            $isSensitive = $shortName && in_array($shortName, self::SENSITIVE_TYPES, true);

            MailLog::create([
                'mail_type'       => $mailType,
                'mailable_class'  => $mailableClass,
                'recipient_email' => $email,
                'recipient_name'  => $name,
                'subject'         => $message->getSubject() ?? '(ingen ämnesrad)',
                'body_html'       => $isSensitive ? null : $message->getHtmlBody(),
                'member_id'       => $resolvedMemberId,
                'user_id'         => $resolvedUserId,
                'mailing_id'      => $mailable?->mailing?->id ?? null,
                'delivery_status' => MailLog::STATUS_SENT,
                'tracking_token'  => $mailable?->trackingToken ?? null,
            ]);
        }
    }
}
