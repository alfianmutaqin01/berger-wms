<?php
namespace App\Http\Controllers\Wms;
use App\Http\Controllers\Controller;
class MasterController extends Controller {
    public function customers() { return view('wms.master.customers'); }
    public function products() { return view('wms.master.products'); }
}