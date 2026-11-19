<?php

namespace App\Domain;

enum OrderSource: string
{
    case Waiter = 'WAITER';
    case CashierDirect = 'CASHIER_DIRECT';
}
