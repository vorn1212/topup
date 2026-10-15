<?php

namespace App\Enums;

enum ProviderOrderStatus: string
{
    case Queued = 'queued';           // not sent yet
    case Submitting = 'submitting';   // a worker is sending it right now
    case Unknown = 'unknown';         // the last send was unclear: will be re-sent with the SAME key
    case Processing = 'processing';   // the provider has it: poll its status
    case Completed = 'completed';
    case Failed = 'failed';
    case Review = 'review';           // automatic handling stopped: a human decides
}