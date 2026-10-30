<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ComplianceReviewController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerDraftController;
use App\Http\Controllers\GuestbookController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\WorkflowController;
use App\Models\FormDraft;
use App\Models\WorkflowInstance;
use Illuminate\Support\Facades\Route;

Route::get('/', [GuestbookController::class, 'index'])->name('guestbook.index');
Route::post('/', [GuestbookController::class, 'store'])->name('guestbook.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
});

Route::bind('customerDraft', function (string $value): FormDraft {
    // Only the user who started a draft can see it; anyone else gets a 404.
    abort_if(request()->user() === null, 404);

    return FormDraft::where('form_type', CustomerDraftController::FORM_TYPE)
        ->whereBelongsTo(request()->user())
        ->findOrFail($value);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');

    Route::get('/workflows/{workflow}', [WorkflowController::class, 'show'])
        ->whereIn('workflow', array_keys(WorkflowInstance::STATE_MACHINES))
        ->name('workflows.show');

    // Compliance officers work only from the inbox and review screens, so the customer list is closed to them too.
    Route::middleware('can:capture-customers')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerDraftController::class, 'create'])->name('customers.create');
        Route::get('/customers/{customer}/edit', [CustomerDraftController::class, 'editCustomer'])->name('customers.edit');
        Route::get('/customer-drafts/{customerDraft}/{step}', [CustomerDraftController::class, 'edit'])
            ->whereIn('step', CustomerDraftController::STEPS)
            ->name('customer-drafts.edit');
        Route::patch('/customer-drafts/{customerDraft}', [CustomerDraftController::class, 'update'])->name('customer-drafts.update');
        Route::post('/customer-drafts/{customerDraft}/submit', [CustomerDraftController::class, 'submit'])->name('customer-drafts.submit');
        Route::delete('/customer-drafts/{customerDraft}', [CustomerDraftController::class, 'destroy'])->name('customer-drafts.destroy');
    });

    Route::middleware('can:review-compliance')->group(function () {
        Route::get('/compliance/reviews', [ComplianceReviewController::class, 'index'])->name('compliance.reviews.index');
        Route::get('/compliance/reviews/{run}', [ComplianceReviewController::class, 'show'])
            ->whereNumber('run')
            ->name('compliance.reviews.show');
        Route::post('/compliance/reviews/{run}', [ComplianceReviewController::class, 'decide'])
            ->whereNumber('run')
            ->name('compliance.reviews.decide');
    });
});
