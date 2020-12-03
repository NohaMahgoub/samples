<?php

namespace App\Http\Controllers\Api\V1;
use App\Http\Utilities\NotificationIos;
use App\Http\Utilities\PushNotification;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Socialite;
use Illuminate\Support\Facades\Auth;
use App\Models\Access\User\User;
use App\Models\SubscriptionDetail\SubscriptionDetail;


/**
 * Class SocialLoginController.
 */
class SocialLoginController extends APIController
{
    use AuthenticatesUsers;

    /**
     * @var \App\Http\Utilities\PushNotification
     */
    protected $notification;

    /**
     * @param NotificationIos $notification
     */
    public function __construct(PushNotification $notification)
    {
        $this->notification = $notification;

    }
    //========================= social login =============================
    public function redirectToProvider($provider)
    {
        return Socialite::driver($provider)->redirect();
    }
    public function handleProviderCallback($provider)
    {
        $user = Socialite::driver($provider)->stateless()->user();

        if($userInfo=User::where('email','=',$user->email)->first())
        {
            $passportToken = $userInfo->createToken('API Access Token');
            $passportToken->token->save();
            $token = $passportToken->accessToken;
            $subscription = SubscriptionDetail::where('user_id',$userInfo->id)->get();
            return response()->json(['user'=>$userInfo ,'message'=>"Login Successfully",'token'=>$token,
            'subscription_details'=>$subscription]);
        }
        $userInfo = User::CreateUser($user, $provider);
        Auth::login($userInfo,true);
        $passportToken = $userInfo->createToken('API Access Token');
        $passportToken->token->save();
        $token = $passportToken->accessToken;
        $subscription = SubscriptionDetail::where('user_id',$userInfo->id)->get();
        return response()->json([
           'user'   => $userInfo,
           'message'=>"Login Successfully",
           'token'=>$token,
           'subscription_details'=>$subscription
        ]);
    }
   
}