<?php

namespace libJSTranspiler;

class TokenContext 
{
  public $sToken;
  public $bIsExpr;
  public $bPreserveSpace;
  public $fnOverride;
  public $bGenerator;

  public function __construct(
          $sToken, 
          $bIsExpr=false, 
          $bPreserveSpace=false, 
          $fnOverride=null, 
          $bGenerator=false) 
  {
    $this->sToken = $sToken;
    $this->bIsExpr = !!$bIsExpr;
    $this->bPreserveSpace = !!$bPreserveSpace;
    $this->fnOverride = $fnOverride;
    $this->bGenerator = !!$bGenerator;
  }
}
