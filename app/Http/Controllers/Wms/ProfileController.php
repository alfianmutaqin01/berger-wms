<?php
namespace App\Http\Controllers\Wms;
use App\Http\Controllers\Controller;
class ProfileController extends Controller {
    public function index() { return view('wms.profile'); }
    public function updatePassword() { return redirect()->back()->with('success', 'Kata sandi berhasil diperbarui.'); }
}