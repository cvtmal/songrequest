<?php

declare(strict_types=1);

namespace App\Requests\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lengths mirror the columns in requests and request_votes. TextType trims and turns a
 * blank submit into null, so a whitespace-only title fails NotBlank here, not in the DB.
 */
final class SongRequestData
{
    #[Assert\NotBlank(message: 'song_request.title.not_blank')]
    #[Assert\Length(max: 200, maxMessage: 'song_request.title.too_long')]
    public ?string $title = null;

    #[Assert\Length(max: 200, maxMessage: 'song_request.artist.too_long')]
    public ?string $artist = null;

    #[Assert\Length(max: 50, maxMessage: 'song_request.nickname.too_long')]
    public ?string $nickname = null;
}
