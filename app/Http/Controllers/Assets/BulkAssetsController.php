<?php

namespace App\Http\Controllers\Assets;

use App\Models\Actionlog;
use App\Helpers\Helper;
use App\Http\Controllers\CheckInOutRequest;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Setting;
use App\View\Label;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\Http\Requests\AssetCheckoutRequest;
use App\Models\CustomField;

class BulkAssetsController extends Controller
{
    use CheckInOutRequest;

    /**
     * Display the bulk edit page.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @return View
     * @internal param int $assetId
     * @since [v2.0]
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function edit(Request $request)
    {
        $this->authorize('view', Asset::class);
      
        if (! $request->filled('ids')) {
            return redirect()->back()->with('error', trans('admin/hardware/message.update.no_assets_selected'));
        }

        // Figure out where we need to send the user after the update is complete, and store that in the session
        $bulk_back_url = request()->headers->get('referer');
        session(['bulk_back_url' => $bulk_back_url]);

        $asset_ids = array_values(array_unique($request->input('ids')));
       
        //custom fields logic
        $asset_custom_field = Asset::with(['model.fieldset.fields', 'model'])->whereIn('id', $asset_ids)->whereHas('model', function ($query) {
            return $query->where('fieldset_id', '!=', null);
        })->get();

        $models = $asset_custom_field->unique('model_id');  
        $modelNames = [];
        foreach($models as $model) {
            $modelNames[] = $model->model->name;
        } 

        if ($request->filled('bulk_actions')) {
            switch ($request->input('bulk_actions')) {
                case 'labels':
                    $this->authorize('view', Asset::class);
                    $assets_found = Asset::find($asset_ids);
                    
                    if ($assets_found->isEmpty()){
                        return redirect()->back();
                    }

                    return (new Label)
                        ->with('assets', $assets_found)
                        ->with('settings', Setting::getSettings())
                        ->with('bulkedit', true)
                        ->with('count', 0);

                case 'delete':
                    $this->authorize('delete', Asset::class);
                    $assets = Asset::with('assignedTo', 'location')->find($asset_ids);
                    $assets->each(function ($asset) {
                        $this->authorize('delete', $asset);
                    });

                    return view('hardware/bulk-delete')->with('assets', $assets);
                   
                case 'restore':
                    $this->authorize('update', Asset::class);
                    $assets = Asset::withTrashed()->find($asset_ids); 
                    $assets->each(function ($asset) {
                        $this->authorize('delete', $asset);
                    });

                    return view('hardware/bulk-restore')->with('assets', $assets);

                case 'edit':
                    $this->authorize('update', Asset::class);
                    return view('hardware/bulk')
                        ->with('assets', $asset_ids)
                        ->with('statuslabel_list', Helper::statusLabelList())
                        ->with('models', $models->pluck(['model'])) 
                        ->with('modelNames', $modelNames);
            }
        }

        return redirect()->back()->with('error', 'No action selected');
    }

    /**
     * Save bulk edits
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @return Redirect
     * @internal param array $assets
     * @since [v2.0]
     */
    public function update(Request $request)
    {
        $this->authorize('update', Asset::class);
        $has_errors = 0;
        $error_array = array();

        // Get the back url from the session and then destroy the session
        $bulk_back_url = route('hardware.index');
        if ($request->session()->has('bulk_back_url')) {
            $bulk_back_url = $request->session()->pull('bulk_back_url');
        }

       $custom_field_columns = CustomField::all()->pluck('db_column')->toArray();
     
        if (Session::exists('ids')) {
            $assets = Session::get('ids'); 
        } elseif (! $request->filled('ids') || count($request->input('ids')) <= 0) {
            return redirect($bulk_back_url)->with('error', trans('admin/hardware/message.update.no_assets_selected'));
        }
         
        $assets = array_keys($request->input('ids'));
        
       if ($request->anyFilled($custom_field_columns)) {
           $custom_fields_present = true;
         } else {
           $custom_fields_present = false;
         }
        if (($request->filled('purchase_date'))
            || ($request->filled('expected_checkin'))
            || ($request->filled('purchase_cost'))
            || ($request->filled('supplier_id'))
            || ($request->filled('order_number'))
            || ($request->filled('warranty_months'))
            || ($request->filled('rtd_location_id'))
            || ($request->filled('requestable'))
            || ($request->filled('company_id'))
            || ($request->filled('status_id'))
            || ($request->filled('model_id'))
            || ($request->filled('next_audit_date'))
            || ($request->filled('null_purchase_date'))
            || ($request->filled('null_expected_checkin_date'))
            || ($request->filled('null_next_audit_date'))
            || ($request->anyFilled($custom_field_columns))

        ) {
            foreach ($assets as $assetId) {

                $this->update_array = [];

                $this->conditionallyAddItem('purchase_date')
                    ->conditionallyAddItem('expected_checkin')
                    ->conditionallyAddItem('order_number')
                    ->conditionallyAddItem('requestable')
                    ->conditionallyAddItem('status_id')
                    ->conditionallyAddItem('supplier_id')
                    ->conditionallyAddItem('warranty_months')
                    ->conditionallyAddItem('next_audit_date');
                    foreach ($custom_field_columns as $key => $custom_field_column) {
                        $this->conditionallyAddItem($custom_field_column); 
                   } 

                $asset = Asset::find($assetId);
				
		if (!($asset->eol_explicit)) {
                    if ($request->filled('model_id')) {
			$model = \App\Models\AssetModel::find($request->input('model_id'));
			if ($model->eol > 0) {
				if ($request->filled('purchase_date')) {
					$this->update_array['asset_eol_date'] = Carbon::parse($request->input('purchase_date'))->addMonths($model->eol)->format('Y-m-d');
				} else {
					$this->update_array['asset_eol_date'] = Carbon::parse($asset->purchase_date)->addMonths($model->eol)->format('Y-m-d');
				}
			} else {
				$this->update_array['asset_eol_date'] = null;
			}
                    } elseif (($request->filled('purchase_date')) && ($asset->model->eol > 0)) {
				$this->update_array['asset_eol_date'] = Carbon::parse($request->input('purchase_date'))->addMonths($asset->model->eol)->format('Y-m-d');
                    }
		}
				
		if ($request->input('null_purchase_date')=='1') {
                    $this->update_array['purchase_date'] = null;
                    if (!($asset->eol_explicit)) {
			$this->update_array['asset_eol_date'] = null;
                    }
                }

                if ($request->input('null_expected_checkin_date')=='1') {
                    $this->update_array['expected_checkin'] = null;
                }

                if ($request->input('null_next_audit_date')=='1') {
                    $this->update_array['next_audit_date'] = null;
                }

                if ($request->filled('purchase_cost')) {
                    $this->update_array['purchase_cost'] =  $request->input('purchase_cost');
                }


                if ($request->filled('company_id')) {
                    $this->update_array['company_id'] = $request->input('company_id');
                    if ($request->input('company_id') == 'clear') {
                        $this->update_array['company_id'] = null;
                    }
                }

                if ($request->filled('rtd_location_id')) {
                    if (($request->filled('update_real_loc')) && (($request->input('update_real_loc')) == '0')) {
                        $this->update_array['rtd_location_id'] = $request->input('rtd_location_id');
                    }
                    if (($request->filled('update_real_loc')) && (($request->input('update_real_loc')) == '1')) {
                        $this->update_array['location_id'] = $request->input('rtd_location_id');
                        $this->update_array['rtd_location_id'] = $request->input('rtd_location_id');
                    }
                    if (($request->filled('update_real_loc')) && (($request->input('update_real_loc')) == '2')) {
                        $this->update_array['location_id'] = $request->input('rtd_location_id');
                    }
                }

                $changed = [];

                foreach ($this->update_array as $key => $value) {
                    if ($this->update_array[$key] != $asset->{$key}) {
                        $changed[$key]['old'] = $asset->{$key};
                        $changed[$key]['new'] = $this->update_array[$key];
                    }
                }
 
                if ($custom_fields_present) {

                    $model = $asset->model()->first();

                    // Use the rules of the new model fieldsets if the model changed
                    if ($request->filled('model_id')) {
                        $this->update_array['model_id'] =  $request->input('model_id');
                        $model = \App\Models\AssetModel::find($request->input('model_id'));
                    }


                    // Make sure this model is valid
                    $assetCustomFields = ($model) ? $model->fieldset : null;

                    if ($assetCustomFields && $assetCustomFields->fields) {

                            foreach ($assetCustomFields->fields as $field) {

                                if ((array_key_exists($field->db_column, $this->update_array)) && ($field->field_encrypted=='1')) {
                                    $decrypted_old = Helper::gracefulDecrypt($field, $asset->{$field->db_column});

                                    /*
                                     * Check if the decrypted existing value is different from one we just submitted
                                     * and if not, pull it out of the object since it shouldn't really be updating at all.
                                     * If we don't do this, it will try to re-encrypt it, and the same value encrypted two
                                     * different times will have different values, so it will *look* like it was updated
                                     * but it wasn't.
                                     */
                                    if ($decrypted_old != $this->update_array[$field->db_column]) {
                                        $asset->{$field->db_column} = \Crypt::encrypt($this->update_array[$field->db_column]);
                                    } else {
                                        /*
                                         * Remove the encrypted custom field from the update_array, since nothing changed
                                         */
                                        unset($this->update_array[$field->db_column]);
                                        unset($asset->{$field->db_column});
                                    }

                                /*
                                 * These custom fields aren't encrypted, just carry on as usual
                                 */
                                } else {


                                    if ((array_key_exists($field->db_column, $this->update_array)) && ($asset->{$field->db_column} != $this->update_array[$field->db_column])) {

                                        // Check if this is an array, and if so, flatten it
                                        if (is_array($this->update_array[$field->db_column])) {
                                            $asset->{$field->db_column} = implode(', ', $this->update_array[$field->db_column]);
                                        } else {
                                            $asset->{$field->db_column} = $this->update_array[$field->db_column];
                                        }
                                    }
                                }

                            } // endforeach
                    } // end custom field check
                } // end custom fields handler



                // Check if it passes validation, and then try to save
                if (!$asset->update($this->update_array)) {

                    // Build the error array
                    foreach ($asset->getErrors()->toArray() as $key => $message) {
                        for ($x = 0; $x < count($message); $x++) {
                            $error_array[$key][] = trans('general.asset') . ' ' . $asset->id . ': ' . $message[$x];
                            $has_errors++;
                        }
                    }

                }  // end if saved

            } // end asset foreach

            if ($has_errors > 0) {
                return redirect($bulk_back_url)->with('bulk_asset_errors', $error_array);
            }

            return redirect($bulk_back_url)->with('success', trans('admin/hardware/message.update.success'));
        }
        // no values given, nothing to update
        return redirect($bulk_back_url)->with('warning', trans('admin/hardware/message.update.nothing_updated'));
    }

