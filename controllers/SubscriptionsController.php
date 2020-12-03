<?php

namespace App\Http\Controllers\Api\V1;

use App\Mail\ReminderMail;
use Illuminate\Support\Facades\Mail;
use App\Models\Subscription\Subscription;
use App\Models\Quotation\Quotation;
use App\Models\SubscriptionDetail\SubscriptionDetail;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Notifications\Notifiable;
use App\Notifications\NewSubscription;



class SubscriptionsController extends Controller
{
   
    public function index()
    {
        $subscriptions = Subscription::with('delivery')->get()->makeHidden(['created_at','updated_at']);
        return response()->json($subscriptions); 
    }
  
    public function show($id)
    {
        $subscription = Subscription::findOrFail($id);
        return response()->json($subscription);
    }

    public function subscribe($id,$resource_id)
    { 
        //payment
        $responseObj = SubscriptionDetail::getStatus($resource_id);
        $paymentObj = json_decode($responseObj,true);
        //the plan
        $subscription =  Subscription::findOrFail($id);        
                if(array_key_exists("id",$paymentObj)  && !empty($paymentObj['id']) ) //id
                {
                            // this function contains (subscribe + change plan)
                                $UserSubscription = SubscriptionDetail::where('user_id',auth()->guard('api')->user()->id)->get();
                                if(!$UserSubscription->isEmpty()){ 
                                        
                                        //if the user has old plan
                                        $oldSubscription = SubscriptionDetail::where('user_id',auth()->guard('api')->user()->id)->first();                               
                                        //check if user already subscribe in this plan 
                                        $oldPlan = SubscriptionDetail::oldPlan($oldSubscription ,$id); 
                                        if($oldPlan==true){
                                            // upgrade or downgrade the plan or re_subscribe in the same plan
                                            $updatedPlan=SubscriptionDetail::changePlane($id,$paymentObj['id']); 
                                            $subscriber =  SubscriptionDetail::with('user')->where('id', $updatedPlan->id)->first();
                                            //send slack notification
                                            $updatedPlan->notify(new NewSubscription());
                                            //pdf & email
                                            $subscriptionData = SubscriptionDetail::getSubscriptionData($subscriber,$subscription);
                                            SubscriptionDetail::sendInvoicePdf($subscriptionData); 
                                            return response()->json(['updatedPlan'=> json_decode($updatedPlan) ,'message' => 'You are Successfully Subscribe to a New Plan..']); 
                                        }else{
                                            return response()->json("you are already subscriber in this plan..!",403); 
                                        }
                                }else{
                                        //for new subscription 
                                        $newSubscription=SubscriptionDetail::newSubscription($id,$paymentObj['id']);
                                        $subscriber =  SubscriptionDetail::with('user')->where('id', $newSubscription->id)->first();
                                        //send slack notification
                                        $newSubscription->notify(new NewSubscription());
                                        //pdf & email
                                        $subscriptionData = SubscriptionDetail::getSubscriptionData($subscriber,$subscription);
                                        SubscriptionDetail::sendInvoicePdf($subscriptionData);
                                        return response()->json(['updatedPlan'=> json_decode($newSubscription) ,'message' => 'You are Successfully Subscribe in a New Plan..']);            

                                    } 
                }else{
                    $responseObj=json_decode($responseObj,true);
                    return response()->json($responseObj['result'],422);
                    
                }
           

    }

    public function updatePoints(Request $request){
        $userSubscription=SubscriptionDetail::updateUserPoints($request); 
        return $userSubscription;
    }

    public function subscriptionPrepareCheckout(Request $request)
     {
        //the plan 
        $subscription =  Subscription::findOrFail($request->id);
        $planPrice = number_format($subscription->price,2, '.', '');
        $totalPrice = number_format($request->total_price,2, '.', '');
        $vat =  Quotation::where('name', 'VAT')->value('rate');
        $vatValue = $totalPrice*$vat/100;
        $priceWithVat=  number_format($vatValue+$totalPrice,2, '.', '');
        // Check if plan price equals total price

         if($planPrice == $totalPrice){
                $url = env('HYPER_PAY_URL'); //env
                $data = "entityId=".env('HYPER_PAY_DATA').//env
                        "&amount=$priceWithVat".
                        "&currency=".env('HYPER_PAY_CURRENCY').//env
                        "&paymentType=DB";
    
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            "Authorization:Bearer ".env('HYPER_PAY_HTTPHEADER').""));//env
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, env('HYPER_PAY_SSL_VERIFYPEER'));// this should be set to true in production  //env
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $responseData = curl_exec($ch);
                if(curl_errno($ch)) {
                    return curl_error($ch);
                }
                curl_close($ch);
                
                $checkoutObject = json_decode($responseData,true);
                $code= $checkoutObject['result']['code'];
                $pattern = "/^(000\.200)/";
                if (preg_match($pattern, $code)){
                        return $responseData;       
                }
                return response()->json(["description"=>$checkoutObject['result']['description']], 422);

            }else{
                return response()->json(['error'=>'amount missmatch'],422);            
            }

     } //prepareCheckout
  
}