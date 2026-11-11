<?php

namespace App\Http\Controllers;

use App\Models\Content\Mailing;
use App\Models\Content\MailingCategoryOptout;
use App\Models\Content\MailingUnsubscribe;
use App\Models\Membership\Member;
use App\Support\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailingUnsubscribeController extends Controller
{
    public function show(Request $request, int $mailing, string $email): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $decodedEmail = base64_decode($email);
        $mailingModel = Mailing::find($mailing);
        $category     = $request->query('category');

        if ($category && NotificationCategory::canOptOut($category)) {
            $alreadyOptedOut = MailingCategoryOptout::hasOptedOut($decodedEmail, $category);
        } else {
            $category        = null;
            $alreadyOptedOut = MailingUnsubscribe::hasUnsubscribed($decodedEmail);
        }

        return view('mailing.unsubscribe', [
            'email'          => $decodedEmail,
            'mailing'        => $mailingModel,
            'category'       => $category,
            'alreadyOptedOut'=> $alreadyOptedOut,
            'encodedEmail'   => $email,
        ]);
    }

    public function confirm(Request $request, int $mailing, string $email): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $decodedEmail = base64_decode($email);
        $member       = Member::where('email', $decodedEmail)->first();
        $category     = $request->query('category');

        if ($category && NotificationCategory::canOptOut($category)) {
            MailingCategoryOptout::record(
                email:    $decodedEmail,
                category: $category,
                memberId: $member?->id,
            );
        } else {
            $category = null;
            MailingUnsubscribe::record(
                email:     $decodedEmail,
                mailingId: $mailing ?: null,
                memberId:  $member?->id,
            );
        }

        return view('mailing.unsubscribed', [
            'email'    => $decodedEmail,
            'category' => $category,
        ]);
    }
}
