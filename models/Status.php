<?php

namespace App\Models\Status;

use App\Models\ModelTrait;
use Illuminate\Database\Eloquent\Model;
use App\Models\Status\Traits\StatusAttribute;
use App\Models\Status\Traits\StatusRelationship;
use App\Models\SharedModel;
use Storage;

class Status extends Model
{
    use ModelTrait,
        StatusAttribute,
        SharedModel,
    	StatusRelationship {
            // StatusAttribute::getEditButtonAttribute insteadof ModelTrait;
        }

    /**
     * NOTE : If you want to implement Soft Deletes in this model,
     * then follow the steps here : https://laravel.com/docs/5.4/eloquent#soft-deleting
     */

    /**
     * The database table used by the model.
     * @var string
     */
    protected $table = 'statuses';

    /**
     * Mass Assignable fields of model
     * @var array
     */
    protected $fillable = [

    ];

    /**
     * Default values for model fields
     * @var array
     */
    protected $attributes = [

    ];

    /**
     * Dates
     * @var array
     */
    protected $dates = [
        'created_at',
        'updated_at'
    ];

    /**
     * Guarded fields of model
     * @var array
     */
    protected $guarded = [
        'id'
    ];

    /**
     * Constructor of Model
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public static function insertStatus ($request)
    {
        if(!empty($request['icon']))
        {
            // Get filename with the extension
            $filenameWithExt = $request['icon']->getClientOriginalName();
            // dd($filenameWithExt);
            // Get just filename
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            // Get just ext
            $extension = $request['icon']->getClientOriginalExtension();
            // Filename to store
            $fileNameToStore= $filename.'_'.time().'.'.$extension;
            // Upload Image
            // $path = $request['icon']->storeAs('public/statuses', $fileNameToStore);
        } else {
            $fileNameToStore = 'noimage.jpg';
        }
        // $fileNameToStore = "/".$fileNameToStore;
        // $url=  asset('storage/app/public/statuses').$fileNameToStore;
           $file = $request['icon'];
           $filePath = 'status/' . $fileNameToStore;
           Storage::disk('s3MyImages')->put($filePath, file_get_contents($file));
           $url= "https://s3.me-south-1.amazonaws.com/shapelist.images/".$filePath;
           $status = Status::create(['type'=> $request['type'] ,'type_ar'=> $request['type_ar'] ,'color'=> $request['color'] , 'icon'=>$url]);
         return $status;
    }

    public static function updateStatus($status,$request)
    {
        if(!empty($request['icon']))
        {
            $old_image_path = public_path() .  '/storage/statuses/' . $status->icon;  // prev image path
            if (file_exists($old_image_path)) {
                @unlink($old_image_path);
            }
            // Get filename with the extension
            $filenameWithExt = $request['icon']->getClientOriginalName();
            // Get just filename
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
            // Get just ext
            $extension = $request['icon']->getClientOriginalExtension();
            // Filename to store
            $fileNameToStore= $filename.'_'.time().'.'.$extension;
            // Upload Image

            $file = $request['icon'];
            $filePath = 'status/' . $fileNameToStore;
            Storage::disk('s3MyImages')->put($filePath, file_get_contents($file));
            $url= "https://s3.me-south-1.amazonaws.com/shapelist.images/".$filePath;

            // $fileNameToStore = "/".$fileNameToStore;
            // $url=  asset('storage/app/public/statuses').$fileNameToStore;


            $status->update(['type'=> $request['type'] ,'type_ar'=> $request['type_ar'] ,'color'=> $request['color'], 'icon'=>$url]);
        } 

        $status->update(['type'=> $request['type'],'type_ar'=> $request['type_ar'],'color'=> $request['color']]);

        return $status;
    }

}
