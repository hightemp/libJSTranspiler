<?php

namespace libJSTranspiler;

class Position
{
  public $iLine; 
  public $iColumn; 
  public $iOffset;
  
  public function __construct($iLine, $iColumn)
  {
    $this->iLine = $iLine;
    $this->iColumn = $iColumn;
  }
  
  public function fnOffset($iN)
  {
    return new Position($this->iLine, $this->iColumn + $iN);
  }
}
