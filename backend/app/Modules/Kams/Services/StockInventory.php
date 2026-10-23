<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDailySettlement;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsRevenueShare;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockAssignmentItem;
use App\Modules\Kams\Models\KamsStockAdjustment;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockMovement;
use App\Modules\Kams\Models\KamsStockReceipt;
use App\Modules\Kams\Models\KamsStockReceiptItem;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockReturnItem;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockSaleItem;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Models\KamsStockTransferItem;
use App\Modules\Kams\Models\KamsTreasuryAccount;
use App\Modules\Kams\Models\KamsTreasuryEntry;
use App\Modules\Kams\Support\KamsDocumentRefs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockInventory
{
    public function __construct(private readonly SavingsLedger $savings) {}

    public function ensureOfficeDepots(int $companyId): void
    {
        Office::query()->where('company_id', $companyId)->where('is_active', true)->each(function (Office $office) {
            $this->officeDepot($office);
        });
    }

    public function officeDepot(Office $office): KamsDepot
    {
        return KamsDepot::query()->firstOrCreate(
            ['company_id' => $office->company_id, 'office_id' => $office->id, 'kind' => 'depot'],
            [
                'name' => 'Dépôt '.$office->name,
                'code' => 'DEP-'.$office->code,
                'status' => 'active',
                'province_id' => $office->province_id,
            ]
        );
    }

    public function sellerLocation(KamsMember $member): KamsDepot
    {
        abort_unless($member->office_id, 422, __('The agent must belong to an office.'));

        return KamsDepot::query()->firstOrCreate(
            ['company_id' => $member->company_id, 'member_id' => $member->id, 'kind' => 'seller'],
            [
                'name' => 'Stock '.$member->full_name,
                'code' => 'VEN-'.$member->member_code,
                'office_id' => $member->office_id,
                'status' => 'active',
            ]
        );
    }

    /**
     * @param  list<array{product_id:int, quantity:int, unit_cost:float|int|string}>  $items
     */
    public function createReceipt(KamsDepot $warehouse, array $items, ?string $supplier, ?int $userId, ?string $notes = null): KamsStockReceipt
    {
        abort_unless(in_array($warehouse->kind, ['warehouse', 'depot'], true), 422, __('Receptions are recorded at a warehouse or office depot.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));

        return DB::transaction(function () use ($warehouse, $items, $supplier, $userId, $notes) {
            $receipt = KamsStockReceipt::query()->create([
                'company_id' => $warehouse->company_id,
                'reference' => 'RC-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'warehouse_id' => $warehouse->id,
                'supplier_name' => $supplier,
                'status' => 'pending',
                'notes' => $notes,
                'created_by' => $userId,
            ]);
            $total = 0.0;
            foreach ($items as $item) {
                $cost = (float) $item['unit_cost'];
                $qty = (int) $item['quantity'];
                abort_unless($qty > 0 && $cost >= 0, 422, __('Invalid receipt line.'));
                KamsStockReceiptItem::query()->create([
                    'receipt_id' => $receipt->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                ]);
                $total += $qty * $cost;
            }
            $receipt->update(['total_cost' => round($total, 2)]);

            return $receipt->load(['warehouse', 'items.product']);
        });
    }

    public function validateReceipt(KamsStockReceipt $receipt, int $userId): KamsStockReceipt
    {
        abort_unless(in_array($receipt->status, ['draft', 'pending'], true), 422, __('This reception cannot be validated.'));

        return DB::transaction(function () use ($receipt, $userId) {
            $receipt = KamsStockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $warehouse = $receipt->warehouse;
            foreach ($receipt->items()->with('product')->get() as $line) {
                $this->applyWeightedAverage($line->product, $line->quantity, (float) $line->unit_cost);
                $this->changeBalance($warehouse, $line->product, $line->quantity, 'available');
                $this->recordMovement($line->product, $line->quantity, 'receipt', $userId, null, $warehouse->id, $receipt, null, $receipt->notes, 'available', $userId, (float) $line->unit_cost);
                $this->syncProductStock($line->product);
            }
            $receipt->update([
                'status' => 'received',
                'validated_by' => $userId,
                'validated_at' => now(),
            ]);

            return $receipt->fresh(['warehouse', 'items.product']);
        });
    }

    public function cancelReceipt(KamsStockReceipt $receipt): KamsStockReceipt
    {
        abort_unless(in_array($receipt->status, ['draft', 'pending'], true), 422, __('A validated reception cannot be cancelled.'));
        $receipt->update(['status' => 'cancelled']);

        return $receipt->fresh();
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function transfer(
        KamsDepot $from,
        KamsDepot $to,
        array $items,
        ?int $userId = null,
        ?string $notes = null,
        bool $autoReceive = false,
    ): KamsStockTransfer {
        abort_unless($from->id !== $to->id, 422, __('Choose two different locations.'));
        abort_unless($from->company_id === $to->company_id, 422, __('Locations must belong to the same company.'));
        abort_unless($from->isWarehouse(), 422, __('Transfers start from a regional warehouse.'));
        abort_unless(in_array($to->kind, ['depot', 'warehouse'], true), 422, __('Transfers must arrive in a warehouse or office depot.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));

        $aggregated = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            abort_unless($quantity > 0, 422, __('Quantity must be greater than zero.'));
            $aggregated[$productId] = ($aggregated[$productId] ?? 0) + $quantity;
        }

        return DB::transaction(function () use ($from, $to, $aggregated, $userId, $notes, $autoReceive) {
            $transfer = KamsStockTransfer::query()->create([
                'company_id' => $from->company_id,
                'reference' => 'TR-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'from_depot_id' => $from->id,
                'to_depot_id' => $to->id,
                'status' => $autoReceive ? 'received' : 'in_transit',
                'notes' => $notes,
                'created_by' => $userId,
                'dispatched_by' => $userId,
                'transferred_at' => now(),
                'received_at' => $autoReceive ? now() : null,
                'received_by' => $autoReceive ? $userId : null,
            ]);

            foreach ($aggregated as $productId => $quantity) {
                $product = KamsProduct::query()->where('company_id', $from->company_id)->findOrFail($productId);
                $available = $this->quantityAt($from, $product, 'available');
                abort_unless($available >= $quantity, 422, __('Insufficient stock for :product at :location. Available: :available, requested: :requested.', [
                    'product' => $product->name,
                    'location' => $from->name,
                    'available' => $available,
                    'requested' => $quantity,
                ]));
                $cost = $this->unitCost($product);
                $this->changeBalance($from, $product, -$quantity, 'available');
                if ($autoReceive) {
                    $this->changeBalance($to, $product, $quantity, 'available');
                } else {
                    $this->changeBalance($from, $product, $quantity, 'in_transit');
                }
                KamsStockTransferItem::query()->create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost' => $cost,
                ]);
                // One ledger row per transfer: dispatch+auto-receive used to create transfer + transfer_receive duplicates.
                $this->recordMovement(
                    $product,
                    $quantity,
                    'transfer',
                    $userId,
                    $from->id,
                    $to->id,
                    $transfer,
                    null,
                    $notes,
                    $autoReceive ? 'available' : 'in_transit',
                    $userId,
                    $cost,
                );
                $this->syncProductStock($product);
            }

            return $transfer->load([
                'fromDepot',
                'toDepot.office',
                'items.product',
                'createdBy:id,name',
                'dispatchedBy:id,name',
                'receivedBy:id,name',
            ]);
        });
    }

    /**
     * @param  array<int, int>  $receivedByItemId  transfer item id => quantity actually received
     */
    public function receiveTransfer(KamsStockTransfer $transfer, int $userId, array $receivedByItemId, bool $confirmLoss = false): KamsStockTransfer
    {
        abort_unless($transfer->status === 'in_transit', 422, __('This transfer is not in transit.'));

        return DB::transaction(function () use ($transfer, $userId, $receivedByItemId, $confirmLoss) {
            $transfer = KamsStockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            abort_unless($transfer->status === 'in_transit', 422, __('This transfer is not in transit.'));
            $from = $transfer->fromDepot;
            $to = $transfer->toDepot;
            $lines = $transfer->items()->with('product')->get();
            abort_unless($lines->isNotEmpty(), 422, __('Add at least one product.'));

            $hasLoss = false;
            foreach ($lines as $line) {
                abort_unless(array_key_exists($line->id, $receivedByItemId), 422, __('Enter the quantity received for each product.'));
                $received = (int) $receivedByItemId[$line->id];
                abort_unless($received >= 0, 422, __('Quantity must be greater than zero.'));
                abort_unless($received <= (int) $line->quantity, 422, __('The quantity received cannot exceed the quantity transferred.'));
                if ($received < (int) $line->quantity) {
                    $hasLoss = true;
                }
            }
            abort_unless(! $hasLoss || $confirmLoss, 422, __('Confirm that the missing quantity is lost.'));

            foreach ($lines as $line) {
                $received = (int) $receivedByItemId[$line->id];
                $lost = (int) $line->quantity - $received;
                $this->changeBalance($from, $line->product, -(int) $line->quantity, 'in_transit');
                if ($received > 0) {
                    $this->changeBalance($to, $line->product, $received, 'available');
                }
                if ($lost > 0) {
                    $this->recordMovement(
                        $line->product,
                        $lost,
                        'loss',
                        $userId,
                        $from->id,
                        null,
                        $transfer,
                        null,
                        __('Missing quantity on transfer :reference marked as lost.', ['reference' => $transfer->reference]),
                        'lost',
                        $userId,
                        (float) $line->unit_cost,
                    );
                }
                $line->update([
                    'quantity_received' => $received,
                    'quantity_lost' => $lost,
                ]);
                $this->syncProductStock($line->product);
            }
            $transfer->update([
                'status' => 'received',
                'received_at' => now(),
                'received_by' => $userId,
            ]);

            return $transfer->fresh([
                'fromDepot',
                'toDepot.office',
                'items.product',
                'createdBy:id,name',
                'dispatchedBy:id,name',
                'receivedBy:id,name',
            ]);
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function assign(KamsMember $member, array $items, ?int $userId = null, ?string $notes = null): KamsStockAssignment
    {
        abort_unless($member->office_id, 422, __('The agent must belong to an office.'));
        abort_unless($member->status === 'active', 422, __('Only active agents can receive stock.'));
        abort_unless($member->canReceiveStockAssignment(), 422, __('Platform administrators cannot receive stock assignments.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));
        abort_unless(
            ! KamsStockAssignment::query()
                ->where('member_id', $member->id)
                ->where('status', 'open')
                ->exists(),
            422,
            __('This agent already has an open assignment.'),
        );

        $office = Office::query()->findOrFail($member->office_id);
        $depot = $this->officeDepot($office);
        $seller = $this->sellerLocation($member);

        return DB::transaction(function () use ($member, $items, $userId, $notes, $office, $depot, $seller) {
            $assignment = KamsStockAssignment::query()->create([
                'company_id' => $member->company_id,
                'reference' => 'AF-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'office_id' => $office->id,
                'depot_id' => $depot->id,
                'member_id' => $member->id,
                'status' => 'open',
                'assigned_at' => now(),
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($items as $item) {
                $product = KamsProduct::query()->where('company_id', $member->company_id)->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                abort_unless($quantity > 0, 422, __('Quantity must be greater than zero.'));
                $cost = $this->unitCost($product);
                $this->changeBalance($depot, $product, -$quantity, 'available');
                $this->changeBalance($seller, $product, $quantity, 'available');
                KamsStockAssignmentItem::query()->create([
                    'assignment_id' => $assignment->id,
                    'product_id' => $product->id,
                    'quantity_out' => $quantity,
                    'unit_price' => $product->salePrice((int) $office->id),
                    'unit_cost' => $cost > 0 ? $cost : $product->purchasePrice(),
                ]);
                $this->recordMovement($product, $quantity, 'assignment', $userId, $depot->id, $seller->id, $assignment, $member->id, $notes, 'available', $userId, $cost > 0 ? $cost : $product->purchasePrice());
                $this->syncProductStock($product);
            }

            return $assignment->load(['member', 'office', 'depot', 'items.product']);
        });
    }

    /**
     * Delete an open or closed assignment and return remaining agent stock to the office depot.
     * Settled assignments must be removed via the day report delete flow first.
     */
    public function deleteAssignment(KamsStockAssignment $assignment, ?int $userId = null): void
    {
        abort_if(
            $assignment->status === 'settled',
            422,
            __('Cette affectation est déjà comptabilisée. Supprimez d\'abord le rapport de la journée.'),
        );
        abort_unless(
            in_array($assignment->status, ['open', 'closed'], true),
            422,
            __('Only open or closed assignments can be deleted.'),
        );

        DB::transaction(function () use ($assignment, $userId) {
            $assignment = KamsStockAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $assignment->status === 'settled',
                422,
                __('Cette affectation est déjà comptabilisée. Supprimez d\'abord le rapport de la journée.'),
            );
            abort_unless(
                in_array($assignment->status, ['open', 'closed'], true),
                422,
                __('Only open or closed assignments can be deleted.'),
            );

            $assignment->load(['items.product', 'member']);
            $member = $assignment->member;
            abort_unless($member, 422, __('The assignment has no agent.'));

            $depot = KamsDepot::query()->findOrFail($assignment->depot_id);
            $seller = $this->sellerLocation($member);
            $note = __('Deletion of assignment :ref', ['ref' => $assignment->reference]);

            foreach ($assignment->items as $line) {
                if (! $line->product) {
                    continue;
                }
                // Open: full qty still with seller. Closed: returned already at depot; sold+lost remain with seller.
                $onSeller = $assignment->status === 'open'
                    ? (int) $line->quantity_out
                    : max(0, (int) $line->quantity_out - (int) $line->quantity_returned);
                if ($onSeller <= 0) {
                    continue;
                }
                $this->changeBalance($seller, $line->product, -$onSeller, 'available');
                $this->changeBalance($depot, $line->product, $onSeller, 'available');
                $this->recordMovement(
                    $line->product,
                    $onSeller,
                    'return',
                    $userId,
                    $seller->id,
                    $depot->id,
                    $assignment,
                    $member->id,
                    $note,
                    'available',
                    $userId,
                    (float) $line->unit_cost,
                );
                $this->syncProductStock($line->product);
            }

            KamsStockMovement::query()
                ->where('reference_type', KamsStockAssignment::class)
                ->where('reference_id', $assignment->id)
                ->delete();

            KamsStockAssignmentItem::query()->where('assignment_id', $assignment->id)->delete();
            $assignment->delete();
        });
    }

    /**
     * Add products/quantities to an open assignment, optionally collecting prepaid cash from prior sales.
     *
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function addToAssignment(
        KamsStockAssignment $assignment,
        array $items,
        float $collectedAmount = 0,
        ?int $userId = null,
        ?string $notes = null,
    ): KamsStockAssignment {
        abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));
        abort_unless($collectedAmount >= 0, 422, __('Collected amount must be zero or greater.'));

        return DB::transaction(function () use ($assignment, $items, $collectedAmount, $userId, $notes) {
            $assignment = KamsStockAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
            $assignment->load(['member', 'items']);
            $member = $assignment->member;
            abort_unless($member, 422, __('The assignment has no agent.'));
            abort_unless($member->status === 'active', 422, __('Only active agents can receive stock.'));

            $depot = KamsDepot::query()->findOrFail($assignment->depot_id);
            $seller = $this->sellerLocation($member);
            $existingByProduct = $assignment->items->keyBy('product_id');
            $movementNote = $notes ?: __('Top-up for assignment :ref', ['ref' => $assignment->reference]);

            foreach ($items as $item) {
                $product = KamsProduct::query()->where('company_id', $assignment->company_id)->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                abort_unless($quantity > 0, 422, __('Quantity must be greater than zero.'));
                $cost = $this->unitCost($product);
                $this->changeBalance($depot, $product, -$quantity, 'available');
                $this->changeBalance($seller, $product, $quantity, 'available');

                $existing = $existingByProduct->get($product->id);
                if ($existing) {
                    $existing->update(['quantity_out' => (int) $existing->quantity_out + $quantity]);
                } else {
                    $created = KamsStockAssignmentItem::query()->create([
                        'assignment_id' => $assignment->id,
                        'product_id' => $product->id,
                        'quantity_out' => $quantity,
                        'unit_price' => $product->salePrice((int) $assignment->office_id),
                        'unit_cost' => $cost > 0 ? $cost : $product->purchasePrice(),
                    ]);
                    $existingByProduct->put($product->id, $created);
                }

                $this->recordMovement(
                    $product,
                    $quantity,
                    'assignment',
                    $userId,
                    $depot->id,
                    $seller->id,
                    $assignment,
                    $member->id,
                    $movementNote,
                    'available',
                    $userId,
                    $cost,
                );
                $this->syncProductStock($product);
            }

            $updates = [];
            if ($collectedAmount > 0) {
                $updates['collected_amount'] = round((float) $assignment->collected_amount + $collectedAmount, 2);
            }
            if ($notes) {
                $updates['notes'] = trim(($assignment->notes ? $assignment->notes."\n" : '').$notes);
            } elseif ($collectedAmount > 0) {
                $cashNote = __('Prepaid cash collected: :amount', [
                    'amount' => number_format($collectedAmount, 2, '.', ''),
                ]);
                $updates['notes'] = trim(($assignment->notes ? $assignment->notes."\n" : '').$cashNote);
            }
            if ($updates !== []) {
                $assignment->update($updates);
            }

            return $assignment->fresh(['member', 'office', 'depot', 'items.product']);
        });
    }

    /**
     * Update quantities on an open assignment (absolute quantity_out per line).
     *
     * @param  list<array{id:int, quantity:int}>  $items
     */
    public function updateAssignment(
        KamsStockAssignment $assignment,
        array $items,
        ?int $userId = null,
        ?string $notes = null,
    ): KamsStockAssignment {
        abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));

        return DB::transaction(function () use ($assignment, $items, $userId, $notes) {
            $assignment = KamsStockAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
            $assignment->load(['member', 'items.product']);
            $member = $assignment->member;
            abort_unless($member, 422, __('The assignment has no agent.'));

            $depot = KamsDepot::query()->findOrFail($assignment->depot_id);
            $seller = $this->sellerLocation($member);
            $byId = $assignment->items->keyBy('id');
            $seen = [];
            $movementNote = $notes ?: __('Assignment update :ref', ['ref' => $assignment->reference]);

            foreach ($items as $payload) {
                $line = $byId->get((int) $payload['id']);
                abort_unless($line, 422, __('Assignment line not found.'));
                abort_unless(! in_array($line->id, $seen, true), 422, __('Duplicate assignment line.'));
                $seen[] = $line->id;

                $newQty = (int) $payload['quantity'];
                abort_unless($newQty >= 0, 422, __('Quantity must be zero or greater.'));
                $oldQty = (int) $line->quantity_out;
                $delta = $newQty - $oldQty;
                if ($delta === 0) {
                    continue;
                }

                $product = $line->product ?: KamsProduct::query()->findOrFail($line->product_id);
                $cost = (float) $line->unit_cost;
                if ($delta > 0) {
                    $this->changeBalance($depot, $product, -$delta, 'available');
                    $this->changeBalance($seller, $product, $delta, 'available');
                    $this->recordMovement(
                        $product,
                        $delta,
                        'assignment',
                        $userId,
                        $depot->id,
                        $seller->id,
                        $assignment,
                        $member->id,
                        $movementNote,
                        'available',
                        $userId,
                        $cost,
                    );
                } else {
                    $returnQty = abs($delta);
                    $this->changeBalance($seller, $product, -$returnQty, 'available');
                    $this->changeBalance($depot, $product, $returnQty, 'available');
                    $this->recordMovement(
                        $product,
                        $returnQty,
                        'assignment',
                        $userId,
                        $seller->id,
                        $depot->id,
                        $assignment,
                        $member->id,
                        $movementNote,
                        'available',
                        $userId,
                        $cost,
                    );
                }
                $this->syncProductStock($product);

                if ($newQty === 0) {
                    $line->delete();
                } else {
                    $line->update(['quantity_out' => $newQty]);
                }
            }

            $remaining = $assignment->items()->count();
            abort_unless($remaining > 0, 422, __('An assignment must keep at least one product.'));

            if ($notes) {
                $assignment->update([
                    'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '').$notes),
                ]);
            }

            return $assignment->fresh(['member', 'office', 'depot', 'items.product']);
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function sell(
        KamsMember $member,
        array $items,
        ?int $clientId,
        ?string $clientName,
        float $paidAmount,
        ?int $userId,
        ?string $notes = null,
        ?KamsStockAssignment $assignment = null,
    ): KamsStockSale {
        abort_unless($items !== [], 422, __('Add at least one product.'));
        $seller = $this->sellerLocation($member);
        $office = Office::query()->findOrFail($member->office_id);
        $depot = $this->officeDepot($office);
        $client = $clientId ? KamsClient::query()->find($clientId) : null;
        $manager = $this->savings->resolveManager($member);

        return DB::transaction(function () use ($member, $items, $client, $clientName, $paidAmount, $userId, $notes, $seller, $depot, $manager, $assignment) {
            $sale = KamsStockSale::query()->create([
                'company_id' => $member->company_id,
                'reference' => KamsDocumentRefs::invoice(),
                'member_id' => $member->id,
                'assignment_id' => $assignment?->id,
                'manager_member_id' => $manager?->id,
                'depot_id' => $depot->id,
                'client_id' => $client?->id,
                'client_name' => $clientName ?: $client?->name,
                'payment_status' => 'unpaid',
                'notes' => $notes,
                'created_by' => $userId,
                'sold_at' => now(),
            ]);
            $subtotal = 0.0;
            $costTotal = 0.0;
            $fieldTotal = 0.0;
            $managerTotal = 0.0;
            $companyTotal = 0.0;

            foreach ($items as $item) {
                $product = KamsProduct::query()->where('company_id', $member->company_id)->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                $salePrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $product->salePrice((int) $member->office_id);
                abort_unless($quantity > 0, 422, __('Quantity must be greater than zero.'));
                // Prefer snapshot from assignment/settlement when provided; otherwise live DB cost.
                $purchasePrice = isset($item['unit_cost']) ? (float) $item['unit_cost'] : $this->unitCost($product);
                if ($purchasePrice <= 0) {
                    $purchasePrice = $product->purchasePrice();
                }
                abort_unless(
                    $salePrice >= $purchasePrice,
                    422,
                    __('Sale price must be greater than or equal to purchase price.'),
                );
                $this->changeBalance($seller, $product, -$quantity, 'available');
                KamsStockSaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $salePrice,
                    'unit_cost' => $purchasePrice,
                    'field_price' => $salePrice,
                    'manager_price' => $purchasePrice,
                    'company_price' => $purchasePrice,
                    'seller_margin' => 0,
                    'manager_margin' => 0,
                ]);
                $this->recordMovement($product, $quantity, 'sale', $userId, $seller->id, null, $sale, $member->id, $notes, 'available', $userId, $purchasePrice);
                $this->syncProductStock($product);
                $subtotal += $quantity * $salePrice;
                $costTotal += $quantity * $purchasePrice;
                $fieldTotal += $quantity * $salePrice;
                $managerTotal += $quantity * $purchasePrice;
                $companyTotal += $quantity * $purchasePrice;
            }

            $sale->update([
                'subtotal' => round($subtotal, 2),
                'field_total' => round($fieldTotal, 2),
                'manager_total' => round($managerTotal, 2),
                'company_total' => round($companyTotal, 2),
                'seller_margin' => 0,
                'manager_margin' => 0,
                'cost_total' => round($costTotal, 2),
                'margin' => round($subtotal - $costTotal, 2),
            ]);
            KamsSellerCommission::query()->create([
                'company_id' => $member->company_id,
                'sale_id' => $sale->id,
                'member_id' => $member->id,
                'status' => 'estimated',
                'estimated_amount' => 0,
                'adjusted_amount' => 0,
            ]);
            $fresh = $sale->fresh(['member', 'client', 'items.product', 'commission']);
            app(RevenueShareService::class)->accrue($fresh);
            if ($paidAmount > 0) {
                $this->paySale($fresh, $paidAmount);
            }

            return $sale->fresh(['member', 'client', 'items.product', 'commission']);
        });
    }

    public function paySale(KamsStockSale $sale, float $amount): KamsStockSale
    {
        abort_unless($amount > 0, 422, __('Payment must be greater than zero.'));

        return DB::transaction(function () use ($sale, $amount) {
            $sale = KamsStockSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $paid = min((float) $sale->subtotal, (float) $sale->paid_amount + $amount);
            $status = $paid <= 0 ? 'unpaid' : ($paid + 0.009 >= (float) $sale->subtotal ? 'paid' : 'partial');
            $sale->update(['paid_amount' => round($paid, 2), 'payment_status' => $status]);
            $commission = $sale->commission;
            if ($commission && $status === 'paid' && $commission->status !== 'paid') {
                $commission->update([
                    'status' => 'payable',
                    'payable_amount' => (float) $commission->adjusted_amount,
                ]);
            }
            if ($status === 'paid') {
                $this->savings->postSale($sale->fresh(['member', 'items']));
            }

            return $sale->fresh(['member', 'client', 'items.product', 'commission']);
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function createReturn(
        string $type,
        array $items,
        ?KamsMember $member,
        ?KamsStockSale $sale,
        ?KamsDepot $from,
        ?KamsDepot $to,
        string $condition,
        string $reason,
        ?int $userId,
    ): KamsStockReturn {
        abort_unless($items !== [], 422, __('Add at least one product.'));
        abort_unless(in_array($type, ['customer', 'seller_to_depot', 'depot_to_warehouse', 'supplier', 'defective'], true), 422, __('Unknown return type.'));
        $condition = in_array($condition, ['available', 'quarantine', 'damaged'], true) ? $condition : 'quarantine';

        return DB::transaction(function () use ($type, $items, $member, $sale, $from, $to, $condition, $reason, $userId) {
            $companyId = $member?->company_id ?? $from?->company_id ?? $to?->company_id ?? $sale?->company_id;
            $return = KamsStockReturn::query()->create([
                'company_id' => $companyId,
                'reference' => 'RT-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'type' => $type,
                'status' => 'requested',
                'sale_id' => $sale?->id,
                'member_id' => $member?->id,
                'from_depot_id' => $from?->id,
                'to_depot_id' => $to?->id,
                'reason' => $reason,
                'condition' => $condition,
                'created_by' => $userId,
            ]);
            $saleItems = $sale
                ? $sale->items()->get()->keyBy('product_id')
                : collect();

            foreach ($items as $item) {
                $product = KamsProduct::query()->findOrFail($item['product_id']);
                $saleLine = $saleItems->get($product->id);
                KamsStockReturnItem::query()->create([
                    'return_id' => $return->id,
                    'product_id' => $product->id,
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => $saleLine
                        ? (float) $saleLine->unit_cost
                        : $this->unitCost($product),
                    'unit_price' => $saleLine
                        ? (float) ($saleLine->field_price ?: $saleLine->unit_price)
                        : $product->salePrice(
                            (int) ($member?->office_id ?: $from?->office_id ?: $to?->office_id ?: 0) ?: null
                        ),
                ]);
            }

            return $return->load(['items.product', 'member', 'fromDepot', 'toDepot', 'sale']);
        });
    }

    public function acceptReturn(KamsStockReturn $return, int $userId): KamsStockReturn
    {
        abort_unless($return->status === 'requested', 422, __('This return cannot be accepted.'));

        return DB::transaction(function () use ($return, $userId) {
            $return = KamsStockReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->load(['member', 'sale.items', 'items.product']);
            $condition = $return->condition ?: 'quarantine';
            foreach ($return->items()->with('product')->get() as $line) {
                if ($return->type === 'customer' && $return->member_id) {
                    $seller = $this->sellerLocation($return->member);
                    $this->changeBalance($seller, $line->product, $line->quantity, $condition);
                    $this->recordMovement($line->product, $line->quantity, 'return', $userId, null, $seller->id, $return, $return->member_id, $return->reason, $condition, $userId, (float) $line->unit_cost);
                    $this->adjustCommissionForReturn($return, $line);
                    $this->savings->reverseReturn($return, $line);
                } elseif ($return->type === 'seller_to_depot' && $return->member_id && $return->to_depot_id) {
                    $seller = $this->sellerLocation($return->member);
                    $to = KamsDepot::query()->findOrFail($return->to_depot_id);
                    $this->changeBalance($seller, $line->product, -$line->quantity, 'available');
                    $this->changeBalance($to, $line->product, $line->quantity, $condition === 'damaged' ? 'damaged' : 'available');
                    $this->recordMovement($line->product, $line->quantity, 'return', $userId, $seller->id, $to->id, $return, $return->member_id, $return->reason, $condition, $userId, (float) $line->unit_cost);
                } elseif ($return->type === 'depot_to_warehouse' && $return->from_depot_id && $return->to_depot_id) {
                    $from = KamsDepot::query()->findOrFail($return->from_depot_id);
                    $to = KamsDepot::query()->findOrFail($return->to_depot_id);
                    abort_unless($from->kind === 'depot', 422, __('Returns of this type must leave an office depot.'));
                    abort_unless($to->isWarehouse(), 422, __('Returns of this type must arrive in a warehouse.'));
                    abort_unless($this->quantityAt($from, $line->product, 'available') >= (int) $line->quantity, 422, __('Insufficient stock for :product at :location.', [
                        'product' => $line->product->name,
                        'location' => $from->name,
                    ]));
                    $this->changeBalance($from, $line->product, -$line->quantity, 'available');
                    $this->changeBalance($to, $line->product, $line->quantity, 'available');
                    $this->recordMovement($line->product, $line->quantity, 'return', $userId, $from->id, $to->id, $return, null, $return->reason, 'available', $userId, (float) $line->unit_cost);
                } elseif ($return->type === 'defective' && $return->from_depot_id) {
                    $from = KamsDepot::query()->findOrFail($return->from_depot_id);
                    $this->changeBalance($from, $line->product, -$line->quantity, 'available');
                    $this->changeBalance($from, $line->product, $line->quantity, 'damaged');
                    $this->recordMovement($line->product, $line->quantity, 'loss', $userId, $from->id, $from->id, $return, $return->member_id, $return->reason, 'damaged', $userId, (float) $line->unit_cost);
                }
                $this->syncProductStock($line->product);
            }
            $return->update([
                'status' => $condition === 'damaged' ? 'quarantine' : 'restocked',
                'validated_by' => $userId,
                'validated_at' => now(),
            ]);

            return $return->fresh(['items.product', 'member', 'fromDepot', 'toDepot', 'sale']);
        });
    }

    /**
     * Move available stock from an office depot back to a regional warehouse.
     *
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function returnToWarehouse(
        KamsDepot $from,
        KamsDepot $to,
        array $items,
        ?int $userId = null,
        ?string $notes = null,
    ): KamsStockReturn {
        abort_unless($from->kind === 'depot', 422, __('Returns of this type must leave an office depot.'));
        abort_unless($to->isWarehouse(), 422, __('Returns of this type must arrive in a warehouse.'));
        abort_unless($from->company_id === $to->company_id, 422, __('Locations must belong to the same company.'));
        abort_unless($items !== [], 422, __('Add at least one product.'));

        $return = $this->createReturn(
            'depot_to_warehouse',
            $items,
            null,
            null,
            $from,
            $to,
            'available',
            $notes ?: __('Returned to warehouse'),
            $userId,
        );

        return $this->acceptReturn($return, (int) $userId);
    }

    public function resolveReturnWarehouse(KamsDepot $depot, ?int $productId = null): ?KamsDepot
    {
        abort_unless($depot->kind === 'depot', 422, __('Returns of this type must leave an office depot.'));

        if ($productId) {
            $sourceId = KamsStockMovement::query()
                ->where('to_depot_id', $depot->id)
                ->where('product_id', $productId)
                ->whereNotNull('from_depot_id')
                ->whereIn('type', ['transfer', 'receipt', 'return'])
                ->latest('id')
                ->value('from_depot_id');
            if ($sourceId) {
                $source = KamsDepot::query()->whereKey($sourceId)->where('kind', 'warehouse')->first();
                if ($source) {
                    return $source;
                }
            }
        }

        return KamsDepot::query()
            ->where('company_id', $depot->company_id)
            ->where('kind', 'warehouse')
            ->when($depot->province_id, fn ($q) => $q->orderByRaw('case when province_id = ? then 0 else 1 end', [$depot->province_id]))
            ->orderBy('name')
            ->first();
    }

    public function payCommission(KamsSellerCommission $commission, float $amount): KamsSellerCommission
    {
        abort_unless($commission->status === 'payable', 422, __('This commission is not payable yet.'));
        $paid = min((float) $commission->payable_amount, (float) $commission->paid_amount + $amount);
        $commission->update([
            'paid_amount' => round($paid, 2),
            'status' => $paid + 0.009 >= (float) $commission->payable_amount ? 'paid' : 'payable',
        ]);

        return $commission->fresh(['member', 'sale']);
    }

    /**
     * Traceable stock correction at a warehouse or depot.
     * - defective: available → damaged
     * - lost: write-off from available
     * - found: add to available
     */
    public function adjust(
        KamsDepot $location,
        KamsProduct $product,
        string $type,
        int $quantity,
        string $reason,
        ?int $userId = null,
    ): KamsStockAdjustment {
        abort_unless(in_array($type, ['defective', 'lost', 'found'], true), 422, __('Unknown adjustment type.'));
        abort_unless($quantity > 0, 422, __('Quantity must be greater than zero.'));
        abort_unless(trim($reason) !== '', 422, __('A reason is required for stock adjustments.'));
        abort_unless($location->company_id === $product->company_id, 422, __('Product and location must belong to the same company.'));
        abort_unless(in_array($location->kind, ['warehouse', 'depot'], true), 422, __('Adjustments apply only to warehouses and office depots.'));

        return DB::transaction(function () use ($location, $product, $type, $quantity, $reason, $userId) {
            $before = $this->quantityAt($location, $product, 'available');

            if ($type === 'found') {
                $this->changeBalance($location, $product, $quantity, 'available');
                $after = $before + $quantity;
                $movementType = 'adjustment';
                $condition = 'available';
                $fromId = null;
                $toId = $location->id;
            } elseif ($type === 'defective') {
                abort_unless($before >= $quantity, 422, __('Insufficient stock for :product at :location.', [
                    'product' => $product->name,
                    'location' => $location->name,
                ]));
                $this->changeBalance($location, $product, -$quantity, 'available');
                $this->changeBalance($location, $product, $quantity, 'damaged');
                $after = $before - $quantity;
                $movementType = 'loss';
                $condition = 'damaged';
                $fromId = $location->id;
                $toId = $location->id;
            } else {
                abort_unless($before >= $quantity, 422, __('Insufficient stock for :product at :location.', [
                    'product' => $product->name,
                    'location' => $location->name,
                ]));
                $this->changeBalance($location, $product, -$quantity, 'available');
                $after = $before - $quantity;
                $movementType = 'loss';
                $condition = 'available';
                $fromId = $location->id;
                $toId = null;
            }

            $adjustment = KamsStockAdjustment::query()->create([
                'company_id' => $location->company_id,
                'reference' => 'AJ-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'depot_id' => $location->id,
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => trim($reason),
                'created_by' => $userId,
                'adjusted_at' => now(),
            ]);

            $this->recordMovement(
                $product,
                $quantity,
                $movementType,
                $userId,
                $fromId,
                $toId,
                $adjustment,
                null,
                trim($reason),
                $condition,
                $userId,
                $this->unitCost($product),
            );
            $this->syncProductStock($product);

            return $adjustment->load(['depot', 'product', 'creator:id,name']);
        });
    }

    public function quantityAt(KamsDepot $location, KamsProduct $product, string $condition = 'available'): int
    {
        return (int) KamsStockBalance::query()
            ->where('depot_id', $location->id)
            ->where('product_id', $product->id)
            ->where('condition', $condition)
            ->value('quantity');
    }

    public function receive(KamsDepot $location, KamsProduct $product, int $quantity, ?int $userId = null, ?string $notes = null): void
    {
        $receipt = $this->createReceipt($location, [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $this->unitCost($product),
        ]], null, $userId, $notes);
        if ($userId) {
            $this->validateReceipt($receipt, $userId);
        }
    }

    /**
     * Record an agent's closing figures and immediately return unsold stock to the office depot.
     * Sales, losses, and compensation are posted later via closeDay / settle.
     *
     * @param  list<array{id:int, quantity_returned:int, quantity_lost:int}>  $items
     */
    public function close(
        KamsStockAssignment $assignment,
        array $items,
        float $collectedAmount,
        ?string $notes = null,
        bool $depositSurplus = false,
        ?int $userId = null,
    ): KamsStockAssignment {
        abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));

        return DB::transaction(function () use ($assignment, $items, $collectedAmount, $notes, $depositSurplus, $userId) {
            $assignment = KamsStockAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
            $totals = $this->applyClosingQuantities($assignment, $items);
            abort_unless(
                round($collectedAmount, 2) + 0.001 >= $totals['expected'],
                422,
                __('L\'argent collecté doit être égal au montant dû (:due), ou supérieur pour épargner le surplus.', [
                    'due' => number_format($totals['expected'], 2, '.', ''),
                ]),
            );

            $this->returnClosedStockToDepot($assignment, $userId, $notes);

            $assignment->update([
                'status' => 'closed',
                'expected_amount' => $totals['expected'],
                'collected_amount' => round($collectedAmount, 2),
                'profit_amount' => $totals['profit'],
                'loss_amount' => $totals['loss'],
                'deposit_surplus' => $depositSurplus && round($collectedAmount, 2) > $totals['expected'],
                'notes' => $notes ?? $assignment->notes,
                'closed_by' => $userId,
            ]);

            return $assignment->fresh(['member', 'office', 'depot', 'items.product']);
        });
    }

    /**
     * Post stock, sales, and account movements for assignments already closed by their agents.
     *
     * @param  list<int>|null  $officeIds
     * @return list<KamsStockAssignment>
     */
    public function closeDay(?int $companyId, ?int $officeId, ?int $userId, ?array $officeIds = null): array
    {
        return DB::transaction(function () use ($companyId, $officeId, $userId, $officeIds) {
            $assignments = KamsStockAssignment::query()
                ->where('status', 'closed')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
                ->when($officeIds !== null, fn ($q) => $q->whereIn('office_id', $officeIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_if($assignments->isEmpty(), 422, __('Aucune affectation clôturée à comptabiliser.'));
            $stillOpen = KamsStockAssignment::query()
                ->where('status', 'open')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
                ->when($officeIds !== null, fn ($q) => $q->whereIn('office_id', $officeIds))
                ->exists();
            abort_if($stillOpen, 422, __('Toutes les affectations doivent être clôturées avant de clôturer la journée.'));

            $posted = [];
            foreach ($assignments as $assignment) {
                $assignment->load('items');
                $posted[] = $this->settle(
                    $assignment,
                    $assignment->items->map(fn ($line) => [
                        'id' => $line->id,
                        'quantity_returned' => (int) $line->quantity_returned,
                        'quantity_lost' => (int) $line->quantity_lost,
                    ])->all(),
                    (float) $assignment->collected_amount,
                    $assignment->notes,
                    $userId,
                    (bool) $assignment->deposit_surplus,
                );
            }

            return $posted;
        });
    }

    /**
     * Delete a settled day report: reverse sales/compensation for that date and reopen assignments.
     *
     * @param  list<int>|null  $officeIds
     * @return array{deleted_assignments: int, voided_sales: int, date: string}
     */
    public function deleteDayReport(?int $companyId, string $date, ?int $officeId = null, ?array $officeIds = null): array
    {
        return DB::transaction(function () use ($companyId, $date, $officeId, $officeIds) {
            $assignments = KamsStockAssignment::query()
                ->with(['items.product', 'member'])
                ->where('status', 'settled')
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
                ->when($officeIds !== null, fn ($q) => $q->whereIn('office_id', $officeIds))
                ->whereDate('settled_at', $date)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($assignments->isEmpty(), 422, __('Aucun rapport d\'affectation à supprimer pour cette date.'));

            $voidedSales = 0;
            foreach ($assignments as $assignment) {
                $voidedSales += $this->unsettleAssignment($assignment);
            }

            KamsDailySettlement::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
                ->when($officeIds !== null, fn ($q) => $q->whereIn('office_id', $officeIds))
                ->whereDate('settled_on', $date)
                ->delete();

            return [
                'deleted_assignments' => $assignments->count(),
                'voided_sales' => $voidedSales,
                'date' => $date,
            ];
        });
    }

    private function unsettleAssignment(KamsStockAssignment $assignment): int
    {
        abort_unless($assignment->status === 'settled', 422, __('Only settled assignment reports can be deleted.'));

        $sales = $this->salesForAssignment($assignment);
        foreach ($sales as $sale) {
            $this->voidSale($sale);
        }

        $this->deleteLedgerSources(KamsStockAssignment::class, [(int) $assignment->id]);

        $member = $assignment->member;
        if ($member) {
            $seller = $this->sellerLocation($member);
            foreach ($assignment->items as $line) {
                $lost = (int) $line->quantity_lost;
                if ($lost <= 0 || ! $line->product) {
                    continue;
                }
                $this->changeBalance($seller, $line->product, -$lost, 'damaged');
                $this->changeBalance($seller, $line->product, $lost, 'available');
                $this->syncProductStock($line->product);
            }
        }

        $assignment->update([
            'status' => 'closed',
            'settled_at' => null,
            'day_closed_by' => null,
        ]);

        return $sales->count();
    }

    /**
     * @return Collection<int, KamsStockSale>
     */
    private function salesForAssignment(KamsStockAssignment $assignment): Collection
    {
        $linked = KamsStockSale::query()
            ->with(['items.product', 'member', 'commission', 'revenueShares'])
            ->where('assignment_id', $assignment->id)
            ->get();
        if ($linked->isNotEmpty()) {
            return $linked;
        }

        $expected = [];
        foreach ($assignment->items as $line) {
            $sold = max(0, (int) $line->quantity_out - (int) $line->quantity_returned - (int) $line->quantity_lost);
            if ($sold <= 0) {
                continue;
            }
            $expected[(int) $line->product_id] = ($expected[(int) $line->product_id] ?? 0) + $sold;
        }
        if ($expected === [] || ! $assignment->member_id || ! $assignment->settled_at) {
            return collect();
        }

        ksort($expected);

        return KamsStockSale::query()
            ->with(['items.product', 'member', 'commission', 'revenueShares'])
            ->where('member_id', $assignment->member_id)
            ->whereDate('sold_at', $assignment->settled_at->toDateString())
            ->where(function ($q) use ($assignment) {
                $q->whereNull('assignment_id')->orWhere('assignment_id', $assignment->id);
            })
            ->orderBy('id')
            ->get()
            ->filter(function (KamsStockSale $sale) use ($expected) {
                $got = [];
                foreach ($sale->items as $item) {
                    $got[(int) $item->product_id] = ($got[(int) $item->product_id] ?? 0) + (int) $item->quantity;
                }
                ksort($got);

                return $got === $expected;
            })
            ->values();
    }

    private function voidSale(KamsStockSale $sale): void
    {
        abort_if(
            KamsStockReturn::query()->where('sale_id', $sale->id)->exists(),
            422,
            __('Impossible de supprimer ce rapport : une vente liée a déjà un retour (:ref).', ['ref' => $sale->reference]),
        );
        abort_if(
            $sale->commission && $sale->commission->status === 'paid',
            422,
            __('Impossible de supprimer ce rapport : une commission a déjà été payée (:ref).', ['ref' => $sale->reference]),
        );

        $shareIds = KamsRevenueShare::query()->where('sale_id', $sale->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->deleteLedgerSources(KamsStockSale::class, [(int) $sale->id]);
        if ($shareIds !== []) {
            $this->deleteLedgerSources(KamsRevenueShare::class, $shareIds);
        }

        KamsTreasuryEntry::query()
            ->where('notes', 'like', '%'.$sale->reference.'%')
            ->get()
            ->each(function (KamsTreasuryEntry $entry) {
                $accountId = (int) $entry->account_id;
                $entry->delete();
                $account = KamsTreasuryAccount::query()->find($accountId);
                if ($account) {
                    app(TreasuryLedger::class)->refreshBalance($account);
                }
            });

        KamsRevenueShare::query()->where('sale_id', $sale->id)->delete();
        KamsSellerCommission::query()->where('sale_id', $sale->id)->delete();

        $member = $sale->member;
        if ($member) {
            $seller = $this->sellerLocation($member);
            foreach ($sale->items as $item) {
                if (! $item->product) {
                    continue;
                }
                $this->changeBalance($seller, $item->product, (int) $item->quantity, 'available');
                $this->syncProductStock($item->product);
            }
        }

        KamsStockMovement::query()
            ->where('reference_type', KamsStockSale::class)
            ->where('reference_id', $sale->id)
            ->delete();

        KamsStockSaleItem::query()->where('sale_id', $sale->id)->delete();
        $sale->delete();
    }

    /**
     * @param  list<int>  $sourceIds
     */
    private function deleteLedgerSources(string $sourceType, array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        $entries = KamsSavingsEntry::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->get();
        if ($entries->isEmpty()) {
            return;
        }

        $accountIds = $entries->pluck('account_id')->unique()->filter()->all();
        $treasury = KamsTreasuryEntry::query()
            ->whereIn('savings_entry_id', $entries->pluck('id')->all())
            ->get();
        $treasuryAccountIds = $treasury->pluck('account_id')->unique()->filter()->all();

        foreach ($treasury as $entry) {
            $entry->delete();
        }
        foreach ($entries as $entry) {
            $entry->delete();
        }

        foreach ($accountIds as $accountId) {
            $account = KamsSavingsAccount::query()->find($accountId);
            if ($account) {
                $this->savings->refreshBalance($account);
            }
        }
        foreach ($treasuryAccountIds as $accountId) {
            $account = KamsTreasuryAccount::query()->find($accountId);
            if ($account) {
                app(TreasuryLedger::class)->refreshBalance($account);
            }
        }
    }

    /**
     * Move returned quantities from the seller location back to the office depot at agent close.
     */
    private function returnClosedStockToDepot(KamsStockAssignment $assignment, ?int $userId, ?string $notes = null): void
    {
        $assignment->loadMissing(['items.product', 'member']);
        $member = $assignment->member;
        abort_unless($member, 422, __('The assignment has no agent.'));

        $depot = KamsDepot::query()->findOrFail($assignment->depot_id);
        $seller = $this->sellerLocation($member);
        $movementNote = $notes ?: __('Return to depot for assignment :ref', ['ref' => $assignment->reference]);

        foreach ($assignment->items as $line) {
            $returned = (int) $line->quantity_returned;
            if ($returned <= 0 || ! $line->product) {
                continue;
            }
            $this->changeBalance($seller, $line->product, -$returned, 'available');
            $this->changeBalance($depot, $line->product, $returned, 'available');
            $this->recordMovement(
                $line->product,
                $returned,
                'return',
                $userId,
                $seller->id,
                $depot->id,
                $assignment,
                $member->id,
                $movementNote,
                'available',
                $userId,
                (float) $line->unit_cost,
            );
            $this->syncProductStock($line->product);
        }
    }

    private function assignmentReturnsPosted(KamsStockAssignment $assignment): bool
    {
        return KamsStockMovement::query()
            ->where('reference_type', KamsStockAssignment::class)
            ->where('reference_id', $assignment->id)
            ->where('type', 'return')
            ->exists();
    }

    /**
     * @param  list<array{id:int, quantity_returned:int, quantity_lost:int}>  $items
     * @return array{expected: float, profit: float, loss: float}
     */
    private function applyClosingQuantities(KamsStockAssignment $assignment, array $items): array
    {
        $assignment->load(['items.product']);
        $byId = collect($items)->keyBy('id');
        $expectedAmount = 0.0;
        $profitAmount = 0.0;
        $lossAmount = 0.0;

        foreach ($assignment->items as $line) {
            $payload = $byId->get($line->id, []);
            $returned = (int) ($payload['quantity_returned'] ?? 0);
            $lost = (int) ($payload['quantity_lost'] ?? 0);
            abort_unless($returned >= 0 && $lost >= 0, 422, __('Quantities must be zero or greater.'));
            $productName = $line->product?->name ?: (string) $line->product_id;
            abort_unless(
                $returned + $lost <= (int) $line->quantity_out,
                422,
                __('Pour :product, restitués et perdus dépassent la quantité sortie (:out). Vendus, restitués et perdus doivent correspondre à la quantité sortie.', [
                    'product' => $productName,
                    'out' => (int) $line->quantity_out,
                ]),
            );
            $sold = max(0, $line->quantity_out - $returned - $lost);
            $line->update([
                'quantity_returned' => $returned,
                'quantity_lost' => $lost,
            ]);
            $unitPrice = (float) $line->unit_price;
            $unitCost = (float) $line->unit_cost;
            $expectedAmount += $sold * $unitPrice;
            $profitAmount += $sold * ($unitPrice - $unitCost);
            $lossAmount += $lost * $unitCost;
        }

        return [
            'expected' => round($expectedAmount, 2),
            'profit' => round($profitAmount, 2),
            'loss' => round($lossAmount, 2),
        ];
    }

    /**
     * Post sales, returns, compensation, and treasury for an agent assignment already closed.
     * Only invoked from closeDay — never from per-agent close.
     *
     * @param  list<array{id:int, quantity_returned:int, quantity_lost:int}>  $items
     */
    private function settle(
        KamsStockAssignment $assignment,
        array $items,
        float $collectedAmount,
        ?string $notes = null,
        ?int $userId = null,
        bool $depositSurplus = false,
    ): KamsStockAssignment {
        abort_unless($assignment->status === 'closed', 422, __('Close the agent first, then close the day to post sales and compensation.'));

        return DB::transaction(function () use ($assignment, $items, $collectedAmount, $notes, $userId, $depositSurplus) {
            $assignment = KamsStockAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'closed', 422, __('Close the agent first, then close the day to post sales and compensation.'));
            $assignment->load(['items.product', 'member']);
            $member = $assignment->member;
            $byId = collect($items)->keyBy('id');
            $saleLines = [];
            $returnLines = [];
            $expectedAmount = 0.0;
            $profitAmount = 0.0;
            $lossAmount = 0.0;

            foreach ($assignment->items as $line) {
                $payload = $byId->get($line->id, []);
                $returned = (int) ($payload['quantity_returned'] ?? $line->quantity_returned);
                $lost = (int) ($payload['quantity_lost'] ?? $line->quantity_lost);
                abort_unless($returned >= 0 && $lost >= 0, 422, __('Quantities must be zero or greater.'));
                $productName = $line->product?->name ?: (string) $line->product_id;
                abort_unless(
                    $returned + $lost <= (int) $line->quantity_out,
                    422,
                    __('Pour :product, restitués et perdus dépassent la quantité sortie (:out). Vendus, restitués et perdus doivent correspondre à la quantité sortie.', [
                        'product' => $productName,
                        'out' => (int) $line->quantity_out,
                    ]),
                );
                $sold = max(0, $line->quantity_out - $returned - $lost);
                $line->update([
                    'quantity_returned' => $returned,
                    'quantity_lost' => $lost,
                ]);
                $unitPrice = (float) $line->unit_price;
                $unitCost = (float) $line->unit_cost;
                $expectedAmount += $sold * $unitPrice;
                $profitAmount += $sold * ($unitPrice - $unitCost);
                $lossAmount += $lost * $unitCost;
                if ($sold > 0) {
                    $saleLines[] = [
                        'product_id' => $line->product_id,
                        'quantity' => $sold,
                        'unit_price' => (float) $line->unit_price,
                        'unit_cost' => (float) $line->unit_cost,
                    ];
                }
                if ($returned > 0) {
                    $returnLines[] = ['product_id' => $line->product_id, 'quantity' => $returned];
                }
                if ($lost > 0) {
                    $seller = $this->sellerLocation($member);
                    $this->changeBalance($seller, $line->product, -$lost, 'available');
                    $this->changeBalance($seller, $line->product, $lost, 'damaged');
                }
            }

            $expectedAmount = round($expectedAmount, 2);
            $profitAmount = round($profitAmount, 2);
            $lossAmount = round($lossAmount, 2);
            abort_unless(
                round($collectedAmount, 2) + 0.001 >= $expectedAmount,
                422,
                __('L\'argent collecté doit être égal au montant dû (:due), ou supérieur pour épargner le surplus.', [
                    'due' => number_format($expectedAmount, 2, '.', ''),
                ]),
            );
            $surplus = round(max(0, $collectedAmount - $expectedAmount), 2);
            $salePayment = min($collectedAmount, $expectedAmount);

            $sale = null;
            if ($saleLines !== []) {
                $sale = $this->sell($member, $saleLines, null, null, $salePayment, $userId, $notes, $assignment);
            }
            // Returns are posted at agent close; keep this path for older closed assignments.
            if ($returnLines !== [] && ! $this->assignmentReturnsPosted($assignment)) {
                $depot = KamsDepot::query()->find($assignment->depot_id);
                $ret = $this->createReturn('seller_to_depot', $returnLines, $member, null, $this->sellerLocation($member), $depot, 'available', $notes ?: 'Return to depot', $userId);
                if ($userId) {
                    $this->acceptReturn($ret, $userId);
                }
            }

            $surplusEntry = null;
            if ($surplus > 0 && $userId) {
                abort_unless($member, 422, __('The assignment has no agent.'));
                $surplusEntry = $this->savings->depositDayClosingSurplus(
                    $member,
                    $surplus,
                    $assignment,
                    $userId,
                );
            }

            if ($sale && $member && $userId && $salePayment > 0) {
                app(TreasuryLedger::class)->recordOfficeSaleCollection($member, $sale, $salePayment, $userId);
            }

            $assignmentNotes = $notes ?? $assignment->notes;
            if ($surplusEntry) {
                $surplusNote = __('Surplus :amount deposited to agent account (:ref).', [
                    'amount' => number_format($surplus, 2, '.', ''),
                    'ref' => $surplusEntry->id,
                ]);
                $assignmentNotes = trim(($assignmentNotes ? $assignmentNotes."\n" : '').$surplusNote);
            }

            $assignment->update([
                'status' => 'settled',
                'settled_at' => now(),
                'expected_amount' => $expectedAmount,
                'collected_amount' => round($collectedAmount, 2),
                'profit_amount' => $profitAmount,
                'loss_amount' => $lossAmount,
                'notes' => $assignmentNotes,
                'day_closed_by' => $userId ?? $assignment->day_closed_by,
            ]);

            $fresh = $assignment->fresh(['member', 'office', 'depot', 'items.product']);
            if ($sale) {
                $fresh->setAttribute('posted_sale', $sale);
            }
            if ($surplusEntry) {
                $fresh->setAttribute('surplus_deposited', round($surplus, 2));
                $fresh->setAttribute('surplus_entry', $this->savings->serializeEntry($surplusEntry));
            }

            return $fresh;
        });
    }

    private function adjustCommissionForReturn(KamsStockReturn $return, KamsStockReturnItem $line): void
    {
        if (! $return->sale_id) {
            return;
        }
        $commission = KamsSellerCommission::query()->where('sale_id', $return->sale_id)->first();
        if (! $commission) {
            return;
        }
        $match = $return->sale?->items()->where('product_id', $line->product_id)->first();
        $delta = $match
            ? $line->quantity * (float) $match->seller_margin
            : $line->quantity * ((float) ($line->product?->sellerMarginUnit() ?? 0));
        $adjusted = max(0, (float) $commission->adjusted_amount - $delta);
        $status = $commission->status === 'paid' ? 'adjusted' : ($commission->status === 'payable' ? 'payable' : 'adjusted');
        $commission->update([
            'adjusted_amount' => round($adjusted, 2),
            'payable_amount' => $commission->status === 'payable' ? round($adjusted, 2) : $commission->payable_amount,
            'status' => $status,
        ]);
    }

    private function applyWeightedAverage(KamsProduct $product, int $qty, float $unitCost): void
    {
        $onHand = (int) KamsStockBalance::query()->where('product_id', $product->id)->sum('quantity');
        $current = $this->unitCost($product);
        $avg = $onHand + $qty > 0
            ? (($onHand * $current) + ($qty * $unitCost)) / ($onHand + $qty)
            : $unitCost;
        $product->forceFill(['avg_cost' => round($avg, 4), 'cost' => round($avg, 2)])->save();
    }

    private function unitCost(KamsProduct $product): float
    {
        return $product->inventoryUnitCost();
    }

    private function changeBalance(KamsDepot $location, KamsProduct $product, int $delta, string $condition = 'available'): void
    {
        $balance = KamsStockBalance::query()->firstOrCreate(
            ['depot_id' => $location->id, 'product_id' => $product->id, 'condition' => $condition],
            ['company_id' => $location->company_id, 'quantity' => 0]
        );
        $locked = KamsStockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        $next = (int) $locked->quantity + $delta;
        abort_unless($next >= 0, 422, __('Insufficient stock for :product at :location.', [
            'product' => $product->name,
            'location' => $location->name,
        ]));
        $locked->quantity = $next;
        $locked->save();
    }

    private function recordMovement(
        KamsProduct $product,
        int $quantity,
        string $type,
        ?int $userId,
        ?int $fromDepotId = null,
        ?int $toDepotId = null,
        mixed $reference = null,
        ?int $memberId = null,
        ?string $notes = null,
        string $condition = 'available',
        ?int $approvedBy = null,
        ?float $unitCost = null,
    ): void {
        KamsStockMovement::query()->create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'from_depot_id' => $fromDepotId,
            'to_depot_id' => $toDepotId,
            'member_id' => $memberId,
            'quantity' => $quantity,
            'type' => $type,
            'status' => 'posted',
            'condition' => $condition,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->id,
            'unit_cost' => $unitCost ?? $this->unitCost($product),
            'notes' => $notes,
            'user_id' => $userId,
            'approved_by' => $approvedBy,
            'occurred_at' => now(),
        ]);
    }

    private function syncProductStock(KamsProduct $product): void
    {
        $stored = (int) KamsStockBalance::query()->where('product_id', $product->id)->sum('quantity');
        $product->forceFill(['stock' => $stored])->save();
    }
}