    /**
     * Array to store update data per item
     * @var array
     */
    private $update_array;

    /**
     * Adds parameter to update array for an item if it exists in request
     * @param  string $field field name
     * @return BulkAssetsController Model for Chaining
     */
    protected function conditionallyAddItem($field)
    {
        if (request()->filled($field)) {
            $this->update_array[$field] = request()->input($field);
        }

        return $this;
    }

    /**
     * Save bulk deleted.
     *
     * @author [A. Gianotto] [<snipe@snipe.net>]
     * @param Request $request
     * @return View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @internal param array $assets
     * @since [v2.0]
     */
    public function destroy(Request $request)
    {
        $this->authorize('delete', Asset::class);

        $bulk_back_url = route('hardware.index');
        if ($request->session()->has('bulk_back_url')) {
            $bulk_back_url = $request->session()->pull('bulk_back_url');
        }

        if ($request->filled('ids')) {
            $assets = Asset::find($request->get('ids'));
            foreach ($assets as $asset) {
                $update_array['deleted_at'] = date('Y-m-d H:i:s');
                $update_array['assigned_to'] = null;

                DB::table('assets')
                    ->where('id', $asset->id)
                    ->update($update_array);
            } // endforeach

            return redirect($bulk_back_url)->with('success', trans('admin/hardware/message.delete.success'));
            // no values given, nothing to update
        }

        return redirect($bulk_back_url)->with('error', trans('admin/hardware/message.delete.nothing_updated'));
    }

