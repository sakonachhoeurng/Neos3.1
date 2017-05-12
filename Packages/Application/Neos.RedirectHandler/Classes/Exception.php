<?php
namespace Neos\RedirectHandler;

/*
 * This file is part of the Neos.RedirectHandler package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Http;

/**
 * A generic RedirectHandler exception
 *
 * @api
 */
class Exception extends Http\Exception
{
    /**
     * @param integer $statusCode
     * @return void
     */
    public function setStatusCode($statusCode) {
        $this->statusCode = $statusCode;
    }
}
