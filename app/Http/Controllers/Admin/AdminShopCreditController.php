<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Employee\EmployeeShopCreditController;

/**
 * Administrators read and correct every shop's credit ledger; only the
 * assignment guard in the parent class differs, nothing else does.
 */
class AdminShopCreditController extends EmployeeShopCreditController {}
