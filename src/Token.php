<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;
use libJSTranspiler\TokenType;
use libJSTranspiler\SourceLocation;

class Token 
{
  public $oType;
  public $mValue;
  public $iStart;
  public $iEnd;
  public $oLoc;
  public $aRange;
  
  public function __construct($oP)
  {
    
  }
}