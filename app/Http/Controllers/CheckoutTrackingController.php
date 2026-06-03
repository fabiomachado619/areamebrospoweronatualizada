<?php

namespace App\Http\Controllers;

use App\Models\CheckoutFieldEvent;
use App\Models\CheckoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutTrackingController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'step' => ['required', 'string', 'in:form_started,form_filled'],
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $session = CheckoutSession::where('session_token', $validated['session_token'])->first();

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        $step = $validated['step'];
        if ($step === CheckoutSession::STEP_FORM_FILLED && $session->step === CheckoutSession::STEP_CONVERTED) {
            return response()->json(['success' => true]);
        }

        if (in_array($session->step, [CheckoutSession::STEP_CONVERTED], true)) {
            return response()->json(['success' => true]);
        }

        $updates = ['step' => $step];
        if (! empty($validated['email'])) {
            $updates['email'] = $validated['email'];
        }
        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
        }

        if ($step === CheckoutSession::STEP_FORM_STARTED && $session->form_started_at === null) {
            $updates['form_started_at'] = now();
        }
        if ($step === CheckoutSession::STEP_FORM_FILLED) {
            if ($session->form_started_at === null) {
                $updates['form_started_at'] = now();
            }
            if ($session->form_filled_at === null) {
                $updates['form_filled_at'] = now();
            }
        }

        $country = \App\Support\CountryCatalog::normalize($validated['country_code'] ?? null);
        if ($country !== null) {
            $updates['country_code'] = $country;
        }

        $session->update($updates);

        return response()->json(['success' => true]);
    }

    public function trackCountry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $country = \App\Support\CountryCatalog::normalize($validated['country_code']);
        if ($country === null) {
            return response()->json(['success' => false, 'message' => 'Código de país inválido.'], 422);
        }

        $session = CheckoutSession::where('session_token', $validated['session_token'])->first();
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        if ($session->step !== CheckoutSession::STEP_CONVERTED) {
            $session->update(['country_code' => $country]);
        }

        return response()->json(['success' => true, 'country_code' => $country]);
    }

    public function trackField(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'field_key' => ['required', 'string', 'max:64', 'in:email,name,cpf,phone,payment_method,submit'],
            'event' => ['required', 'string', 'in:reached,completed'],
        ]);

        $session = CheckoutSession::where('session_token', $validated['session_token'])->first();

        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Sessão não encontrada.'], 404);
        }

        if ($session->step === CheckoutSession::STEP_CONVERTED) {
            return response()->json(['success' => true]);
        }

        $exists = CheckoutFieldEvent::query()
            ->where('session_token', $validated['session_token'])
            ->where('field_key', $validated['field_key'])
            ->where('event', $validated['event'])
            ->exists();

        if ($exists) {
            return response()->json(['success' => true]);
        }

        CheckoutFieldEvent::create([
            'checkout_session_id' => $session->id,
            'session_token' => $validated['session_token'],
            'tenant_id' => $session->tenant_id,
            'product_id' => $session->product_id,
            'field_key' => $validated['field_key'],
            'event' => $validated['event'],
        ]);

        return response()->json(['success' => true]);
    }
}
