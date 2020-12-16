<?php

declare(strict_types=1);

namespace EMS\CoreBundle\Twig\Table;

final class TableAction
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var string
     */
    private $labelKey;
    /**
     * @var string
     */
    private $confirmationKey;
    /**
     * @var string
     */
    private $icon;

    public function __construct(string $name, string $icon, string $labelKey, string $confirmationKey)
    {
        $this->name = $name;
        $this->icon = $icon;
        $this->labelKey = $labelKey;
        $this->confirmationKey = $confirmationKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabelKey(): string
    {
        return $this->labelKey;
    }

    public function getConfirmationKey(): string
    {
        return $this->confirmationKey;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }
}
