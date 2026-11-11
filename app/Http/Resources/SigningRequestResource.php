<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signing request. Never exposes sign_url (whoever has it can sign) or the
 * callback token.
 */
class SigningRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'reference' => $this->reference,
            'comment' => $this->comment,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_demo' => $this->is_demo,
            'provider' => $this->provider,
            'provider_document_id' => $this->provider_document_id,
            'send_reminders' => $this->send_reminders,
            'send_receipt' => $this->send_receipt,
            'has_signed_pdf' => $this->signed_path !== null,
            'last_error' => $this->last_error,
            'created_by' => $this->created_by,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
            ])),
            'signers' => $this->whenLoaded('signers', fn () => $this->signers->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->signer->name,
                'email' => $s->signer->email,
                'phone_number' => $s->signer->phone_number,
                'sign_method' => $s->signer->sign_method->value,
                'role_label' => $s->role_label,
                'user_id' => $s->signer->user_id,
                'signed_at' => $s->signed_at?->toIso8601String(),
                'signed_by_name' => $s->signed_by_name,
            ])),
        ];
    }
}
