<?php

namespace App\Models\Order;
use App\Models\Product\Product;
use App\Models\Location\Location;
use App\Models\Payment\Payment;
use App\Models\Status\Status;
use App\Models\Access\User\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use App\Models\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use App\Models\Order\Traits\OrderAttribute;
use App\Models\Order\Traits\OrderRelationship;
use Storage;
use App\Models\OrderItem\OrderItem;
use App\Models\Package\Package; 
use App\Models\OrderPackage\OrderPackage;
use App\Models\MediaFile\MediaFile;
use App\Models\Quotation\Quotation;
use Illuminate\Support\Facades\Mail;
use Mpdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use App\Models\Invoice\Invoice;
use App\Models\Delivery\Delivery;
use App\Models\SubscriptionDetail\SubscriptionDetail;
use Carbon\Carbon;
use App\Notifications\NewOrder;

class Order extends Model
{
    use ModelTrait,
        Notifiable,
        OrderAttribute,
    	OrderRelationship {
            // OrderAttribute::getEditButtonAttribute insteadof ModelTrait;
        }

    protected $table = 'orders';

    /**
     * Mass Assignable fields of model
     * @var array
     */
    protected $fillable = ['user_id','status_id','coupon_code',
    'sub_total','vat','delivery_id','on_set','location_id','totalPrice','totalOnSet','totalVat','grandTotal'];


    protected $dates =['created_at'];

    //Notification 
    public function routeNotificationForSlack($notification)
    {
        return 'https://hooks.slack.com/services/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';
    }


