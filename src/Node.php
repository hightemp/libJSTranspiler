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
    $this->sType = "";
    $this->iStart = $iPos;
    $this->iEnd = 0;
    if ($oParser->aOptions['locations'])
      $this->oLoc = new SourceLocation($oParser, $oLoc);
    if ($oParser->aOptions['directSourceFile'])
      $this->oSourceFile = $oParser->aOptions['directSourceFile'];
    if ($oParser->aOptions['ranges'])
      $this->aRange = [$iPos, 0];
  }
}
