<?php

namespace App\Currency\Enums;

enum RateSource: string
{
    case NBRB = 'nbrb';
    case KRAKEN = 'kraken';
}
