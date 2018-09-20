<?php

namespace libJSTranspiler;

use libJSTranspiler\Parser;

class Position
{
  public $iLine; 
  public $iColumn; 
  public $iOffset;
}

class SourceLocation 
{
  public $oStart;
  public $oEnd;
  public $sSource;
  
  public function __construct($oP, $oStart, $oEnd)
  {
    
  }
  
}

