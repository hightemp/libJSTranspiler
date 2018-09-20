<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;
use libJSTranspiler\SourceLocation;

class Node 
{
  public $sType;
  public $iStart;
  public $iEnd;
  public $oLoc;
  public $sSourceFile;
  public $aRange;
          
  public function __construct($oParser, $iPos, $oLoc)
  {
    
  }
}
