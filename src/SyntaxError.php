<?php

namespace libJSTranspiler;

use Exception;

class SyntaxError extends Exception
{
  public $iPos;
  public $oLoc;
  public $iRaisedAt;
}