    /**
     * Show Bulk Checkout Page
     * @return View View to checkout multiple assets
     */
    public function showCheckout()
    {
        $this->authorize('checkout', Asset::class);
        // Filter out assets that are not deployable.

        return view('hardware/bulk-checkout');
    }

    /**
     * Process Multiple Checkout Request
     * @return View
     */
    public function storeCheckout(AssetCheckoutRequest $request)
    {

        $this->authorize('checkout', Asset::class);

        try {
            $admin = Auth::user();

            $target = $this->determineCheckoutTarget();

            if (! is_array($request->get('selected_assets'))) {
                return redirect()->route('hardware.bulkcheckout.show')->withInput()->with('error', trans('admin/hardware/message.checkout.no_assets_selected'));
            }

            $asset_ids = array_filter($request->get('selected_assets'));

            if (request('checkout_to_type') == 'asset') {
                foreach ($asset_ids as $asset_id) {
                    if ($target->id == $asset_id) {
                        return redirect()->back()->with('error', 'You cannot check an asset out to itself.');
                    }
                }
            }
            $checkout_at = date('Y-m-d H:i:s');
            if (($request->filled('checkout_at')) && ($request->get('checkout_at') != date('Y-m-d'))) {
                $checkout_at = e($request->get('checkout_at'));
            }

            $expected_checkin = '';

            if ($request->filled('expected_checkin')) {
                $expected_checkin = e($request->get('expected_checkin'));
            }

            $errors = [];
            DB::transaction(function () use ($target, $admin, $checkout_at, $expected_checkin, $errors, $asset_ids, $request) {
                foreach ($asset_ids as $asset_id) {
                    $asset = Asset::findOrFail($asset_id);
                    $this->authorize('checkout', $asset);

                    $error = $asset->checkOut($target, $admin, $checkout_at, $expected_checkin, e($request->get('note')), $asset->name, null);

                    if ($target->location_id != '') {
                        $asset->location_id = $target->location_id;
                        $asset->unsetEventDispatcher();
                        $asset->save();
                    }

                    if ($error) {
                        array_merge_recursive($errors, $asset->getErrors()->toArray());
                    }
                }
            });

            if (! $errors) {
                // Redirect to the new asset page
                return redirect()->to('hardware')->with('success', trans('admin/hardware/message.checkout.success'));
            }
            // Redirect to the asset management page with error
            return redirect()->route('hardware.bulkcheckout.show')->with('error', trans('admin/hardware/message.checkout.error'))->withErrors($errors);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('hardware.bulkcheckout.show')->with('error', $e->getErrors());
        }
        
    }
    public function restore(Request $request) {
        $this->authorize('update', Asset::class);
       $assetIds = $request->get('ids');
      if (empty($assetIds)) {
          return redirect()->route('hardware.index')->with('error', trans('admin/hardware/message.restore.nothing_updated'));
        } else {
            foreach ($assetIds as $key => $assetId) {
                    $asset = Asset::withTrashed()->find($assetId);
                    $asset->restore(); 
            } 
        return redirect()->route('hardware.index')->with('success', trans('admin/hardware/message.restore.success'));
        }
    }
}
