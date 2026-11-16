<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseCredits;
use App\Http\Requests\CreditPurchaseRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CreditController extends Controller
{
    public function store(CreditPurchaseRequest $request, PurchaseCredits $purchase): RedirectResponse
    {
        $purchase->handle($request->user(), $request->string('request_id')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => '$5 in test credits added. No payment was taken.']);

        return to_route('dashboard');
    }
}
