<?php

namespace Chaungoclong\Container\Exceptions;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{

}