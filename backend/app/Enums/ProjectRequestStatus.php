<?php

namespace App\Enums;

enum ProjectRequestStatus: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Quoted = 'quoted';
    case Won = 'won';
    case Declined = 'declined';
}
