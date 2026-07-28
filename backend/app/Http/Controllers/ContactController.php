<?php

namespace App\Http\Controllers;

use App\Http\Requests\Contact\StoreContactRequest;
use App\Mail\ContactMessageMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(StoreContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        Mail::to(config('mail.contact_address'))
            ->send(new ContactMessageMail($data['name'], $data['email'], $data['message']));

        return response()->json(['message' => "Thanks — we'll be in touch soon."]);
    }
}
