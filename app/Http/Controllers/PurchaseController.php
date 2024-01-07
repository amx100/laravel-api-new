<?php


namespace App\Http\Controllers;

use App\Http\Filters\PurchasesFilter;
use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\Request;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\PurchaseCollection;

use App\Models\Drug;
use App\Http\Requests\BulkStorePurchaseRequest;
use Illuminate\Support\Arr;

class PurchaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filter = new PurchasesFilter();
        $filterItems = $filter->transform($request);
        
        $purchases = Purchase::where($filterItems);

        $purchases = $purchases->paginate();
    
        return new PurchaseCollection($purchases->appends(request()->query()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePurchaseRequest $request)
    {
        $purchaseData = $request->all();

        $drug = Drug::find($purchaseData['DRUG_ID']);
        if ($drug && $drug->EXPIRATION_DATE < now()) {
            return response()->json(['message' => 'Izvinjavamo se, ali lek je istekao.'], 400);
        }

        $purchase = Purchase::create($purchaseData);
        $drug = $purchase->drug;
        $newQuantity = $drug->QUANTITY - $purchase->QUANTITY_PURCHASED;
        $drug->update(['QUANTITY' => max(0, $newQuantity)]);
    
        return new PurchaseResource($purchase);
    }

    public function bulkStore(BulkStorePurchaseRequest $request)
    {
        $bulk = collect($request->all())->map(function ($arr) {
            $drug = Drug::find($arr['DRUG_ID']);
            if ($drug && $drug->EXPIRATION_DATE < now()) {
                return [
                    'CUSTOMER_ID' => $arr['CUSTOMER_ID'],
                    'DRUG_ID' => $arr['DRUG_ID'],
                    'error' => 'Izvinjavamo se, ali lek je istekao.',
                ];
            }

            $arr['TOTAL_BILL'] = $arr['TOTAL_BILL'] ?? null;

            return $arr;
        });

        $expiredDrugs = $bulk->where('error', '!=', null)->all();

        if (!empty($expiredDrugs)) {
            return response()->json(['message' => 'Neki lekovi su istekli.', 'expired_drugs' => $expiredDrugs], 400);
        }

        $bulk = $bulk->reject(function ($arr) {
            return isset($arr['error']);
        });

        Purchase::insert($bulk->toArray());

        return response()->json(['message' => 'Bulk insert successful'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Purchase $purchase)
    {
    
        return new PurchaseResource($purchase);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePurchaseRequest $request, Purchase $purchase)
    {
        $originalQuantityPurchased = $purchase->QUANTITY_PURCHASED;

     
        $purchase->update($request->all());
    
   
        if ($request->has('IS_REFUNDED') && $request->input('IS_REFUNDED') === true || $request->input('IS_REFUNDED') == 1) {
       
            $drug = $purchase->drug;
            $newQuantity = $drug->QUANTITY + $originalQuantityPurchased;
            $drug->update(['QUANTITY' => $newQuantity]);
        }
    
        return new PurchaseResource($purchase);;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Purchase $purchase)
    {
        $purchase->delete();
        return response()->json(null, 204);
    }
}
