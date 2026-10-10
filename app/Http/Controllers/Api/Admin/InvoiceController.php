<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Admin\Invoices\CreateInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\DeleteInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoiceRequest;
use App\Http\Requests\Api\Admin\Invoices\GetInvoicesRequest;
use App\Http\Requests\Api\Admin\Invoices\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\QueryBuilder;

#[Group(name: 'Invoices', weight: 4)]
class InvoiceController extends ApiController
{
    protected const INCLUDES = [
        'items',
        'user',
    ];

    /**
     * List Invoices
     */
    #[QueryParameter('per_page', 'How many items to show per page.', type: 'int', default: 15, example: 20)]
    #[QueryParameter('page', 'Which page to show.', type: 'int', example: 2)]
    public function index(GetInvoicesRequest $request)
    {
        // Fetch invoices with pagination
        $invoices = QueryBuilder::for(Invoice::class)
            ->allowedFilters(['id', 'currency_code', 'user_id', 'status'])
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->allowedSorts(['id', 'created_at', 'updated_at', 'currency_code'])
            ->simplePaginate(request('per_page', 15));

        // Return the invoices as a JSON response
        return InvoiceResource::collection($invoices);
    }

    /**
     * Create a new invoice
     */
    public function store(CreateInvoiceRequest $request)
    {
        // Validate and create the invoice
        $invoice = Invoice::create($request->validated());

        // Return the created invoice as a JSON response
        return new InvoiceResource($this->loadAllowedIncludes($invoice, self::INCLUDES));
    }

    /**
     * Show a specific invoice
     */
    public function show(GetInvoiceRequest $request, Invoice $invoice)
    {
        $invoice = QueryBuilder::for(Invoice::class)
            ->allowedIncludes($this->allowedIncludes(self::INCLUDES))
            ->findOrFail($invoice->id);

        // Return the invoice as a JSON response
        return new InvoiceResource($invoice);
    }

    /**
     * Update a specific invoice
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($invoice, $data) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (($data['status'] ?? null) === Invoice::STATUS_CANCELLED) {
                if (!$lockedInvoice->canBeEdited()
                    || in_array($lockedInvoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID], true)
                    || (config('settings.immutable_invoices_enabled', false) && $lockedInvoice->status !== Invoice::STATUS_DRAFT)) {
                    throw ValidationException::withMessages([
                        'status' => __('invoices.invoice_cancel_blocked'),
                    ]);
                }
            } elseif (!$lockedInvoice->canBeEdited() || (config('settings.immutable_invoices_enabled', false) && $lockedInvoice->status !== Invoice::STATUS_DRAFT)) {
                throw ValidationException::withMessages([
                    'invoice' => __('invoices.invoice_edit_blocked'),
                ]);
            }

            $lockedInvoice->update($data);
            if ($lockedInvoice->wasChanged('status') && $lockedInvoice->status === Invoice::STATUS_CANCELLED) {
                $lockedInvoice->cancelPendingServices();
            }

            return new InvoiceResource($this->loadAllowedIncludes($lockedInvoice, self::INCLUDES));
        });
    }

    /**
     * Delete a specific invoice
     */
    public function destroy(DeleteInvoiceRequest $request, Invoice $invoice)
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (!$invoice->canBeEdited() || (config('settings.immutable_invoices_enabled', false) && $invoice->status !== Invoice::STATUS_DRAFT)) {
                throw ValidationException::withMessages([
                    'invoice' => __('invoices.invoice_delete_blocked'),
                ]);
            }

            $invoice->delete();
        });

        return $this->returnNoContent();
    }
}
