<?php

namespace App\Enums;

enum SubmissionState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Uncertain = 'uncertain';
    case Rejected = 'rejected';
}
