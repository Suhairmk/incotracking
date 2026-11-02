<?php

namespace App\Enums;

enum VisitOutcome: string
{
    case Successful = 'successful';
    case NotAvailable = 'not_available';
    case Interested = 'interested';
    case FollowUp = 'follow_up';
    case OrderReceived = 'order_received';
    case NotInterested = 'not_interested';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Successful => 'Successful Visit',
            self::NotAvailable => 'Customer Not Available',
            self::Interested => 'Interested',
            self::FollowUp => 'Follow-up Required',
            self::OrderReceived => 'Order Received',
            self::NotInterested => 'Not Interested',
            self::Other => 'Other',
        };
    }
}
