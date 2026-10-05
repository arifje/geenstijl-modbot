<?php

namespace App\Enums;

enum Category: string
{
    case HateSpeech = 'haatspraak';
    case Discrimination = 'discriminatie';
    case Threat = 'bedreiging';
    case PersonalAttack = 'persoonlijke_aanval';
    case SexualHarassment = 'seksuele_intimidatie';
    case Privacy = 'privacyschending';
    case Spam = 'spam';
    case Manipulation = 'manipulatie';
    case Disruption = 'verstoren';
    case ModerationComplaint = 'moderatieklacht';

    public function label(): string
    {
        return match ($this) {
            self::HateSpeech => 'Haatspraak / racisme / antisemitisme',
            self::Discrimination => 'Discriminatie',
            self::Threat => 'Bedreiging / aanzetten tot geweld',
            self::PersonalAttack => 'Persoonlijke aanval / intimidatie',
            self::SexualHarassment => 'Seksuele intimidatie / seksistische bejegening',
            self::Privacy => 'Privacyschending',
            self::Spam => 'Spam / oplichting / phishing',
            self::Manipulation => 'Manipulatie / vermoedelijk dubbelaccount',
            self::Disruption => 'Herhaald verstoren van de discussie',
            self::ModerationComplaint => 'Moderatieklacht in reactiepaneel',
        };
    }
}