    //relationships
    public function users()
    {
        return $this->belongsTo(User::class,'user_id','id');   
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class,'delivery_id','id');
    }

    public function location()
    {
        return $this->hasOne(Location::class,'order_id');   
    } 
    public function status()
    {
        return $this->belongsTo(Status::class,'status_id','id');   
    }  
   
    public function payment()
    {
        return $this->hasOne(Payment::class,'order_id');   
    }
    //package_order
    public function package()
    {
        return $this->belongsToMany(Package::class,'order_packages')->withPivot('order_id','package_id','quantity','type','video_length'); 
    }
    //media attach
    public function media()
    {
        return $this->hasMany(MediaFile::class ,'order_id','id');
    }
    //order Invoice
    public function invoices()
    {
        return $this->hasOne(Invoice::class ,'order_id','id');
    }

    public function products()
    {
        return $this->hasMany(OrderItem::class,'order_id');
    }

    public static function CreateOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice)
    {
        // dd($totalOnset);

        $subTotal = $totalPrice + $totalOnset + $request->delivery_price;
        $orderData = Order::create(array_merge($request->only('delivery_id','coupon_code'),
        ['user_id'=>auth()->guard('api')->user()->id,
        'grandTotal' => $grandTotal,'totalOnSet'=>$totalOnset,'totalVat'=>$totalVat ,'totalPrice'=>$totalPrice ,'sub_total'=>$subTotal ] ));

        $locationArr = array($request->location_details);
        foreach($locationArr as $key=>$value)
        {
            $data = ['country'=>$value['country'],'city'=>$value['city'],'address'=>$value['address'],'postal_code'=>$value['postal_code'],'lat'=>$value['lat'],'lng'=>$value['lng'],'rep_first_name'=>$value['rep_first_name'],'rep_phone_number'=>$value['rep_phone_number']];
        }
        Location::create(array_merge($data,['order_id'=>$orderData->id,'user_id'=>auth()->guard('api')->user()->id]));
        return $orderData;
    }

    public static function updateAdminOrder($order, $request)
    {
        $request->validate([
            'status_id' =>['numeric','not_in:0','exists:'. Status::table() .',id'],
            'media_file' => '|file|mimes:zip,rar'
        ]);
        $OrderObj=$order->update($request->only('status_id'));
        if($request->hasfile('media_file'))
        {
           $file = $request->file('media_file');
           $name = time().$file->getClientOriginalName();
           $filePath = 'media_files/' . $name;
           Storage::disk('s3')->put($filePath, file_get_contents($file));
           MediaFile::create(['auth_by'=> auth()->user()->id,'order_id'=>$order->id,
           'path'=>$filePath,'zip_name'=>$name ,'size' =>  number_format(Storage::disk('s3')->size($filePath)/ 1048576, 2)]);
           return back()->with('success','media_file Uploaded successfully');
        }
    }

    //
    public static function sendPdfInvoice($OrderObject) 
    {
        $data = [
            'subject'=> 'Purchase Invoice',
            'Invoice_Number' => $OrderObject->id,
            'user_id' => auth()->guard('api')->user()->id,
            'first_name' => auth()->guard('api')->user()->first_name,
            'last_name' => auth()->guard('api')->user()->last_name,
            'email'=> auth()->guard('api')->user()->email,
            'phone_number'=> auth()->guard('api')->user()->phone_number,
            'sub_total'=> $OrderObject->sub_total,
            'vatPercentage' => Quotation::where('name','Vat')->pluck('rate')->first(),
            'vat_value'=>$OrderObject->totalVat,
            'grandTotal' => $OrderObject->grandTotal,
            'date' =>($OrderObject->created_at)->format('M j,Y'),
            'locationInfo' => Order::with('location')->where(['id'=>$OrderObject->id,'user_id'=>auth()->guard('api')->user()->id])->get(),
            'productsInfo' => OrderItem::where('order_id',$OrderObject->id)->get(),
        ];
            
            
            $html = view('emails.email-invoice',$data)->render();
            $pdf = Mpdf::loadHTML($html);

            $fileName = time() . ".pdf";
            $filePath = 'orders_pdf/'. $fileName;
            Storage::disk('s3')->put($filePath, $pdf->output());
            Invoice::create(['order_id'=>$OrderObject->id,'file_name'=>$fileName]);
    
            Mail::send('emails.email-body',$data,function($message)use($data,$pdf) {
            $message->to($data["email"],$data["first_name"],$data["Invoice_Number"])
            ->subject($data["subject"])
            ->attachData($pdf->output(),"invoice.pdf");
            });


    }

    //get Payment Status Object Using Checkout Id
    public static function getStatus($checkoutId)
    {       
            $url = env('HYPER_PAY_URL')."/{$checkoutId}/payment";//env
            $url .= "?entityId=".env('HYPER_PAY_DATA');//env

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                      "Authorization:Bearer ".env('HYPER_PAY_HTTPHEADER').""));//env
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, env('HYPER_PAY_SSL_VERIFYPEER'));// this should be set to true in production//env
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $responseData = curl_exec($ch);
            if(curl_errno($ch)) {
                return curl_error($ch);
            }
            curl_close($ch);
            return $responseData;
    }

    public static function getOrderInfo ($orderObj)
    {
        $OrderInfo = Order::with('status')->where('id',$orderObj->id)->get();
        return $OrderInfo;
    }

    //create order Points without Resource ID
    public static function createOrderWithoutResourceId($request)
    {
            $userPoints = SubscriptionDetail::where('user_id',auth()->user()->id)->first();
            if($userPoints)
            {
                $OrderAfterPoints = Order::createOrderUserPoints($request,$userPoints);
                return $OrderAfterPoints;
            }
            else
            {
                return response()->json(['message'=>'Your Are Not Subscribe any Plan'],422);
            }
                
    } //createOrderWithoutResourceId 

    public static function createOrderUserPoints($request,$userPoints)
    {
            $allPoints = 0;
            for($i=0;$i<count($request->products);$i++)
            {
                $allPoints += $request->products[$i]['totalPrice'];
            }
            if($userPoints->purchase_points >= $allPoints)
            {
                $reponseOrder = Order::InsertOrderAfterPoints($request,$allPoints);
                return $reponseOrder;
            }
            else
            {
                    $purchaseFree = $userPoints->purchase_points + $userPoints->free_points;
                    if($purchaseFree >= $allPoints)
                    {
                        $reponseOrder = Order::InsertOrderAfterPoints($request,$allPoints);
                        return $reponseOrder;
                    }
                    else
                    {
                        return response()->json(['message'=>'Your account balance not enough'],422);
                    }
            }
    } //createOrderUserPoints


    //CreateOrderWithoutResourceId
    public static function InsertOrderAfterPoints($request, $allPoints)
    {
        $orderObject = Order::CreateOrderNoResourceId($request,$allPoints);
        $Products = OrderItem::insertProducts($request,$orderObject);
        //send slack notifications
        $orderObject->notify(new NewOrder());
        Order::sendPdfInvoice($orderObject);    
        $orderInfo = Order::getOrderInfo($orderObject);
        return response()->json($orderInfo[0],200);
    } //InsertOrderAfterPoints


    //create Order Points Using Resource ID
    public static function createOrderUsingResourceId($request)
    {
        $userPoints = SubscriptionDetail::where('user_id',auth()->user()->id)->first();
        if($userPoints)
        {
            $orderUsingSub=Order::CreateOrderUsingSubscription($request,$userPoints);
            return $orderUsingSub;
        }
        else
        {
            $orderWithoutSub = Order::CreateOrderWithoutSubscription($request);
            return  $orderWithoutSub;
        }

    } //createOrderUsingResourceId

    public static function CreateOrderUsingSubscription($request,$userPoints)
    {
        $allPoints = 0;
        $lengthOnset = 0;
        for($i=0;$i<count($request->products);$i++)
        {
            $allPoints += $request->products[$i]['totalPrice'];
            $lengthOnset += count($request->products[$i]['dates']);
        }
        if( ($userPoints->purchase_points >= $allPoints) || ( ($userPoints->purchase_points + $userPoints->free_points) >= $allPoints ) )
        {
            $totalPrice  = 0;
            $totalOnset =  $request->onset * $lengthOnset;
            $totalVat =  ($totalPrice +$totalOnset+$request->delivery_price) * (15/100);
            $grandTotal = $totalPrice  + $totalOnset + $totalVat + $request->delivery_price;
            // check grand total Match
            if($grandTotal == $request->grandTotal)
            {
                $responseOrder = Order::payOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice);
                return $responseOrder;
            }
            else
            {
                return response()->json(['error'=>'Your account balance not enough'],422);
            }
          
        }
        else
        {
            //discount
            $totalOnset = $request->onset * $lengthOnset; // 6000
            $discountValue = ( $allPoints - ($userPoints->purchase_points + $userPoints->free_points) ) * ($userPoints->discount/100); //600
            $totalPrice = ( $allPoints - ($userPoints->purchase_points + $userPoints->free_points) ) - $discountValue;  //1800
            $totalVat = ( $totalPrice + $totalOnset  + $request->delivery_price ) * (15/100); //1185
            $grandTotal = $totalPrice  + $totalOnset + $totalVat + $request->delivery_price; //9085
            if($grandTotal == $request->grandTotal)
            {
                $responseOrder = Order::payOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice);
                return $responseOrder;
            }
            else
            {
                return response()->json(['error'=>'Your account balance not enough'],422);
            }

        }
    } //CreateOrderUsingSubscription

    public static function CreateOrderWithoutSubscription ($request)
    {
        $totalPrice = 0;
        $lengthOnset = 0;
        for($i=0;$i<count($request->products);$i++)
        {
            $totalPrice += $request->products[$i]['totalPrice'];
            $lengthOnset += count($request->products[$i]['dates']);
        }

        $totalOnset = $request->onset * $lengthOnset; // 6000
        $totalVat = ( $totalPrice + $totalOnset  + $request->delivery_price ) * (15/100); 
        $grandTotal = $totalPrice  + $totalOnset + $totalVat + $request->delivery_price;
        if($grandTotal == $request->grandTotal)
        {
            $responseOrder = Order::payOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice);
            return $responseOrder;
        }
        else
        {
            return response()->json(['error'=>'Your account balance not enough'],422);
        }
    }



    public static function CreateOrderNoResourceId($request,$allPoints)
    {
        $orderData = Order::create(array_merge($request->only('coupon_code'),
        ['user_id'=>auth()->guard('api')->user()->id,
        'grandTotal' => $request->grandTotal , 'totalPrice' => $allPoints] ));

        $locationArr = array($request->location_details);
        foreach($locationArr as $key=>$value)
        {
            $data = ['country'=>$value['country'],'city'=>$value['city'],'address'=>$value['address'],'postal_code'=>$value['postal_code'],'lat'=>$value['lat'],'lng'=>$value['lng'],'rep_first_name'=>$value['rep_first_name'],'rep_phone_number'=>$value['rep_phone_number']];
        }
        Location::create(array_merge($data,['order_id'=>$orderData->id,'user_id'=>auth()->guard('api')->user()->id]));
        return $orderData;

    } //CreateOrderNoResourceIdWithSubscription

    public static function payOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice)
    {
            $responseObj = Order::getStatus($request->resource_id);
            $paymentObj = json_decode($responseObj,true);
            if(array_key_exists("amount",$paymentObj) && ($grandTotal == $paymentObj['amount']) ) //amount
            {
                $orderObj = Order::CreateOrderRequest($request,$grandTotal,$totalOnset,$totalVat,$totalPrice);
                $Products = OrderItem::insertProducts($request,$orderObj);
                Payment::create(['bank_transaction_id'=>$paymentObj['id'] ,'order_id'=> $orderObj->id]);
                $orderInfo = Order::getOrderInfo($orderObj);
                //send slack notifications
                $orderObj->notify(new NewOrder());
                Order::sendPdfInvoice($orderObj);
                return response()->json($orderInfo[0], 200);
            }
            else
            {
                $responseObj=json_decode($responseObj,true);
                return response()->json(["description"=>$responseObj['result']['description']], 422);
            }
    } //payOrderRequest


    
}