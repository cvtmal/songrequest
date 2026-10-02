<?php

declare(strict_types=1);

namespace App\Events\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The length mirrors events.name VARCHAR(120). TextType trims, so a blank name fails NotBlank.
 */
final class EventData
{
    #[Assert\NotBlank(message: 'event.name.not_blank')]
    #[Assert\Length(max: 120, maxMessage: 'event.name.too_long')]
    public ?string $name = null;
}
