<?php
namespace GCWorld\Common\Interfaces;

/**
 * CommonEnvironmentEnumInterface Interface
 */
interface CommonEnvironmentEnumInterface extends \BackedEnum
{
    public function getName(): string;
}