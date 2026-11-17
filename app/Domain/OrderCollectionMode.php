<?php

namespace App\Domain;

enum OrderCollectionMode: string
{
    case WaiterAccount = 'WAITER_ACCOUNT';
    case PaidTicket = 'PAID_TICKET';
}
