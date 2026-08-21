<?php
namespace App\Http\Controllers\Wms;
use App\Http\Controllers\Controller;
class AdminController extends Controller {
    public function users() { return view('wms.admin.users'); }
    public function sequence() { return view('wms.admin.sequence'); }
}