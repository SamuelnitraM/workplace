<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class UserProfileUpdateDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 50)]
    public ?string $username = null;

    #[Assert\Length(max: 500)]
    public ?string $bio = null;

    #[Assert\Choice(['Empire', 'Alliance', 'Neutral'])]
    public ?string $favoriteFaction = null;

    public ?bool $showActivity = null;
}
