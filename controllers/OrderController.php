<?php

namespace App\Http\Controllers\Api\V1;

use Storage;
use App\Models\Order\Order;
use Illuminate\Http\Request;
use App\Models\MediaFile\MediaFile;
use App\Http\Requests\StoreOrderFront;
use App\Http\Requests\Backend\Order\StoreOrderRequest;
use App\Http\Requests\Backend\Order\UpdateOrderRequest;
use App\Models\Payment\Payment;
use App\Models\OrderItem\OrderItem;
use App\Models\OrderPackage\OrderPackage;
use App\Models\Invoice\Invoice;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\URL;
use App\Notifications\NewOrder;
use Illuminate\Notifications\Notifiable;


class OrderController extends APIController
{
    
        //======================== index orders  ======================
        public function index()
        {
            $allOrders = Order::with('products','location')->where('user_id',auth()->guard('api')->user()->id)->get();
            return response()->json(json_decode($allOrders));   
        } 
        //======================== create order StoreOrderRequest  ======================
        public function store(Request $request)
        {

        }

        public function show($id)
        {
            $order = Order::findOrFail($id);
            $orderInfo = Order::with('products','location')->where(['id'=>$id,'user_id'=>auth()->guard('api')->user()->id])->get();
            if(is_null($orderInfo)){
                return back();
            } 
            $responseCheckout = Order::prepareCheckout($order->total_price);
            return response()->json(['order'=> $orderInfo ,'responseCheckout'=>json_decode($responseCheckout)]);
        }

        public function getMedia($id)
        {
            $order = Order::findOrFail($id);
            if($order->download_file)
            {
               return response()->download(storage_path("app/public/orders-download/{$order->download_file}"));
            }
            return response()->json(['message'=>'There Is No Media File']);          
        }

        public function savePaymentInfo(StoreOrderRequest $request)
        {     
            // 1 payment successs
            if($request->status == 1 && !empty($request->bank_transaction_id))
            {    
                $orderObj = Order::CreteOrderRequest($request);
                OrderItem::where('user_id','=', $orderObj->user_id)->update(['order_id'=>$orderObj->id]);
                OrderPackage::where('user_id','=', $orderObj->user_id)->update(['order_id'=>$orderObj->id]);
                Payment::create(array_merge($request->only('bank_transaction_id','status'),['order_id'=> $orderObj ->id]));
                //send slack notifications
                $orderObj->notify(new NewOrder());
                $InvoiceEmail=Order::sendPdfInvoice($orderObj);
                return response()->json(['message'=>'Payment Process Successfully']);
            }
            else
            {
                return response()->json(['message'=>'Payment Process Failure']);
            }
        }
        //======================== order download url===========================
        public function orderDownload($orderId)
        {
           $fileName = MediaFile::where('order_id', $orderId)->value('zip_name');   
           $key = 'media_files/'.$fileName;
           $disk = \Storage::disk('s3');
           $command = $disk->getDriver()->getAdapter()->getClient()->getCommand('GetObject', [
            'Bucket'                     => \Config::get('filesystems.disks.s3.bucket'),
            'Key'                        => $key,
            ]);
           $request = $disk->getDriver()->getAdapter()->getClient()->createPresignedRequest($command, '+10 minutes');
           $generate_url = $request->getUri();
           return $generate_url;
        }    
        //=====================================
        //download Invoice
        public function downloadInvoice($orderId)
        {
           $fileName = Invoice::where('order_id', $orderId)->value('file_name');
           $key = 'orders_pdf/'.$fileName;
           $disk = \Storage::disk('s3');
           $command = $disk->getDriver()->getAdapter()->getClient()->getCommand('GetObject', [
            'Bucket'                     => \Config::get('filesystems.disks.s3.bucket'),
            'Key'                        => $key,
            ]);
           $request = $disk->getDriver()->getAdapter()->getClient()->createPresignedRequest($command, '+10 minutes');
           $Invoice_url = $request->getUri();
           return $Invoice_url;
        }
        
        //======================== view detials download===========================
        public function myDownload($id){  
            $download = MediaFile::where('order_id', $id)->first();      
            return response()->json($download);
        }

        
    
}
