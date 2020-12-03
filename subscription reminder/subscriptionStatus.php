<?php

namespace App\Console\Commands;
use Carbon\Carbon;
use App\Mail\ReminderMail;
use App\Models\SubscriptionDetail\SubscriptionDetail;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;


class subscriptionStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'status:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'this will change status to 0 after 14 day! and send email before two days!';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    { 
        //=====================================================================
        $today = Carbon::now()->toDateString();
        $activeUse = SubscriptionDetail::with('user')->whereNotNull('subscription_id')->where('status', 1)->get();
        $inactiveUsers= SubscriptionDetail::with('user')->where('status', 0)->get();
        //================== Reminder at  Day 28  of  Subscription  ===========================
        foreach ($activeUse as $user) {
            $secondReminderDay = Carbon::parse($user->end_date)->subDays(2)->toDateString();
            if($secondReminderDay == $today){
                $userReminder = SubscriptionDetail::with('user')->where('id', $user->user->id)->get();
                Mail::to($user->user->email)->send(new ReminderMail($user,1,0));
            } 
        }
        //=====================  Reminder at Day 30 Subscription & change status after 30 day ====================================
        foreach ($activeUse as $user) {
            $inactivateDay = Carbon::parse($user->end_date)->addDays(1)->toDateString();
            if($today == $user->end_date){
                Mail::to($user->user->email)->send(new ReminderMail($user,2,0));
            }
            if($today == $inactivateDay){
                $inactiveUser = subscriptionDetail::where('id', $user->id)->update(array('status' => 0,'bank_transaction_id' => Null,'discount' => 0,'free_points' => 0));
            }
        }
        //================= Subscription Last Reminder 14 day =======================================
        foreach ($inactiveUsers as $user){
            $thirdRemindDay = Carbon::parse($user->end_date)->addDays(10)->toDateString();
            if($thirdRemindDay == $today){
                Mail::to($user->user->email)->send(new ReminderMail($user,3,0));
            }
        }
       //================= delete all points after 14 day =======================================
       foreach ($inactiveUsers as $user){
            $lastDay = Carbon::parse($user->end_date)->addDays(14)->toDateString();
            if($lastDay == $today){
                $inactiveUser = subscriptionDetail::where('id', $user->id)->update(array('purchase_points' => 0));
            }
        }
    }
}
